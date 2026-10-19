<?php
/* IZENINFO – réception des mesures d'audience (sans cookie, sans IP stockée). */
define('IZS', 1);
require __DIR__ . '/../espace-client/_lib.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Cache-Control: no-store');
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit; }
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); exit; }

$raw = file_get_contents('php://input', false, null, 0, 4096);
$d = json_decode((string)$raw, true);
if (!is_array($d) || empty($d['k']) || !is_string($d['k'])) { http_response_code(400); exit; }

$ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 400);
if ($ua === '' || preg_match('/bot|crawl|spider|slurp|preview|headless|lighthouse|pingdom|uptime|monitor|curl|wget|python|httpclient/i', $ua)) { http_response_code(204); exit; }
if (!izs_rate('c', 240, 60)) { http_response_code(429); exit; }

$db = izs_db();
$st = $db->prepare('SELECT id, domain FROM sites WHERE skey=?');
$st->execute([$d['k']]);
$site = $st->fetch();
if (!$site) { http_response_code(204); exit; }

/* La mesure doit venir du domaine du site (ou de sa version www). */
$origin = $_SERVER['HTTP_ORIGIN'] ?? ($_SERVER['HTTP_REFERER'] ?? '');
$host = izs_norm_domain((string)parse_url($origin, PHP_URL_HOST));
if ($host !== $site['domain'] && !str_ends_with($host, '.' . $site['domain'])) { http_response_code(204); exit; }

$now = time();
$vid = substr(hash('sha256', izs_salt() . '|' . $site['id'] . '|' . izs_client_ip() . '|' . $ua), 0, 20);
$kind = in_array($d['t'] ?? '', ['pv', 'hb', 'ev'], true) ? $d['t'] : 'pv';
$path = substr(preg_replace('/[?#].*$/', '', (string)($d['p'] ?? '/')) ?: '/', 0, 200);

/* Présence en direct */
$db->prepare('INSERT INTO live(site_id,vid,path,ts) VALUES(?,?,?,?) ON CONFLICT(site_id,vid) DO UPDATE SET path=excluded.path, ts=excluded.ts')
   ->execute([$site['id'], $vid, $path, $now]);
if ($kind === 'hb') { http_response_code(204); exit; }

/* Provenance */
$ref = '';
$source = 'Accès direct';
$r = (string)($d['r'] ?? '');
$utm = substr((string)($d['u'] ?? ''), 0, 60);
if ($r !== '') {
    $rh = izs_norm_domain((string)parse_url($r, PHP_URL_HOST));
    if ($rh !== '' && $rh !== $site['domain'] && !str_ends_with($rh, '.' . $site['domain'])) {
        $ref = $rh;
        $map = [
            'Google' => '/(^|\.)google\./', 'Bing' => '/(^|\.)bing\.com$/', 'Yahoo' => '/(^|\.)yahoo\./', 'DuckDuckGo' => '/duckduckgo\.com$/',
            'Ecosia' => '/ecosia\.org$/', 'Qwant' => '/qwant\.com$/', 'Facebook' => '/(facebook\.com|fb\.me|fb\.com)$/', 'Instagram' => '/instagram\.com$/',
            'LinkedIn' => '/(linkedin\.com|lnkd\.in)$/', 'X / Twitter' => '/(twitter\.com|t\.co|x\.com)$/', 'TikTok' => '/tiktok\.com$/',
            'YouTube' => '/(youtube\.com|youtu\.be)$/', 'Pinterest' => '/pinterest\./', 'Google Maps' => '/maps\.google\./', 'IZENINFO' => '/izeninfo\.fr$/',
        ];
        $source = $rh;
        foreach ($map as $name => $re) { if (preg_match($re, $rh)) { $source = $name; break; } }
    }
}
if ($utm !== '') $source = ucfirst(strtolower(preg_replace('/[^\w .-]/u', '', $utm)));
if (!empty($d['i'])) { $source = null; $ref = ''; } /* navigation interne : pas une nouvelle entrée */

/* Appareil et navigateur (déduits, non stockés tels quels) */
$device = preg_match('/iPad|Tablet|PlayBook|Silk|Kindle/i', $ua) || (preg_match('/Android/i', $ua) && !preg_match('/Mobile/i', $ua)) ? 'Tablette'
        : (preg_match('/Mobi|iPhone|iPod|Android|Windows Phone/i', $ua) ? 'Mobile' : 'Ordinateur');
$browser = preg_match('/Edg\//', $ua) ? 'Edge' : (preg_match('/OPR\/|Opera/', $ua) ? 'Opera' : (preg_match('/SamsungBrowser/', $ua) ? 'Samsung Internet'
         : (preg_match('/Firefox\//', $ua) ? 'Firefox' : (preg_match('/Chrome\//', $ua) ? 'Chrome' : (preg_match('/Safari\//', $ua) ? 'Safari' : 'Autre')))));

/* Pays : déduit du fuseau horaire du navigateur (aucune géolocalisation par IP). */
$tz = (string)($d['z'] ?? '');
$tzmap = ['Europe/Paris' => 'France', 'Europe/Brussels' => 'Belgique', 'Europe/Zurich' => 'Suisse', 'Europe/Luxembourg' => 'Luxembourg', 'Europe/Monaco' => 'Monaco',
    'Europe/Madrid' => 'Espagne', 'Atlantic/Canary' => 'Espagne', 'Europe/Rome' => 'Italie', 'Europe/Berlin' => 'Allemagne', 'Europe/London' => 'Royaume-Uni',
    'Europe/Lisbon' => 'Portugal', 'Europe/Amsterdam' => 'Pays-Bas', 'Europe/Vienna' => 'Autriche', 'Europe/Dublin' => 'Irlande', 'America/Montreal' => 'Canada',
    'America/Toronto' => 'Canada', 'America/New_York' => 'États-Unis', 'America/Chicago' => 'États-Unis', 'America/Los_Angeles' => 'États-Unis',
    'Africa/Casablanca' => 'Maroc', 'Africa/Algiers' => 'Algérie', 'Africa/Tunis' => 'Tunisie', 'Indian/Reunion' => 'La Réunion', 'America/Martinique' => 'Martinique',
    'America/Guadeloupe' => 'Guadeloupe', 'Pacific/Tahiti' => 'Polynésie française', 'Indian/Mayotte' => 'Mayotte', 'America/Cayenne' => 'Guyane'];
$country = $tzmap[$tz] ?? ($tz !== '' ? 'Autre' : 'Inconnu');

$ev = null;
if ($kind === 'ev') {
    $ev = in_array($d['e'] ?? '', ['tel', 'mail', 'form', 'out'], true) ? $d['e'] : null;
    if (!$ev) { http_response_code(204); exit; }
}

$db->prepare('INSERT INTO hits(site_id,ts,day,vid,kind,path,source,ref,device,browser,country,ev) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)')
   ->execute([$site['id'], $now, date('Y-m-d', $now), $vid, $kind, $path, $kind === 'pv' ? $source : null, $kind === 'pv' ? $ref : null, $device, $browser, $country, $ev]);
izs_purge();
http_response_code(204);
