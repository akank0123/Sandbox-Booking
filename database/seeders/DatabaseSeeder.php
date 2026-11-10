<?php

namespace Database\Seeders;

use App\Services\MongoStore;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /** Creates indexes and seeds 5 sandboxes (idempotent). */
    public function run(): void
    {
        app(MongoStore::class)->seed();
    }
}
