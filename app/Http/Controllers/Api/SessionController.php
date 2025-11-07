<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Session;
use App\Models\Participant;
use Illuminate\Http\Request;

class SessionController extends Controller
{
    // POST /api/session
    public function create(Request $request)
    {
        $validated = $request->validate([
            'host_name' => 'required|string|max:50',
            'host_emoji' => 'nullable|string|max:10',
        ]);

        $session = Session::create([
            'code' => Session::generateCode(),
            'current_round' => 1,
            'status' => 'waiting',
        ]);

        $host = $session->participants()->create([
            'name' => $validated['host_name'],
            'emoji' => $validated['host_emoji'] ?? '👤',
        ]);

        $session->update(['host_id' => $host->id]);

        return response()->json([
            'session' => $session->load('participants'),
            'participant_id' => $host->id,
        ], 201);
    }

    // POST /api/session/{code}/start
    public function start($code)
    {
        $session = Session::where('code', $code)->firstOrFail();

        // Validate session is in waiting state
        if ($session->status !== 'waiting') {
            return response()->json([
                'error' => 'Voting has already started'
            ], 400);
        }

        $session->update(['status' => 'voting']);

        // TODO: broadcast(new VotingStarted($session));

        return response()->json($session);
    }

    // POST /api/session/join
    public function join(Request $request)
    {
        $validated = $request->validate([
            'code' => 'required|string|size:6|exists:sessions,code',
            'name' => 'required|string|max:50',
            'emoji' => 'nullable|string|max:10',
        ]);

        $session = Session::where('code', $validated['code'])->firstOrFail();

        $participant = $session->participants()->create([
            'name' => $validated['name'],
            'emoji' => $validated['emoji'] ?? '👤',
        ]);

        // TODO: broadcast(new ParticipantJoined($session, $participant))->toOthers();

        return response()->json([
            'session' => $session->load('participants', 'currentRoundVotes'),
            'participant_id' => $participant->id,
        ]);
    }

    // GET /api/session/{code}
    public function show($code)
    {
        $session = Session::where('code', $code)
            ->with(['participants', 'currentRoundVotes.participant'])
            ->firstOrFail();

        return response()->json($session);
    }

    // POST /api/session/{code}/reveal
    public function reveal($code)
    {
        $session = Session::where('code', $code)->firstOrFail();

        // Validate session is in voting state
        if ($session->status !== 'voting') {
            return response()->json([
                'error' => 'No active voting to reveal'
            ], 400);
        }

        $session->update(['status' => 'revealed']);

        $votes = $session->currentRoundVotes()->with('participant')->get();

        // TODO: broadcast(new CardsRevealed($session, $votes));

        return response()->json([
            'session' => $session,
            'votes' => $votes,
        ]);
    }

    // POST /api/session/{code}/next-round
    public function nextRound($code)
    {
        $session = Session::where('code', $code)->firstOrFail();

        // Validate session is in revealed state
        if ($session->status !== 'revealed') {
            return response()->json([
                'error' => 'Cards must be revealed before starting next round'
            ], 400);
        }

        $session->update([
            'current_round' => $session->current_round + 1,
            'status' => 'voting',
        ]);

        // TODO: broadcast(new NextRoundStarted($session));

        return response()->json($session);
    }
}
