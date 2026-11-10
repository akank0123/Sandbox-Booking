<?php

namespace App\Services;

use App\Exceptions\BookingRejected;
use Carbon\CarbonImmutable;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Client;
use MongoDB\Collection;
use MongoDB\Database;
use MongoDB\Driver\Exception\BulkWriteException;

/**
 * MongoDB persistence.
 *
 * Collections
 *   sandboxes     {_id:int, name, os}
 *   bookings      {_id:int, sandbox_id, start_utc, end_utc (UTCDateTime), analyst_name, sample_ref, status, created_at}
 *   booking_slots {sandbox_id, slot (epoch secs of a 15-min UTC slot), booking_id}  -- UNIQUE(sandbox_id, slot)
 *   counters      {_id:'bookings', seq}
 *
 * Double-booking protection: a booking claims one slot document per 15 minutes it
 * covers. The unique index makes it impossible for two bookings to claim the same
 * sandbox+slot, regardless of how many app processes race. Needs no replica set / transactions.
 */
class MongoStore
{
    public const SLOT_SECONDS = 900;

    private Database $db;

    public function __construct()
    {
        $this->db = (new Client(config('mongo.uri')))->selectDatabase(config('mongo.database'));
    }

    public function db(): Database
    {
        return $this->db;
    }

    private function c(string $n): Collection
    {
        return $this->db->selectCollection($n);
    }

    public function ensureIndexes(): void
    {
        $this->c('sandboxes')->createIndex(['name' => 1], ['unique' => true]);
        $this->c('booking_slots')->createIndex(['sandbox_id' => 1, 'slot' => 1], ['unique' => true]);
        $this->c('booking_slots')->createIndex(['booking_id' => 1]);
        $this->c('bookings')->createIndex(['status' => 1, 'start_utc' => 1, 'end_utc' => 1]);
    }

    public function seed(): void
    {
        $this->ensureIndexes();
        foreach ([
            [1, 'win10-sandbox-1', 'Windows 10'],
            [2, 'win11-sandbox-1', 'Windows 11'],
            [3, 'ubuntu-sandbox-1', 'Ubuntu 22.04'],
            [4, 'macos-sandbox-1', 'macOS 14'],
            [5, 'remnux-sandbox-1', 'REMnux (Linux malware analysis)'],
        ] as [$id, $name, $os]) {
            $this->c('sandboxes')->updateOne(['_id' => $id], ['$setOnInsert' => ['name' => $name, 'os' => $os]], ['upsert' => true]);
        }
    }

    public function sandboxes(): array
    {
        $out = [];
        foreach ($this->c('sandboxes')->find([], ['sort' => ['_id' => 1]]) as $s) {
            $out[] = ['id' => $s['_id'], 'name' => $s['name'], 'os' => $s['os']];
        }

        return $out;
    }

    public function sandbox(int $id): ?array
    {
        $s = $this->c('sandboxes')->findOne(['_id' => $id]);

        return $s ? ['id' => $s['_id'], 'name' => $s['name'], 'os' => $s['os']] : null;
    }

    private function toApi($b): array
    {
        $iso = fn (UTCDateTime $d) => CarbonImmutable::createFromTimestampUTC((int) ($d->toDateTime()->getTimestamp()))->format('Y-m-d\TH:i:s\Z');

        return [
            'id' => $b['_id'],
            'sandbox_id' => $b['sandbox_id'],
            'start_utc' => $iso($b['start_utc']),
            'end_utc' => $iso($b['end_utc']),
            'analyst_name' => $b['analyst_name'],
            'sample_ref' => $b['sample_ref'],
            'status' => $b['status'],
        ];
    }

    private static function ms(CarbonImmutable $t): UTCDateTime
    {
        return new UTCDateTime($t->getTimestamp() * 1000);
    }

    /** Active bookings intersecting [lo, hi). */
    public function bookingsBetween(CarbonImmutable $lo, CarbonImmutable $hi): array
    {
        $cur = $this->c('bookings')->find(
            ['status' => 'active', 'start_utc' => ['$lt' => self::ms($hi)], 'end_utc' => ['$gt' => self::ms($lo)]],
            ['sort' => ['start_utc' => 1]]
        );
        $out = [];
        foreach ($cur as $b) {
            $out[] = $this->toApi($b);
        }

        return $out;
    }

    private function nextId(): int
    {
        $d = $this->c('counters')->findOneAndUpdate(
            ['_id' => 'bookings'], ['$inc' => ['seq' => 1]],
            ['upsert' => true, 'returnDocument' => \MongoDB\Operation\FindOneAndUpdate::RETURN_DOCUMENT_AFTER]
        );

        return (int) $d['seq'];
    }

    /** Claims slots atomically; throws BookingRejected('overlap') if any slot is taken. */
    public function createBooking(int $sandboxId, CarbonImmutable $start, CarbonImmutable $end, string $analyst, string $sample): array
    {
        $id = $this->nextId();
        $this->c('bookings')->insertOne([
            '_id' => $id, 'sandbox_id' => $sandboxId,
            'start_utc' => self::ms($start), 'end_utc' => self::ms($end),
            'analyst_name' => $analyst, 'sample_ref' => $sample,
            'status' => 'pending', 'created_at' => new UTCDateTime(),
        ]);

        $slots = [];
        for ($t = $start->getTimestamp(); $t < $end->getTimestamp(); $t += self::SLOT_SECONDS) {
            $slots[] = ['sandbox_id' => $sandboxId, 'slot' => $t, 'booking_id' => $id];
        }

        try {
            // Ordered insert in ascending time: stops at the first taken slot. Claiming in a
            // fixed order guarantees that of two overlapping requests one fully succeeds.
            $this->c('booking_slots')->insertMany($slots, ['ordered' => true]);
        } catch (BulkWriteException $e) {
            $this->c('booking_slots')->deleteMany(['booking_id' => $id]);
            $this->c('bookings')->deleteOne(['_id' => $id]);
            if (! $this->isDuplicate($e)) {
                throw $e;
            }
            $clash = null;
            foreach ($slots as $s) {
                $taken = $this->c('booking_slots')->findOne(['sandbox_id' => $sandboxId, 'slot' => $s['slot']]);
                if ($taken) {
                    $clash = $this->c('bookings')->findOne(['_id' => $taken['booking_id']]);
                    break;
                }
            }
            $name = $this->sandbox($sandboxId)['name'];
            $msg = $clash
                ? "$name is already booked {$this->toApi($clash)['start_utc']} - {$this->toApi($clash)['end_utc']} (UTC) by {$clash['analyst_name']}."
                : "$name is already booked for that time (UTC).";
            throw new BookingRejected('overlap', $msg, 409);
        }

        $this->c('bookings')->updateOne(['_id' => $id], ['$set' => ['status' => 'active']]);

        return $this->toApi($this->c('bookings')->findOne(['_id' => $id]));
    }

    private function isDuplicate(BulkWriteException $e): bool
    {
        foreach ($e->getWriteResult()->getWriteErrors() as $we) {
            if ($we->getCode() === 11000) {
                return true;
            }
        }

        return false;
    }

    public function cancelBooking(int $id): array
    {
        // Atomic state transition: only one caller can flip active -> cancelled.
        $b = $this->c('bookings')->findOneAndUpdate(
            ['_id' => $id, 'status' => 'active'], ['$set' => ['status' => 'cancelled']],
            ['returnDocument' => \MongoDB\Operation\FindOneAndUpdate::RETURN_DOCUMENT_AFTER]
        );
        if (! $b) {
            $exists = $this->c('bookings')->findOne(['_id' => $id]);
            if ($exists && $exists['status'] === 'cancelled') {
                throw new BookingRejected('already_cancelled', "Booking $id is already cancelled.", 409);
            }
            throw new BookingRejected('not_found', "Booking $id not found.", 404);
        }
        $this->c('booking_slots')->deleteMany(['booking_id' => $id]); // frees the slots

        return $this->toApi($b);
    }
}
