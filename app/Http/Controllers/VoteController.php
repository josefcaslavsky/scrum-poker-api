<?php

namespace App\Http\Controllers;

use App\Events\VoteSubmitted as VoteSubmittedEvent;
use App\Models\PokerSession;
use App\Models\Vote;
use Illuminate\Http\Request;

class VoteController extends Controller
{
    /**
     * Submit a vote for the current round
     * POST /api/sessions/{code}/vote
     */
    public function vote(Request $request, string $code)
    {
        $validated = $request->validate([
            'participant_id' => 'required|exists:participants,id',
            'card_value' => 'required|string|max:10',
        ]);

        $session = PokerSession::where('code', $code)->firstOrFail();

        // Validate session status
        if ($session->status !== 'voting') {
            return response()->json([
                'error' => 'Voting is only allowed when session is in voting state',
            ], 400);
        }

        // Create or update vote (upsert based on unique constraint)
        $vote = Vote::updateOrCreate(
            [
                'session_id' => $session->id,
                'participant_id' => $validated['participant_id'],
                'round' => $session->current_round,
            ],
            [
                'card_value' => $validated['card_value'],
                'voted_at' => now(),
            ]
        );

        broadcast(new VoteSubmittedEvent($code, $validated['participant_id']));

        return response()->json([
            'vote_id' => $vote->id,
            'card_value' => $vote->card_value,
            'voted_at' => $vote->voted_at,
        ]);
    }
}
