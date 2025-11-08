<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Participant extends Model
{
    protected $fillable = [
        'session_id',
        'name',
        'emoji',
    ];

    /**
     * Get the session this participant belongs to
     */
    public function session(): BelongsTo
    {
        return $this->belongsTo(PokerSession::class, 'session_id');
    }

    /**
     * Get all votes by this participant
     */
    public function votes(): HasMany
    {
        return $this->hasMany(Vote::class, 'participant_id');
    }
}
