<?php

namespace App\Http\Controllers;

use App\Services\MongoStore;

class SandboxController extends Controller
{
    public function index(MongoStore $store)
    {
        return $store->sandboxes();
    }
}
