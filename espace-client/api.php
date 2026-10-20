<?php
/* IZENINFO – Espace client : API JSON (connexion, statistiques, gestion des comptes). */
define('IZS', 1);
require __DIR__ . '/_lib.php';
izs_session();

$a = $_GET['a'] ?? '';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$in = [];
if ($method === 'POST') {
    $in = json_decode((string)file_get_contents('php://input'), true) ?: [];
    if (!izs_csrf_ok($in['csrf'] ?? null)) izs_json(['error' => 'Session expirée, rechargez la page.'], 403);
}
$db = izs_db();

/* ---------- Accès public : état, connexion, première configuration ---------- */
if ($a === 'state') {
    $u = izs_user();
    izs_json(['csrf' => $_SESSION['csrf'], 'setup' => !izs_has_users(), 'user' => $u, 'sites' => $u ? izs_sites_for($u) : []]);
}

if ($a === 'setup' && $method === 'POST') {
    if (izs_has_users()) izs_json(['error' => 'Le compte administrateur existe déjà.'], 403);
    $email = strtolower(trim((string)($in['email'] ?? '')));
    $name = trim((string)($in['name'] ?? 'IZENINFO'));
    $pass = (string)($in['password'] ?? '');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) izs_json(['error' => 'Adresse e-mail invalide.'], 422);
    if (!izs_password_ok($pass)) izs_json(['error' => 'Le mot de passe doit contenir au moins 10 caractères.'], 422);
    $db->prepare('INSERT INTO users(email,name,pass,role,created) VALUES(?,?,?,?,?)')->execute([$email, $name, password_hash($pass, PASSWORD_DEFAULT), 'admin', time()]);
    /* Le site IZENINFO lui-même est créé d'office. */
    $uid = (int)$db->lastInsertId();
    $db->prepare("UPDATE sites SET user_id=? WHERE skey='izeninfo'")->execute([$uid]);
    session_regenerate_id(true);
    $_SESSION['uid'] = $uid;
    izs_json(['ok' => true]);
}

if ($a === 'login' && $method === 'POST') {
    if (!izs_rate('login', 8, 900)) izs_json(['error' => 'Trop de tentatives. Réessayez dans 15 minutes.'], 429);
    $st = $db->prepare('SELECT id,pass FROM users WHERE email=?');
    $st->execute([strtolower(trim((string)($in['email'] ?? '')))]);
    $u = $st->fetch();
    if (!$u || !password_verify((string)($in['password'] ?? ''), $u['pass'])) {
        usleep(400000);
        izs_json(['error' => 'Identifiant ou mot de passe incorrect.'], 401);
    }
    session_regenerate_id(true);
    $_SESSION['uid'] = (int)$u['id'];
    $db->prepare('UPDATE users SET last_login=? WHERE id=?')->execute([time(), $u['id']]);
    izs_json(['ok' => true]);
}

if ($a === 'logout' && $method === 'POST') {
    $_SESSION = [];
    session_regenerate_id(true);
    izs_json(['ok' => true]);
}

/* ---------- À partir d'ici : connexion requise ---------- */
$u = izs_user();
if (!$u) izs_json(['error' => 'Connexion requise.'], 401);
$isAdmin = $u['role'] === 'admin';

function izs_site_or_403(array $u, $id): array {
    $st = izs_db()->prepare('SELECT * FROM sites WHERE id=?');
    $st->execute([(int)$id]);
    $s = $st->fetch();
    if (!$s || ($u['role'] !== 'admin' && (int)$s['user_id'] !== (int)$u['id'])) izs_json(['error' => 'Site introuvable.'], 404);
    return $s;
}

if ($a === 'password' && $method === 'POST') {
    $st = $db->prepare('SELECT pass FROM users WHERE id=?');
    $st->execute([$u['id']]);
    if (!password_verify((string)($in['old'] ?? ''), (string)$st->fetchColumn())) izs_json(['error' => 'Mot de passe actuel incorrect.'], 422);
    if (!izs_password_ok((string)($in['new'] ?? ''))) izs_json(['error' => 'Le nouveau mot de passe doit contenir au moins 10 caractères.'], 422);
    $db->prepare('UPDATE users SET pass=? WHERE id=?')->execute([password_hash((string)$in['new'], PASSWORD_DEFAULT), $u['id']]);
    izs_json(['ok' => true]);
}

/* ---------- Statistiques ---------- */
/* Périmètre : un site, ou tous les sites pour l'administrateur (site=all). */
function izs_scope(array $u): array {
    $want = $_GET['site'] ?? '0';
    if ($want === 'all') {
        if ($u['role'] !== 'admin') izs_json(['error' => 'Accès réservé.'], 403);
        $ids = array_map('intval', izs_db()->query('SELECT id FROM sites')->fetchAll(PDO::FETCH_COLUMN));
        return ['all' => true, 'ids' => $ids ?: [0], 'site' => ['id' => 'all', 'name' => 'Tous les sites', 'domain' => count($ids) . ' sites suivis']];
    }
    $s = izs_site_or_403($u, $want);
    return ['all' => false, 'ids' => [(int)$s['id']], 'site' => ['id' => (int)$s['id'], 'name' => $s['name'], 'domain' => $s['domain']]];
}

if ($a === 'live') {
    $sc = izs_scope($u);
    $IN = 'site_id IN (' . implode(',', $sc['ids']) . ')';
    $since = time() - 300;
    $count = (int)$db->query("SELECT COUNT(*) FROM live WHERE $IN AND ts>=$since")->fetchColumn();
    $label = $sc['all'] ? "(SELECT name FROM sites WHERE id=live.site_id)||' · '||path" : 'path';
    $pages = $db->query("SELECT $label AS path, COUNT(*) n FROM live WHERE $IN AND ts>=$since GROUP BY 1 ORDER BY n DESC LIMIT 8")->fetchAll();
    izs_json(['count' => $count, 'pages' => $pages, 'at' => time()]);
}

if ($a === 'stats') {
    $sc = izs_scope($u);
    $IN = 'site_id IN (' . implode(',', $sc['ids']) . ')';
    $range = in_array($_GET['range'] ?? '', ['today', '7d', '30d', '90d', '12m'], true) ? $_GET['range'] : '30d';
    $now = time();
    $todayStart = strtotime('today');
    switch ($range) {
        case 'today': $from = $todayStart; $bucket = 'hour'; break;
        case '7d': $from = strtotime('-6 days', $todayStart); $bucket = 'day'; break;
        case '90d': $from = strtotime('-89 days', $todayStart); $bucket = 'day'; break;
        case '12m': $from = strtotime(date('Y-m-01', strtotime('-11 months', $todayStart))); $bucket = 'month'; break;
        default: $from = strtotime('-29 days', $todayStart); $bucket = 'day';
    }
    $len = $now - $from;
    $pFrom = $from - $len; $pTo = $from;

    $kpi = function (int $a, int $b) use ($db, $IN): array {
        $st = $db->prepare("SELECT
            (SELECT COALESCE(SUM(n),0) FROM (SELECT COUNT(DISTINCT vid) n FROM hits WHERE $IN AND ts>=:a AND ts<:b AND kind='pv' GROUP BY site_id, day)) AS visitors,
            (SELECT COUNT(*) FROM hits WHERE $IN AND ts>=:a AND ts<:b AND kind='pv') AS pageviews,
            (SELECT COUNT(*) FROM hits WHERE $IN AND ts>=:a AND ts<:b AND kind='ev') AS contacts");
        $st->execute([':a' => $a, ':b' => $b]);
        $r = array_map('intval', $st->fetch());
        $r['ppv'] = $r['visitors'] ? round($r['pageviews'] / $r['visitors'], 1) : 0;
        return $r;
    };
    $cur = $kpi($from, $now + 1);
    $prev = $kpi($pFrom, $pTo);

    /* Série temporelle (cases vides comprises) ; visiteurs = somme des visiteurs uniques par site et par jour */
    $series = [];
    if ($bucket === 'hour') {
        $st = $db->prepare("SELECT h, SUM(v) v, SUM(p) p FROM (SELECT CAST(strftime('%H', ts, 'unixepoch', 'localtime') AS INTEGER) h, COUNT(DISTINCT vid) v, COUNT(*) p FROM hits WHERE $IN AND ts>=? AND kind='pv' GROUP BY site_id, h) GROUP BY h");
        $st->execute([$from]);
        $rows = []; foreach ($st->fetchAll() as $r) $rows[(int)$r['h']] = $r;
        $hNow = (int)date('G');
        for ($h = 0; $h <= $hNow; $h++) $series[] = ['label' => sprintf('%02d h', $h), 'key' => (string)$h, 'visitors' => (int)($rows[$h]['v'] ?? 0), 'pageviews' => (int)($rows[$h]['p'] ?? 0)];
    } else {
        $st = $db->prepare("SELECT day, SUM(v) v, SUM(p) p FROM (SELECT day, COUNT(DISTINCT vid) v, COUNT(*) p FROM hits WHERE $IN AND ts>=? AND kind='pv' GROUP BY site_id, day) GROUP BY day");
        $st->execute([$from]);
        $rows = []; foreach ($st->fetchAll() as $r) $rows[$r['day']] = $r;
        if ($bucket === 'day') {
            for ($t = $from; $t <= $now; $t = strtotime('+1 day', $t)) {
                $k = date('Y-m-d', $t);
                $series[] = ['label' => $k, 'key' => $k, 'visitors' => (int)($rows[$k]['v'] ?? 0), 'pageviews' => (int)($rows[$k]['p'] ?? 0)];
            }
        } else {
            $m = [];
            foreach ($rows as $k => $r) { $mk = substr($k, 0, 7); $m[$mk]['v'] = ($m[$mk]['v'] ?? 0) + $r['v']; $m[$mk]['p'] = ($m[$mk]['p'] ?? 0) + $r['p']; }
            for ($t = $from; $t <= $now; $t = strtotime('+1 month', $t)) {
                $k = date('Y-m', $t);
                $series[] = ['label' => $k, 'key' => $k, 'visitors' => (int)($m[$k]['v'] ?? 0), 'pageviews' => (int)($m[$k]['p'] ?? 0)];
            }
        }
    }

    $top = function (string $col, string $where, int $limit = 8, string $count = 'COUNT(*)') use ($db, $IN, $from): array {
        $st = $db->prepare("SELECT $col AS name, $count AS n FROM hits WHERE $IN AND ts>=? AND $where GROUP BY 1 ORDER BY n DESC LIMIT $limit");
        $st->execute([$from]);
        return array_map(fn($r) => ['name' => $r['name'], 'n' => (int)$r['n']], $st->fetchAll());
    };
    $visits = 'COUNT(DISTINCT site_id||day||vid)';
    $events = ['tel' => 0, 'mail' => 0, 'form' => 0];
    foreach ($top('ev', "kind='ev'", 5) as $r) if (isset($events[$r['name']])) $events[$r['name']] = $r['n'];

    $out = [
        'site' => $sc['site'], 'all' => $sc['all'],
        'range' => $range, 'bucket' => $bucket, 'kpi' => $cur, 'prev' => $prev, 'series' => $series,
        'sources' => $top('source', "kind='pv' AND source IS NOT NULL", 8, $visits),
        'pages' => $top($sc['all'] ? "(SELECT domain FROM sites WHERE id=hits.site_id)||path" : 'path', "kind='pv'", 10),
        'devices' => $top('device', "kind='pv'", 4, $visits),
        'browsers' => $top('browser', "kind='pv'", 6, $visits),
        'countries' => $top('country', "kind='pv'", 8, $visits),
        'events' => $events,
    ];

    /* Comparatif par site (vue d'ensemble administrateur) */
    if ($sc['all']) {
        $per = function (int $a, int $b) use ($db): array {
            $st = $db->prepare("SELECT site_id,
                SUM(CASE WHEN kind='pv' THEN 1 ELSE 0 END) p, SUM(CASE WHEN kind='ev' THEN 1 ELSE 0 END) c
                FROM hits WHERE ts>=? AND ts<? GROUP BY site_id");
            $st->execute([$a, $b]);
            $r = []; foreach ($st->fetchAll() as $x) $r[(int)$x['site_id']] = ['p' => (int)$x['p'], 'c' => (int)$x['c']];
            $st = $db->prepare("SELECT site_id, SUM(n) v FROM (SELECT site_id, COUNT(DISTINCT vid) n FROM hits WHERE ts>=? AND ts<? AND kind='pv' GROUP BY site_id, day) GROUP BY site_id");
            $st->execute([$a, $b]);
            foreach ($st->fetchAll() as $x) $r[(int)$x['site_id']]['v'] = (int)$x['v'];
            return $r;
        };
        $c = $per($from, $now + 1); $p = $per($pFrom, $pTo);
        $live = [];
        foreach ($db->query('SELECT site_id, COUNT(*) n FROM live WHERE ts>=' . ($now - 300) . ' GROUP BY site_id') as $x) $live[(int)$x['site_id']] = (int)$x['n'];
        $rows = [];
        foreach ($db->query('SELECT s.id, s.name, s.domain, s.user_id, u.name AS owner, u.role, (SELECT MAX(ts) FROM hits h WHERE h.site_id=s.id) last_hit FROM sites s LEFT JOIN users u ON u.id=s.user_id ORDER BY s.name') as $x) {
            $id = (int)$x['id'];
            $rows[] = ['id' => $id, 'name' => $x['name'], 'domain' => $x['domain'],
                'owner' => $x['role'] === 'client' ? $x['owner'] : ($x['role'] === 'admin' ? 'Vous (IZENINFO)' : null),
                'visitors' => $c[$id]['v'] ?? 0, 'pageviews' => $c[$id]['p'] ?? 0, 'contacts' => $c[$id]['c'] ?? 0,
                'prev_visitors' => $p[$id]['v'] ?? 0, 'live' => $live[$id] ?? 0, 'last_hit' => $x['last_hit'] ? (int)$x['last_hit'] : null];
        }
        usort($rows, fn($a, $b) => $b['visitors'] <=> $a['visitors'] ?: strcmp($a['name'], $b['name']));
        $out['per_site'] = $rows;
        $clients = (int)$db->query("SELECT COUNT(*) FROM users WHERE role='client'")->fetchColumn();
        $out['totals'] = ['sites' => count($rows), 'clients' => $clients, 'active' => count(array_filter($rows, fn($r) => $r['last_hit'] && $r['last_hit'] > $now - 7 * 86400))];
    }
    izs_json($out);
}

/* ---------- Administration (IZENINFO uniquement) ---------- */
if (!$isAdmin) izs_json(['error' => 'Accès réservé.'], 403);

$snippet = fn(string $key) => '<script defer src="https://izeninfo.fr/stats/t.js" data-site="' . $key . '"></script>';
$newKey = fn() => bin2hex(random_bytes(8));

if ($a === 'clients') {
    $users = $db->query("SELECT id,email,name,role,created,last_login FROM users ORDER BY role='admin' DESC, name")->fetchAll();
    $sites = $db->query('SELECT s.id,s.user_id,s.name,s.domain,s.skey,(SELECT MAX(ts) FROM hits h WHERE h.site_id=s.id) last_hit FROM sites s ORDER BY s.name')->fetchAll();
    foreach ($sites as &$x) $x['snippet'] = $snippet($x['skey']);
    izs_json(['users' => $users, 'sites' => $sites]);
}

if ($method !== 'POST') izs_json(['error' => 'Action inconnue.'], 400);

if ($a === 'create_client') {
    $email = strtolower(trim((string)($in['email'] ?? '')));
    $name = trim((string)($in['name'] ?? ''));
    $domain = izs_norm_domain((string)($in['domain'] ?? ''));
    $siteName = trim((string)($in['site_name'] ?? '')) ?: $name;
    if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) izs_json(['error' => 'Nom et e-mail valides requis.'], 422);
    if ($domain === '') izs_json(['error' => 'Nom de domaine invalide (ex. lentracte-auriol.fr).'], 422);
    $st = $db->prepare('SELECT COUNT(*) FROM users WHERE email=?'); $st->execute([$email]);
    if ($st->fetchColumn()) izs_json(['error' => 'Un compte existe déjà avec cet e-mail.'], 409);
    $pass = izs_gen_password();
    $db->beginTransaction();
    $db->prepare('INSERT INTO users(email,name,pass,role,created) VALUES(?,?,?,?,?)')->execute([$email, $name, password_hash($pass, PASSWORD_DEFAULT), 'client', time()]);
    $uid = (int)$db->lastInsertId();
    $key = $newKey();
    $db->prepare('INSERT INTO sites(user_id,name,domain,skey,created) VALUES(?,?,?,?,?)')->execute([$uid, $siteName, $domain, $key, time()]);
    $db->commit();
    izs_json(['ok' => true, 'email' => $email, 'password' => $pass, 'snippet' => $snippet($key)]);
}

if ($a === 'add_site') {
    $domain = izs_norm_domain((string)($in['domain'] ?? ''));
    $name = trim((string)($in['name'] ?? ''));
    $uid = (int)($in['user_id'] ?? 0);
    if ($domain === '' || $name === '') izs_json(['error' => 'Nom et domaine requis.'], 422);
    $st = $db->prepare('SELECT COUNT(*) FROM users WHERE id=?'); $st->execute([$uid]);
    if (!$st->fetchColumn()) izs_json(['error' => 'Client introuvable.'], 404);
    $key = $newKey();
    $db->prepare('INSERT INTO sites(user_id,name,domain,skey,created) VALUES(?,?,?,?,?)')->execute([$uid, $name, $domain, $key, time()]);
    izs_json(['ok' => true, 'snippet' => $snippet($key)]);
}

if ($a === 'reset_password') {
    $uid = (int)($in['user_id'] ?? 0);
    if ($uid === (int)$u['id']) izs_json(['error' => 'Utilisez « Mon mot de passe » pour votre propre compte.'], 422);
    $pass = izs_gen_password();
    $st = $db->prepare("UPDATE users SET pass=? WHERE id=? AND role='client'");
    $st->execute([password_hash($pass, PASSWORD_DEFAULT), $uid]);
    if (!$st->rowCount()) izs_json(['error' => 'Client introuvable.'], 404);
    izs_json(['ok' => true, 'password' => $pass]);
}

if ($a === 'delete_client') {
    $uid = (int)($in['user_id'] ?? 0);
    $db->beginTransaction();
    $db->prepare("UPDATE sites SET user_id=NULL WHERE user_id=(SELECT id FROM users WHERE id=? AND role='client')")->execute([$uid]);
    $st = $db->prepare("DELETE FROM users WHERE id=? AND role='client'");
    $st->execute([$uid]);
    $db->commit();
    if (!$st->rowCount()) izs_json(['error' => 'Client introuvable.'], 404);
    izs_json(['ok' => true]);
}

/* Donner l'accès d'un site référencé à un client (nouveau ou existant). */
if ($a === 'assign_client') {
    $sid = (int)($in['site_id'] ?? 0);
    $email = strtolower(trim((string)($in['email'] ?? '')));
    $name = trim((string)($in['name'] ?? ''));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) izs_json(['error' => 'E-mail invalide.'], 422);
    $st = $db->prepare('SELECT s.id, s.skey, s.user_id, u.role FROM sites s LEFT JOIN users u ON u.id=s.user_id WHERE s.id=?');
    $st->execute([$sid]);
    $site = $st->fetch();
    if (!$site) izs_json(['error' => 'Site introuvable.'], 404);
    if ($site['user_id'] && $site['role'] === 'client') izs_json(['error' => 'Ce site est déjà attribué à un client.'], 409);
    $st = $db->prepare('SELECT id, role FROM users WHERE email=?'); $st->execute([$email]);
    $existing = $st->fetch();
    $pass = null;
    if ($existing) {
        if ($existing['role'] !== 'client') izs_json(['error' => 'Cet e-mail est celui du compte administrateur.'], 422);
        $uid = (int)$existing['id'];
    } else {
        if ($name === '') izs_json(['error' => 'Nom du client requis.'], 422);
        $pass = izs_gen_password();
        $db->prepare('INSERT INTO users(email,name,pass,role,created) VALUES(?,?,?,?,?)')->execute([$email, $name, password_hash($pass, PASSWORD_DEFAULT), 'client', time()]);
        $uid = (int)$db->lastInsertId();
    }
    $db->prepare('UPDATE sites SET user_id=? WHERE id=?')->execute([$uid, $sid]);
    izs_json(['ok' => true, 'email' => $email, 'password' => $pass, 'snippet' => $snippet($site['skey'])]);
}

izs_json(['error' => 'Action inconnue.'], 400);
