<?php

namespace Tests\Feature;

use App\Models\Participant;
use App\Models\PokerSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SessionTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_create_session(): void
    {
        $response = $this->postJson('/api/sessions', [
            'host_name' => 'Alice',
            'host_emoji' => '👩‍💻',
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'code',
                'session_id',
                'participant_id',
                'status',
                'current_round',
            ])
            ->assertJson([
                'status' => 'waiting',
                'current_round' => 1,
            ]);

        $this->assertDatabaseHas('poker_sessions', [
            'code' => $response->json('code'),
            'status' => 'waiting',
            'current_round' => 1,
        ]);

        $this->assertDatabaseHas('participants', [
            'id' => $response->json('participant_id'),
            'name' => 'Alice',
            'emoji' => '👩‍💻',
        ]);

        // Verify the participant is set as host in the session
        $this->assertDatabaseHas('poker_sessions', [
            'code' => $response->json('code'),
            'host_id' => $response->json('participant_id'),
        ]);
    }

    public function test_session_code_is_unique_and_six_characters(): void
    {
        $response = $this->postJson('/api/sessions', [
            'host_name' => 'Test',
            'host_emoji' => '🧪',
        ]);

        $code = $response->json('code');
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
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'session_id',
                'participant_id',
                'status',
                'current_round',
                'participants',
            ]);

        $this->assertDatabaseHas('participants', [
            'session_id' => $session->id,
            'name' => 'Bob',
            'emoji' => '👨‍💼',
        ]);
    }

    public function test_cannot_join_non_existent_session(): void
    {
        $response = $this->postJson('/api/sessions/NOTFND/join', [
            'name' => 'Bob',
            'emoji' => '👨‍💼',
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

        $response = $this->postJson('/api/sessions/TEST02/start');

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

        $response = $this->postJson('/api/sessions/TEST03/start');

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

        $participant = Participant::create([
            'session_id' => $session->id,
            'name' => 'Alice',
            'emoji' => '👩‍💻',
        ]);

        $session->update(['host_id' => $participant->id]);

        $response = $this->postJson('/api/sessions/TEST04/reveal');

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

        $response = $this->postJson('/api/sessions/TEST05/reveal');

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

        $response = $this->postJson('/api/sessions/TEST06/next-round');

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

        $response = $this->postJson('/api/sessions/TEST07/next-round');

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

        $response = $this->getJson('/api/sessions/TEST08');

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

        $response = $this->getJson('/api/sessions/TEST09');

        $response->assertStatus(200)
            ->assertJson(['votes' => []]);
    }
}
