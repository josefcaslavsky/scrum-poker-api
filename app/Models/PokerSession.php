<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PokerSession extends Model
{
    protected $table = 'poker_sessions';

    protected $fillable = [
        'code',
        'host_id',
        'current_round',
        'status',
    ];

    protected $casts = [
        'current_round' => 'integer',
    ];

    /**
     * Get the host participant of this session
     */
    public function host(): BelongsTo
    {
        return $this->belongsTo(Participant::class, 'host_id');
    }

    /**
     * Get all participants in this session
     */
    public function participants(): HasMany
    {
        return $this->hasMany(Participant::class, 'session_id');
    }

    /**
     * Get all votes in this session
     */
    public function votes(): HasMany
    {
        return $this->hasMany(Vote::class, 'session_id');
    }

    /**
     * Get votes for the current round
     */
    public function currentRoundVotes(): HasMany
    {
        return $this->votes()->where('round', $this->current_round);
    }
}
