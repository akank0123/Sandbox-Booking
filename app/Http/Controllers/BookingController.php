<?php

namespace App\Http\Controllers;

use App\Exceptions\BookingRejected;
use App\Services\MongoStore;
use App\Services\BookingService;
use Illuminate\Http\Request;

class BookingController extends Controller
{
    public function __construct(private BookingService $svc, private MongoStore $store) {}

    /** Active bookings intersecting local day `date` in `timezone`. Times returned in UTC. */
    public function index(Request $r)
    {
        $date = (string) $r->query('date', '');
        $tz = (string) $r->query('timezone', 'UTC');
        if ($date === '') {
            throw new BookingRejected('invalid_datetime', "Query parameter 'date' (YYYY-MM-DD) is required.", 400);
        }
        [$lo, $hi] = $this->svc->dayBoundsUtc($date, $tz);

        $rows = $this->store->bookingsBetween($lo, $hi);

        return [
            'date' => $date,
            'timezone' => $tz,
            'day_start_utc' => $lo->format('Y-m-d\TH:i:s\Z'),
            'day_end_utc' => $hi->format('Y-m-d\TH:i:s\Z'),
            'bookings' => $rows,
        ];
    }

    public function store(Request $r)
    {
        $data = $r->validate([
            'sandbox_id' => 'required|integer',
            'date' => 'required|string',
            'start_time' => 'required|string',
            'end_time' => 'required|string',
            'timezone' => 'sometimes|string',
            'analyst_name' => 'required|string|max:100',
            'sample_ref' => 'required|string|max:200',
        ]);
        $tz = $data['timezone'] ?? 'UTC';
        $start = $this->svc->localToUtc($data['date'], $data['start_time'], $tz);
        $end = $this->svc->localToUtc($data['date'], $data['end_time'], $tz);

        $b = $this->svc->create($data['sandbox_id'], $start, $end, trim($data['analyst_name']), trim($data['sample_ref']));

        return response()->json($b, 201);
    }

    public function destroy(int $id)
    {
        $b = $this->svc->cancel($id);

        return ['id' => $b['id'], 'status' => $b['status']];
    }
}
