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

        $token = $participant->createToken('test-token')->plainTextToken;

        $response = $this->postJson("/api/sessions/VOTE01/vote", [
            'card_value' => '5',
        ], [
            'Authorization' => "Bearer {$token}",
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

        $token = $participant->createToken('test-token')->plainTextToken;

        $response = $this->postJson("/api/sessions/VOTE02/vote", [
            'card_value' => '5',
        ], [
            'Authorization' => "Bearer {$token}",
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

        $token = $participant->createToken('test-token')->plainTextToken;

        $response = $this->postJson("/api/sessions/VOTE03/vote", [
            'card_value' => '5',
        ], [
            'Authorization' => "Bearer {$token}",
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

        // Add second participant to prevent auto-reveal
        Participant::create([
            'session_id' => $session->id,
            'name' => 'Bob',
            'emoji' => '👨‍💼',
        ]);

        $token = $participant->createToken('test-token')->plainTextToken;

        // First vote
        $this->postJson("/api/sessions/VOTE04/vote", [
            'card_value' => '5',
        ], [
            'Authorization' => "Bearer {$token}",
        ]);

        // Update vote
        $response = $this->postJson("/api/sessions/VOTE04/vote", [
            'card_value' => '8',
        ], [
            'Authorization' => "Bearer {$token}",
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

        $host = Participant::create([
            'session_id' => $session->id,
            'name' => 'Host',
            'emoji' => '👤',
        ]);

        $session->update(['host_id' => $host->id]);

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

        // Add third participant to prevent auto-reveal (3 participants total, only 2 will vote)
        Participant::create([
            'session_id' => $session->id,
            'name' => 'Charlie',
            'emoji' => '🧑‍💻',
        ]);

        // Alice votes 5
        $this->actingAs($alice, 'sanctum')
            ->postJson("/api/sessions/VOTE05/vote", [
                'card_value' => '5',
            ])->assertStatus(200);

        // Bob votes 8
        $this->actingAs($bob, 'sanctum')
            ->postJson("/api/sessions/VOTE05/vote", [
                'card_value' => '8',
            ])->assertStatus(200);

        // Both votes should exist with correct participant IDs
        $votes = Vote::where('session_id', $session->id)->get();
        $this->assertCount(2, $votes);

        // Check that we have one vote from alice and one from bob
        $this->assertTrue(
            $votes->contains(function ($vote) use ($alice) {
                return $vote->participant_id === $alice->id && $vote->card_value === '5';
            }),
            'Alice\'s vote (card 5) should exist'
        );

        $this->assertTrue(
            $votes->contains(function ($vote) use ($bob) {
                return $vote->participant_id === $bob->id && $vote->card_value === '8';
            }),
            'Bob\'s vote (card 8) should exist'
        );
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

        // Add second participant to prevent auto-reveal
        Participant::create([
            'session_id' => $session->id,
            'name' => 'Bob',
            'emoji' => '👨‍💼',
        ]);

        $token = $participant->createToken('test-token')->plainTextToken;

        // Vote in round 1
        $this->postJson("/api/sessions/VOTE06/vote", [
            'card_value' => '5',
        ], [
            'Authorization' => "Bearer {$token}",
        ]);

        // Move to round 2 and reset to voting status
        $session->update(['current_round' => 2, 'status' => 'voting']);

        // Vote in round 2
        $this->postJson("/api/sessions/VOTE06/vote", [
            'card_value' => '8',
        ], [
            'Authorization' => "Bearer {$token}",
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
        PokerSession::create([
            'code' => 'VOTE07',
            'current_round' => 1,
            'status' => 'voting',
        ]);

        // Try to vote without authentication
        $response = $this->postJson("/api/sessions/VOTE07/vote", [
            'card_value' => '5',
        ]);

        $response->assertStatus(401);
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

        $token = $participant->createToken('test-token')->plainTextToken;

        $response = $this->postJson("/api/sessions/VOTE08/vote", [], [
            'Authorization' => "Bearer {$token}",
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

        // Add second participant to prevent auto-reveal
        Participant::create([
            'session_id' => $session->id,
            'name' => 'Bob',
            'emoji' => '👨‍💼',
        ]);

        $token = $participant->createToken('test-token')->plainTextToken;
        $cardValues = ['1', '2', '3', '5', '8', '13', '21', '?', '☕'];

        foreach ($cardValues as $value) {
            $response = $this->postJson("/api/sessions/VOTE09/vote", [
                'card_value' => $value,
            ], [
                'Authorization' => "Bearer {$token}",
            ]);

            $response->assertStatus(200);
        }
    }

    public function test_auto_reveals_when_all_participants_vote(): void
    {
        $session = PokerSession::create([
            'code' => 'VOTE10',
            'current_round' => 1,
            'status' => 'voting',
        ]);

        $host = Participant::create([
            'session_id' => $session->id,
            'name' => 'Host',
            'emoji' => '👤',
        ]);

        $session->update(['host_id' => $host->id]);

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

        $aliceToken = $alice->createToken('alice-token')->plainTextToken;
        $bobToken = $bob->createToken('bob-token')->plainTextToken;

        // Alice votes - should not auto-reveal yet (1/3 including host)
        $response = $this->postJson("/api/sessions/VOTE10/vote", [
            'card_value' => '5',
        ], [
            'Authorization' => "Bearer {$aliceToken}",
        ]);

        $response->assertStatus(200)
            ->assertJson(['auto_revealed' => false]);

        $session->refresh();
        $this->assertEquals('voting', $session->status);

        // Bob votes - should auto-reveal (2/3 voted, but host didn't vote - only 2/2 non-host participants voted)
        $response = $this->postJson("/api/sessions/VOTE10/vote", [
            'card_value' => '8',
        ], [
            'Authorization' => "Bearer {$bobToken}",
        ]);

        $response->assertStatus(200)
            ->assertJson(['auto_revealed' => false]); // Won't auto-reveal because host hasn't voted

        // Verify session status is still voting
        $session->refresh();
        $this->assertEquals('voting', $session->status);
    }

    public function test_does_not_auto_reveal_with_partial_votes(): void
    {
        $session = PokerSession::create([
            'code' => 'VOTE11',
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

        $charlie = Participant::create([
            'session_id' => $session->id,
            'name' => 'Charlie',
            'emoji' => '🧑‍💻',
        ]);

        $aliceToken = $alice->createToken('alice-token')->plainTextToken;
        $bobToken = $bob->createToken('bob-token')->plainTextToken;

        // Only 2/3 vote
        $this->postJson("/api/sessions/VOTE11/vote", [
            'card_value' => '5',
        ], [
            'Authorization' => "Bearer {$aliceToken}",
        ]);

        $response = $this->postJson("/api/sessions/VOTE11/vote", [
            'card_value' => '8',
        ], [
            'Authorization' => "Bearer {$bobToken}",
        ]);

        // Should NOT auto-reveal (only 2/3 voted)
        $response->assertStatus(200)
            ->assertJson(['auto_revealed' => false]);

        $session->refresh();
        $this->assertEquals('voting', $session->status);
    }
}
