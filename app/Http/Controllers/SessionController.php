<?php

namespace App\Http\Controllers;

use App\Events\NextRoundStarted;
use App\Events\ParticipantJoined;
use App\Events\ParticipantLeft;
use App\Events\SessionEnded;
use App\Events\CardsRevealed;
use App\Events\VotingStarted;
use App\Models\PokerSession;
use App\Models\Participant;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class SessionController extends Controller
{
    /**
     * Create a new poker session
     * POST /api/sessions
     */
    public function create(Request $request)
    {
        $validated = $request->validate([
            'host_name' => 'required|string|max:50',
            'host_emoji' => 'nullable|string|max:10',
        ]);

        // Generate unique 6-character code
        do {
            $code = strtoupper(Str::random(6));
        } while (PokerSession::where('code', $code)->exists());

        // Create session
        $session = PokerSession::create([
            'code' => $code,
            'current_round' => 1,
            'status' => 'waiting',
        ]);

        // Create host participant
        $host = Participant::create([
            'session_id' => $session->id,
            'name' => $validated['host_name'],
            'emoji' => $validated['host_emoji'] ?? '👤',
        ]);

        // Update session with host_id
        $session->update(['host_id' => $host->id]);

        return response()->json([
            'code' => $session->code,
            'session_id' => $session->id,
            'participant_id' => $host->id,
            'status' => $session->status,
            'current_round' => $session->current_round,
        ], 201);
    }

    /**
     * Join an existing session
     * POST /api/sessions/{code}/join
     */
    public function join(Request $request, string $code)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:50',
            'emoji' => 'nullable|string|max:10',
        ]);

        $session = PokerSession::where('code', $code)->firstOrFail();

        $participant = Participant::create([
            'session_id' => $session->id,
            'name' => $validated['name'],
            'emoji' => $validated['emoji'] ?? '👤',
        ]);

        broadcast(new ParticipantJoined($code, $participant));

        return response()->json([
            'session_id' => $session->id,
            'participant_id' => $participant->id,
            'status' => $session->status,
            'current_round' => $session->current_round,
            'participants' => $session->participants,
        ]);
    }

    /**
     * Start voting for current round
     * POST /api/sessions/{code}/start
     */
    public function start(string $code)
    {
        $session = PokerSession::where('code', $code)->firstOrFail();

        if ($session->status !== 'waiting') {
            return response()->json([
                'error' => 'Voting can only be started when session is in waiting state',
            ], 400);
        }

        $session->update(['status' => 'voting']);

        broadcast(new VotingStarted($code, $session->current_round));

        return response()->json([
            'status' => $session->status,
            'current_round' => $session->current_round,
        ]);
    }

    /**
     * Reveal all votes for current round
     * POST /api/sessions/{code}/reveal
     */
    public function reveal(string $code)
    {
        $session = PokerSession::where('code', $code)->firstOrFail();

        if ($session->status !== 'voting') {
            return response()->json([
                'error' => 'Cards can only be revealed when session is in voting state',
            ], 400);
        }

        $session->update(['status' => 'revealed']);

        $votes = $session->currentRoundVotes()->with('participant')->get();

        broadcast(new CardsRevealed($code, $votes->toArray()));

        return response()->json([
            'status' => $session->status,
            'votes' => $votes,
        ]);
    }

    /**
     * Start next round
     * POST /api/sessions/{code}/next-round
     */
    public function nextRound(string $code)
    {
        $session = PokerSession::where('code', $code)->firstOrFail();

        if ($session->status !== 'revealed') {
            return response()->json([
                'error' => 'Next round can only be started after cards are revealed',
            ], 400);
        }

        $session->update([
            'current_round' => $session->current_round + 1,
            'status' => 'voting',
        ]);

        broadcast(new NextRoundStarted($code, $session->current_round));

        return response()->json([
            'status' => $session->status,
            'current_round' => $session->current_round,
        ]);
    }

    /**
     * Get session details
     * GET /api/sessions/{code}
     */
    public function show(string $code)
    {
        $session = PokerSession::where('code', $code)
            ->with(['participants', 'currentRoundVotes.participant'])
            ->firstOrFail();

        return response()->json([
            'code' => $session->code,
            'status' => $session->status,
            'current_round' => $session->current_round,
            'participants' => $session->participants,
            'votes' => $session->status === 'revealed' ? $session->currentRoundVotes : [],
        ]);
    }

    /**
     * Leave session (participant leaves or disconnects)
     * DELETE /api/sessions/{code}/participants/{participantId}
     */
    public function leave(string $code, int $participantId)
    {
        $session = PokerSession::where('code', $code)->firstOrFail();
        $participant = Participant::where('id', $participantId)
            ->where('session_id', $session->id)
            ->firstOrFail();

        // Check if participant is the host
        $isHost = $session->host_id === $participant->id;

        if ($isHost) {
            // Host is leaving - end the entire session
            broadcast(new SessionEnded($code));

            // Delete session (cascade will handle participants and votes)
            $session->delete();

            return response()->json([
                'message' => 'Session ended - host left',
                'session_ended' => true,
            ]);
        } else {
            // Regular participant leaving
            $participantName = $participant->name;

            // Delete participant (but keep their votes for history)
            $participant->delete();

            // Get remaining participants
            $remainingParticipants = $session->participants()->get()->toArray();

            // Broadcast participant left event
            broadcast(new ParticipantLeft(
                $code,
                $participantId,
                $participantName,
                $remainingParticipants
            ));

            return response()->json([
                'message' => 'Participant left session',
                'session_ended' => false,
                'remaining_participants' => $remainingParticipants,
            ]);
        }
    }
}
