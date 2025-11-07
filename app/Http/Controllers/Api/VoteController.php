<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Session;
use App\Models\Vote;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class VoteController extends Controller
{
    // POST /api/session/{code}/vote
    public function vote(Request $request, $code)
    {
        $session = Session::where('code', $code)->firstOrFail();

        // Validate voting is active
        if ($session->status !== 'voting') {
            return response()->json([
                'error' => 'Voting is not currently active'
            ], 403);
        }

        $validated = $request->validate([
            'participant_id' => 'required|exists:participants,id',
            'card_value' => ['required', Rule::in(Vote::validCardValues())],
        ]);

        $vote = Vote::updateOrCreate(
            [
                'session_id' => $session->id,
                'participant_id' => $validated['participant_id'],
                'round' => $session->current_round,
            ],
            [
                'card_value' => $validated['card_value'],
            ]
        );

        $vote->load('participant');

        // TODO: broadcast(new VoteSubmitted($session, $vote))->toOthers();

        // Check if all participants have voted
        $totalParticipants = $session->participants()->count();
        $votesCount = $session->currentRoundVotes()->count();

        return response()->json([
            'vote' => $vote,
            'all_voted' => $votesCount >= $totalParticipants,
        ]);
    }
}
