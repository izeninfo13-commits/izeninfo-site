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

/* Visite en cours (s'il y en a une) : canal d'arrivée et ville, déjà calculés à l'entrée */
$st = $db->prepare('SELECT ch, city FROM live WHERE site_id=? AND vid=? AND ts>=?');
$st->execute([$site['id'], $vid, $now - 1800]);
$cur = $st->fetch() ?: null;

/* Provenance détaillée et canal */
$ref = '';
$source = 'Accès direct';
$ch = 'Accès direct';
$camp = null;
$r = (string)($d['r'] ?? '');
$q = is_array($d['q'] ?? null) ? $d['q'] : [];
$qs = fn(string $k) => substr(trim((string)($q[$k] ?? '')), 0, 80);
$us = strtolower($qs('us') ?: (string)($d['u'] ?? ''));
$um = strtolower($qs('um'));
$uc = $qs('uc');
$rh = '';
if ($r !== '') {
    $rh = izs_norm_domain((string)parse_url($r, PHP_URL_HOST));
    if ($rh === $site['domain'] || str_ends_with($rh, '.' . $site['domain'])) $rh = '';
}
$engines = ['Google' => '/(^|\.)google\./', 'Bing' => '/(^|\.)bing\.com$/', 'Yahoo' => '/(^|\.)yahoo\./', 'DuckDuckGo' => '/duckduckgo\.com$/',
    'Ecosia' => '/ecosia\.org$/', 'Qwant' => '/qwant\.com$/', 'Brave' => '/search\.brave\.com$/'];
$socials = ['Facebook' => '/(facebook\.com|fb\.me|fb\.com)$/', 'Instagram' => '/instagram\.com$/', 'LinkedIn' => '/(linkedin\.com|lnkd\.in)$/',
    'X / Twitter' => '/(twitter\.com|t\.co|x\.com)$/', 'TikTok' => '/tiktok\.com$/', 'YouTube' => '/(youtube\.com|youtu\.be)$/', 'Pinterest' => '/pinterest\./', 'Snapchat' => '/snapchat\.com$/'];
$match = function (string $h, array $map) { foreach ($map as $n => $re) if (preg_match($re, $h)) return $n; return null; };
$paidMedium = preg_match('/^(cpc|ppc|paid|paidsearch|paid_search|sem|cpm|display|ads?)$/', $um);

if ($qs('gclid') !== '' || $qs('gbraid') !== '' || $qs('wbraid') !== '' || $qs('gad') !== '' || ($paidMedium && ($us === '' || str_contains($us, 'google')))) {
    $ch = 'Google Ads (payant)'; $source = 'Google Ads';
    $camp = $uc !== '' ? $uc : ($qs('gad') !== '' || $qs('gclid') !== '' ? 'Google Ads (campagne non nommée)' : null);
} elseif ($qs('msclkid') !== '' || ($paidMedium && preg_match('/bing|microsoft/', $us))) {
    $ch = 'Autres publicités'; $source = 'Microsoft Ads'; $camp = $uc ?: null;
} elseif ($paidMedium) {
    $ch = 'Autres publicités'; $source = ucfirst($us ?: 'Publicité'); $camp = $uc ?: null;
} elseif (preg_match('/^(gbp|gmb|maps|local|fiche|business|google_business)/', $um . $us) || preg_match('/gbp|gmb|fiche|maps/i', $uc)
          || preg_match('/(^|\.)maps\.google\./', $rh) || preg_match('~google\.[a-z.]+/maps~', $r)) {
    $ch = 'Fiche Google (Maps)'; $source = 'Fiche Google'; $camp = $uc ?: null;
} elseif ($um === 'email' || $um === 'e-mail' || $um === 'newsletter') {
    $ch = 'E-mail'; $source = ucfirst($us ?: 'E-mail'); $camp = $uc ?: null;
} elseif ($rh !== '' && ($e = $match($rh, $engines))) {
    $ch = $e === 'Google' ? 'Google (naturel)' : 'Autres moteurs'; $source = $e; $ref = $rh;
} elseif (($rh !== '' && ($sn = $match($rh, $socials))) || $qs('fbclid') !== '' || preg_match('/facebook|instagram|linkedin|tiktok|twitter|youtube|pinterest/', $us)) {
    $ch = 'Réseaux sociaux'; $source = $sn ?? ($qs('fbclid') !== '' ? 'Facebook' : ucfirst($us)); $ref = $rh; $camp = $uc ?: null;
} elseif ($rh !== '') {
    $ch = 'Sites référents'; $source = $rh === 'izeninfo.fr' ? 'IZENINFO' : $rh; $ref = $rh;
} elseif ($us !== '') {
    $ch = 'Campagnes (UTM)'; $source = ucfirst($us); $camp = $uc ?: null;
}

$internal = !empty($d['i']) || $kind !== 'pv';
if ($internal) { $source = null; $ref = ''; $camp = null; $ch = $cur['ch'] ?? null; }

/* Ville : déterminée une fois par visite, à partir de l'adresse IP (jamais stockée). */
$city = $cur['city'] ?? null; $region = null; $cc = null;
if (!$cur) {
    $g = izs_geo(izs_client_ip());
    if ($g) { $city = $g['city'] ?: null; $region = $g['region'] ?: null; $cc = $g['cc']; }
}
if (!$city) $city = null;

/* Présence en direct */
$db->prepare('INSERT INTO live(site_id,vid,path,ts,city,ch) VALUES(?,?,?,?,?,?) ON CONFLICT(site_id,vid) DO UPDATE SET path=excluded.path, ts=excluded.ts,
    city=COALESCE(live.city, excluded.city), ch=CASE WHEN ? THEN live.ch ELSE excluded.ch END')
   ->execute([$site['id'], $vid, $path, $now, $city, $ch ?? 'Accès direct', $internal ? 1 : 0]);
if ($kind === 'hb') { http_response_code(204); exit; }
if ($ch === null) $ch = 'Accès direct';
if ($city && !$region) {
    $st = $db->prepare('SELECT region FROM hits WHERE site_id=? AND day=? AND vid=? AND region IS NOT NULL LIMIT 1');
    $st->execute([$site['id'], date('Y-m-d', $now), $vid]);
    $region = $st->fetchColumn() ?: null;
}

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
$ccmap = ['FR' => 'France', 'BE' => 'Belgique', 'CH' => 'Suisse', 'LU' => 'Luxembourg', 'MC' => 'Monaco'];
if ($cc && isset($ccmap[$cc])) $country = $ccmap[$cc];

$ev = null;
if ($kind === 'ev') {
    $ev = in_array($d['e'] ?? '', ['tel', 'mail', 'form', 'out'], true) ? $d['e'] : null;
    if (!$ev) { http_response_code(204); exit; }
}

$db->prepare('INSERT INTO hits(site_id,ts,day,vid,kind,path,source,ref,device,browser,country,ev,city,region,ch,camp) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
   ->execute([$site['id'], $now, date('Y-m-d', $now), $vid, $kind, $path, $kind === 'pv' ? $source : null, $kind === 'pv' ? $ref : null, $device, $browser, $country, $ev,
              $city, $region, $ch, $camp]);
izs_purge();
http_response_code(204);
