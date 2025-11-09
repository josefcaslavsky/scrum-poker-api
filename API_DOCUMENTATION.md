# Scrum Poker API Documentation

Base URL: `http://localhost:8000/api`

All requests should include:
```
Content-Type: application/json
Accept: application/json
```

---

## Session Endpoints

### 1. Create Session

**POST** `/sessions`

Creates a new poker session and the host participant.

**Request Body:**
```json
{
  "host_name": "Alice",
  "host_emoji": "👩‍💻"  // optional, defaults to 👤
}
```

**Response:** `201 Created`
```json
{
  "code": "ABC123",
  "session_id": 1,
  "participant_id": 1,
  "status": "waiting",
  "current_round": 1
}
```

**Use Case:** Called when user clicks "Create New Session" button.

---

### 2. Get Session Details

**GET** `/sessions/{code}`

Retrieves current session state, participants, and votes (if revealed).

**URL Parameters:**
- `code` - 6-character session code (e.g., "ABC123")

**Response:** `200 OK`
```json
{
  "code": "ABC123",
  "status": "voting",
  "current_round": 2,
  "participants": [
    {
      "id": 1,
      "session_id": 1,
      "name": "Alice",
      "emoji": "👩‍💻",
      "created_at": "2025-11-08T14:23:30.000000Z",
      "updated_at": "2025-11-08T14:23:30.000000Z"
    },
    {
      "id": 2,
      "session_id": 1,
      "name": "Bob",
      "emoji": "👨‍💼",
      "created_at": "2025-11-08T14:23:42.000000Z",
      "updated_at": "2025-11-08T14:23:42.000000Z"
    }
  ],
  "votes": []  // Empty array unless status is "revealed"
}
```

**When Status is "revealed":**
```json
{
  "code": "ABC123",
  "status": "revealed",
  "current_round": 1,
  "participants": [...],
  "votes": [
    {
      "id": 1,
      "session_id": 1,
      "participant_id": 1,
      "round": 1,
      "card_value": "5",
      "voted_at": "2025-11-08T14:23:56.000000Z",
      "participant": {
        "id": 1,
        "name": "Alice",
        "emoji": "👩‍💻"
      }
    },
    {
      "id": 2,
      "session_id": 1,
      "participant_id": 2,
      "round": 1,
      "card_value": "8",
      "voted_at": "2025-11-08T14:24:00.000000Z",
      "participant": {
        "id": 2,
        "name": "Bob",
        "emoji": "👨‍💼"
      }
    }
  ]
}
```

**Use Case:**
- Load session state on join
- Check who the host is via `session.host_id`
- Refresh session state periodically

**Notes:**
- `votes` array is **empty** when status is "waiting" or "voting"
- `votes` array is **populated** only when status is "revealed"
- To check if participant is host: `participant.id === session.host_id`

---

### 3. Join Session

**POST** `/sessions/{code}/join`

Adds a new participant to an existing session.

**URL Parameters:**
- `code` - 6-character session code

**Request Body:**
```json
{
  "name": "Bob",
  "emoji": "👨‍💼"  // optional, defaults to 👤
}
```

**Response:** `200 OK`
```json
{
  "session_id": 1,
  "participant_id": 2,
  "status": "waiting",
  "current_round": 1,
  "participants": [
    {
      "id": 1,
      "session_id": 1,
      "name": "Alice",
      "emoji": "👩‍💻",
      "created_at": "2025-11-08T14:23:30.000000Z",
      "updated_at": "2025-11-08T14:23:30.000000Z"
    },
    {
      "id": 2,
      "session_id": 1,
      "name": "Bob",
      "emoji": "👨‍💼",
      "created_at": "2025-11-08T14:23:42.000000Z",
      "updated_at": "2025-11-08T14:23:42.000000Z"
    }
  ]
}
```

**Error Response:** `404 Not Found`
```json
{
  "message": "No query results for model [App\\Models\\PokerSession]."
}
```

**WebSocket Event Triggered:**
- `ParticipantJoined` - Broadcast to all other participants

**Use Case:** Called when user enters session code and clicks "Join Session".

**Important:** Store the returned `participant_id` - you'll need it for voting!

---

### 4. Start Voting

**POST** `/sessions/{code}/start`

Transitions session from "waiting" to "voting" state.

**URL Parameters:**
- `code` - 6-character session code

**Request Body:** None

**Response:** `200 OK`
```json
{
  "status": "voting",
  "current_round": 1
}
```

**Error Response:** `400 Bad Request`
```json
{
  "error": "Voting can only be started when session is in waiting state"
}
```

**WebSocket Event Triggered:**
- `VotingStarted` - Broadcast to all participants

**Use Case:**
- Host clicks "Start Voting" button
- Only allowed when status is "waiting"
- Typically used at the beginning of the session or after creating session

---

### 5. Reveal Cards

**POST** `/sessions/{code}/reveal`

Transitions session from "voting" to "revealed" state and returns all votes.

**URL Parameters:**
- `code` - 6-character session code

**Request Body:** None

**Response:** `200 OK`
```json
{
  "status": "revealed",
  "votes": [
    {
      "id": 1,
      "session_id": 1,
      "participant_id": 1,
      "round": 1,
      "card_value": "5",
      "voted_at": "2025-11-08T14:23:56.000000Z",
      "created_at": "2025-11-08T14:23:56.000000Z",
      "participant": {
        "id": 1,
        "session_id": 1,
        "name": "Alice",
        "emoji": "👩‍💻",
        "created_at": "2025-11-08T14:23:30.000000Z",
        "updated_at": "2025-11-08T14:23:30.000000Z"
      }
    },
    {
      "id": 2,
      "session_id": 1,
      "participant_id": 2,
      "round": 1,
      "card_value": "8",
      "voted_at": "2025-11-08T14:24:00.000000Z",
      "created_at": "2025-11-08T14:24:00.000000Z",
      "participant": {
        "id": 2,
        "session_id": 1,
        "name": "Bob",
        "emoji": "👨‍💼",
        "created_at": "2025-11-08T14:23:42.000000Z",
        "updated_at": "2025-11-08T14:23:42.000000Z"
      }
    }
  ]
}
```

**Error Response:** `400 Bad Request`
```json
{
  "error": "Cards can only be revealed when session is in voting state"
}
```

**WebSocket Event Triggered:**
- `CardsRevealed` - Broadcast to all participants with vote data

**Use Case:**
- Host clicks "Reveal Cards" button
- Only allowed when status is "voting"
- Shows all votes to everyone

---

### 6. Next Round

**POST** `/sessions/{code}/next-round`

Starts a new voting round. Increments round number and transitions to "voting" state.

**URL Parameters:**
- `code` - 6-character session code

**Request Body:** None

**Response:** `200 OK`
```json
{
  "status": "voting",
  "current_round": 2
}
```

**Error Response:** `400 Bad Request`
```json
{
  "error": "Next round can only be started after cards are revealed"
}
```

**WebSocket Event Triggered:**
- `NextRoundStarted` - Broadcast to all participants

**Use Case:**
- Host clicks "Next Round" button after discussing revealed votes
- Only allowed when status is "revealed"
- Clears UI for new voting
- Old votes remain in database for history but are not shown

**Important:** Participants need to vote again for the new round!

---

### 7. Leave Session

**DELETE** `/sessions/{code}/participants/{participantId}`

Removes a participant from the session. If the host leaves, the entire session is deleted.

**URL Parameters:**
- `code` - 6-character session code
- `participantId` - ID of the participant leaving

**Request Body:** None

**Response (Regular Participant):** `200 OK`
```json
{
  "message": "Participant left session",
  "session_ended": false,
  "remaining_participants": [
    {
      "id": 1,
      "session_id": 1,
      "name": "Alice",
      "emoji": "👩‍💻",
      "created_at": "2025-11-08T14:23:30.000000Z",
      "updated_at": "2025-11-08T14:23:30.000000Z"
    }
  ]
}
```

**Response (Host Leaves):** `200 OK`
```json
{
  "message": "Session ended - host left",
  "session_ended": true
}
```

**Error Response:** `404 Not Found`
```json
{
  "message": "No query results for model [App\\Models\\Participant]."
}
```

**WebSocket Event Triggered:**
- `ParticipantLeft` - When regular participant leaves
- `SessionEnded` - When host leaves (ends session for everyone)

**Use Case:**
- User clicks "Leave Session" button
- Auto-cleanup when user closes window (call on disconnect)
- **Important:** Participant's votes are preserved for history/analytics (participant_id becomes NULL)

**Behavior:**
- **Host leaves:** Entire session is deleted, all participants disconnected
- **Regular participant leaves:** Only that participant is removed, votes remain in database with NULL participant_id

---

## Voting Endpoint

### Submit Vote

**POST** `/sessions/{code}/vote`

Submits or updates a vote for the current round.

**URL Parameters:**
- `code` - 6-character session code

**Request Body:**
```json
{
  "participant_id": 1,
  "card_value": "5"
}
```

**Valid Card Values:**
- Fibonacci: `"0"`, `"1/2"`, `"1"`, `"2"`, `"3"`, `"5"`, `"8"`, `"13"`, `"21"`
- Special: `"?"`, `"☕"`

**Response:** `200 OK`
```json
{
  "vote_id": 1,
  "card_value": "5",
  "voted_at": "2025-11-08T14:23:56.000000Z"
}
```

**Error Response:** `400 Bad Request`
```json
{
  "error": "Voting is only allowed when session is in voting state"
}
```

**Validation Errors:** `422 Unprocessable Entity`
```json
{
  "message": "The participant id field is required.",
  "errors": {
    "participant_id": [
      "The participant id field is required."
    ]
  }
}
```

**WebSocket Event Triggered:**
- `VoteSubmitted` - Broadcast to **other** participants (not the voter)

**Use Case:**
- Participant selects a card
- Can change vote multiple times before reveal
- Same endpoint for both submitting and updating votes

**Important:**
- Only works when `status === "voting"`
- Use the `participant_id` you received from create/join
- Voting after reveal is blocked

---

## State Machine

The session follows this state flow:

```
waiting → voting → revealed → voting (next round) → revealed → ...
   ↓         ↓         ↓
[start]  [reveal] [next-round]
```

### State: `waiting`
- **Actions Allowed:** `start`
- **Actions Blocked:** `reveal`, `next-round`, `vote`
- **When:** Initial state after session creation

### State: `voting`
- **Actions Allowed:** `reveal`, `vote`
- **Actions Blocked:** `start`, `next-round`
- **When:** After start or next-round

### State: `revealed`
- **Actions Allowed:** `next-round`
- **Actions Blocked:** `start`, `reveal`, `vote`
- **When:** After reveal, votes are visible

---

## WebSocket Events

Connect to Reverb and subscribe to: `session.{code}`

```javascript
echo.channel(`session.${sessionCode}`)
  .listen('ParticipantJoined', (e) => {
    // e.participant: { id, name, emoji }
  })
  .listen('VotingStarted', (e) => {
    // e.round: 1
    // e.status: "voting"
  })
  .listen('VoteSubmitted', (e) => {
    // e.participant_id: 2
    // Note: You don't see the card value!
  })
  .listen('CardsRevealed', (e) => {
    // e.votes: [{ participant, card_value, ... }]
    // e.status: "revealed"
  })
  .listen('NextRoundStarted', (e) => {
    // e.round: 2
    // e.status: "voting"
  })
  .listen('ParticipantLeft', (e) => {
    // e.participant_id: 3
    // e.participant_name: "Charlie"
    // e.remaining_participants: [...]
  })
  .listen('SessionEnded', (e) => {
    // e.reason: "Host left the session"
    // e.status: "ended"
  });
```

### Event Details

#### ParticipantJoined
```json
{
  "participant": {
    "id": 2,
    "name": "Bob",
    "emoji": "👨‍💼"
  }
}
```
**Trigger:** Someone joins the session
**Action:** Add participant to UI list

---

#### VotingStarted
```json
{
  "round": 1,
  "status": "voting"
}
```
**Trigger:** Host starts voting
**Action:** Show voting cards, enable voting UI

---

#### VoteSubmitted
```json
{
  "participant_id": 2
}
```
**Trigger:** Someone votes or changes their vote
**Action:** Show "voted" indicator next to participant (but don't show card value!)
**Note:** Broadcast uses `toOthers()` - voter doesn't receive their own event

---

#### CardsRevealed
```json
{
  "votes": [
    {
      "id": 1,
      "session_id": 1,
      "participant_id": 1,
      "round": 1,
      "card_value": "5",
      "voted_at": "2025-11-08T14:23:56.000000Z",
      "participant": {
        "id": 1,
        "name": "Alice",
        "emoji": "👩‍💻"
      }
    }
  ],
  "status": "revealed"
}
```
**Trigger:** Host reveals cards
**Action:** Show all vote values, calculate statistics, disable voting

---

#### NextRoundStarted
```json
{
  "round": 2,
  "status": "voting"
}
```
**Trigger:** Host starts next round
**Action:** Clear votes, reset UI, enable voting again

---

#### ParticipantLeft
```json
{
  "participant_id": 3,
  "participant_name": "Charlie",
  "remaining_participants": [
    {
      "id": 1,
      "name": "Alice",
      "emoji": "👩‍💻"
    },
    {
      "id": 2,
      "name": "Bob",
      "emoji": "👨‍💼"
    }
  ]
}
```
**Trigger:** A regular participant leaves the session
**Action:** Remove participant from UI list, update participant count
**Note:** This event is NOT broadcast when the host leaves (SessionEnded is used instead)

---

#### SessionEnded
```json
{
  "reason": "Host left the session",
  "status": "ended"
}
```
**Trigger:** Host leaves the session
**Action:** Show "Session ended" message, disconnect all participants, return to home screen
**Important:** When this event is received, the session no longer exists in the database

---

## UI Flow Example

### 1. Creating a Session (Host)
```javascript
// 1. Create session
const response = await fetch('http://localhost:8000/api/sessions', {
  method: 'POST',
  headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify({ host_name: 'Alice', host_emoji: '👩‍💻' })
});
const { code, participant_id } = await response.json();

// 2. Subscribe to WebSocket
echo.channel(`session.${code}`)
  .listen('ParticipantJoined', updateParticipantsList)
  .listen('VotingStarted', enableVoting)
  .listen('VoteSubmitted', markParticipantVoted)
  .listen('CardsRevealed', showAllVotes)
  .listen('NextRoundStarted', resetForNextRound);

// 3. Show session code to share
displaySessionCode(code);

// 4. When ready, start voting
await fetch(`http://localhost:8000/api/sessions/${code}/start`, {
  method: 'POST'
});
```

### 2. Joining a Session (Participant)
```javascript
// 1. Join session
const response = await fetch(`http://localhost:8000/api/sessions/${code}/join`, {
  method: 'POST',
  headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify({ name: 'Bob', emoji: '👨‍💼' })
});
const { participant_id, participants } = await response.json();

// 2. Subscribe to WebSocket
echo.channel(`session.${code}`)
  .listen('VotingStarted', enableVoting)
  .listen('VoteSubmitted', markParticipantVoted)
  .listen('CardsRevealed', showAllVotes)
  .listen('NextRoundStarted', resetForNextRound);

// 3. Wait for host to start voting
```

### 3. Voting
```javascript
// When participant clicks a card
async function submitVote(cardValue) {
  await fetch(`http://localhost:8000/api/sessions/${code}/vote`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      participant_id: participantId,
      card_value: cardValue
    })
  });

  // Update local UI immediately
  showMyVote(cardValue);
}
```

### 4. Host Actions
```javascript
// Reveal cards
async function revealCards() {
  const response = await fetch(`http://localhost:8000/api/sessions/${code}/reveal`, {
    method: 'POST'
  });
  const { votes } = await response.json();

  // Display votes (also received via WebSocket)
  displayResults(votes);
}

// Next round
async function nextRound() {
  await fetch(`http://localhost:8000/api/sessions/${code}/next-round`, {
    method: 'POST'
  });

  // UI cleared via WebSocket event
}
```

---

## Error Handling

### Common HTTP Status Codes

- **200 OK** - Request successful
- **201 Created** - Session created successfully
- **400 Bad Request** - Invalid state transition
- **404 Not Found** - Session not found
- **422 Unprocessable Entity** - Validation errors

### Example Error Handling
```javascript
try {
  const response = await fetch(`http://localhost:8000/api/sessions/${code}/vote`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ participant_id: id, card_value: '5' })
  });

  if (!response.ok) {
    const error = await response.json();
    if (response.status === 400) {
      showError(error.error); // "Voting is only allowed when session is in voting state"
    } else if (response.status === 422) {
      showValidationErrors(error.errors);
    }
  }
} catch (err) {
  showError('Network error');
}
```

---

## Tips for Frontend

1. **Store participant_id locally** - You need it for every vote
2. **Store host_id from session** - To show/hide host-only buttons
3. **Listen to WebSocket events** - Don't rely only on REST API polling
4. **Handle status changes** - Disable/enable UI based on session status
5. **Show vote count** - Track `VoteSubmitted` events to show "3/5 voted"
6. **Handle disconnects** - Reconnect to WebSocket if connection drops
7. **Optimistic updates** - Update local UI immediately, rely on WebSocket for others
8. **Session state sync** - Call `GET /sessions/{code}` on reconnect to resync state

---

## Full Example: Complete Voting Flow

```javascript
class ScrumPokerSession {
  constructor(apiUrl, wsConfig) {
    this.apiUrl = apiUrl;
    this.echo = new Echo(wsConfig);
    this.sessionCode = null;
    this.participantId = null;
    this.isHost = false;
  }

  async createSession(name, emoji) {
    const response = await fetch(`${this.apiUrl}/sessions`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ host_name: name, host_emoji: emoji })
    });

    const data = await response.json();
    this.sessionCode = data.code;
    this.participantId = data.participant_id;
    this.isHost = true;

    this.subscribeToEvents();
    return data;
  }

  async joinSession(code, name, emoji) {
    const response = await fetch(`${this.apiUrl}/sessions/${code}/join`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ name, emoji })
    });

    const data = await response.json();
    this.sessionCode = code;
    this.participantId = data.participant_id;

    // Check if we're the host
    const session = await this.getSessionDetails();
    this.isHost = session.host_id === this.participantId;

    this.subscribeToEvents();
    return data;
  }

  async getSessionDetails() {
    const response = await fetch(`${this.apiUrl}/sessions/${this.sessionCode}`);
    return response.json();
  }

  subscribeToEvents() {
    this.echo.channel(`session.${this.sessionCode}`)
      .listen('ParticipantJoined', (e) => {
        console.log('New participant:', e.participant);
        // Update UI
      })
      .listen('VotingStarted', (e) => {
        console.log('Voting started for round:', e.round);
        // Enable voting UI
      })
      .listen('VoteSubmitted', (e) => {
        console.log('Participant voted:', e.participant_id);
        // Show voted indicator
      })
      .listen('CardsRevealed', (e) => {
        console.log('Cards revealed:', e.votes);
        // Display all votes
      })
      .listen('NextRoundStarted', (e) => {
        console.log('Next round:', e.round);
        // Clear UI, reset for new round
      })
      .listen('ParticipantLeft', (e) => {
        console.log('Participant left:', e.participant_name);
        // Remove participant from UI
      })
      .listen('SessionEnded', (e) => {
        console.log('Session ended:', e.reason);
        // Show "Session ended" message, return to home
        this.disconnect();
      });
  }

  async startVoting() {
    if (!this.isHost) throw new Error('Only host can start voting');

    const response = await fetch(`${this.apiUrl}/sessions/${this.sessionCode}/start`, {
      method: 'POST'
    });
    return response.json();
  }

  async vote(cardValue) {
    const response = await fetch(`${this.apiUrl}/sessions/${this.sessionCode}/vote`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        participant_id: this.participantId,
        card_value: cardValue
      })
    });
    return response.json();
  }

  async revealCards() {
    if (!this.isHost) throw new Error('Only host can reveal cards');

    const response = await fetch(`${this.apiUrl}/sessions/${this.sessionCode}/reveal`, {
      method: 'POST'
    });
    return response.json();
  }

  async nextRound() {
    if (!this.isHost) throw new Error('Only host can start next round');

    const response = await fetch(`${this.apiUrl}/sessions/${this.sessionCode}/next-round`, {
      method: 'POST'
    });
    return response.json();
  }

  async leaveSession() {
    const response = await fetch(
      `${this.apiUrl}/sessions/${this.sessionCode}/participants/${this.participantId}`,
      { method: 'DELETE' }
    );

    const data = await response.json();

    // Disconnect from WebSocket
    this.disconnect();

    return data;
  }

  disconnect() {
    if (this.sessionCode) {
      this.echo.leave(`session.${this.sessionCode}`);
    }
  }
}

// Usage
const session = new ScrumPokerSession('http://localhost:8000/api', {
  broadcaster: 'reverb',
  key: '3xmj8ojoufvlr6xuiaka',
  wsHost: 'localhost',
  wsPort: 8081,
  forceTLS: false,
  enabledTransports: ['ws', 'wss'],
  disableStats: true,
});

// Create or join
await session.createSession('Alice', '👩‍💻');
// OR
await session.joinSession('ABC123', 'Bob', '👨‍💼');

// Start voting (host only)
await session.startVoting();

// Vote
await session.vote('5');

// Reveal (host only)
await session.revealCards();

// Next round (host only)
await session.nextRound();
```
