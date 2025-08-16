<?php
// Fantacalcio Bid App - single-file PHP (index.php)
// Run locally with: php -S 0.0.0.0:8000 index.php
// Stores state in data/state.json with file locking to preserve strict offer order.

declare(strict_types=1);

session_start();
date_default_timezone_set('Europe/Rome');

// --- Config ---
$DATA_DIR = __DIR__ . '/data';
$STATE_FILE = $DATA_DIR . '/state.json';
if (!is_dir($DATA_DIR)) {
    @mkdir($DATA_DIR, 0777, true);
}

// --- Helpers ---
function respond_json($payload, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function init_state(): array {
    return [
        'next_seq' => 1,               // global monotonic sequence for ordering events
        'next_round_id' => 1,          // auction round id
        'current' => [
            'active' => false,
            'round_id' => null,
            'player' => '',
            'start_price' => 0,
            'amount' => 0,
            'leader_id' => null,
            'leader_name' => null,
            'started_at' => null,
            'last_bid_time' => null,   // unix time of last bid/start
        ],
        'bids' => [],                  // bids for current round
        'history' => [],               // past rounds
        'presence' => []               // user_id => { user_name, last_seen }
    ];
}

/**
 * Atomic read/modify/write under exclusive lock.
 */
function with_state_lock(callable $callback) {
    global $STATE_FILE;
    $f = fopen($STATE_FILE, 'c+');
    if (!$f) throw new RuntimeException('Unable to open state file');

    try {
        flock($f, LOCK_EX);
        $raw = stream_get_contents($f);
        $originalRaw = ($raw === false) ? '' : $raw;
        if ($originalRaw === '') {
            $state = init_state();
        } else {
            $state = json_decode($originalRaw, true);
            if (!is_array($state)) $state = init_state();
        }
        $result = $callback($state);
        $newRaw = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($originalRaw !== $newRaw) {
            ftruncate($f, 0);
            rewind($f);
            fwrite($f, $newRaw);
            fflush($f);
        }
        fclose($f);
        return $result;
    } catch (Throwable $e) {
        fclose($f);
        throw $e;
    }
}

function get_state(): array {
    global $STATE_FILE;
    if (!file_exists($STATE_FILE)) {
        file_put_contents($STATE_FILE, json_encode(init_state()));
    }
    $raw = file_get_contents($STATE_FILE);
    $state = json_decode($raw, true);
    if (!is_array($state)) $state = init_state();
    return $state;
}

function current_user_id(): string {
    if (!isset($_SESSION['user_id'])) {
        $_SESSION['user_id'] = bin2hex(random_bytes(8));
    }
    return $_SESSION['user_id'];
}

function current_user_name(): string { return $_SESSION['user_name'] ?? ''; }
function set_user_name(string $name): void { $_SESSION['user_name'] = trim($name); }
function now_iso(): string { return date('c'); }
function sanitize_text(string $s): string { return trim(filter_var($s, FILTER_SANITIZE_SPECIAL_CHARS)); }

function session_close_early(): void {
    if (session_status() === PHP_SESSION_ACTIVE) {
        @session_write_close();
    }
}

// --- Routing: JSON API endpoints ---
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = $_GET['action'] ?? '';
$view   = $_GET['view'] ?? '';

if ($action === 'set_name' && $method === 'POST') {
    $name = sanitize_text($_POST['name'] ?? '');
    if ($name === '') respond_json(['ok' => false, 'error' => 'Name cannot be empty'], 400);
    set_user_name($name);
    // Release session lock immediately after setting name
    session_close_early();
    respond_json(['ok' => true, 'user_id' => current_user_id(), 'user_name' => current_user_name()]);
}

if ($action === 'start' && $method === 'POST') {
    $player = sanitize_text($_POST['player'] ?? '');
    $start  = (int)($_POST['start_price'] ?? 0);
    $team   = sanitize_text($_POST['team'] ?? '');
    if ($player === '' || $start < 0) respond_json(['ok' => false, 'error' => 'Invalid player or start price'], 400);
    if (current_user_name() === '') respond_json(['ok' => false, 'error' => 'Set your name first'], 400);

    $uid = current_user_id();
    $uname = current_user_name();
    session_close_early();

    $result = with_state_lock(function (&$state) use ($player, $start, $team, $uid, $uname) {
        // Do not allow starting a new round while one is active
        if ($state['current']['active']) {
            return ['error' => 'An auction round is already active'];
        }
        $round_id = $state['next_round_id']++;
        $state['current'] = [
            'active' => true,
            'round_id' => $round_id,
            'player' => $player,
            'team' => $team,
            'start_price' => $start,
            'amount' => $start,
            'leader_id' => $uid,
            'leader_name' => $uname,
            'started_at' => now_iso(),
            'last_bid_time' => time(),
        ];
        $state['bids'] = [];
        // Initial START event (seq ensures ordering)
        $seq = $state['next_seq']++;
        $state['bids'][] = [
            'seq' => $seq,
            'ts' => now_iso(),
            'user_id' => $uid,
            'user_name' => $uname,
            'delta' => 0,
            'amount' => $start,
            'note' => 'START',
        ];
        return ['round_id' => $round_id, 'seq' => $seq];
    });

    if (isset($result['error'])) respond_json(['ok' => false, 'error' => $result['error']], 400);
    respond_json(['ok' => true, 'started' => $result]);
}

if ($action === 'bid' && $method === 'POST') {
    $delta = (int)($_POST['delta'] ?? 0);
    if (!in_array($delta, [1,5,10], true)) respond_json(['ok' => false, 'error' => 'Invalid delta'], 400);
    if (current_user_name() === '') respond_json(['ok' => false, 'error' => 'Set your name first'], 400);

    $uid = current_user_id();
    $uname = current_user_name();
    $clientRoundId = (int)($_POST['round_id'] ?? 0);
    session_close_early();

    $result = with_state_lock(function (&$state) use ($delta, $uid, $uname, $clientRoundId) {
        if (!$state['current']['active']) return ['error' => 'No active round'];
        if ($clientRoundId !== 0 && $clientRoundId !== (int)$state['current']['round_id']) {
            return ['error' => 'Round has changed'];
        }
        $state['current']['amount'] += $delta;
        $state['current']['leader_id'] = $uid;
        $state['current']['leader_name'] = $uname;
        $state['current']['last_bid_time'] = time();
        $seq = $state['next_seq']++;
        $state['bids'][] = [
            'seq' => $seq,
            'ts' => now_iso(),
            'user_id' => $uid,
            'user_name' => $uname,
            'delta' => $delta,
            'amount' => $state['current']['amount'],
        ];
        return ['ok' => true, 'seq' => $seq, 'amount' => $state['current']['amount']];
    });

    if (isset($result['error'])) respond_json(['ok' => false, 'error' => $result['error']], 400);
    respond_json(['ok' => true] + $result);
}

// Manual end removed; auto-close handles finalization

if ($action === 'players') {
    // Load players from CSV file
    $players = [];
    $csv_file = __DIR__ . '/config.csv';
    
    if (file_exists($csv_file)) {
        $handle = fopen($csv_file, 'r');
        if ($handle) {
            // Skip header lines
            fgetcsv($handle); // Skip "Quotazioni Fantacalcio Stagione 2025 26"
            fgetcsv($handle); // Skip column headers
            
            while (($data = fgetcsv($handle)) !== false) {
                if (count($data) >= 5) {
                    $players[] = [
                        'id' => $data[0],
                        'role' => $data[1],
                        'role_detail' => $data[2],
                        'name' => $data[3],
                        'team' => $data[4]
                    ];
                }
            }
            fclose($handle);
        }
    }
    
    respond_json(['ok' => true, 'players' => $players]);
}

if ($action === 'logo' && $method === 'GET') {
    $team = $_GET['team'] ?? '';
    if ($team === '') {
        http_response_code(400);
        exit('Team parameter required');
    }
    
    $logo_file = __DIR__ . '/logo/' . $team . '.png';
    if (!file_exists($logo_file)) {
        http_response_code(404);
        exit('Logo not found');
    }
    
    // Serve the image
    header('Content-Type: image/png');
    header('Cache-Control: public, max-age=86400'); // Cache for 24 hours
    readfile($logo_file);
    exit;
}

if ($action === 'state') {
    // Read user then release session lock for fast polling
    $uid = current_user_id();
    $uname = current_user_name();
    session_close_early();

    // Auto-close if idle for >= 5s, throttled to once per second; return state from inside the lock
    $state = with_state_lock(function (&$state) use ($uid, $uname) {
        $now = time();
        $lastCheck = (int)($state['__last_auto_check'] ?? 0);
        if (!isset($state['presence']) || !is_array($state['presence'])) {
            $state['presence'] = [];
        }
        // Update presence for users with a valid name, throttle writes to every 2s per user
        if ($uname !== '') {
            $existing = $state['presence'][$uid] ?? null;
            $lastSeen = is_array($existing) && isset($existing['last_seen']) ? (int)$existing['last_seen'] : 0;
            if ($now - $lastSeen >= 2) {
                $state['presence'][$uid] = [
                    'user_name' => $uname,
                    'last_seen' => $now,
                ];
            }
        }
        if ($now - $lastCheck >= 1) {
            $state['__last_auto_check'] = $now;
            if ($state['current']['active'] && isset($state['current']['last_bid_time'])) {
                if ($now - (int)$state['current']['last_bid_time'] >= 5.5) {
                    $state['history'][] = [
                        'round_id' => $state['current']['round_id'],
                        'player' => $state['current']['player'],
                        'team' => $state['current']['team'] ?? '',
                        'start_price' => $state['current']['start_price'],
                        'winner_name' => $state['current']['leader_name'],
                        'final_amount' => $state['current']['amount'],
                        'bids' => $state['bids'],
                    ];
                    $state['current'] = init_state()['current'];
                    $state['bids'] = [];
                }
            }
            // Presence GC: remove entries idle for > 15s
            foreach ($state['presence'] as $pid => $pinfo) {
                $pLast = isset($pinfo['last_seen']) ? (int)$pinfo['last_seen'] : 0;
                if ($now - $pLast > 5) {
                    unset($state['presence'][$pid]);
                }
            }
        }
        return $state;
    });
    // Hide internal housekeeping key from clients
    unset($state['__last_auto_check']);
    respond_json([
        'ok' => true,
        'state' => $state,
        'user' => [ 'id' => $uid, 'name' => $uname ]
    ]);
}

// --- Views (HTML) ---
function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function render_header(string $title = 'Fantacalcio Bid App'): void {
    echo "<!doctype html><html lang=\"it\"><head><meta charset=\"utf-8\">";
    echo "<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">";
    echo "<title>" . h($title) . "</title>";
    echo '<style>
:root{--bg:#0f172a;--card:#111827;--muted:#94a3b8;--text:#e5e7eb;--accent:#22c55e;--danger:#ef4444;--brand:#38bdf8}
*{box-sizing:border-box} body{margin:0;background:var(--bg);color:var(--text);font-family:system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,Helvetica,Arial,sans-serif}
.container{max-width:960px;margin:0 auto;padding:16px}
.card{background:var(--card);border-radius:16px;padding:16px;box-shadow:0 10px 30px rgba(0,0,0,.3)}
.row{display:flex;gap:12px;flex-wrap:wrap}
.col{flex:1 1 260px}
input,button{font:inherit}
input[type=text],input[type=number]{width:100%;padding:12px;border-radius:12px;border:1px solid #334155;background:#0b1220;color:var(--text)}
button{padding:12px 14px;border:none;border-radius:12px;cursor:pointer}
.btn{background:#1f2937;color:var(--text)} .btn:hover{filter:brightness(1.1)}
.btn-bid{font-size:1.35rem;font-weight:800;padding:18px 24px;min-width:120px;border-radius:14px}
.btn-primary{background:var(--brand)} .btn-accent{background:var(--accent)} .btn-danger{background:var(--danger)}
.badge{display:inline-block;padding:6px 10px;border-radius:999px;background:#0b1220;border:1px solid #334155;color:var(--muted);font-size:.85rem}
.title{font-size:1.25rem;margin:0 0 10px}
.big{font-size:2.2rem;font-weight:800}
.grid-2{display:grid;grid-template-columns:1fr;gap:16px}
@media (min-width:800px){.grid-2{grid-template-columns:1.1fr .9fr}}
.list{max-height:50vh;overflow:auto;border:1px solid #1f2937;border-radius:12px}
.list table{width:100%;border-collapse:collapse}
.list th,.list td{padding:10px;border-bottom:1px solid #1f2937;text-align:left}
.footer{opacity:.7;margin-top:8px;font-size:.85rem}
.screen{min-height:100vh;display:flex;align-items:center;justify-content:center}
.screen .board{width:min(1100px,95vw);}
.board h1{font-size:clamp(2rem,5vw,4rem);margin:.2em 0}
.board .price{font-size:clamp(2.2rem,7vw,5rem);font-weight:900;color:var(--accent)}
.board .timer-big{font-size:clamp(2rem,6vw,4rem);font-weight:900;color:var(--danger);text-align:center}
.board .leader{font-size:clamp(1.1rem,3.2vw,2rem);color:#cbd5e1}
.board .player{color:#93c5fd}
.badge.mono{font-feature-settings:"tnum" 1; font-variant-numeric: tabular-nums}
/* Presence grid */
.presence-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:10px;margin:8px 0 12px}
.presence-card{display:flex;align-items:center;gap:10px;background:#0b1220;border:1px solid #334155;border-radius:12px;padding:10px}
.presence-card .avatar{width:36px;height:36px;border-radius:50%;background:#1f2937;display:flex;align-items:center;justify-content:center;color:var(--text);font-weight:700}
.presence-card .meta{display:flex;flex-direction:column;min-width:0}
.presence-card .name{font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.presence-card .sub{font-size:.8rem;color:var(--muted)}
.presence-card .dot{width:8px;height:8px;border-radius:999px;display:inline-block;margin-right:6px;background:#16a34a}
.presence-card .dot.off{background:#64748b}
/* Player search dropdown */
.player-search-container{position:relative}
.player-dropdown{position:absolute;top:100%;left:0;right:0;background:var(--card);border:1px solid #334155;border-radius:12px;max-height:300px;overflow-y:auto;z-index:1000;margin-top:4px}
.player-dropdown-item{display:flex;align-items:center;gap:12px;padding:12px;cursor:pointer;border-bottom:1px solid #1f2937}
.player-dropdown-item:hover{background:#1f2937}
.player-dropdown-item:last-child{border-bottom:none}
.player-dropdown-item .team-logo{width:24px;height:24px;object-fit:contain;display:block}
.player-dropdown-item .player-info{flex:1;min-width:0}
.player-dropdown-item .player-name{font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.player-dropdown-item .player-role{font-size:.8rem;color:var(--muted);margin-top:2px}
</style>';
    echo '</head><body>';
}

function render_footer(): void { echo '</body></html>'; }

if ($view === 'screen') {
    // Release session for read-only screen
    session_close_early();
    render_header('Schermo Asta Fantacalcio');
    ?>
    <div class="screen">
      <div class="board card">
        <div class="row" style="align-items:center;justify-content:space-between">
          <div><span class="badge">Schermo</span></div>
          <div><a class="badge" href="./">Torna al pannello</a></div>
        </div>
        <div class="footer" style="margin-top:4px">Utenti connessi</div>
        <div id="presence" class="presence-grid"></div>
                 <h1>Asta: <img id="current-team-logo" class="team-logo-screen" style="display:none;width:40px;height:40px;object-fit:contain;margin-right:10px;vertical-align:middle"> <span id="player" class="player">—</span></h1>
         <div style="display:flex;align-items:center;justify-content:space-between;margin:20px 0">
           <div class="price" id="amount">$ 0</div>
           <div class="timer-big" id="timer">—</div>
         </div>
         <div class="leader" id="leader">Leader: —</div>
         <div class="footer">Aggiornamento in tempo reale (poll 400ms)</div>
        <div class="list" style="margin-top:18px">
          <table>
            <thead><tr><th>#</th><th>Ora</th><th>Nome</th><th>+Δ</th><th>Totale</th></tr></thead>
            <tbody id="bids"></tbody>
          </table>
        </div>
        <div style="margin-top:16px">
          <div class="footer">Storico aste</div>
          <div class="list">
            <table>
              <thead><tr><th></th><th>#</th><th>Giocatore</th><th>Vincitore</th><th>Totale</th><th>Offerte</th><th>Ultimo</th></tr></thead>
              <tbody id="history"></tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
    <script>
    const fmt = n => new Intl.NumberFormat('it-IT').format(n);
    let lastState = null;
    let allPlayers = [];

    // Load players data for team logo fallback
    async function loadPlayers() {
        try {
            const response = await fetch('?action=players');
            const data = await response.json();
            if (data.ok) {
                allPlayers = data.players;
            }
        } catch (e) {
            console.error('Error loading players:', e);
        }
    }

    function render(state){
        lastState = state;
        const c = state.current || {};
        document.getElementById('player').textContent = c.active ? c.player : '—';
        
        // Handle current team logo
        const currentLogo = document.getElementById('current-team-logo');
        if (c.active && c.team) {
            currentLogo.src = `?action=logo&team=${encodeURIComponent(c.team)}`;
            currentLogo.style.display = 'inline-block';
            currentLogo.onerror = () => currentLogo.style.display = 'none';
        } else {
            currentLogo.style.display = 'none';
        }
        
        document.getElementById('amount').textContent = '$ ' + fmt(c.amount||0);
        document.getElementById('leader').textContent = c.active && c.leader_name ? ('Leader: ' + c.leader_name) : '—';
        // Presence render
        const pres = document.getElementById('presence');
        if (pres){
          const entries = Object.entries(state.presence||{});
          if (!entries.length){ pres.innerHTML = ''; }
          else{
            pres.innerHTML = entries.map(([pid,p])=>{
              const name = (p && p.user_name) ? p.user_name : '—';
              const initials = name.split(/\s+/).map(s=>s[0]).join('').toUpperCase().slice(0,2) || 'U';
              const last = p && p.last_seen ? new Date(p.last_seen*1000).toLocaleTimeString('it-IT') : '—';
              return `<div class="presence-card" title="${name}">
                        <div class="avatar">${initials}</div>
                        <div class="meta">
                          <div class="name">${name}</div>
                          <div class="sub"><span class="dot"></span>Ping: ${last}</div>
                        </div>
                      </div>`;
            }).join('');
          }
        }
        const tbody = document.getElementById('bids');
        tbody.innerHTML = '';
        (state.bids||[]).forEach(b => {
            const tr = document.createElement('tr');
            tr.innerHTML = `<td class="badge mono">${b.seq}</td><td>${new Date(b.ts).toLocaleTimeString('it-IT')}</td><td>${b.user_name||''}</td><td>+${b.delta||0}</td><td>$ ${fmt(b.amount||0)}</td>`;
            tbody.appendChild(tr);
        });

        // Render history table rows
        const h = document.getElementById('history');
        if (h) {
            h.innerHTML = '';
            (state.history||[]).slice().reverse().forEach(r => {
                const lastTs = (r.bids && r.bids.length) ? r.bids[r.bids.length-1].ts : null;
                const lastStr = lastTs ? new Date(lastTs).toLocaleTimeString('it-IT') : '—';
                const tr = document.createElement('tr');
                
                // Create team logo cell - try to find team from player name if not stored
                let teamName = r.team;
                if (!teamName && r.player && allPlayers) {
                    const playerData = allPlayers.find(p => p.name === r.player);
                    teamName = playerData ? playerData.team : '';
                }
                
                const logoCell = teamName ? 
                    `<td style="text-align:center;width:40px"><img src="?action=logo&team=${encodeURIComponent(teamName)}" alt="${teamName}" style="width:24px;height:24px;object-fit:contain;vertical-align:middle" onerror="this.style.display='none'"></td>` : 
                    '<td style="text-align:center;width:40px"></td>';
                
                tr.innerHTML = `${logoCell}<td class="badge mono">${r.round_id}</td><td>${r.player}</td><td>${r.winner_name||'—'}</td><td>$ ${fmt(r.final_amount||0)}</td><td>${r.bids?.length||0}</td><td>${lastStr}</td>`;
                h.appendChild(tr);
            });
        }
    }

    async function poll(){
        try{
          const r = await fetch('?action=state');
          if(!r.ok) return;
          const j = await r.json();
          render(j.state);
        }catch(e){}
        setTimeout(poll, 400); // server poll every 400ms
    }

    // Smooth countdown every 100ms without extra server calls
    setInterval(() => {
      if (!lastState) return;
      const c = lastState.current || {};
      const t = document.getElementById('timer');
      if (c.active && c.last_bid_time){
        const remaining = Math.max(0, 5 - (Date.now()/1000 - c.last_bid_time));
        t.textContent = (Math.ceil(remaining*10)/10).toFixed(1) + 's';
      } else {
        t.textContent = '—';
      }
    }, 100);

    // Load players data first, then start polling
    loadPlayers().then(() => {
        poll();
    });
    </script>
    <?php
    render_footer();
    exit;
}

// Default control panel view
render_header();
$me = current_user_name();
?>
<div class="container">
  <div class="row" style="align-items:center;justify-content:space-between;margin-bottom:8px">
    <div><span class="badge">Fantacalcio Bid · Locale</span></div>
    <div><a class="badge" href="?view=screen" target="_blank">Apri Schermo</a></div>
  </div>

  <div class="grid-2">
    <div class="card" id="nameCard">
      <h2 class="title">Imposta il tuo nome</h2>
      <form id="nameForm" class="row" onsubmit="return false;">
        <div class="col"><input type="text" id="name" placeholder="Il tuo nome" value="<?=h($me)?>" required></div>
        <div><button class="btn btn-primary" id="saveName">Salva</button></div>
      </form>
      <div id="nameStatus" class="footer"></div>
    </div>

    <div class="card">
      <h2 class="title">Avvia nuova asta</h2>
      <form id="startForm" class="row" onsubmit="return false;">
        <div class="col">
          <div class="player-search-container">
            <input type="text" id="player" placeholder="Cerca giocatore..." required>
            <div id="playerDropdown" class="player-dropdown" style="display: none;"></div>
          </div>
        </div>
        <div style="width:140px"><input type="number" id="start_price" placeholder="Prezzo iniziale" min="0" value="1"></div>
        <div><button class="btn btn-accent" id="startBtn">Avvia</button></div>
      </form>
    </div>
  </div>

  <div class="card" style="margin-top:16px">
    <h2 class="title">Fai un'offerta</h2>
    <div id="current" class="row" style="align-items:center;gap:16px">
      <div class="badge">Giocatore: <span id="c_player">—</span></div>
      <div class="badge">Totale: <span id="c_amount">$ 0</span></div>
      <div class="badge">Leader: <span id="c_leader">—</span></div>
      <div class="badge">Round #<span id="c_round">—</span></div>
      <div class="badge">Auto chiusura: <span id="c_timer">—</span></div>
    </div>
    <div class="row" style="margin-top:12px">
      <button class="btn btn-bid btn-accent" data-delta="1">+1</button>
      <button class="btn btn-bid btn-accent" data-delta="5">+5</button>
      <button class="btn btn-bid btn-accent" data-delta="10">+10</button>
    </div>

    <div class="list" style="margin-top:14px">
      <table>
        <thead><tr><th>#</th><th>Ora</th><th>Nome</th><th>+Δ</th><th>Totale</th></tr></thead>
        <tbody id="list"></tbody>
      </table>
    </div>
  </div>

  <div class="card" style="margin-top:16px">
    <h2 class="title">Storico aste</h2>
    <div class="list">
      <table>
        <thead><tr><th>#</th><th>Giocatore</th><th>Vincitore</th><th>Totale</th><th>Offerte</th><th>Ultimo</th></tr></thead>
        <tbody id="history"></tbody>
      </table>
    </div>
  </div>
</div>

<script>
const fmt = n => new Intl.NumberFormat('it-IT').format(n);

async function post(action, data){
  const r = await fetch('?action='+action, {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body: new URLSearchParams(data)});
  const j = await r.json();
  if(!r.ok){ throw new Error(j.error||'Errore'); }
  return j;
}

// Name
document.getElementById('saveName').addEventListener('click', async ()=>{
  const name = document.getElementById('name').value.trim();
  try {
    const j = await post('set_name', {name});
    document.getElementById('nameStatus').textContent = 'Salvato: ' + j.user_name;
    // Hide the name card after successful save
    document.getElementById('nameCard').style.display = 'none';
  } catch(e){ alert(e.message); }
});

// Player search functionality
let allPlayers = [];
let filteredPlayers = [];

async function loadPlayers() {
  try {
    const response = await fetch('?action=players');
    const data = await response.json();
    if (data.ok) {
      allPlayers = data.players;
    }
  } catch (e) {
    console.error('Error loading players:', e);
  }
}

function filterPlayers(query) {
  if (!query.trim()) {
    filteredPlayers = [];
    return;
  }
  
  const searchTerm = query.toLowerCase();
  filteredPlayers = allPlayers.filter(player => 
    player.name.toLowerCase().includes(searchTerm) ||
    player.team.toLowerCase().includes(searchTerm)
  ).slice(0, 10); // Limit to 10 results
}

function showDropdown() {
  const dropdown = document.getElementById('playerDropdown');
  if (filteredPlayers.length === 0) {
    dropdown.style.display = 'none';
    return;
  }
  
  dropdown.innerHTML = filteredPlayers.map(player => `
    <div class="player-dropdown-item" data-player="${player.name}">
      <img src="?action=logo&team=${encodeURIComponent(player.team)}" alt="${player.team}" class="team-logo" onerror="this.style.display='none'" onload="this.style.display='block'">
      <div class="player-info">
        <div class="player-name">${player.name}</div>
        <div class="player-role">${player.role} - ${player.team}</div>
      </div>
    </div>
  `).join('');
  
  dropdown.style.display = 'block';
  
  // Add click handlers
  dropdown.querySelectorAll('.player-dropdown-item').forEach(item => {
    item.addEventListener('click', () => {
      document.getElementById('player').value = item.dataset.player;
      dropdown.style.display = 'none';
    });
  });
  
  // Debug: Log the first few players to check team names
  if (filteredPlayers.length > 0) {
    console.log('First player:', filteredPlayers[0]);
    console.log('Image path:', `logo/${filteredPlayers[0].team}.png`);
  }
}

function hideDropdown() {
  document.getElementById('playerDropdown').style.display = 'none';
}

// Initialize player search
document.addEventListener('DOMContentLoaded', () => {
  loadPlayers();
  
  const playerInput = document.getElementById('player');
  
  playerInput.addEventListener('input', (e) => {
    filterPlayers(e.target.value);
    showDropdown();
  });
  
  playerInput.addEventListener('focus', () => {
    if (playerInput.value.trim()) {
      filterPlayers(playerInput.value);
      showDropdown();
    }
  });
  
  // Hide dropdown when clicking outside
  document.addEventListener('click', (e) => {
    if (!e.target.closest('.player-search-container')) {
      hideDropdown();
    }
  });
});

// Start
document.getElementById('startBtn').addEventListener('click', async ()=>{
  const player = document.getElementById('player').value.trim();
  const start_price = document.getElementById('start_price').valueAsNumber || 0;
  
  // Find the team for the selected player
  let team = '';
  if (player) {
    const playerData = allPlayers.find(p => p.name === player);
    team = playerData ? playerData.team : '';
  }
  
  try { await post('start', {player, start_price, team}); } catch(e){ alert(e.message); }
});

// Bid buttons
Array.from(document.querySelectorAll('button[data-delta]')).forEach(btn => {
  btn.addEventListener('click', async ()=>{
    const delta = btn.getAttribute('data-delta');
    const round_id = (lastState && lastState.current && lastState.current.round_id) ? lastState.current.round_id : 0;
    try{ await post('bid', {delta, round_id}); }catch(e){ alert(e.message); }
  });
});

// End round removed

let lastState = null;

function renderState(s){
  lastState = s;
  const c = s.current || {};
  document.getElementById('c_player').textContent = c.active ? c.player : '—';
  document.getElementById('c_amount').textContent = '$ ' + fmt(c.amount||0);
  document.getElementById('c_leader').textContent = c.leader_name || '—';
  document.getElementById('c_round').textContent = c.round_id || '—';
  const tbody = document.getElementById('list');
  tbody.innerHTML = '';
  (s.bids||[]).forEach(b => {
    const tr = document.createElement('tr');
    tr.innerHTML = `<td class="badge mono">${b.seq}</td><td>${new Date(b.ts).toLocaleTimeString('it-IT')}</td><td>${b.user_name||''}</td><td>+${b.delta||0}</td><td>$ ${fmt(b.amount||0)}</td>`;
    tbody.appendChild(tr);
  });
  const h = document.getElementById('history');
  h.innerHTML = '';
  (s.history||[]).slice().reverse().forEach(r => {
    const lastTs = (r.bids && r.bids.length) ? r.bids[r.bids.length-1].ts : null;
    const lastStr = lastTs ? new Date(lastTs).toLocaleTimeString('it-IT') : '—';
    const tr = document.createElement('tr');
    tr.innerHTML = `<td class="badge mono">${r.round_id}</td><td>${r.player}</td><td>${r.winner_name||'—'}</td><td>$ ${fmt(r.final_amount||0)}</td><td>${r.bids?.length||0}</td><td>${lastStr}</td>`;
    h.appendChild(tr);
  });
}

// Server poll every 400ms
async function tick(){
  try {
    const r = await fetch('?action=state');
    if(!r.ok) return;
    const j = await r.json();
    renderState(j.state);
  } catch(e) {}
  setTimeout(tick, 400);
}

// Hide name card if user already has a name
if (document.getElementById('name').value.trim() !== '') {
  document.getElementById('nameCard').style.display = 'none';
}

tick();

// Smooth local countdown @ 100ms
setInterval(() => {
  if (!lastState) return;
  const c = lastState.current || {};
  const ct = document.getElementById('c_timer');
  if (c.active && c.last_bid_time){
    const remaining = Math.max(0, 5 - (Date.now()/1000 - c.last_bid_time));
    ct.textContent = (Math.ceil(remaining*10)/10).toFixed(1) + 's';
  } else {
    ct.textContent = '—';
  }
}, 100);
</script>
<?php render_footer();
