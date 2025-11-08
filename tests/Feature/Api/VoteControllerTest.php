<?php

namespace Tests\Feature\Api;

use App\Models\Session;
use App\Models\Participant;
use App\Models\Vote;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VoteControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_submit_vote_successfully()
    {
        $session = Session::create([
            'code' => 'VOTE01',
            'current_round' => 1,
            'status' => 'voting',
        ]);

        $participant = $session->participants()->create([
            'name' => 'Voter',
            'emoji' => '👤',
        ]);

        $response = $this->postJson("/api/session/VOTE01/vote", [
            'participant_id' => $participant->id,
            'card_value' => '5',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'vote' => ['id', 'session_id', 'participant_id', 'round', 'card_value'],
                'all_voted',
            ])
            ->assertJson([
                'vote' => [
                    'card_value' => '5',
                    'round' => 1,
                ],
                'all_voted' => true, // Only one participant
            ]);

        $this->assertDatabaseHas('votes', [
            'session_id' => $session->id,
            'participant_id' => $participant->id,
            'round' => 1,
            'card_value' => '5',
        ]);
    }

    public function test_update_vote_in_same_round()
    {
        $session = Session::create([
            'code' => 'UPDATE',
            'current_round' => 1,
            'status' => 'voting',
        ]);

        $participant = $session->participants()->create([
            'name' => 'Voter',
            'emoji' => '👤',
        ]);

        // First vote
        Vote::create([
            'session_id' => $session->id,
            'participant_id' => $participant->id,
            'round' => 1,
            'card_value' => '3',
        ]);

        // Update vote
        $response = $this->postJson("/api/session/UPDATE/vote", [
            'participant_id' => $participant->id,
            'card_value' => '8',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'vote' => [
                    'card_value' => '8',
                ],
            ]);

        // Verify only one vote exists with updated value
        $this->assertDatabaseCount('votes', 1);
        $this->assertDatabaseHas('votes', [
            'participant_id' => $participant->id,
            'card_value' => '8',
        ]);
    }

    public function test_vote_fails_when_not_voting_status()
    {
        $session = Session::create([
            'code' => 'WAIT02',
            'current_round' => 1,
            'status' => 'waiting',
        ]);

        $participant = $session->participants()->create([
            'name' => 'Voter',
            'emoji' => '👤',
        ]);

        $response = $this->postJson("/api/session/WAIT02/vote", [
            'participant_id' => $participant->id,
            'card_value' => '5',
        ]);

        $response->assertStatus(403)
            ->assertJson(['error' => 'Voting is not currently active']);

        $this->assertDatabaseCount('votes', 0);
    }

    public function test_vote_validates_card_value()
    {
        $session = Session::create([
            'code' => 'VALID1',
            'current_round' => 1,
            'status' => 'voting',
        ]);

        $participant = $session->participants()->create([
            'name' => 'Voter',
            'emoji' => '👤',
        ]);

        $response = $this->postJson("/api/session/VALID1/vote", [
            'participant_id' => $participant->id,
            'card_value' => '99', // Invalid card value
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['card_value']);
    }

    public function test_vote_accepts_all_valid_card_values()
    {
        $session = Session::create([
            'code' => 'CARDS1',
            'current_round' => 1,
            'status' => 'voting',
        ]);

        $validCards = ['0', '1/2', '1', '2', '3', '5', '8', '13', '21', '?', '☕'];

        foreach ($validCards as $index => $cardValue) {
            $participant = $session->participants()->create([
                'name' => "Voter{$index}",
                'emoji' => '👤',
            ]);

            $response = $this->postJson("/api/session/CARDS1/vote", [
                'participant_id' => $participant->id,
                'card_value' => $cardValue,
            ]);

            $response->assertStatus(200)
                ->assertJson([
                    'vote' => [
                        'card_value' => $cardValue,
                    ],
                ]);
        }

        $this->assertDatabaseCount('votes', count($validCards));
    }

    public function test_all_voted_flag_with_multiple_participants()
    {
        $session = Session::create([
            'code' => 'MULTI1',
            'current_round' => 1,
            'status' => 'voting',
        ]);

        $participant1 = $session->participants()->create(['name' => 'Alice', 'emoji' => '👤']);
        $participant2 = $session->participants()->create(['name' => 'Bob', 'emoji' => '👤']);
        $participant3 = $session->participants()->create(['name' => 'Charlie', 'emoji' => '👤']);

        // First vote
        $response1 = $this->postJson("/api/session/MULTI1/vote", [
            'participant_id' => $participant1->id,
            'card_value' => '5',
        ]);
        $response1->assertJson(['all_voted' => false]);

        // Second vote
        $response2 = $this->postJson("/api/session/MULTI1/vote", [
            'participant_id' => $participant2->id,
            'card_value' => '8',
        ]);
        $response2->assertJson(['all_voted' => false]);

        // Third vote - all voted
        $response3 = $this->postJson("/api/session/MULTI1/vote", [
            'participant_id' => $participant3->id,
            'card_value' => '13',
        ]);
        $response3->assertJson(['all_voted' => true]);
    }

    public function test_votes_are_round_specific()
    {
        $session = Session::create([
            'code' => 'ROUND1',
            'current_round' => 1,
            'status' => 'voting',
        ]);

        $participant = $session->participants()->create([
            'name' => 'Voter',
            'emoji' => '👤',
        ]);

        // Vote in round 1
        $this->postJson("/api/session/ROUND1/vote", [
            'participant_id' => $participant->id,
            'card_value' => '5',
        ]);

        // Move to round 2
        $session->update(['current_round' => 2]);

        // Vote in round 2
        $response = $this->postJson("/api/session/ROUND1/vote", [
            'participant_id' => $participant->id,
            'card_value' => '8',
        ]);

        $response->assertStatus(200);

        // Should have 2 votes (one per round)
        $this->assertDatabaseCount('votes', 2);
        $this->assertDatabaseHas('votes', [
            'participant_id' => $participant->id,
            'round' => 1,
            'card_value' => '5',
        ]);
        $this->assertDatabaseHas('votes', [
            'participant_id' => $participant->id,
            'round' => 2,
            'card_value' => '8',
        ]);
    }

    public function test_vote_validates_participant_exists()
    {
        $session = Session::create([
            'code' => 'EXIST1',
            'current_round' => 1,
            'status' => 'voting',
        ]);

        $response = $this->postJson("/api/session/EXIST1/vote", [
            'participant_id' => 99999, // Non-existent participant
            'card_value' => '5',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['participant_id']);
    }

    public function test_vote_requires_participant_id()
    {
        $session = Session::create([
            'code' => 'REQ001',
            'current_round' => 1,
            'status' => 'voting',
        ]);

        $response = $this->postJson("/api/session/REQ001/vote", [
            'card_value' => '5',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['participant_id']);
    }

    public function test_vote_requires_card_value()
    {
        $session = Session::create([
            'code' => 'REQ002',
            'current_round' => 1,
            'status' => 'voting',
        ]);

        $participant = $session->participants()->create([
            'name' => 'Voter',
            'emoji' => '👤',
        ]);

        $response = $this->postJson("/api/session/REQ002/vote", [
            'participant_id' => $participant->id,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['card_value']);
    }
}
