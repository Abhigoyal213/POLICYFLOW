<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json([
        'service' => 'TripperShield Policy Processing Engine',
        'status' => 'operational',
        'version' => '1.0.0',
        'documentation' => 'See README.md for complete API specification and curl examples.',
        'endpoints' => [
            'create_request' => 'POST /api/policy-requests',
            'view_request' => 'GET /api/policy-requests/{id}',
            'list_requests' => 'GET /api/policy-requests',
            'submit_request' => 'POST /api/policy-requests/{id}/submit',
            'mock_callback' => 'POST /api/mock-insurer/callback',
        ],
    ]);
});
