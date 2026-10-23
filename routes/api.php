<?php

use App\Http\Controllers\MockInsurerController;
use App\Http\Controllers\PolicyRequestController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| TripperShield Policy Processing API
|
*/

// Policy Request endpoints
Route::post('/policy-requests', [PolicyRequestController::class, 'store']);
Route::get('/policy-requests', [PolicyRequestController::class, 'index']);
Route::get('/policy-requests/{id}', [PolicyRequestController::class, 'show']);
Route::post('/policy-requests/{id}/submit', [PolicyRequestController::class, 'submit']);

// Mock Insurer endpoints
Route::post('/mock-insurer/submit', [MockInsurerController::class, 'submit']);
Route::get('/mock-insurer/status/{providerReference}', [MockInsurerController::class, 'status']);
Route::post('/mock-insurer/callback', [MockInsurerController::class, 'callback']);
