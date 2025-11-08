# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Purpose

This is a Laravel 12 API backend for a Scrum Poker Electron application. It provides real-time voting functionality using Laravel Reverb (WebSocket server) for synchronized estimation sessions.

**Related Project:** The frontend Electron app is located at `/Applications/MAMP/htdocs/scrum-poker`

## Development Environment

This project uses **Laravel Sail** (Docker) for local development:

```bash
# Start development environment
./vendor/bin/sail up -d

# Stop environment
./vendor/bin/sail down

# Run artisan commands
./vendor/bin/sail artisan [command]

# Run composer
./vendor/bin/sail composer [command]

# Run tests
./vendor/bin/sail test
```

**Important Ports:**
- Laravel app: `http://localhost:8000`
- MySQL: `localhost:3306`
- Reverb WebSocket: `localhost:8080`
- Vite: `localhost:5174`

**Database:** MySQL (`scrum_poker` database)

## Session State Machine Architecture

The core of this application is a finite state machine managing voting sessions. Understanding this is critical:

**States:** `waiting` → `voting` → `revealed` (cycles back to `voting` for new rounds)

**State Transitions (Host-only actions):**
- `POST /api/session/{code}/start` - Start voting (waiting → voting)
- `POST /api/session/{code}/reveal` - Reveal cards (voting → revealed)
- `POST /api/session/{code}/next-round` - Next round (revealed → voting, increments round)

**Status Validation:**
- Voting is ONLY allowed when `session.status === 'voting'`
- Each state transition broadcasts a WebSocket event to all participants
- Participants can join at any time, but voting depends on session status

## Database Schema

**sessions:**
- `code` (6-char unique): Session identifier
- `host_id`: References participant who created session
- `current_round`: Tracks voting rounds (increments with each reset)
- `status`: enum('waiting', 'voting', 'revealed')

**participants:**
- `session_id`: Foreign key to sessions
- `name`: Participant name
- `emoji`: Display emoji
- `is_host`: Boolean flag

**votes:**
- Composite unique key: `(session_id, participant_id, round)`
- `card_value`: Fibonacci values ("0", "1/2", "1", "2", "3", "5", "8", "13", "21", "?", "☕")
- Old votes persist in DB for history/analytics

## Real-Time Broadcasting

Uses **Laravel Reverb** for WebSocket communication:

**Channel Pattern:** `session.{code}` (e.g., `session.ABC123`)

**Events:**
- `ParticipantJoined` - New participant joins (broadcast to others)
- `VotingStarted` - Host starts voting
- `VoteSubmitted` - Participant submits/changes vote (broadcast to others, vote value hidden)
- `CardsRevealed` - Host reveals all votes (everyone sees full results)
- `NextRoundStarted` - Host starts new round (clears UI for new voting)

**Key Behavior:**
- `VoteSubmitted` uses `->toOthers()` - voter doesn't receive their own event
- Vote counts are visible, but card values remain hidden until reveal
- All participants on the same channel receive state transition events simultaneously

## API Structure

**Controllers in `app/Http/Controllers/Api/`:**
- `SessionController` - Session CRUD + state transitions (start, reveal, nextRound)
- `VoteController` - Vote submission with status validation

**Models in `app/Models/`:**
- `Session` - Includes `generateCode()` helper for unique 6-char codes
- `Participant`
- `Vote` - Includes `validCardValues()` static method

**Events in `app/Events/`:**
- All implement `ShouldBroadcast` interface
- Broadcast on public channel (no auth in current implementation)

## Common Development Commands

```bash
# Create migration
./vendor/bin/sail artisan make:migration create_table_name

# Run migrations
./vendor/bin/sail artisan migrate

# Rollback migrations
./vendor/bin/sail artisan migrate:rollback

# Fresh migration
./vendor/bin/sail artisan migrate:fresh

# Create model
./vendor/bin/sail artisan make:model ModelName

# Create controller
./vendor/bin/sail artisan make:controller Api/ControllerName

# Create event
./vendor/bin/sail artisan make:event EventName

# Start Reverb server (for WebSocket testing)
./vendor/bin/sail artisan reverb:start --debug

# Clear caches
./vendor/bin/sail artisan config:clear
./vendor/bin/sail artisan cache:clear

# View routes
./vendor/bin/sail artisan route:list
```

## Frontend Integration Notes

The Electron app will:
1. Use `axios` for REST API calls
2. Use `laravel-echo` + `pusher-js` for WebSocket subscriptions
3. Subscribe to `session.{code}` channel after joining/creating session
4. Listen for all 5 broadcast events to update UI in real-time

**Environment variables needed in Electron app:**
```env
VITE_API_URL=http://localhost:8000/api
VITE_REVERB_HOST=localhost
VITE_REVERB_PORT=8080
VITE_REVERB_APP_KEY={from Laravel .env}
```

## Implementation Reference

See `IMPLEMENTATION_PLAN.md` for the complete step-by-step implementation guide, including:
- Detailed migration schemas
- Full model/controller/event code examples
- Testing procedures
- Troubleshooting guide
