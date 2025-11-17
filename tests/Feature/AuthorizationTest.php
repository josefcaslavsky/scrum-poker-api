<?php

namespace Tests\Feature;

use App\Models\Participant;
use App\Models\PokerSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private string $validApiKey = 'scrum-poker-internal-2025';

    // ==================== API Key Tests ====================

    public function test_create_session_requires_api_key(): void
    {
        $response = $this->postJson('/api/sessions', [
            'host_name' => 'Alice',
            'host_emoji' => '👩‍💻',
        ]);

        $response->assertStatus(401)
            ->assertJson([
                'error' => 'Unauthorized',
                'message' => 'Invalid or missing API key',
            ]);
    }

    public function test_create_session_rejects_invalid_api_key(): void
    {
        $response = $this->postJson('/api/sessions', [
            'host_name' => 'Alice',
            'host_emoji' => '👩‍💻',
        ], [
            'X-API-Key' => 'wrong-key',
        ]);

        $response->assertStatus(401)
            ->assertJson([
                'error' => 'Unauthorized',
                'message' => 'Invalid or missing API key',
            ]);
    }

    public function test_create_session_accepts_valid_api_key(): void
    {
        $response = $this->postJson('/api/sessions', [
            'host_name' => 'Alice',
            'host_emoji' => '👩‍💻',
        ], [
            'X-API-Key' => $this->validApiKey,
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'session' => ['code', 'id', 'status', 'current_round'],
                'participant' => ['id', 'name', 'emoji'],
                'token',
            ]);
    }

    public function test_join_session_requires_api_key(): void
    {
        $session = $this->createSessionWithHost();

        $response = $this->postJson("/api/sessions/{$session->code}/join", [
            'name' => 'Bob',
            'emoji' => '👨',
        ]);

        $response->assertStatus(401)
            ->assertJson([
                'error' => 'Unauthorized',
                'message' => 'Invalid or missing API key',
            ]);
    }

    public function test_join_session_accepts_valid_api_key(): void
    {
        $session = $this->createSessionWithHost();

        $response = $this->postJson("/api/sessions/{$session->code}/join", [
            'name' => 'Bob',
            'emoji' => '👨',
        ], [
            'X-API-Key' => $this->validApiKey,
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'participant' => ['id', 'name', 'emoji'],
                'session',
                'token',
            ]);
    }

    // ==================== Token Authentication Tests ====================

    public function test_vote_requires_authentication(): void
    {
        $session = $this->createSessionWithHost();
        $session->update(['status' => 'voting']);

        $response = $this->postJson("/api/sessions/{$session->code}/vote", [
            'card_value' => '5',
        ]);

        $response->assertStatus(401);
    }

    public function test_vote_rejects_invalid_token(): void
    {
        $session = $this->createSessionWithHost();
        $session->update(['status' => 'voting']);

        $response = $this->postJson("/api/sessions/{$session->code}/vote", [
            'card_value' => '5',
        ], [
            'Authorization' => 'Bearer invalid-token',
        ]);

        $response->assertStatus(401);
    }

    public function test_vote_accepts_valid_token(): void
    {
        $session = $this->createSessionWithHost();
        $session->update(['status' => 'voting']);

        $participant = Participant::create([
            'session_id' => $session->id,
            'name' => 'Bob',
            'emoji' => '👨',
        ]);

        $token = $participant->createToken('test-token')->plainTextToken;

        $response = $this->postJson("/api/sessions/{$session->code}/vote", [
            'card_value' => '5',
        ], [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(200);
    }

    public function test_participant_cannot_vote_in_different_session(): void
    {
        $session1 = $this->createSessionWithHost('SESS01');
        $session2 = $this->createSessionWithHost('SESS02');

        $session1->update(['status' => 'voting']);

        $participant = Participant::create([
            'session_id' => $session2->id,
            'name' => 'Bob',
            'emoji' => '👨',
        ]);

        $token = $participant->createToken('test-token')->plainTextToken;

        $response = $this->postJson("/api/sessions/{$session1->code}/vote", [
            'card_value' => '5',
        ], [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(403)
            ->assertJson([
                'error' => 'Forbidden',
                'message' => 'You are not a member of this session',
            ]);
    }

    public function test_show_session_requires_authentication(): void
    {
        $session = $this->createSessionWithHost();

        $response = $this->getJson("/api/sessions/{$session->code}");

        $response->assertStatus(401);
    }

    public function test_leave_session_requires_authentication(): void
    {
        $session = $this->createSessionWithHost();

        $response = $this->deleteJson("/api/sessions/{$session->code}/leave");

        $response->assertStatus(401);
    }

    // ==================== Host-Only Permission Tests ====================

    public function test_start_voting_requires_host_permission(): void
    {
        $session = $this->createSessionWithHost();

        // Create a non-host participant
        $participant = Participant::create([
            'session_id' => $session->id,
            'name' => 'Bob',
            'emoji' => '👨',
        ]);

        $token = $participant->createToken('test-token')->plainTextToken;

        $response = $this->postJson("/api/sessions/{$session->code}/start", [], [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(403)
            ->assertJson([
                'error' => 'Forbidden',
                'message' => 'This action requires host privileges',
            ]);
    }

    public function test_host_can_start_voting(): void
    {
        $session = $this->createSessionWithHost();
        $host = $session->host;

        $token = $host->createToken('test-token')->plainTextToken;

        $response = $this->postJson("/api/sessions/{$session->code}/start", [], [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('poker_sessions', [
            'id' => $session->id,
            'status' => 'voting',
        ]);
    }

    public function test_reveal_cards_requires_host_permission(): void
    {
        $session = $this->createSessionWithHost();
        $session->update(['status' => 'voting']);

        // Create a non-host participant
        $participant = Participant::create([
            'session_id' => $session->id,
            'name' => 'Bob',
            'emoji' => '👨',
        ]);

        $token = $participant->createToken('test-token')->plainTextToken;

        $response = $this->postJson("/api/sessions/{$session->code}/reveal", [], [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(403)
            ->assertJson([
                'error' => 'Forbidden',
                'message' => 'This action requires host privileges',
            ]);
    }

    public function test_host_can_reveal_cards(): void
    {
        $session = $this->createSessionWithHost();
        $session->update(['status' => 'voting']);
        $host = $session->host;

        $token = $host->createToken('test-token')->plainTextToken;

        $response = $this->postJson("/api/sessions/{$session->code}/reveal", [], [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('poker_sessions', [
            'id' => $session->id,
            'status' => 'revealed',
        ]);
    }

    public function test_next_round_requires_host_permission(): void
    {
        $session = $this->createSessionWithHost();
        $session->update(['status' => 'revealed']);

        // Create a non-host participant
        $participant = Participant::create([
            'session_id' => $session->id,
            'name' => 'Bob',
            'emoji' => '👨',
        ]);

        $token = $participant->createToken('test-token')->plainTextToken;

        $response = $this->postJson("/api/sessions/{$session->code}/next-round", [], [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(403)
            ->assertJson([
                'error' => 'Forbidden',
                'message' => 'This action requires host privileges',
            ]);
    }

    public function test_host_can_start_next_round(): void
    {
        $session = $this->createSessionWithHost();
        $session->update(['status' => 'revealed', 'current_round' => 1]);
        $host = $session->host;

        $token = $host->createToken('test-token')->plainTextToken;

        $response = $this->postJson("/api/sessions/{$session->code}/next-round", [], [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('poker_sessions', [
            'id' => $session->id,
            'status' => 'voting',
            'current_round' => 2,
        ]);
    }

    public function test_participant_can_leave_session(): void
    {
        $session = $this->createSessionWithHost();

        $participant = Participant::create([
            'session_id' => $session->id,
            'name' => 'Bob',
            'emoji' => '👨',
        ]);

        $token = $participant->createToken('test-token')->plainTextToken;

        $response = $this->deleteJson("/api/sessions/{$session->code}/leave", [], [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(200);

        $this->assertDatabaseMissing('participants', [
            'id' => $participant->id,
        ]);
    }

    public function test_host_leaving_ends_session(): void
    {
        $session = $this->createSessionWithHost();
        $host = $session->host;

        $token = $host->createToken('test-token')->plainTextToken;

        $response = $this->deleteJson("/api/sessions/{$session->code}/leave", [], [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'session_ended' => true,
            ]);

        $this->assertDatabaseMissing('poker_sessions', [
            'id' => $session->id,
        ]);
    }

    // ==================== Helper Methods ====================

    private function createSessionWithHost(string $code = 'TEST01'): PokerSession
    {
        $session = PokerSession::create([
            'code' => $code,
            'current_round' => 1,
            'status' => 'waiting',
        ]);

        $host = Participant::create([
            'session_id' => $session->id,
            'name' => 'Host',
            'emoji' => '👤',
        ]);

        $session->update(['host_id' => $host->id]);

        // Reload to get the host relationship
        return $session->fresh(['host']);
    }
}
