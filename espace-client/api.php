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
    /* Empreinte des données : change dès qu'une visite, une page vue ou un contact arrive. */
    $rev = (int)$db->query("SELECT COALESCE(MAX(id),0) FROM hits WHERE $IN")->fetchColumn();
    $cities = $db->query("SELECT COALESCE(city,'Ville non identifiée') AS name, COUNT(*) n FROM live WHERE $IN AND ts>=$since GROUP BY 1 ORDER BY n DESC, name LIMIT 8")->fetchAll();
    $chans = $db->query("SELECT COALESCE(ch,'Accès direct') AS name, COUNT(*) n FROM live WHERE $IN AND ts>=$since GROUP BY 1 ORDER BY n DESC LIMIT 6")->fetchAll();
    /* Carte : position approximative des visiteurs en ligne (ville, jamais l'adresse IP). */
    $geo = izs_coords($db->query("SELECT city, (SELECT h.region FROM hits h WHERE h.site_id=live.site_id AND h.vid=live.vid AND h.region IS NOT NULL ORDER BY h.ts DESC LIMIT 1) AS region,
        COUNT(*) n FROM live WHERE $IN AND ts>=$since AND city IS NOT NULL GROUP BY 1, 2 ORDER BY n DESC LIMIT 60")->fetchAll(), 'FR');
    izs_json(['count' => $count, 'pages' => $pages, 'cities' => $cities, 'channels' => $chans, 'geo' => $geo, 'rev' => $rev, 'at' => time()]);
}

if ($a === 'stats' || $a === 'csv') {
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

    /* Canal d'arrivée (données antérieures : déduit de la source) */
    $CH = "COALESCE(ch, CASE WHEN source IS NULL THEN 'Non attribué' WHEN source='Accès direct' THEN 'Accès direct' WHEN source='Google' THEN 'Google (naturel)'
        WHEN source IN ('Bing','Yahoo','DuckDuckGo','Ecosia','Qwant') THEN 'Autres moteurs' WHEN source='Google Maps' THEN 'Fiche Google (Maps)'
        WHEN source IN ('Facebook','Instagram','LinkedIn','X / Twitter','TikTok','YouTube','Pinterest') THEN 'Réseaux sociaux' ELSE 'Sites référents' END)";
    $st = $db->prepare("SELECT $CH AS name,
        COUNT(DISTINCT CASE WHEN kind='pv' THEN site_id||day||vid END) AS visits,
        SUM(CASE WHEN kind='pv' THEN 1 ELSE 0 END) AS pages,
        SUM(CASE WHEN kind='ev' THEN 1 ELSE 0 END) AS contacts,
        COUNT(DISTINCT CASE WHEN kind='ev' THEN site_id||day||vid END) AS converted
        FROM hits WHERE $IN AND ts>=? GROUP BY 1 ORDER BY visits DESC");
    $st->execute([$from]);
    $channels = array_values(array_filter(array_map(fn($r) => ['name' => $r['name'], 'visits' => (int)$r['visits'], 'pages' => (int)$r['pages'],
        'contacts' => (int)$r['contacts'], 'converted' => (int)$r['converted']], $st->fetchAll()), fn($r) => $r['visits'] || $r['contacts']));

    $cityCol = "city||CASE WHEN region IS NOT NULL AND region<>'' THEN ' · '||region ELSE '' END";
    $cities = $top($cityCol, "kind='pv' AND city IS NOT NULL", 12, $visits);
    $st = $db->prepare("SELECT city, region, $visits AS n FROM hits WHERE $IN AND ts>=? AND kind='pv' AND city IS NOT NULL GROUP BY city, region ORDER BY n DESC LIMIT 250");
    $st->execute([$from]);
    $geo = izs_coords($st->fetchAll(), 'FR'); /* carte ciblée sur la France */
    $unknownCity = (int)$db->query("SELECT COUNT(DISTINCT site_id||day||vid) FROM hits WHERE $IN AND ts>=$from AND kind='pv' AND city IS NULL")->fetchColumn();

    /* Google Ads : visites payantes, contacts obtenus, coût si le budget est renseigné */
    $ADS = "ch='Google Ads (payant)'";
    $ads = null;
    if (!$sc['all']) {
        $q1 = $db->prepare("SELECT COUNT(DISTINCT CASE WHEN kind='pv' THEN day||vid END) v, SUM(CASE WHEN kind='pv' THEN 1 ELSE 0 END) p,
            SUM(CASE WHEN kind='ev' THEN 1 ELSE 0 END) c, COUNT(DISTINCT CASE WHEN kind='ev' THEN day||vid END) cv,
            COUNT(DISTINCT CASE WHEN kind='pv' AND device='Mobile' THEN day||vid END) mob FROM hits WHERE $IN AND ts>=? AND $ADS");
        $q1->execute([$from]); $A = array_map('intval', $q1->fetch());
        $q2 = $db->prepare("SELECT COUNT(DISTINCT CASE WHEN kind='pv' THEN day||vid END) v, COUNT(DISTINCT CASE WHEN kind='ev' THEN day||vid END) cv,
            SUM(CASE WHEN kind='pv' THEN 1 ELSE 0 END) p FROM hits WHERE $IN AND ts>=? AND ($CH)<>'Google Ads (payant)'");
        $q2->execute([$from]); $O = array_map('intval', $q2->fetch());
        $sub = function (string $col, string $where, int $n = 6) use ($db, $IN, $from, $ADS): array {
            $st = $db->prepare("SELECT $col AS name, COUNT(DISTINCT day||vid) AS n FROM hits WHERE $IN AND ts>=? AND $ADS AND $where GROUP BY 1 ORDER BY n DESC LIMIT $n");
            $st->execute([$from]);
            return array_map(fn($r) => ['name' => $r['name'], 'n' => (int)$r['n']], $st->fetchAll());
        };
        $budget = (int)$db->query('SELECT COALESCE(ads_budget,0) FROM sites WHERE id=' . (int)$sc['ids'][0])->fetchColumn();
        $days = max(1, (int)ceil(($now - $from) / 86400));
        $spent = $budget ? round($budget * 12 / 365 * $days, 2) : null;
        $adsSeries = [];
        if ($A['v']) {
            $st = $db->prepare("SELECT day, COUNT(DISTINCT vid) n FROM hits WHERE $IN AND ts>=? AND $ADS AND kind='pv' GROUP BY day");
            $st->execute([$from]); foreach ($st->fetchAll() as $r) $adsSeries[$r['day']] = (int)$r['n'];
        }
        $ads = [
            'visits' => $A['v'], 'pages' => $A['p'], 'contacts' => $A['c'], 'converted' => $A['cv'], 'mobile' => $A['mob'],
            'ppv' => $A['v'] ? round($A['p'] / $A['v'], 1) : 0,
            'rate' => $A['v'] ? round($A['cv'] / $A['v'] * 100, 1) : 0,
            'other_rate' => $O['v'] ? round($O['cv'] / $O['v'] * 100, 1) : 0,
            'other_ppv' => $O['v'] ? round($O['p'] / $O['v'], 1) : 0,
            'share' => ($A['v'] + $O['v']) ? round($A['v'] / ($A['v'] + $O['v']) * 100, 1) : 0,
            'campaigns' => $sub("COALESCE(camp,'Campagne non nommée')", "kind='pv' AND source IS NOT NULL"),
            'cities' => $sub($cityCol, "kind='pv' AND city IS NOT NULL"),
            'landing' => $sub('path', "kind='pv' AND source IS NOT NULL"),
            'budget' => $budget ?: null, 'spent' => $spent, 'days' => $days,
            'cpv' => $spent && $A['v'] ? round($spent / $A['v'], 2) : null,
            'cpc' => $spent && $A['cv'] ? round($spent / $A['cv'], 2) : null,
            'series' => $adsSeries,
        ];
    }

    /* Accès directs : indice le plus probable pour chaque arrivée sans provenance.
       Données antérieures au détail : déduites de la page d'arrivée et du pays. */
    $DX = "COALESCE(dx, CASE WHEN country='Non mesuré' THEN 'Historique importé (journaux du serveur)'
        WHEN country='Autre' AND city IS NULL THEN 'Robot probable (hors zone, avant filtrage)'
        WHEN path IN ('/','/index.html','/index.php') THEN 'Adresse tapée, favori ou QR code'
        ELSE 'Lien partagé (messagerie, e-mail, PDF…) ou favori' END)";
    $DW = "kind='pv' AND ($CH)='Accès direct' AND (source='Accès direct' OR dx IS NOT NULL)";
    $dTot = (int)$db->query("SELECT COUNT(DISTINCT site_id||day||vid) FROM hits WHERE $IN AND ts>=$from AND $DW")->fetchColumn();
    $direct = $dTot ? [
        'total' => $dTot,
        'kinds' => $top($DX, $DW, 8, $visits),
        'pages' => $top($sc['all'] ? "(SELECT domain FROM sites WHERE id=hits.site_id)||path" : 'path', $DW, 6, $visits),
        'devices' => $top('device', $DW, 3, $visits),
        'hours' => $top("CAST(strftime('%H', ts, 'unixepoch', 'localtime') AS INTEGER)", $DW, 24, $visits),
    ] : null;

    /* ---------- Engagement réel : rebond, temps visible, lecture, pages d'entrée et de sortie ---------- */
    $PREF = fn(string $t) => $sc['all'] ? "(SELECT domain FROM sites WHERE id=$t.site_id)||$t.path" : "$t.path";
    $KEY = "(lower(path) LIKE '%contact%' OR lower(path) LIKE '%devis%' OR lower(path) LIKE '%tarif%' OR lower(path) LIKE '%prix%'
        OR lower(path) LIKE '%reserv%' OR lower(path) LIKE '%réserv%' OR lower(path) LIKE '%rdv%' OR lower(path) LIKE '%rendez%' OR lower(path) LIKE '%booking%'
        OR lower(path) LIKE '%commande%' OR lower(path) LIKE '%panier%' OR lower(path) LIKE '%cart%' OR lower(path) LIKE '%checkout%' OR lower(path) LIKE '%boutique%')";
    $CTE = "v AS (SELECT site_id, day, vid, COUNT(*) pv, MIN(ts) t0, MAX(ts) t1, MAX(CASE WHEN $KEY THEN 1 ELSE 0 END) k
                  FROM hits WHERE $IN AND ts>=:a AND ts<:b AND kind='pv' GROUP BY site_id, day, vid),
            c AS (SELECT DISTINCT site_id, day, vid FROM hits WHERE $IN AND ts>=:a AND ts<:b AND kind='ev'),
            e AS (SELECT site_id, day, vid, SUM(dur) d FROM eng WHERE $IN AND ts>=:a AND ts<:b + 3600 GROUP BY site_id, day, vid),
            x AS (SELECT v.*, CASE WHEN c.vid IS NULL THEN 0 ELSE 1 END conv, e.d d, CASE WHEN e.vid IS NULL THEN 0 ELSE 1 END m,
                  CASE WHEN c.vid IS NOT NULL OR v.pv>=2 OR e.d>=10000 THEN 1 ELSE 0 END en
                  FROM v LEFT JOIN c ON c.site_id=v.site_id AND c.day=v.day AND c.vid=v.vid
                         LEFT JOIN e ON e.site_id=v.site_id AND e.day=v.day AND e.vid=v.vid)";
    $agg = function (int $a, int $b) use ($db, $CTE): array {
        $st = $db->prepare("WITH $CTE SELECT COUNT(*) visits, COALESCE(SUM(pv=1 AND conv=0),0) bounces, COALESCE(SUM(m),0) measured,
            COALESCE(SUM(CASE WHEN m=1 THEN d ELSE 0 END),0) dur, COALESCE(SUM(en),0) engaged, COALESCE(SUM(conv),0) converted,
            COALESCE(SUM(en=1 AND (k=1 OR conv=1)),0) keyv FROM x");
        $st->execute([':a' => $a, ':b' => $b]);
        $r = array_map('intval', $st->fetch());
        $r['bounce_rate'] = $r['visits'] ? round($r['bounces'] / $r['visits'] * 100, 1) : null;
        $r['engaged_rate'] = $r['visits'] ? round($r['engaged'] / $r['visits'] * 100, 1) : null;
        $r['conv_rate'] = $r['visits'] ? round($r['converted'] / $r['visits'] * 100, 2) : null;
        $r['avg_time'] = $r['measured'] ? (int)round($r['dur'] / $r['measured'] / 1000) : null;
        return $r;
    };
    $E = $agg($from, $now + 1); $Ep = $agg($pFrom, $pTo);
    $st = $db->prepare("SELECT AVG(scroll) s, COUNT(*) n FROM eng WHERE $IN AND ts>=? AND scroll>0");
    $st->execute([$from]); $sr = $st->fetch();
    $E['scroll'] = $sr['n'] ? (int)round($sr['s']) : null;
    $st = $db->prepare("SELECT AVG(scroll) s, COUNT(*) n FROM eng WHERE $IN AND ts>=? AND ts<? AND scroll>0");
    $st->execute([$pFrom, $pTo]); $sr = $st->fetch();
    $Ep['scroll'] = $sr['n'] ? (int)round($sr['s']) : null;
    $since = $db->query("SELECT MIN(ts) FROM eng WHERE $IN")->fetchColumn();

    $edge = function (string $which) use ($db, $CTE, $from, $now, $PREF): array {
        $t = $which === 'entry' ? 'h.ts=x.t0 ORDER BY h.id' : 'h.ts=x.t1 ORDER BY h.id DESC';
        $st = $db->prepare("WITH $CTE SELECT name, COUNT(*) n, SUM(pv=1 AND conv=0) b, SUM(conv) c, SUM(en) en FROM (
            SELECT x.*, (SELECT {$PREF('h')} FROM hits h WHERE h.site_id=x.site_id AND h.day=x.day AND h.vid=x.vid AND h.kind='pv' AND $t LIMIT 1) name FROM x)
            GROUP BY name ORDER BY n DESC LIMIT 25");
        $st->execute([':a' => $from, ':b' => $now + 1]);
        return array_map(fn($r) => ['name' => $r['name'], 'n' => (int)$r['n'], 'bounce' => $r['n'] ? round($r['b'] / $r['n'] * 100, 1) : 0,
            'contacts' => (int)$r['c'], 'rate' => $r['n'] ? round($r['c'] / $r['n'] * 100, 1) : 0], $st->fetchAll());
    };
    $entries = $edge('entry'); $exits = $edge('exit');
    $exitMap = []; foreach ($exits as $r) $exitMap[$r['name']] = $r['n'];
    $st = $db->prepare("SELECT {$PREF('hits')} AS name, COUNT(*) n FROM hits WHERE $IN AND ts>=? AND kind='pv' GROUP BY 1 ORDER BY n DESC LIMIT 12");
    $st->execute([$from]); $pv = $st->fetchAll();
    $st = $db->prepare("SELECT {$PREF('eng')} AS name, AVG(NULLIF(dur,0)) t, AVG(NULLIF(scroll,0)) s, COUNT(*) n FROM eng WHERE $IN AND ts>=? GROUP BY 1");
    $st->execute([$from]); $em = []; foreach ($st->fetchAll() as $r) $em[$r['name']] = $r;
    $pagesEng = array_map(fn($r) => ['name' => $r['name'], 'views' => (int)$r['n'],
        'time' => isset($em[$r['name']]) && $em[$r['name']]['t'] !== null ? (int)round($em[$r['name']]['t'] / 1000) : null,
        'scroll' => isset($em[$r['name']]) && $em[$r['name']]['s'] !== null ? (int)round($em[$r['name']]['s']) : null,
        'exit' => $r['n'] ? min(100, round(($exitMap[$r['name']] ?? 0) / $r['n'] * 100, 1)) : 0], $pv);

    /* ---------- Entonnoir de contact et valeur estimée ---------- */
    $leadValue = $sc['all'] ? null : ((int)$db->query('SELECT COALESCE(lead_value,0) FROM sites WHERE id=' . (int)$sc['ids'][0])->fetchColumn() ?: null);
    $funnel = [
        'steps' => [
            ['name' => 'Visites', 'n' => $E['visits'], 'hint' => 'Toutes les visites de la période'],
            ['name' => 'Visites engagées', 'n' => $E['engaged'], 'hint' => '2 pages ou plus, 10 s de lecture ou un contact'],
            ['name' => 'Intérêt commercial', 'n' => $E['keyv'], 'hint' => 'Page contact, devis, tarifs, réservation ou boutique consultée (ou contact)'],
            ['name' => 'Contact', 'n' => $E['converted'], 'hint' => 'Appel, e-mail ou formulaire envoyé'],
        ],
        'prev_converted' => $Ep['converted'], 'prev_visits' => $Ep['visits'],
        'lead_value' => $leadValue,
        'value' => $leadValue ? $E['converted'] * $leadValue : null,
        'prev_value' => $leadValue ? $Ep['converted'] * $leadValue : null,
    ];

    /* ---------- Santé du site : Core Web Vitals mesurés chez les visiteurs, erreurs ---------- */
    $TH = ['lcp' => [2500, 4000, 'ms'], 'inp' => [200, 500, 'ms'], 'cls' => [0.1, 0.25, ''], 'fcp' => [1800, 3000, 'ms'], 'ttfb' => [800, 1800, 'ms']];
    $metric = function (string $col, string $extra = '') use ($db, $IN, $from, $TH): ?array {
        [$g, $p] = $TH[$col];
        $st = $db->prepare("SELECT COUNT($col) n, SUM($col<=$g) g, SUM($col>$g AND $col<=$p) ni, SUM($col>$p) po FROM eng WHERE $IN AND ts>=? AND $col IS NOT NULL $extra");
        $st->execute([$from]); $r = $st->fetch();
        $n = (int)$r['n']; if (!$n) return null;
        $st = $db->prepare("SELECT $col FROM eng WHERE $IN AND ts>=? AND $col IS NOT NULL $extra ORDER BY $col LIMIT 1 OFFSET " . (int)floor(($n - 1) * .75));
        $st->execute([$from]); $p75 = $st->fetchColumn() + 0;
        return ['n' => $n, 'p75' => $p75, 'good' => round($r['g'] / $n * 100), 'ni' => round($r['ni'] / $n * 100), 'poor' => round($r['po'] / $n * 100)];
    };
    $score = function (?array $m, string $k) use ($TH): ?int {
        if (!$m) return null; [$g, $p] = $TH[$k]; $v = $m['p75'];
        if ($v <= $g) return (int)round(90 + 10 * (1 - $v / $g));
        if ($v <= $p) return (int)round(50 + 40 * ($p - $v) / ($p - $g));
        return (int)max(0, round(50 * (1 - ($v - $p) / $p)));
    };
    $H = []; foreach (array_keys($TH) as $k) $H[$k] = $metric($k);
    $H['lcp_mobile'] = $metric('lcp', "AND device='Mobile'");
    $W = ['lcp' => .35, 'inp' => .3, 'cls' => .25, 'ttfb' => .1]; $sum = 0; $wt = 0;
    foreach ($W as $k => $w) { $s = $score($H[$k], $k); if ($s !== null) { $sum += $s * $w; $wt += $w; $H[$k]['score'] = $s; } }
    $st = $db->prepare("SELECT {$PREF('eng')} AS name, status, COUNT(*) n FROM eng WHERE $IN AND ts>=? AND status>=400 GROUP BY 1, 2 ORDER BY n DESC LIMIT 12");
    $st->execute([$from]); $errs = array_map(fn($r) => ['name' => $r['name'], 'status' => (int)$r['status'], 'n' => (int)$r['n']], $st->fetchAll());
    $st = $db->prepare("SELECT COUNT(*) FROM eng WHERE $IN AND ts>=?"); $st->execute([$from]); $measuredPages = (int)$st->fetchColumn();
    $errN = array_sum(array_column($errs, 'n'));
    $overall = $wt ? $sum / $wt : null;
    if ($overall !== null && $measuredPages) $overall = max(0, $overall - min(15, $errN / $measuredPages * 100 * 1.5));
    $health = ['score' => $overall !== null ? (int)round($overall) : null, 'metrics' => $H, 'errors' => $errs, 'error_rate' => $measuredPages ? round($errN / $measuredPages * 100, 1) : 0,
        'pages' => $measuredPages];

    $out = [
        'engagement' => $E, 'engagement_prev' => $Ep, 'eng_since' => $since ? (int)$since : null,
        'entries' => array_slice($entries, 0, 10), 'exits' => array_slice($exits, 0, 10), 'pages_eng' => $pagesEng,
        'funnel' => $funnel, 'health' => $health,
        'from' => $from, 'to' => $now,
        'direct' => $direct,
        'channels' => $channels, 'cities' => $cities, 'geo' => $geo, 'unknown_city' => $unknownCity, 'ads' => $ads,
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
    if ($a === 'csv') izs_csv($out, (string)($_GET['type'] ?? 'days'));
    izs_json($out);
}

/* Valeur moyenne d'un contact (en euros), pour estimer la valeur générée par le site */
if ($a === 'set_value' && $method === 'POST') {
    $s = izs_site_or_403($u, $in['site_id'] ?? 0);
    $v = max(0, min(1000000, (int)round((float)str_replace(',', '.', (string)($in['value'] ?? 0)))));
    $db->prepare('UPDATE sites SET lead_value=? WHERE id=?')->execute([$v ?: null, $s['id']]);
    izs_json(['ok' => true, 'value' => $v]);
}

/* Budget Google Ads mensuel du site (renseigné par le client ou par IZENINFO) */
if ($a === 'set_budget' && $method === 'POST') {
    $s = izs_site_or_403($u, $in['site_id'] ?? 0);
    $b = max(0, min(1000000, (int)round((float)str_replace(',', '.', (string)($in['budget'] ?? 0)))));
    $db->prepare('UPDATE sites SET ads_budget=? WHERE id=?')->execute([$b ?: null, $s['id']]);
    izs_json(['ok' => true, 'budget' => $b]);
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
