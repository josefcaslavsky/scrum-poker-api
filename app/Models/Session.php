<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Session extends Model
{
    protected $fillable = [
        'code',
        'host_id',
        'current_round',
        'status',
    ];

    protected $casts = [
        'status' => 'string',
    ];

    public function participants(): HasMany
    {
        return $this->hasMany(Participant::class);
    }

    public function host(): BelongsTo
    {
        return $this->belongsTo(Participant::class, 'host_id');
    }

    public function votes(): HasMany
    {
        return $this->hasMany(Vote::class);
    }

    public function currentRoundVotes(): HasMany
    {
        return $this->votes()->where('round', $this->current_round);
    }

    // Generate unique 6-character session code
    public static function generateCode(): string
    {
        do {
            $code = strtoupper(substr(str_shuffle('ABCDEFGHJKLMNPQRSTUVWXYZ23456789'), 0, 6));
        } while (self::where('code', $code)->exists());

        return $code;
    }
}
