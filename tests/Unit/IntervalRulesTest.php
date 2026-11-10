<?php

namespace Tests\Unit;

use App\Exceptions\BookingRejected;
use App\Services\BookingService;
use App\Services\MongoStore;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class IntervalRulesTest extends TestCase
{
    private function check(string $s, string $e): void
    {
        (new BookingService($this->createStub(MongoStore::class)))->validateInterval(new CarbonImmutable($s, 'UTC'), new CarbonImmutable($e, 'UTC'));
        $this->assertTrue(true);
    }

    public static function valid(): array
    {
        return [
            'normal' => ['2026-03-10 10:00', '2026-03-10 11:00'],
            'exactly 2h' => ['2026-03-10 10:00', '2026-03-10 12:00'],
            'ends at maintenance start' => ['2026-03-10 01:00', '2026-03-10 02:00'],
            'starts at maintenance end' => ['2026-03-10 03:00', '2026-03-10 04:00'],
            'crosses midnight UTC' => ['2026-03-10 23:00', '2026-03-11 01:00'],
        ];
    }

    #[DataProvider('valid')]
    public function test_valid(string $s, string $e): void
    {
        $this->check($s, $e);
    }

    public static function invalid(): array
    {
        return [
            'end before start' => ['2026-03-10 11:00', '2026-03-10 10:00', 'invalid_range'],
            'zero length' => ['2026-03-10 11:00', '2026-03-10 11:00', 'invalid_range'],
            'over 2h' => ['2026-03-10 10:00', '2026-03-10 12:15', 'too_long'],
            'inside maintenance' => ['2026-03-10 02:15', '2026-03-10 02:45', 'maintenance_window'],
            'overlaps maintenance start' => ['2026-03-10 01:30', '2026-03-10 02:15', 'maintenance_window'],
            'overlaps maintenance end' => ['2026-03-10 02:45', '2026-03-10 03:30', 'maintenance_window'],
            'spans maintenance' => ['2026-03-10 01:30', '2026-03-10 03:15', 'maintenance_window'],
            'maintenance on a later day' => ['2026-03-11 01:30', '2026-03-11 02:30', 'maintenance_window'],
            'odd minutes' => ['2026-03-10 10:07', '2026-03-10 11:00', 'invalid_granularity'],
        ];
    }

    #[DataProvider('invalid')]
    public function test_invalid(string $s, string $e, string $code): void
    {
        try {
            $this->check($s, $e);
            $this->fail('expected rejection');
        } catch (BookingRejected $ex) {
            $this->assertSame($code, $ex->errorCode);
        }
    }
}
