# Scrum Poker API - Laravel Backend Implementation Plan

## Project Overview

This Laravel backend provides real-time voting API for the Scrum Poker Electron app located at `/Applications/MAMP/htdocs/scrum-poker`.

**Tech Stack:**
- Laravel 12 (latest)
- Laravel Reverb (WebSocket server)
- MySQL (via MAMP)
- RESTful API + WebSocket broadcasting

---

## Session State Flow

The application uses a state machine to manage voting sessions:

```
┌─────────────────────────────────────────────────────────────┐
│  SESSION LIFECYCLE                                          │
└─────────────────────────────────────────────────────────────┘

   CREATE SESSION
        │
        ▼
   ┌──────────┐
   │ WAITING  │ ◄─── Participants can join
   └──────────┘      Host sees "Start Voting" button
        │
        │ POST /session/{code}/start
        │ Event: VotingStarted
        ▼
   ┌──────────┐
   │  VOTING  │ ◄─── Participants can submit/change votes
   └──────────┘      Cards are hidden
        │            Host sees "Reveal Cards" button
        │
        │ POST /session/{code}/reveal
        │ Event: CardsRevealed
        ▼
   ┌──────────┐
   │ REVEALED │ ◄─── All cards shown with values
   └──────────┘      Voting is locked
        │            Host sees "Next Round" button
        │
        │ POST /session/{code}/next-round
        │ Event: NextRoundStarted
        │ (current_round++)
        │
        └────────────┐
                     │
                     ▼
                ┌──────────┐
                │  VOTING  │ ◄─── New round begins
                └──────────┘      Votes cleared
                     │
                     └─── Cycle continues...
```

**Key Points:**
- Sessions start in `waiting` status
- Only host can trigger state transitions (`start`, `reveal`, `next-round`)
- Voting is only allowed when status = `voting`
- Status transitions are broadcast to all participants via WebSocket

---

## Phase 1: Environment Setup

### 1.1 Install Laravel Reverb
```bash
composer require laravel/reverb
php artisan reverb:install
```

This will:
- Install Reverb package
- Publish Reverb configuration
- Add Reverb service provider
- Update `.env` with Reverb credentials

### 1.2 Configure Database (MAMP MySQL)

Update `.env`:
```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=8889  # or 3306 depending on MAMP config
DB_DATABASE=scrum_poker
DB_USERNAME=root
DB_PASSWORD=root
```

Create database:
```bash
# Access MAMP MySQL
mysql -u root -proot -h 127.0.0.1 --port=8889

CREATE DATABASE scrum_poker;
exit;
```

### 1.3 Configure Broadcasting

Update `.env`:
```env
BROADCAST_CONNECTION=reverb
```

Update `config/broadcasting.php` - ensure reverb connection is configured (should be done by reverb:install)

---

## Phase 2: Database Schema

### 2.1 Create Migrations

```bash
php artisan make:migration create_sessions_table
php artisan make:migration create_participants_table
php artisan make:migration create_votes_table
```

### 2.2 Sessions Table Schema

File: `database/migrations/xxxx_create_sessions_table.php`

```php
Schema::create('sessions', function (Blueprint $table) {
    $table->id();
    $table->string('code', 6)->unique(); // e.g., "ABC123"
    $table->unsignedBigInteger('host_id')->nullable();
    $table->integer('current_round')->default(1);
    $table->enum('status', ['waiting', 'voting', 'revealed'])->default('waiting');
    $table->timestamps();

    $table->index('code');
    $table->index('status');
});
```

**Session Status Flow:**
- `waiting` - Session created, waiting for host to start voting
- `voting` - Voting is active, participants can submit votes
- `revealed` - Cards are revealed, voting is locked

### 2.3 Participants Table Schema

```php
Schema::create('participants', function (Blueprint $table) {
    $table->id();
    $table->foreignId('session_id')->constrained()->onDelete('cascade');
    $table->string('name', 50);
    $table->string('emoji', 10)->default('👤');
    $table->boolean('is_host')->default(false);
    $table->timestamps();

    $table->index(['session_id', 'is_host']);
});
```

### 2.4 Votes Table Schema

```php
Schema::create('votes', function (Blueprint $table) {
    $table->id();
    $table->foreignId('session_id')->constrained()->onDelete('cascade');
    $table->foreignId('participant_id')->constrained()->onDelete('cascade');
    $table->integer('round');
    $table->string('card_value', 10); // "0", "1/2", "1", "2", "3", "5", "8", "13", "21", "?", "☕"
    $table->timestamp('voted_at')->useCurrent();

    $table->unique(['session_id', 'participant_id', 'round']);
    $table->index(['session_id', 'round']);
});
```

### 2.5 Run Migrations

```bash
php artisan migrate
```

---

## Phase 3: Models & Relationships

### 3.1 Create Models

```bash
php artisan make:model Session
php artisan make:model Participant
php artisan make:model Vote
```

### 3.2 Session Model

File: `app/Models/Session.php`

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Session extends Model
{
    protected $fillable = [
        'code',
        'host_id',
        'current_round',
        'status',
    ];

    protected $casts = [
        'status' => 'string',
    ];

    public function participants(): HasMany
    {
        return $this->hasMany(Participant::class);
    }

    public function host(): BelongsTo
    {
        return $this->belongsTo(Participant::class, 'host_id');
    }

    public function votes(): HasMany
    {
        return $this->hasMany(Vote::class);
    }

    public function currentRoundVotes(): HasMany
    {
        return $this->votes()->where('round', $this->current_round);
    }

    // Generate unique 6-character session code
    public static function generateCode(): string
    {
        do {
            $code = strtoupper(substr(str_shuffle('ABCDEFGHJKLMNPQRSTUVWXYZ23456789'), 0, 6));
        } while (self::where('code', $code)->exists());

        return $code;
    }
}
```

### 3.3 Participant Model

File: `app/Models/Participant.php`

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Participant extends Model
{
    protected $fillable = [
        'session_id',
        'name',
        'emoji',
        'is_host',
    ];

    protected $casts = [
        'is_host' => 'boolean',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(Session::class);
    }

    public function votes(): HasMany
    {
        return $this->hasMany(Vote::class);
    }
}
```

### 3.4 Vote Model

File: `app/Models/Vote.php`

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Vote extends Model
{
    const UPDATED_AT = null; // Only track voted_at

    protected $fillable = [
        'session_id',
        'participant_id',
        'round',
        'card_value',
        'voted_at',
    ];

    protected $casts = [
        'voted_at' => 'datetime',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(Session::class);
    }

    public function participant(): BelongsTo
    {
        return $this->belongsTo(Participant::class);
    }

    // Valid Fibonacci card values
    public static function validCardValues(): array
    {
        return ['0', '1/2', '1', '2', '3', '5', '8', '13', '21', '?', '☕'];
    }
}
```

---

## Phase 4: API Controllers

### 4.1 Create Controllers

```bash
php artisan make:controller Api/SessionController
php artisan make:controller Api/VoteController
```

### 4.2 SessionController

File: `app/Http/Controllers/Api/SessionController.php`

```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Session;
use App\Models\Participant;
use App\Events\ParticipantJoined;
use App\Events\VotingStarted;
use App\Events\NextRoundStarted;
use App\Events\CardsRevealed;
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
            'is_host' => true,
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

        broadcast(new VotingStarted($session));

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
            'is_host' => false,
        ]);

        broadcast(new ParticipantJoined($session, $participant))->toOthers();

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

        broadcast(new CardsRevealed($session, $votes));

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

        broadcast(new NextRoundStarted($session));

        return response()->json($session);
    }
}
```

### 4.3 VoteController

File: `app/Http/Controllers/Api/VoteController.php`

```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Session;
use App\Models\Vote;
use App\Events\VoteSubmitted;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class VoteController extends Controller
{
    // POST /api/session/{code}/vote
    public function vote(Request $request, $code)
    {
        $session = Session::where('code', $code)->firstOrFail();

        // Validate voting is active
        if ($session->status !== 'voting') {
            return response()->json([
                'error' => 'Voting is not currently active'
            ], 403);
        }

        $validated = $request->validate([
            'participant_id' => 'required|exists:participants,id',
            'card_value' => ['required', Rule::in(Vote::validCardValues())],
        ]);

        $vote = Vote::updateOrCreate(
            [
                'session_id' => $session->id,
                'participant_id' => $validated['participant_id'],
                'round' => $session->current_round,
            ],
            [
                'card_value' => $validated['card_value'],
            ]
        );

        $vote->load('participant');

        broadcast(new VoteSubmitted($session, $vote))->toOthers();

        // Check if all participants have voted
        $totalParticipants = $session->participants()->count();
        $votesCount = $session->currentRoundVotes()->count();

        return response()->json([
            'vote' => $vote,
            'all_voted' => $votesCount >= $totalParticipants,
        ]);
    }
}
```

---

## Phase 5: Broadcasting Events

### 5.1 Create Events

```bash
php artisan make:event ParticipantJoined
php artisan make:event VotingStarted
php artisan make:event VoteSubmitted
php artisan make:event CardsRevealed
php artisan make:event NextRoundStarted
```

### 5.2 VotingStarted Event

File: `app/Events/VotingStarted.php`

```php
<?php

namespace App\Events;

use App\Models\Session;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class VotingStarted implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Session $session) {}

    public function broadcastOn(): Channel
    {
        return new Channel('session.' . $this->session->code);
    }

    public function broadcastWith(): array
    {
        return [
            'session' => $this->session,
        ];
    }
}
```

### 5.3 VoteSubmitted Event

File: `app/Events/VoteSubmitted.php`

```php
<?php

namespace App\Events;

use App\Models\Session;
use App\Models\Vote;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class VoteSubmitted implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Session $session,
        public Vote $vote
    ) {}

    public function broadcastOn(): Channel
    {
        return new Channel('session.' . $this->session->code);
    }

    public function broadcastWith(): array
    {
        $votesCount = $this->session->currentRoundVotes()->count();
        $totalParticipants = $this->session->participants()->count();

        return [
            'vote' => $this->vote,
            'votes_count' => $votesCount,
            'total_participants' => $totalParticipants,
        ];
    }
}
```

### 5.4 CardsRevealed Event

File: `app/Events/CardsRevealed.php`

```php
<?php

namespace App\Events;

use App\Models\Session;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CardsRevealed implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Session $session,
        public $votes
    ) {}

    public function broadcastOn(): Channel
    {
        return new Channel('session.' . $this->session->code);
    }

    public function broadcastWith(): array
    {
        return [
            'session' => $this->session,
            'votes' => $this->votes,
        ];
    }
}
```

### 5.5 NextRoundStarted Event

File: `app/Events/NextRoundStarted.php`

```php
<?php

namespace App\Events;

use App\Models\Session;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class NextRoundStarted implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Session $session) {}

    public function broadcastOn(): Channel
    {
        return new Channel('session.' . $this->session->code);
    }

    public function broadcastWith(): array
    {
        return [
            'session' => $this->session,
        ];
    }
}
```

### 5.6 ParticipantJoined Event

File: `app/Events/ParticipantJoined.php`

```php
<?php

namespace App\Events;

use App\Models\Session;
use App\Models\Participant;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ParticipantJoined implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Session $session,
        public Participant $participant
    ) {}

    public function broadcastOn(): Channel
    {
        return new Channel('session.' . $this->session->code);
    }

    public function broadcastWith(): array
    {
        return [
            'participant' => $this->participant,
        ];
    }
}
```

---

## Phase 6: Routes

### 6.1 API Routes

File: `routes/api.php`

```php
<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\SessionController;
use App\Http\Controllers\Api\VoteController;

// Session management
Route::post('/session', [SessionController::class, 'create']);
Route::post('/session/join', [SessionController::class, 'join']);
Route::get('/session/{code}', [SessionController::class, 'show']);

// Round control (host only)
Route::post('/session/{code}/start', [SessionController::class, 'start']);
Route::post('/session/{code}/reveal', [SessionController::class, 'reveal']);
Route::post('/session/{code}/next-round', [SessionController::class, 'nextRound']);

// Voting
Route::post('/session/{code}/vote', [VoteController::class, 'vote']);
```

---

## Phase 7: CORS Configuration

### 7.1 Update CORS Config

File: `config/cors.php`

```php
return [
    'paths' => ['api/*', 'broadcasting/auth'],
    'allowed_methods' => ['*'],
    'allowed_origins' => ['*'], // For development - tighten in production
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['*'],
    'exposed_headers' => [],
    'max_age' => 0,
    'supports_credentials' => false,
];
```

---

## Phase 8: Testing & Running

### 8.1 Start Laravel Server

```bash
php artisan serve --port=8000
```

### 8.2 Start Reverb WebSocket Server

```bash
php artisan reverb:start
```

Or run in background:
```bash
php artisan reverb:start --debug
```

### 8.3 Test Endpoints

```bash
# 1. Create session (status: waiting)
curl -X POST http://localhost:8000/api/session \
  -H "Content-Type: application/json" \
  -d '{"host_name":"Alice","host_emoji":"👩"}'

# 2. Join session
curl -X POST http://localhost:8000/api/session/join \
  -H "Content-Type: application/json" \
  -d '{"code":"ABC123","name":"Bob","emoji":"👨"}'

# 3. Start voting (status: waiting → voting)
curl -X POST http://localhost:8000/api/session/ABC123/start

# 4. Submit vote (requires status: voting)
curl -X POST http://localhost:8000/api/session/ABC123/vote \
  -H "Content-Type: application/json" \
  -d '{"participant_id":1,"card_value":"5"}'

# 5. Reveal cards (status: voting → revealed)
curl -X POST http://localhost:8000/api/session/ABC123/reveal

# 6. Next round (status: revealed → voting, round++)
curl -X POST http://localhost:8000/api/session/ABC123/next-round
```

---

## Phase 9: Electron App Integration

### 9.1 Install Dependencies in Electron App

```bash
cd /Applications/MAMP/htdocs/scrum-poker
npm install axios laravel-echo pusher-js
```

### 9.2 Create API Client

File: `/Applications/MAMP/htdocs/scrum-poker/src/renderer/composables/useApi.js`

```javascript
import axios from 'axios';
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

const API_URL = import.meta.env.VITE_API_URL || 'http://localhost:8000/api';
const REVERB_HOST = import.meta.env.VITE_REVERB_HOST || 'localhost';
const REVERB_PORT = import.meta.env.VITE_REVERB_PORT || 8080;
const REVERB_KEY = import.meta.env.VITE_REVERB_APP_KEY || 'reverb-key';

window.Pusher = Pusher;

const echo = new Echo({
  broadcaster: 'reverb',
  key: REVERB_KEY,
  wsHost: REVERB_HOST,
  wsPort: REVERB_PORT,
  wssPort: REVERB_PORT,
  forceTLS: false,
  enabledTransports: ['ws', 'wss'],
});

export function useApi() {
  const createSession = async (hostName, hostEmoji) => {
    const { data } = await axios.post(`${API_URL}/session`, {
      host_name: hostName,
      host_emoji: hostEmoji,
    });
    return data;
  };

  const joinSession = async (code, name, emoji) => {
    const { data } = await axios.post(`${API_URL}/session/join`, {
      code,
      name,
      emoji,
    });
    return data;
  };

  const startVoting = async (code) => {
    const { data } = await axios.post(`${API_URL}/session/${code}/start`);
    return data;
  };

  const submitVote = async (code, participantId, cardValue) => {
    const { data } = await axios.post(`${API_URL}/session/${code}/vote`, {
      participant_id: participantId,
      card_value: cardValue,
    });
    return data;
  };

  const revealCards = async (code) => {
    const { data } = await axios.post(`${API_URL}/session/${code}/reveal`);
    return data;
  };

  const nextRound = async (code) => {
    const { data } = await axios.post(`${API_URL}/session/${code}/next-round`);
    return data;
  };

  const subscribeToSession = (code, callbacks) => {
    const channel = echo.channel(`session.${code}`);

    if (callbacks.onParticipantJoined) {
      channel.listen('ParticipantJoined', callbacks.onParticipantJoined);
    }
    if (callbacks.onVotingStarted) {
      channel.listen('VotingStarted', callbacks.onVotingStarted);
    }
    if (callbacks.onVoteSubmitted) {
      channel.listen('VoteSubmitted', callbacks.onVoteSubmitted);
    }
    if (callbacks.onCardsRevealed) {
      channel.listen('CardsRevealed', callbacks.onCardsRevealed);
    }
    if (callbacks.onNextRoundStarted) {
      channel.listen('NextRoundStarted', callbacks.onNextRoundStarted);
    }

    return channel;
  };

  const unsubscribeFromSession = (code) => {
    echo.leave(`session.${code}`);
  };

  return {
    createSession,
    joinSession,
    startVoting,
    submitVote,
    revealCards,
    nextRound,
    subscribeToSession,
    unsubscribeFromSession,
  };
}
```

### 9.3 Update Environment Variables

File: `/Applications/MAMP/htdocs/scrum-poker/.env` (create if doesn't exist)

```env
VITE_API_URL=http://localhost:8000/api
VITE_REVERB_HOST=localhost
VITE_REVERB_PORT=8080
VITE_REVERB_APP_KEY=your-reverb-key-from-laravel-env
```

### 9.4 Frontend State Management Example

Example of using session status in your Vue components:

```javascript
// In your sessionStore.js or component
import { computed } from 'vue';

const session = ref(null);
const currentParticipantId = ref(null);
const isHost = computed(() => {
  return session.value?.host_id === currentParticipantId.value;
});

// UI state based on session status
const canStartVoting = computed(() => {
  return isHost.value && session.value?.status === 'waiting';
});

const canVote = computed(() => {
  return session.value?.status === 'voting';
});

const canReveal = computed(() => {
  return isHost.value && session.value?.status === 'voting';
});

const showResults = computed(() => {
  return session.value?.status === 'revealed';
});

const canStartNextRound = computed(() => {
  return isHost.value && session.value?.status === 'revealed';
});

// WebSocket event handlers
const handleVotingStarted = (event) => {
  session.value.status = event.session.status; // 'voting'
  // Enable voting UI
};

const handleCardsRevealed = (event) => {
  session.value.status = 'revealed';
  // Show all cards with values
};

const handleNextRoundStarted = (event) => {
  session.value.status = 'voting';
  session.value.current_round = event.session.current_round;
  // Clear votes and start fresh
};
```

### 9.5 Replace Mock API in Session Store

Update `/Applications/MAMP/htdocs/scrum-poker/src/renderer/stores/sessionStore.js` to use real API instead of mock.

---

## Next Steps After Implementation

1. **Remove mock API** - Delete or disable `useMockApi.js`
2. **Update sessionStore** - Integrate real API calls
3. **Test real-time features** - Open multiple Electron instances
4. **Add error handling** - Network failures, disconnections
5. **Add session cleanup** - Remove old sessions (add cron job)
6. **Production deployment** - Deploy Laravel + MySQL + Reverb

---

## Troubleshooting

### Reverb Connection Issues
- Check Reverb is running: `php artisan reverb:start --debug`
- Verify `.env` has correct `REVERB_APP_ID`, `REVERB_APP_KEY`, `REVERB_APP_SECRET`
- Check firewall allows port 8080

### CORS Errors
- Verify `config/cors.php` allows localhost
- Check middleware is enabled in `bootstrap/app.php`

### Database Connection
- Verify MAMP MySQL is running
- Check port (usually 8889 for MAMP)
- Ensure database `scrum_poker` exists

### Broadcasting Not Working
- Run `php artisan config:clear`
- Check `.env` has `BROADCAST_CONNECTION=reverb`
- Verify events implement `ShouldBroadcast`

---

## Development Commands Reference

```bash
# Start Laravel dev server
php artisan serve

# Start Reverb WebSocket server
php artisan reverb:start --debug

# Run migrations
php artisan migrate

# Create fresh database
php artisan migrate:fresh

# Clear caches
php artisan config:clear
php artisan cache:clear

# View routes
php artisan route:list
```

---

## File Structure

```
scrum-poker-api/
├── app/
│   ├── Events/
│   │   ├── ParticipantJoined.php
│   │   ├── VotingStarted.php
│   │   ├── VoteSubmitted.php
│   │   ├── CardsRevealed.php
│   │   └── NextRoundStarted.php
│   ├── Http/Controllers/Api/
│   │   ├── SessionController.php
│   │   └── VoteController.php
│   └── Models/
│       ├── Session.php
│       ├── Participant.php
│       └── Vote.php
├── database/migrations/
│   ├── xxxx_create_sessions_table.php
│   ├── xxxx_create_participants_table.php
│   └── xxxx_create_votes_table.php
├── routes/
│   └── api.php
└── config/
    ├── broadcasting.php
    └── cors.php
```

---

## Implementation Checklist

**Backend:**
- [ ] Install Laravel Reverb
- [ ] Configure database connection
- [ ] Create migrations (sessions with status field, participants, votes)
- [ ] Run migrations
- [ ] Create models with relationships
- [ ] Implement SessionController (create, join, start, reveal, nextRound)
- [ ] Implement VoteController (with status validation)
- [ ] Create broadcasting events (ParticipantJoined, VotingStarted, VoteSubmitted, CardsRevealed, NextRoundStarted)
- [ ] Configure API routes
- [ ] Configure CORS
- [ ] Test API endpoints (all status transitions)
- [ ] Start Reverb server

**Frontend:**
- [ ] Install Electron app dependencies (axios, laravel-echo, pusher-js)
- [ ] Create useApi composable with all endpoints
- [ ] Add status-based computed properties
- [ ] Update sessionStore to use real API
- [ ] Implement WebSocket event handlers
- [ ] Test session lifecycle (waiting → voting → revealed)
- [ ] Test real-time voting across multiple clients

---

**Ready to implement! Run Claude Code from this directory to start building.**
