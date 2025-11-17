<?php

namespace App\Http\Middleware;

use App\Models\PokerSession;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureIsHost
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $participant = $request->user();
        $code = $request->route('code');

        if (!$participant || !$code) {
            return response()->json([
                'error' => 'Forbidden',
                'message' => 'This action requires host privileges'
            ], 403);
        }

        $session = PokerSession::where('code', $code)->first();

        if (!$session || $session->host_id !== $participant->id) {
            return response()->json([
                'error' => 'Forbidden',
                'message' => 'This action requires host privileges'
            ], 403);
        }

        return $next($request);
    }
}
