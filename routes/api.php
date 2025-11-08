<?php

use App\Http\Controllers\SessionController;
use App\Http\Controllers\VoteController;
use Illuminate\Support\Facades\Route;

// Session management
Route::post('/sessions', [SessionController::class, 'create']);
Route::get('/sessions/{code}', [SessionController::class, 'show']);
Route::post('/sessions/{code}/join', [SessionController::class, 'join']);
Route::post('/sessions/{code}/start', [SessionController::class, 'start']);
Route::post('/sessions/{code}/reveal', [SessionController::class, 'reveal']);
Route::post('/sessions/{code}/next-round', [SessionController::class, 'nextRound']);

// Voting
Route::post('/sessions/{code}/vote', [VoteController::class, 'vote']);
