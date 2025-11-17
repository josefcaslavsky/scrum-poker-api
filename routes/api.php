<?php

use App\Http\Controllers\SessionController;
use App\Http\Controllers\VoteController;
use Illuminate\Support\Facades\Route;

// Public endpoints (require API key)
Route::middleware(['api-key'])->group(function () {
    Route::post('/sessions', [SessionController::class, 'create']);
    Route::post('/sessions/{code}/join', [SessionController::class, 'join']);
});

// Authenticated participant endpoints (require Sanctum token)
Route::middleware(['auth:sanctum'])->group(function () {
    // Session info (no special privileges needed)
    Route::get('/sessions/{code}', [SessionController::class, 'show']);

    // Voting (any participant)
    Route::post('/sessions/{code}/vote', [VoteController::class, 'vote']);

    // Leave session (any participant)
    Route::delete('/sessions/{code}/leave', [SessionController::class, 'leave']);

    // Host-only actions
    Route::middleware(['host'])->group(function () {
        Route::post('/sessions/{code}/start', [SessionController::class, 'start']);
        Route::post('/sessions/{code}/reveal', [SessionController::class, 'reveal']);
        Route::post('/sessions/{code}/next-round', [SessionController::class, 'nextRound']);
    });
});
