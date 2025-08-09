# Fantacalcio Bid App

A tiny single-file PHP web app to run a fast, ordered fantacalcio-style auction from multiple devices on the same LAN. State is stored in `data/state.json` with strict ordering guaranteed via file locks and a monotonic sequence number.

- Control panel: start rounds, place bids (+1/+5/+10)
- Screen view: big scoreboard with live price, leader, live bids, connected users, and a history table
- Presence: shows connected users on the screen view
- Auto-close: a round auto-closes after 5 seconds of inactivity

## Demo
- Control panel: `/`
- Screen: `/?view=screen`

## Features
- Ordered bids with `flock(LOCK_EX)` and `next_seq`
- Round safety: cannot start a new round while one is active
- Wrong-round protection: bids include `round_id` and are rejected if the round changed
- Auto-close round after 5s idle
- Presence indicator (users with a saved name who are polling)
- Works well for ~10–20 users on a LAN

## How it works
- All state lives in `data/state.json`
- Every write operation happens under an exclusive file lock and updates a monotonic `next_seq`
- Screen and control panel poll `?action=state` every 400ms; countdown renders locally every 100ms
- Presence is updated at most every 2 seconds per user and entries are garbage-collected after 5 seconds of inactivity

## Quick start
Prerequisites: PHP 8+

Option A. PHP built-in server
```bash
php -S 0.0.0.0:8000 index.php
```
- Control panel: `http://localhost:8000/`
- Screen: `http://localhost:8000/?view=screen`

Option B. XAMPP / Apache (Windows)
- Copy the project folder (e.g., `fantaapp/`) under `C:\xampp\htdocs\`
- Open `http://localhost/fantaapp/`
- Ensure `data/` is writable by the web server user

Troubleshooting: If you see “Failed opening required 'index.php'”, ensure you launch PHP from the directory containing `index.php`, or that Apache’s DocumentRoot points to the project folder.

## Using the app
1. Set your name (top-left card)
2. Start a new auction (player name + start price)
3. Place bids with +1/+5/+10
- The round closes automatically after 5 seconds without a new bid
- The screen view shows: current player, price, leader, live bids, connected users, and a history table

## API (JSON)
- `POST ?action=set_name` → body: `name`
- `POST ?action=start` → body: `player`, `start_price`
- `POST ?action=bid` → body: `delta` in {1,5,10}, `round_id` (from last state)
- `GET  ?action=state` → returns `{ ok, state, user }`

State (simplified):
```json
{
  "next_seq": 1,
  "next_round_id": 1,
  "current": {
    "active": true,
    "round_id": 1,
    "player": "...",
    "start_price": 1,
    "amount": 10,
    "leader_id": "...",
    "leader_name": "...",
    "started_at": "ISO8601",
    "last_bid_time": 1723200000
  },
  "bids": [ { "seq": 1, "ts": "ISO8601", "user_id": "...", "user_name": "...", "delta": 5, "amount": 10 } ],
  "history": [ { "round_id": 1, "player": "...", "winner_name": "...", "final_amount": 10, "bids": [/*...*/] } ],
  "presence": { "<user_id>": { "user_name": "...", "last_seen": 1723200000 } }
}
```

## Concurrency/consistency
- All writes are atomic within a file lock; `next_seq` enforces strict ordering across bids
- Bids are validated against the `round_id` sent by the client to avoid crossing rounds
- `state` endpoint returns state computed under lock to avoid torn reads
- Session locks are released early to avoid self-serialization (`session_write_close`)

## Limits and tips
- Polling returns the full state; `history` growth increases payload size over long sessions. For very long auctions consider trimming returned history or adding incremental updates (`since_seq`).
- The app writes the JSON file on changes; for extra resilience you can add temp-file + atomic rename and/or periodic backups of `data/state.json`.
- Running for 10+ hours is fine if an occasional restart is acceptable. State persists across restarts.

## Configuration
- Inactivity auto-close: 5 seconds (client shows a local countdown; server uses its own clock)
- Bid steps: +1/+5/+10 (client UI)
- Presence expiry: 5 seconds idle
- Poll intervals: 400ms (server), 100ms (client-side countdown only)

## Folder structure
```
.
├── data/
│   └── state.json        # App state (auto-created)
└── index.php             # App + API + UI (single file)
```

## Development
- Single file app; PRs welcome for:
  - Incremental polling / last-N history
  - Atomic file write via tmp + rename
  - WebSocket / Server-Sent Events (SSE) option

## Security
- Intended for trusted local networks. No authentication/authorization is provided. Do not expose publicly without adding access controls.

## License
- Add a license for your repository (e.g., MIT) as `LICENSE`.
