<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\SessionController;
use App\Http\Controllers\Api\VoteController;

// Session management
Route::post('/session', [SessionController::class, 'create']);
Route::post('/session/join', [SessionController::class, 'join']);
Route::get('/session/{code}', [SessionController::class, 'show']);

// Round control (host only)
Route::post('/session/{code}/start', [SessionController::class, 'start']);
Route::post('/session/{code}/reveal', [SessionController::class, 'reveal']);
Route::post('/session/{code}/next-round', [SessionController::class, 'nextRound']);

// Voting
Route::post('/session/{code}/vote', [VoteController::class, 'vote']);
