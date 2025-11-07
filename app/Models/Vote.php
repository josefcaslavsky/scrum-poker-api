<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Vote extends Model
{
    const UPDATED_AT = null; // Only track voted_at

    protected $fillable = [
        'session_id',
        'participant_id',
        'round',
        'card_value',
        'voted_at',
    ];

    protected $casts = [
        'voted_at' => 'datetime',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(Session::class);
    }

    public function participant(): BelongsTo
    {
        return $this->belongsTo(Participant::class);
    }

    // Valid Fibonacci card values
    public static function validCardValues(): array
    {
        return ['0', '1/2', '1', '2', '3', '5', '8', '13', '21', '?', '☕'];
    }
}
