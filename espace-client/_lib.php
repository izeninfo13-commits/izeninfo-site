<?php
/* IZENINFO – Espace client : fonctions communes (base SQLite, sessions, sécurité).
   Ce fichier n'est jamais appelé directement. */
if (!defined('IZS')) { http_response_code(404); exit; }

date_default_timezone_set('Europe/Paris');
const IZS_SITES = [
    'izeninfo'    => ['IZENINFO', 'izeninfo.fr'],
    'izenride'    => ['IzenRide', 'izenride.fr'],
    'izencustom'  => ['IzenCustom', 'izencustom.fr'],
    'mycathome'   => ['MyCatHome', 'mycathome.fr'],
    'gameofdrone' => ['Game of Drone', 'game-of-drone.fr'],
    'lentracte'   => ["L'Entracte", 'lentracte-auriol.fr'],
];
if (!function_exists('str_ends_with')) { function str_ends_with(string $h, string $n): bool { return $n === '' || substr($h, -strlen($n)) === $n; } }

/* Dossier des données : hors du dossier public si possible (non écrasé par les déploiements Git). */
function izs_data_dir(): string {
    static $dir = null;
    if ($dir !== null) return $dir;
    $candidates = [dirname(__DIR__, 2) . '/izenstats-data', __DIR__ . '/../.izenstats-data'];
    foreach ($candidates as $c) {
        if (is_dir($c) || @mkdir($c, 0700, true)) {
            if (is_writable($c)) {
                if (!file_exists($c . '/.htaccess')) @file_put_contents($c . '/.htaccess', "Require all denied\nDeny from all\n");
                return $dir = $c;
            }
        }
    }
    http_response_code(500); exit('Stockage indisponible');
}

function izs_db(): PDO {
    static $db = null;
    if ($db) return $db;
    $db = new PDO('sqlite:' . izs_data_dir() . '/stats.sqlite');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $db->exec('PRAGMA journal_mode=WAL; PRAGMA synchronous=NORMAL; PRAGMA busy_timeout=4000; PRAGMA foreign_keys=ON;');
    $db->exec('CREATE TABLE IF NOT EXISTS users(
        id INTEGER PRIMARY KEY, email TEXT UNIQUE NOT NULL, name TEXT NOT NULL, pass TEXT NOT NULL,
        role TEXT NOT NULL DEFAULT "client", created INTEGER NOT NULL, last_login INTEGER)');
    $db->exec('CREATE TABLE IF NOT EXISTS sites(
        id INTEGER PRIMARY KEY, user_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
        name TEXT NOT NULL, domain TEXT NOT NULL, skey TEXT UNIQUE NOT NULL, created INTEGER NOT NULL)');
    $db->exec('CREATE TABLE IF NOT EXISTS hits(
        id INTEGER PRIMARY KEY, site_id INTEGER NOT NULL, ts INTEGER NOT NULL, day TEXT NOT NULL,
        vid TEXT NOT NULL, kind TEXT NOT NULL, path TEXT, source TEXT, ref TEXT, device TEXT,
        browser TEXT, country TEXT, ev TEXT)');
    $db->exec('CREATE INDEX IF NOT EXISTS hits_site_ts ON hits(site_id, ts)');
    $db->exec('CREATE TABLE IF NOT EXISTS live(
        site_id INTEGER NOT NULL, vid TEXT NOT NULL, path TEXT, ts INTEGER NOT NULL, PRIMARY KEY(site_id, vid))');
    /* Sites réalisés par IZENINFO : référencés d'office, avec une clé de mesure fixe. */
    $ins = $db->prepare('INSERT OR IGNORE INTO sites(user_id,name,domain,skey,created) VALUES(NULL,?,?,?,?)');
    foreach (IZS_SITES as $k => [$name, $domain]) $ins->execute([$name, $domain, $k, time()]);
    return $db;
}

/* Sel quotidien : les visiteurs ne sont pas suivis d'un jour à l'autre et aucune IP n'est stockée. */
function izs_salt(): string {
    $f = izs_data_dir() . '/salt.json';
    $day = date('Y-m-d');
    $s = is_file($f) ? json_decode((string)file_get_contents($f), true) : null;
    if (!is_array($s) || ($s['day'] ?? '') !== $day) {
        $s = ['day' => $day, 'salt' => bin2hex(random_bytes(32))];
        @file_put_contents($f, json_encode($s), LOCK_EX);
    }
    return $s['salt'];
}

function izs_client_ip(): string { return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'; }

/* Limitation simple par IP (fichiers temporaires). */
function izs_rate(string $bucket, int $max, int $window): bool {
    $f = izs_data_dir() . '/rl_' . $bucket . '_' . substr(hash('sha256', izs_client_ip()), 0, 24);
    $now = time();
    $t = is_file($f) ? array_filter(explode(',', (string)file_get_contents($f)), fn($x) => (int)$x > $now - $window) : [];
    if (count($t) >= $max) return false;
    $t[] = $now;
    @file_put_contents($f, implode(',', $t), LOCK_EX);
    return true;
}

/* ---------- Sessions ---------- */
function izs_session(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    session_name('izs');
    session_set_cookie_params(['lifetime' => 0, 'path' => '/espace-client/', 'secure' => !empty($_SERVER['HTTPS']) || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https', 'httponly' => true, 'samesite' => 'Strict']);
    session_start();
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(24));
    if (!empty($_SESSION['uid']) && (time() - ($_SESSION['seen'] ?? 0)) > 8 * 3600) { $_SESSION = []; session_regenerate_id(true); }
    $_SESSION['seen'] = time();
}

function izs_user(): ?array {
    izs_session();
    if (empty($_SESSION['uid'])) return null;
    $st = izs_db()->prepare('SELECT id,email,name,role FROM users WHERE id=?');
    $st->execute([$_SESSION['uid']]);
    return $st->fetch() ?: null;
}

function izs_csrf_ok(?string $t): bool { izs_session(); return is_string($t) && hash_equals($_SESSION['csrf'] ?? '', $t); }

function izs_has_users(): bool { return (int)izs_db()->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0; }

function izs_json($data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function izs_norm_domain(string $d): string {
    $d = strtolower(trim($d));
    $d = preg_replace('~^https?://~', '', $d);
    $d = preg_replace('~[/?#:].*$~', '', $d);
    $d = preg_replace('~^www\.~', '', $d);
    return preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $d) ? $d : '';
}

function izs_password_ok(string $p): bool { return strlen($p) >= 10; }

function izs_gen_password(): string {
    $a = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $p = '';
    for ($i = 0; $i < 14; $i++) $p .= $a[random_int(0, strlen($a) - 1)];
    return $p;
}

/* Sites accessibles à l'utilisateur. */
function izs_sites_for(array $u): array {
    $db = izs_db();
    if ($u['role'] === 'admin') return $db->query('SELECT s.id,s.name,s.domain,s.skey,s.user_id,u.email AS owner FROM sites s LEFT JOIN users u ON u.id=s.user_id ORDER BY s.name')->fetchAll();
    $st = $db->prepare('SELECT id,name,domain,skey FROM sites WHERE user_id=? ORDER BY name');
    $st->execute([$u['id']]);
    return $st->fetchAll();
}

/* Purge : données détaillées conservées 25 mois. */
function izs_purge(): void {
    if (random_int(1, 500) !== 1) return;
    $db = izs_db();
    $db->prepare('DELETE FROM hits WHERE ts < ?')->execute([time() - 25 * 31 * 86400]);
    $db->prepare('DELETE FROM live WHERE ts < ?')->execute([time() - 3600]);
}
