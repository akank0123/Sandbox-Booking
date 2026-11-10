<?php

namespace App\Services;

use App\Exceptions\BookingRejected;
use Carbon\CarbonImmutable;
use Throwable;

class BookingService
{
    public const MAX_MINUTES = 120;
    public const GRANULARITY = 15;
    public const MAINT_START_HOUR = 2; // UTC
    public const MAINT_END_HOUR = 3;   // UTC

    /** Convert a wall-clock date+time in an IANA zone to a UTC instant. */
    public function localToUtc(string $date, string $time, string $tz): CarbonImmutable
    {
        $tz = $this->assertTimezone($tz);
        try {
            return CarbonImmutable::createFromFormat('Y-m-d H:i', "$date $time", $tz)->utc();
        } catch (Throwable) {
            throw new BookingRejected('invalid_datetime', "Invalid date/time '$date $time'. Use YYYY-MM-DD and HH:MM.", 400);
        }
    }

    /** [start, end) in UTC of the local calendar day (handles 23h/25h DST days). */
    public function dayBoundsUtc(string $date, string $tz): array
    {
        $tz = $this->assertTimezone($tz);
        try {
            $start = CarbonImmutable::createFromFormat('Y-m-d H:i:s', "$date 00:00:00", $tz);
        } catch (Throwable) {
            throw new BookingRejected('invalid_datetime', "Invalid date '$date'. Use YYYY-MM-DD.", 400);
        }

        return [$start->utc(), $start->addDay()->startOfDay()->utc()];
    }

    /** Legacy names that browsers (ICU) report but PHP's tz database lacks -> canonical IANA names. */
    private const TZ_ALIASES = [
        'Asia/Calcutta' => 'Asia/Kolkata', 'Asia/Katmandu' => 'Asia/Kathmandu', 'Asia/Saigon' => 'Asia/Ho_Chi_Minh',
        'Asia/Rangoon' => 'Asia/Yangon', 'Asia/Dacca' => 'Asia/Dhaka', 'Asia/Thimbu' => 'Asia/Thimphu',
        'Asia/Ujung_Pandang' => 'Asia/Makassar', 'Asia/Macao' => 'Asia/Macau', 'Asia/Ulan_Bator' => 'Asia/Ulaanbaatar',
        'Europe/Kiev' => 'Europe/Kyiv', 'Atlantic/Faeroe' => 'Atlantic/Faroe', 'Africa/Asmera' => 'Africa/Asmara',
        'America/Buenos_Aires' => 'America/Argentina/Buenos_Aires', 'America/Indianapolis' => 'America/Indiana/Indianapolis',
        'America/Louisville' => 'America/Kentucky/Louisville', 'America/Godthab' => 'America/Nuuk',
        'America/Coral_Harbour' => 'America/Atikokan', 'Pacific/Truk' => 'Pacific/Chuuk', 'Pacific/Ponape' => 'Pacific/Pohnpei',
        'Pacific/Enderbury' => 'Pacific/Kanton',
    ];

    /** Validate an IANA zone and return the name PHP understands. */
    private function assertTimezone(string $tz): string
    {
        $tz = self::TZ_ALIASES[$tz] ?? $tz;
        if (! in_array($tz, \DateTimeZone::listIdentifiers(), true)) {
            throw new BookingRejected('invalid_timezone', "Unknown IANA timezone '$tz' (e.g. 'Asia/Kolkata', 'UTC').", 400);
        }

        return $tz;
    }

    /** Pure rule checks on a UTC interval. Throws BookingRejected. */
    public function validateInterval(CarbonImmutable $start, CarbonImmutable $end): void
    {
        if ($end <= $start) {
            throw new BookingRejected('invalid_range', 'End time must be after start time.');
        }
        foreach ([$start, $end] as $t) {
            if ($t->minute % self::GRANULARITY !== 0 || $t->second !== 0) {
                throw new BookingRejected('invalid_granularity', 'Times must be on 15-minute boundaries.');
            }
        }
        if ($start->diffInMinutes($end) > self::MAX_MINUTES) {
            throw new BookingRejected('too_long', 'A booking can be at most 2 hours.');
        }
        // Check the 02:00-03:00 UTC window of every UTC day the booking touches.
        for ($d = $start->utc()->startOfDay(); $d <= $end->utc()->startOfDay(); $d = $d->addDay()) {
            $ws = $d->setTime(self::MAINT_START_HOUR, 0);
            $we = $d->setTime(self::MAINT_END_HOUR, 0);
            // Half-open overlap: ending exactly at 02:00 or starting at 03:00 is allowed.
            if ($start < $we && $end > $ws) {
                throw new BookingRejected(
                    'maintenance_window',
                    'Booking overlaps the daily maintenance window 02:00-03:00 UTC (sandboxes are reverted to a clean snapshot).'
                );
            }
        }
    }

    public function __construct(private MongoStore $store) {}

    public function create(int $sandboxId, CarbonImmutable $start, CarbonImmutable $end, string $analyst, string $sample): array
    {
        $this->validateInterval($start, $end);
        if (! $this->store->sandbox($sandboxId)) {
            throw new BookingRejected('sandbox_not_found', "Sandbox $sandboxId does not exist.", 404);
        }

        // Overlap safety is enforced by MongoDB's unique index on (sandbox_id, 15-min slot).
        return $this->store->createBooking($sandboxId, $start, $end, $analyst, $sample);
    }

    public function cancel(int $id): array
    {
        return $this->store->cancelBooking($id);
    }
}
