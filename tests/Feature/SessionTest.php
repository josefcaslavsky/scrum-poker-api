<?php

namespace Tests\Feature;

use App\Models\Participant;
use App\Models\PokerSession;
use App\Models\Vote;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SessionTest extends TestCase
{
    use RefreshDatabase;

    private string $apiKey = 'scrum-poker-internal-2025';

    public function test_can_create_session(): void
    {
        $response = $this->postJson('/api/sessions', [
            'host_name' => 'Alice',
            'host_emoji' => '👩‍💻',
        ], [
            'X-API-Key' => $this->apiKey,
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'session' => ['code', 'id', 'status', 'current_round'],
                'participant' => ['id', 'name', 'emoji'],
                'token',
            ])
            ->assertJson([
                'session' => [
                    'status' => 'waiting',
                    'current_round' => 1,
                ],
                'participant' => [
                    'name' => 'Alice',
                    'emoji' => '👩‍💻',
                ],
            ]);

        $this->assertDatabaseHas('poker_sessions', [
            'code' => $response->json('session.code'),
            'status' => 'waiting',
            'current_round' => 1,
        ]);

        $this->assertDatabaseHas('participants', [
            'id' => $response->json('participant.id'),
            'name' => 'Alice',
            'emoji' => '👩‍💻',
        ]);

        // Verify the participant is set as host in the session
        $this->assertDatabaseHas('poker_sessions', [
            'code' => $response->json('session.code'),
            'host_id' => $response->json('participant.id'),
        ]);
    }

    public function test_session_code_is_unique_and_six_characters(): void
    {
        $response = $this->postJson('/api/sessions', [
            'host_name' => 'Test',
            'host_emoji' => '🧪',
        ], [
            'X-API-Key' => $this->apiKey,
        ]);

        $code = $response->json('session.code');
        $this->assertEquals(6, strlen($code));
        $this->assertMatchesRegularExpression('/^[A-Z0-9]{6}$/', $code);
    }

    public function test_can_join_existing_session(): void
    {
        $session = PokerSession::create([
            'code' => 'TEST01',
            'current_round' => 1,
            'status' => 'waiting',
        ]);

        $host = Participant::create([
            'session_id' => $session->id,
            'name' => 'Host',
            'emoji' => '👤',
        ]);

        $session->update(['host_id' => $host->id]);

        $response = $this->postJson("/api/sessions/TEST01/join", [
            'name' => 'Bob',
            'emoji' => '👨‍💼',
        ], [
            'X-API-Key' => $this->apiKey,
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'participant' => ['id', 'name', 'emoji'],
                'session',
                'participants',
                'token',
            ]);

        $this->assertDatabaseHas('participants', [
            'session_id' => $session->id,
            'name' => 'Bob',
            'emoji' => '👨‍💼',
        ]);
    }

    public function test_joining_participant_is_included_in_participants_list(): void
    {
        $session = PokerSession::create([
            'code' => 'JOIN01',
            'current_round' => 1,
            'status' => 'waiting',
        ]);

        $host = Participant::create([
            'session_id' => $session->id,
            'name' => 'Host',
            'emoji' => '👤',
        ]);

        $session->update(['host_id' => $host->id]);

        $response = $this->postJson("/api/sessions/JOIN01/join", [
            'name' => 'NewUser',
            'emoji' => '🆕',
        ], [
            'X-API-Key' => $this->apiKey,
        ]);

        $response->assertStatus(200);

        // Get the joining participant's ID from the response
        $joiningParticipantId = $response->json('participant.id');

        // Verify the participants list includes the joining participant
        $participants = $response->json('participants');
        $participantIds = array_column($participants, 'id');

        $this->assertContains($joiningParticipantId, $participantIds,
            'The joining participant should be included in the participants list');

        // Also verify the host is in the list
        $this->assertContains($host->id, $participantIds,
            'The host should be included in the participants list');

        // Verify the total count is correct (host + joining participant)
        $this->assertCount(2, $participants,
            'The participants list should have exactly 2 participants');
    }

    public function test_cannot_join_non_existent_session(): void
    {
        $response = $this->postJson('/api/sessions/NOTFND/join', [
            'name' => 'Bob',
            'emoji' => '👨‍💼',
        ], [
            'X-API-Key' => $this->apiKey,
        ]);

        $response->assertStatus(404);
    }

    public function test_can_start_voting(): void
    {
        $session = PokerSession::create([
            'code' => 'TEST02',
            'current_round' => 1,
            'status' => 'waiting',
        ]);

        $host = Participant::create([
            'session_id' => $session->id,
            'name' => 'Host',
            'emoji' => '👤',
        ]);

        $session->update(['host_id' => $host->id]);
        $token = $host->createToken('test-token')->plainTextToken;

        $response = $this->postJson('/api/sessions/TEST02/start', [], [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'voting',
                'current_round' => 1,
            ]);

        $this->assertDatabaseHas('poker_sessions', [
            'code' => 'TEST02',
            'status' => 'voting',
        ]);
    }

    public function test_cannot_start_voting_if_not_in_waiting_state(): void
    {
        $session = PokerSession::create([
            'code' => 'TEST03',
            'current_round' => 1,
            'status' => 'voting',
        ]);

        $host = Participant::create([
            'session_id' => $session->id,
            'name' => 'Host',
            'emoji' => '👤',
        ]);

        $session->update(['host_id' => $host->id]);
        $token = $host->createToken('test-token')->plainTextToken;

        $response = $this->postJson('/api/sessions/TEST03/start', [], [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(400)
            ->assertJson([
                'error' => 'Voting can only be started when session is in waiting state',
            ]);
    }

    public function test_can_reveal_cards(): void
    {
        $session = PokerSession::create([
            'code' => 'TEST04',
            'current_round' => 1,
            'status' => 'voting',
        ]);

        $host = Participant::create([
            'session_id' => $session->id,
            'name' => 'Alice',
            'emoji' => '👩‍💻',
        ]);

        $session->update(['host_id' => $host->id]);
        $token = $host->createToken('test-token')->plainTextToken;

        $response = $this->postJson('/api/sessions/TEST04/reveal', [], [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'revealed',
            ])
            ->assertJsonStructure(['status', 'votes']);

        $this->assertDatabaseHas('poker_sessions', [
            'code' => 'TEST04',
            'status' => 'revealed',
        ]);
    }

    public function test_cannot_reveal_if_not_in_voting_state(): void
    {
        $session = PokerSession::create([
            'code' => 'TEST05',
            'current_round' => 1,
            'status' => 'waiting',
        ]);

        $host = Participant::create([
            'session_id' => $session->id,
            'name' => 'Host',
            'emoji' => '👤',
        ]);

        $session->update(['host_id' => $host->id]);
        $token = $host->createToken('test-token')->plainTextToken;

        $response = $this->postJson('/api/sessions/TEST05/reveal', [], [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(400)
            ->assertJson([
                'error' => 'Cards can only be revealed when session is in voting state',
            ]);
    }

    public function test_can_start_next_round(): void
    {
        $session = PokerSession::create([
            'code' => 'TEST06',
            'current_round' => 1,
            'status' => 'revealed',
        ]);

        $host = Participant::create([
            'session_id' => $session->id,
            'name' => 'Host',
            'emoji' => '👤',
        ]);

        $session->update(['host_id' => $host->id]);
        $token = $host->createToken('test-token')->plainTextToken;

        $response = $this->postJson('/api/sessions/TEST06/next-round', [], [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'voting',
                'current_round' => 2,
            ]);

        $this->assertDatabaseHas('poker_sessions', [
            'code' => 'TEST06',
            'status' => 'voting',
            'current_round' => 2,
        ]);
    }

    public function test_cannot_start_next_round_if_not_revealed(): void
    {
        $session = PokerSession::create([
            'code' => 'TEST07',
            'current_round' => 1,
            'status' => 'voting',
        ]);

        $host = Participant::create([
            'session_id' => $session->id,
            'name' => 'Host',
            'emoji' => '👤',
        ]);

        $session->update(['host_id' => $host->id]);
        $token = $host->createToken('test-token')->plainTextToken;

        $response = $this->postJson('/api/sessions/TEST07/next-round', [], [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(400)
            ->assertJson([
                'error' => 'Next round can only be started after cards are revealed',
            ]);
    }

    public function test_can_get_session_details(): void
    {
        $session = PokerSession::create([
            'code' => 'TEST08',
            'current_round' => 1,
            'status' => 'waiting',
        ]);

        $participant = Participant::create([
            'session_id' => $session->id,
            'name' => 'Alice',
            'emoji' => '👩‍💻',
        ]);

        $session->update(['host_id' => $participant->id]);
        $token = $participant->createToken('test-token')->plainTextToken;

        $response = $this->getJson('/api/sessions/TEST08', [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'code',
                'status',
                'current_round',
                'participants',
                'votes',
            ])
            ->assertJson([
                'code' => 'TEST08',
                'status' => 'waiting',
                'current_round' => 1,
            ]);
    }

    public function test_session_only_shows_votes_when_revealed(): void
    {
        $session = PokerSession::create([
            'code' => 'TEST09',
            'current_round' => 1,
            'status' => 'voting',
        ]);

        $participant = Participant::create([
            'session_id' => $session->id,
            'name' => 'Participant',
            'emoji' => '👤',
        ]);

        $token = $participant->createToken('test-token')->plainTextToken;

        $response = $this->getJson('/api/sessions/TEST09', [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(200)
            ->assertJson(['votes' => []]);
    }

    public function test_participant_can_leave_session(): void
    {
        $session = PokerSession::create([
            'code' => 'LEAV01',
            'current_round' => 1,
            'status' => 'voting',
        ]);

        $host = Participant::create([
            'session_id' => $session->id,
            'name' => 'Host',
            'emoji' => '👤',
        ]);

        $session->update(['host_id' => $host->id]);

        $participant = Participant::create([
            'session_id' => $session->id,
            'name' => 'Bob',
            'emoji' => '👨‍💼',
        ]);

        $token = $participant->createToken('test-token')->plainTextToken;

        $response = $this->deleteJson("/api/sessions/LEAV01/leave", [], [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'Participant left session',
                'session_ended' => false,
            ])
            ->assertJsonStructure(['remaining_participants']);

        // Participant should be deleted
        $this->assertDatabaseMissing('participants', [
            'id' => $participant->id,
        ]);

        // Session should still exist
        $this->assertDatabaseHas('poker_sessions', [
            'code' => 'LEAV01',
        ]);

        // Host should still exist
        $this->assertDatabaseHas('participants', [
            'id' => $host->id,
        ]);
    }

    public function test_host_leaving_ends_session(): void
    {
        $session = PokerSession::create([
            'code' => 'LEAV02',
            'current_round' => 1,
            'status' => 'voting',
        ]);

        $host = Participant::create([
            'session_id' => $session->id,
            'name' => 'Host',
            'emoji' => '👤',
        ]);

        $session->update(['host_id' => $host->id]);

        $participant = Participant::create([
            'session_id' => $session->id,
            'name' => 'Bob',
            'emoji' => '👨‍💼',
        ]);

        $token = $host->createToken('test-token')->plainTextToken;

        $response = $this->deleteJson("/api/sessions/LEAV02/leave", [], [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'Session ended - host left',
                'session_ended' => true,
            ]);

        // Session should be deleted
        $this->assertDatabaseMissing('poker_sessions', [
            'code' => 'LEAV02',
        ]);

        // All participants should be deleted (cascade)
        $this->assertDatabaseMissing('participants', [
            'id' => $host->id,
        ]);
        $this->assertDatabaseMissing('participants', [
            'id' => $participant->id,
        ]);
    }

    public function test_leaving_keeps_votes_in_database(): void
    {
        $session = PokerSession::create([
            'code' => 'LEAV03',
            'current_round' => 1,
            'status' => 'voting',
        ]);

        $host = Participant::create([
            'session_id' => $session->id,
            'name' => 'Host',
            'emoji' => '👤',
        ]);

        $session->update(['host_id' => $host->id]);

        $participant = Participant::create([
            'session_id' => $session->id,
            'name' => 'Bob',
            'emoji' => '👨‍💼',
        ]);

        // Create a vote for the participant
        $vote = Vote::create([
            'session_id' => $session->id,
            'participant_id' => $participant->id,
            'round' => 1,
            'card_value' => '5',
            'voted_at' => now(),
        ]);

        $token = $participant->createToken('test-token')->plainTextToken;

        // Participant leaves
        $response = $this->deleteJson("/api/sessions/LEAV03/leave", [], [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(200);

        // Participant should be deleted
        $this->assertDatabaseMissing('participants', [
            'id' => $participant->id,
        ]);

        // Vote should still exist (for history/analytics)
        // participant_id will be NULL since the participant was deleted
        $this->assertDatabaseHas('votes', [
            'id' => $vote->id,
            'participant_id' => null,
            'card_value' => '5',
        ]);
    }

    public function test_cannot_leave_non_existent_session(): void
    {
        $session = PokerSession::create([
            'code' => 'EXISTS',
            'current_round' => 1,
            'status' => 'voting',
        ]);

        $participant = Participant::create([
            'session_id' => $session->id,
            'name' => 'Test',
            'emoji' => '👤',
        ]);

        $token = $participant->createToken('test-token')->plainTextToken;

        $response = $this->deleteJson('/api/sessions/NOTFND/leave', [], [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(404);
    }

    public function test_cannot_leave_as_non_existent_participant(): void
    {
        $session = PokerSession::create([
            'code' => 'LEAV04',
            'current_round' => 1,
            'status' => 'voting',
        ]);

        $response = $this->deleteJson('/api/sessions/LEAV04/leave');

        $response->assertStatus(401);
    }

    public function test_cannot_leave_participant_from_different_session(): void
    {
        $session1 = PokerSession::create([
            'code' => 'LEAV05',
            'current_round' => 1,
            'status' => 'voting',
        ]);

        $session2 = PokerSession::create([
            'code' => 'LEAV06',
            'current_round' => 1,
            'status' => 'voting',
        ]);

        $participant = Participant::create([
            'session_id' => $session2->id,
            'name' => 'Bob',
            'emoji' => '👨‍💼',
        ]);

        $token = $participant->createToken('test-token')->plainTextToken;

        // Try to leave session1 (but they belong to session2)
        $response = $this->deleteJson("/api/sessions/LEAV05/leave", [], [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(403)
            ->assertJson([
                'error' => 'Forbidden',
                'message' => 'You are not a member of this session',
            ]);

        // Participant should still exist
        $this->assertDatabaseHas('participants', [
            'id' => $participant->id,
        ]);
    }
}
