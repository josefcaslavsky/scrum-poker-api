<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Vote extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'session_id',
        'participant_id',
        'round',
        'card_value',
        'voted_at',
    ];

    protected $casts = [
        'round' => 'integer',
        'voted_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    /**
     * Get the session this vote belongs to
     */
    public function session(): BelongsTo
    {
        return $this->belongsTo(PokerSession::class, 'session_id');
    }

    /**
     * Get the participant who cast this vote
     */
    public function participant(): BelongsTo
    {
        return $this->belongsTo(Participant::class, 'participant_id');
    }
}
