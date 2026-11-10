<?php

namespace Tests\Feature;

use Database\Seeders\DatabaseSeeder;
use App\Services\MongoStore;
use Tests\TestCase;

class BookingApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        app(MongoStore::class)->db()->drop(); // isolated test database (see phpunit.xml)
        $this->seed(DatabaseSeeder::class);
    }

    private function book(array $o = [])
    {
        return $this->postJson('/api/bookings', $o + [
            'sandbox_id' => 1, 'date' => '2026-03-10', 'start_time' => '10:00', 'end_time' => '11:00',
            'timezone' => 'UTC', 'analyst_name' => 'Asha', 'sample_ref' => 'sha256:abc',
        ]);
    }

    public function test_lists_seeded_sandboxes(): void
    {
        $this->getJson('/api/sandboxes')->assertOk()->assertJsonCount(5);
    }

    public function test_create_and_list_for_date(): void
    {
        $this->book()->assertCreated()->assertJsonPath('start_utc', '2026-03-10T10:00:00Z');
        $this->getJson('/api/bookings?date=2026-03-10&timezone=UTC')->assertOk()->assertJsonCount(1, 'bookings');
        $this->getJson('/api/bookings?date=2026-03-11&timezone=UTC')->assertOk()->assertJsonCount(0, 'bookings');
    }

    public function test_overlap_rejected_but_back_to_back_allowed(): void
    {
        $this->book()->assertCreated();
        $this->book(['start_time' => '10:30', 'end_time' => '11:30'])->assertStatus(409)->assertJsonPath('error', 'overlap');
        $this->book(['start_time' => '11:00', 'end_time' => '12:00'])->assertCreated();
        $this->book(['start_time' => '09:00', 'end_time' => '10:00'])->assertCreated();
        // other sandbox is independent
        $this->book(['sandbox_id' => 2])->assertCreated();
    }

    public function test_rule_rejections_have_codes(): void
    {
        $this->book(['start_time' => '10:00', 'end_time' => '12:30'])->assertStatus(422)->assertJsonPath('error', 'too_long');
        $this->book(['start_time' => '01:30', 'end_time' => '02:30'])->assertStatus(422)->assertJsonPath('error', 'maintenance_window');
        $this->book(['start_time' => '12:00', 'end_time' => '11:00'])->assertStatus(422)->assertJsonPath('error', 'invalid_range');
        $this->book(['sandbox_id' => 99])->assertStatus(404)->assertJsonPath('error', 'sandbox_not_found');
        $this->book(['timezone' => 'Mars/Base'])->assertStatus(400)->assertJsonPath('error', 'invalid_timezone');
        $this->book(['analyst_name' => ''])->assertStatus(422);
    }

    public function test_timezone_is_converted_to_utc(): void
    {
        // 07:30 IST (UTC+5:30) == 02:00 UTC -> inside maintenance window
        $this->book(['timezone' => 'Asia/Kolkata', 'start_time' => '07:30', 'end_time' => '08:00'])
            ->assertStatus(422)->assertJsonPath('error', 'maintenance_window');
        // 15:30 IST == 10:00 UTC
        $this->book(['timezone' => 'Asia/Kolkata', 'start_time' => '15:30', 'end_time' => '16:30'])
            ->assertCreated()->assertJsonPath('start_utc', '2026-03-10T10:00:00Z');
        // visible in the UTC day and the IST day
        $this->getJson('/api/bookings?date=2026-03-10&timezone=UTC')->assertJsonCount(1, 'bookings');
        $this->getJson('/api/bookings?date=2026-03-10&timezone=Asia/Kolkata')->assertJsonCount(1, 'bookings');
    }

    public function test_cancel_frees_the_slot(): void
    {
        $id = $this->book()->json('id');
        $this->deleteJson("/api/bookings/$id")->assertOk()->assertJsonPath('status', 'cancelled');
        $this->deleteJson("/api/bookings/$id")->assertStatus(409)->assertJsonPath('error', 'already_cancelled');
        $this->deleteJson('/api/bookings/9999')->assertStatus(404);
        $this->book()->assertCreated();
    }

    public function test_legacy_browser_timezone_alias_is_accepted(): void
    {
        // Chrome reports India as "Asia/Calcutta"
        $this->getJson('/api/bookings?date=2026-03-10&timezone=Asia/Calcutta')->assertOk();
        $this->book(['timezone' => 'Asia/Calcutta', 'start_time' => '15:30', 'end_time' => '16:30'])
            ->assertCreated()->assertJsonPath('start_utc', '2026-03-10T10:00:00Z');
    }
}
