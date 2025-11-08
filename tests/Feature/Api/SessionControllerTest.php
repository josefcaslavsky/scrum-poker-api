<?php

namespace Tests\Feature\Api;

use App\Models\Session;
use App\Models\Participant;
use App\Models\Vote;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SessionControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_session_successfully()
    {
        $response = $this->postJson('/api/session', [
            'host_name' => 'Alice',
            'host_emoji' => '👩',
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'session' => ['id', 'code', 'host_id', 'current_round', 'status', 'participants'],
                'participant_id',
            ]);

        $this->assertDatabaseHas('sessions', [
            'status' => 'waiting',
            'current_round' => 1,
        ]);

        $this->assertDatabaseHas('participants', [
            'name' => 'Alice',
            'emoji' => '👩',
        ]);

        // Verify session code is 6 characters
        $session = Session::first();
        $this->assertEquals(6, strlen($session->code));
    }

    public function test_create_session_with_default_emoji()
    {
        $response = $this->postJson('/api/session', [
            'host_name' => 'Bob',
        ]);

        $response->assertStatus(201);

        $this->assertDatabaseHas('participants', [
            'name' => 'Bob',
            'emoji' => '👤',
        ]);
    }

    public function test_create_session_validates_host_name()
    {
        $response = $this->postJson('/api/session', [
            'host_emoji' => '👨',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['host_name']);
    }

    public function test_join_session_successfully()
    {
        $session = Session::create([
            'code' => 'ABC123',
            'current_round' => 1,
            'status' => 'waiting',
        ]);

        $host = $session->participants()->create([
            'name' => 'Host',
            'emoji' => '👤',
        ]);

        $session->update(['host_id' => $host->id]);

        $response = $this->postJson('/api/session/join', [
            'code' => 'ABC123',
            'name' => 'Participant',
            'emoji' => '🧑',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'session' => ['id', 'code', 'participants'],
                'participant_id',
            ]);

        $this->assertDatabaseHas('participants', [
            'session_id' => $session->id,
            'name' => 'Participant',
            'emoji' => '🧑',
        ]);
    }

    public function test_join_session_with_invalid_code()
    {
        $response = $this->postJson('/api/session/join', [
            'code' => 'INVALID',
            'name' => 'Bob',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['code']);
    }

    public function test_show_session_successfully()
    {
        $session = Session::create([
            'code' => 'XYZ789',
            'current_round' => 1,
            'status' => 'voting',
        ]);

        $participant = $session->participants()->create([
            'name' => 'Test User',
            'emoji' => '👤',
        ]);

        $session->update(['host_id' => $participant->id]);

        $response = $this->getJson('/api/session/XYZ789');

        $response->assertStatus(200)
            ->assertJson([
                'code' => 'XYZ789',
                'status' => 'voting',
                'current_round' => 1,
            ]);
    }

    public function test_show_session_not_found()
    {
        $response = $this->getJson('/api/session/NOTFOUND');

        $response->assertStatus(404);
    }

    public function test_start_voting_successfully()
    {
        $session = Session::create([
            'code' => 'START1',
            'current_round' => 1,
            'status' => 'waiting',
        ]);

        $response = $this->postJson('/api/session/START1/start');

        $response->assertStatus(200)
            ->assertJson(['status' => 'voting']);

        $this->assertDatabaseHas('sessions', [
            'code' => 'START1',
            'status' => 'voting',
        ]);
    }

    public function test_start_voting_fails_when_already_started()
    {
        $session = Session::create([
            'code' => 'ALRDY1',
            'current_round' => 1,
            'status' => 'voting',
        ]);

        $response = $this->postJson('/api/session/ALRDY1/start');

        $response->assertStatus(400)
            ->assertJson(['error' => 'Voting has already started']);
    }

    public function test_reveal_cards_successfully()
    {
        $session = Session::create([
            'code' => 'REVEAL',
            'current_round' => 1,
            'status' => 'voting',
        ]);

        $participant = $session->participants()->create([
            'name' => 'Voter',
            'emoji' => '👤',
        ]);

        Vote::create([
            'session_id' => $session->id,
            'participant_id' => $participant->id,
            'round' => 1,
            'card_value' => '5',
        ]);

        $response = $this->postJson('/api/session/REVEAL/reveal');

        $response->assertStatus(200)
            ->assertJson([
                'session' => ['status' => 'revealed'],
            ])
            ->assertJsonStructure(['session', 'votes']);

        $this->assertDatabaseHas('sessions', [
            'code' => 'REVEAL',
            'status' => 'revealed',
        ]);
    }

    public function test_reveal_cards_fails_when_not_voting()
    {
        $session = Session::create([
            'code' => 'WAIT01',
            'current_round' => 1,
            'status' => 'waiting',
        ]);

        $response = $this->postJson('/api/session/WAIT01/reveal');

        $response->assertStatus(400)
            ->assertJson(['error' => 'No active voting to reveal']);
    }

    public function test_next_round_successfully()
    {
        $session = Session::create([
            'code' => 'NEXT01',
            'current_round' => 1,
            'status' => 'revealed',
        ]);

        $response = $this->postJson('/api/session/NEXT01/next-round');

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'voting',
                'current_round' => 2,
            ]);

        $this->assertDatabaseHas('sessions', [
            'code' => 'NEXT01',
            'status' => 'voting',
            'current_round' => 2,
        ]);
    }

    public function test_next_round_fails_when_not_revealed()
    {
        $session = Session::create([
            'code' => 'VOTE01',
            'current_round' => 1,
            'status' => 'voting',
        ]);

        $response = $this->postJson('/api/session/VOTE01/next-round');

        $response->assertStatus(400)
            ->assertJson(['error' => 'Cards must be revealed before starting next round']);
    }

    public function test_session_state_flow()
    {
        // Create session (waiting)
        $createResponse = $this->postJson('/api/session', [
            'host_name' => 'Host',
        ]);

        $sessionCode = $createResponse->json('session.code');

        // Start voting (waiting -> voting)
        $startResponse = $this->postJson("/api/session/{$sessionCode}/start");
        $startResponse->assertJson(['status' => 'voting']);

        // Reveal cards (voting -> revealed)
        $revealResponse = $this->postJson("/api/session/{$sessionCode}/reveal");
        $revealResponse->assertJson(['session' => ['status' => 'revealed']]);

        // Next round (revealed -> voting, round++)
        $nextResponse = $this->postJson("/api/session/{$sessionCode}/next-round");
        $nextResponse->assertJson([
            'status' => 'voting',
            'current_round' => 2,
        ]);
    }
}
