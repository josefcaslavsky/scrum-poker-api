<?php

namespace Tests\Feature;

use App\Models\Participant;
use App\Models\PokerSession;
use App\Models\Vote;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VoteTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_submit_vote(): void
    {
        $session = PokerSession::create([
            'code' => 'VOTE01',
            'current_round' => 1,
            'status' => 'voting',
        ]);

        $participant = Participant::create([
            'session_id' => $session->id,
            'name' => 'Alice',
            'emoji' => '👩‍💻',
        ]);

        $response = $this->postJson("/api/sessions/VOTE01/vote", [
            'participant_id' => $participant->id,
            'card_value' => '5',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'vote_id',
                'card_value',
                'voted_at',
            ])
            ->assertJson([
                'card_value' => '5',
            ]);

        $this->assertDatabaseHas('votes', [
            'session_id' => $session->id,
            'participant_id' => $participant->id,
            'round' => 1,
            'card_value' => '5',
        ]);
    }

    public function test_cannot_vote_when_not_in_voting_state(): void
    {
        $session = PokerSession::create([
            'code' => 'VOTE02',
            'current_round' => 1,
            'status' => 'waiting',
        ]);

        $participant = Participant::create([
            'session_id' => $session->id,
            'name' => 'Alice',
            'emoji' => '👩‍💻',
        ]);

        $response = $this->postJson("/api/sessions/VOTE02/vote", [
            'participant_id' => $participant->id,
            'card_value' => '5',
        ]);

        $response->assertStatus(400)
            ->assertJson([
                'error' => 'Voting is only allowed when session is in voting state',
            ]);
    }

    public function test_cannot_vote_when_revealed(): void
    {
        $session = PokerSession::create([
            'code' => 'VOTE03',
            'current_round' => 1,
            'status' => 'revealed',
        ]);

        $participant = Participant::create([
            'session_id' => $session->id,
            'name' => 'Alice',
            'emoji' => '👩‍💻',
        ]);

        $response = $this->postJson("/api/sessions/VOTE03/vote", [
            'participant_id' => $participant->id,
            'card_value' => '5',
        ]);

        $response->assertStatus(400)
            ->assertJson([
                'error' => 'Voting is only allowed when session is in voting state',
            ]);
    }

    public function test_can_update_vote_for_same_round(): void
    {
        $session = PokerSession::create([
            'code' => 'VOTE04',
            'current_round' => 1,
            'status' => 'voting',
        ]);

        $participant = Participant::create([
            'session_id' => $session->id,
            'name' => 'Alice',
            'emoji' => '👩‍💻',
        ]);

        // First vote
        $this->postJson("/api/sessions/VOTE04/vote", [
            'participant_id' => $participant->id,
            'card_value' => '5',
        ]);

        // Update vote
        $response = $this->postJson("/api/sessions/VOTE04/vote", [
            'participant_id' => $participant->id,
            'card_value' => '8',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'card_value' => '8',
            ]);

        // Should only have one vote in database
        $this->assertEquals(1, Vote::where([
            'session_id' => $session->id,
            'participant_id' => $participant->id,
            'round' => 1,
        ])->count());

        // Verify it's updated to 8
        $this->assertDatabaseHas('votes', [
            'session_id' => $session->id,
            'participant_id' => $participant->id,
            'round' => 1,
            'card_value' => '8',
        ]);
    }

    public function test_multiple_participants_can_vote_in_same_round(): void
    {
        $session = PokerSession::create([
            'code' => 'VOTE05',
            'current_round' => 1,
            'status' => 'voting',
        ]);

        $alice = Participant::create([
            'session_id' => $session->id,
            'name' => 'Alice',
            'emoji' => '👩‍💻',
        ]);

        $bob = Participant::create([
            'session_id' => $session->id,
            'name' => 'Bob',
            'emoji' => '👨‍💼',
        ]);

        // Alice votes 5
        $this->postJson("/api/sessions/VOTE05/vote", [
            'participant_id' => $alice->id,
            'card_value' => '5',
        ])->assertStatus(200);

        // Bob votes 8
        $this->postJson("/api/sessions/VOTE05/vote", [
            'participant_id' => $bob->id,
            'card_value' => '8',
        ])->assertStatus(200);

        // Both votes should exist
        $this->assertEquals(2, Vote::where('session_id', $session->id)->count());
    }

    public function test_votes_are_isolated_per_round(): void
    {
        $session = PokerSession::create([
            'code' => 'VOTE06',
            'current_round' => 1,
            'status' => 'voting',
        ]);

        $participant = Participant::create([
            'session_id' => $session->id,
            'name' => 'Alice',
            'emoji' => '👩‍💻',
        ]);

        // Vote in round 1
        $this->postJson("/api/sessions/VOTE06/vote", [
            'participant_id' => $participant->id,
            'card_value' => '5',
        ]);

        // Move to round 2
        $session->update(['current_round' => 2]);

        // Vote in round 2
        $this->postJson("/api/sessions/VOTE06/vote", [
            'participant_id' => $participant->id,
            'card_value' => '8',
        ]);

        // Should have 2 votes total
        $this->assertEquals(2, Vote::where([
            'session_id' => $session->id,
            'participant_id' => $participant->id,
        ])->count());

        // One for each round
        $this->assertDatabaseHas('votes', [
            'session_id' => $session->id,
            'participant_id' => $participant->id,
            'round' => 1,
            'card_value' => '5',
        ]);

        $this->assertDatabaseHas('votes', [
            'session_id' => $session->id,
            'participant_id' => $participant->id,
            'round' => 2,
            'card_value' => '8',
        ]);
    }

    public function test_vote_requires_valid_participant(): void
    {
        $session = PokerSession::create([
            'code' => 'VOTE07',
            'current_round' => 1,
            'status' => 'voting',
        ]);

        $response = $this->postJson("/api/sessions/VOTE07/vote", [
            'participant_id' => 99999,
            'card_value' => '5',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['participant_id']);
    }

    public function test_vote_requires_card_value(): void
    {
        $session = PokerSession::create([
            'code' => 'VOTE08',
            'current_round' => 1,
            'status' => 'voting',
        ]);

        $participant = Participant::create([
            'session_id' => $session->id,
            'name' => 'Alice',
            'emoji' => '👩‍💻',
        ]);

        $response = $this->postJson("/api/sessions/VOTE08/vote", [
            'participant_id' => $participant->id,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['card_value']);
    }

    public function test_can_vote_with_different_card_values(): void
    {
        $session = PokerSession::create([
            'code' => 'VOTE09',
            'current_round' => 1,
            'status' => 'voting',
        ]);

        $participant = Participant::create([
            'session_id' => $session->id,
            'name' => 'Alice',
            'emoji' => '👩‍💻',
        ]);

        $cardValues = ['1', '2', '3', '5', '8', '13', '21', '?', '☕'];

        foreach ($cardValues as $value) {
            $response = $this->postJson("/api/sessions/VOTE09/vote", [
                'participant_id' => $participant->id,
                'card_value' => $value,
            ]);

            $response->assertStatus(200);
        }
    }
}
