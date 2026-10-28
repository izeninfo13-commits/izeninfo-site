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
/* Engagement et vitesse d'une page vue (une ligne par affichage, mise à jour à chaque envoi). */
if (($d['t'] ?? '') === 'en') {
    $x = preg_replace('/[^a-z0-9]/', '', strtolower((string)($d['x'] ?? '')));
    if (strlen($x) < 8) { http_response_code(204); exit; }
    $p = substr(preg_replace('/[?#].*$/', '', (string)($d['p'] ?? '/')) ?: '/', 0, 200);
    $num = fn($a, $k, $max) => isset($a[$k]) && is_numeric($a[$k]) ? max(0, min($max, $a[$k] + 0)) : null;
    $v = is_array($d['v'] ?? null) ? $d['v'] : [];
    $dev = preg_match('/iPad|Tablet|PlayBook|Silk|Kindle/i', $ua) || (preg_match('/Android/i', $ua) && !preg_match('/Mobile/i', $ua)) ? 'Tablette'
         : (preg_match('/Mobi|iPhone|iPod|Android|Windows Phone/i', $ua) ? 'Mobile' : 'Ordinateur');
    $lcp = $num($v, 'lcp', 120000); $cls = $num($v, 'cls', 10); $inp = $num($v, 'inp', 60000); $fcp = $num($v, 'fcp', 120000); $ttfb = $num($v, 'ttfb', 120000);
    $stt = $num($d, 'st', 599);
    $db->prepare('INSERT INTO eng(site_id,pvid,ts,day,vid,path,dur,scroll,lcp,cls,inp,fcp,ttfb,status,device) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ON CONFLICT(site_id,pvid) DO UPDATE SET dur=MAX(eng.dur,excluded.dur), scroll=MAX(eng.scroll,excluded.scroll),
        lcp=COALESCE(excluded.lcp,eng.lcp), cls=COALESCE(excluded.cls,eng.cls), inp=COALESCE(excluded.inp,eng.inp),
        fcp=COALESCE(excluded.fcp,eng.fcp), ttfb=COALESCE(excluded.ttfb,eng.ttfb), status=COALESCE(excluded.status,eng.status)')
       ->execute([$site['id'], substr($x, 0, 24), $now, date('Y-m-d', $now), $vid, $p, (int)($num($d, 'd', 1800000) ?? 0), (int)($num($d, 's', 100) ?? 0),
                  $lcp === null ? null : (int)$lcp, $cls === null ? null : (float)$cls, $inp === null ? null : (int)$inp,
                  $fcp === null ? null : (int)$fcp, $ttfb === null ? null : (int)$ttfb, $stt ? (int)$stt : null, $dev]);
    http_response_code(204); exit;
}

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
$app = '';
if (str_starts_with($r, 'android-app://')) {
    /* Lien ouvert depuis une application Android : le navigateur transmet le nom de l'application */
    $app = strtolower((string)parse_url($r, PHP_URL_HOST));
} elseif ($r !== '') {
    $rh = izs_norm_domain((string)parse_url($r, PHP_URL_HOST));
    if ($rh === $site['domain'] || str_ends_with($rh, '.' . $site['domain'])) $rh = '';
}
/* Visites du propriétaire depuis l'éditeur ou le tableau de bord de son site : ignorées */
if ($kind === 'pv' && empty($d['i']) && preg_match('/(^|\.)(editor|manage|create|users)\.wix\.com$|^(www\.)?wix\.com$|(^|\.)editorx\.com$|(^|\.)wixstudio\.(com|io)$/', $rh)) { http_response_code(204); exit; }
$engines = ['Google' => '/(^|\.)google\./', 'Bing' => '/(^|\.)bing\.com$/', 'Yahoo' => '/(^|\.)yahoo\./', 'DuckDuckGo' => '/duckduckgo\.com$/',
    'Ecosia' => '/ecosia\.org$/', 'Qwant' => '/qwant\.com$/', 'Brave' => '/search\.brave\.com$/'];
$socials = ['Facebook' => '/(facebook\.com|fb\.me|fb\.com)$/', 'Instagram' => '/instagram\.com$/', 'LinkedIn' => '/(linkedin\.com|lnkd\.in)$/',
    'X / Twitter' => '/(twitter\.com|t\.co|x\.com)$/', 'TikTok' => '/tiktok\.com$/', 'YouTube' => '/(youtube\.com|youtu\.be)$/', 'Pinterest' => '/pinterest\./', 'Snapchat' => '/snapchat\.com$/'];
$mailers = ['Gmail' => '/^mail\.google\.com$/', 'Outlook' => '/(^|\.)(outlook\.(live|office|office365)\.com|safelinks\.protection\.outlook\.com)$/', 'Yahoo Mail' => '/^mail\.yahoo\./',
    'Orange Mail' => '/(^|\.)(mail01|messagerie)\.orange\.fr$|^webmail\.orange\.fr$/', 'SFR Mail' => '/(^|\.)webmail\.sfr\.fr$/', 'Free Mail' => '/(^|\.)webmail\.free\.fr$/', 'La Poste Mail' => '/(^|\.)laposte\.net$/',
    'Proton Mail' => '/(^|\.)proton\.me$/'];
$messengers = ['WhatsApp' => '/(^|\.)(whatsapp\.com|wa\.me)$/', 'Telegram' => '/(^|\.)(t\.me|telegram\.org)$/', 'Messenger' => '/(^|\.)messenger\.com$/'];
$payments = '/(^|\.)(stripe\.com|paypal\.com|paypal\.me|sumup\.com|payplug\.com|lydia-app\.com|stancer\.com|mollie\.com|payzen\.eu|systempay\.fr|monetico-services\.com|alma\.eu|klarna\.com|scalapay\.com|wixpay\.com|cashier\.wix\.com)$/';
/* Applications Android connues */
$apps = [
    'com.google.android.googlequicksearchbox' => ['Google (naturel)', 'Google (application)'], 'com.google.android.gm' => ['E-mail', 'Gmail'],
    'com.microsoft.office.outlook' => ['E-mail', 'Outlook'], 'com.samsung.android.email.provider' => ['E-mail', 'E-mail Samsung'], 'com.android.email' => ['E-mail', 'E-mail'],
    'ch.protonmail.android' => ['E-mail', 'Proton Mail'], 'com.yahoo.mobile.client.android.mail' => ['E-mail', 'Yahoo Mail'], 'com.orange.mail.fr' => ['E-mail', 'Orange Mail'],
    'com.whatsapp' => ['Messageries', 'WhatsApp'], 'com.whatsapp.w4b' => ['Messageries', 'WhatsApp'], 'org.telegram.messenger' => ['Messageries', 'Telegram'],
    'com.facebook.orca' => ['Messageries', 'Messenger'], 'org.thoughtcrime.securesms' => ['Messageries', 'Signal'],
    'com.google.android.apps.messaging' => ['Messageries', 'SMS'], 'com.samsung.android.messaging' => ['Messageries', 'SMS'],
    'com.facebook.katana' => ['Réseaux sociaux', 'Facebook'], 'com.facebook.lite' => ['Réseaux sociaux', 'Facebook'], 'com.instagram.android' => ['Réseaux sociaux', 'Instagram'],
    'com.linkedin.android' => ['Réseaux sociaux', 'LinkedIn'], 'com.zhiliaoapp.musically' => ['Réseaux sociaux', 'TikTok'], 'com.ss.android.ugc.trill' => ['Réseaux sociaux', 'TikTok'],
    'com.twitter.android' => ['Réseaux sociaux', 'X / Twitter'], 'com.pinterest' => ['Réseaux sociaux', 'Pinterest'], 'com.snapchat.android' => ['Réseaux sociaux', 'Snapchat'],
    'com.google.android.youtube' => ['Réseaux sociaux', 'YouTube'], 'com.google.android.apps.maps' => ['Fiche Google (Maps)', 'Google Maps (application)'],
];
/* Navigateurs intégrés aux applications (le lien est ouvert dans l'application, souvent sans provenance) */
$inapp = [
    'Messenger' => ['Messageries', '/FBAN\/Messenger|MessengerForiOS|MessengerLite|Orca-Android|FB_IAB\/Orca/'],
    'Facebook' => ['Réseaux sociaux', '/FBAN|FBAV|FB_IAB|FBIOS|FB4A/'], 'Instagram' => ['Réseaux sociaux', '/Instagram/'],
    'TikTok' => ['Réseaux sociaux', '/musical_ly|BytedanceWebview|TikTok|trill_/'], 'LinkedIn' => ['Réseaux sociaux', '/LinkedInApp/'],
    'Snapchat' => ['Réseaux sociaux', '/Snapchat/'], 'Pinterest' => ['Réseaux sociaux', '/Pinterest/'], 'X / Twitter' => ['Réseaux sociaux', '/Twitter(Android)?\//'],
    'WhatsApp' => ['Messageries', '/WhatsApp/'], 'LINE' => ['Messageries', '/\bLine\//'],
    'Google (application)' => ['Google (naturel)', '/\bGSA\//'],
];
$match = function (string $h, array $map) { foreach ($map as $n => $re) if (preg_match($re, $h)) return $n; return null; };
$paidMedium = preg_match('/^(cpc|ppc|paid|paidsearch|paid_search|sem|cpm|display|ads?)$/', $um);
$alias = ['ig' => 'instagram', 'insta' => 'instagram', 'fb' => 'facebook', 'li' => 'linkedin', 'tt' => 'tiktok', 'yt' => 'youtube', 'wa' => 'whatsapp'];
if (isset($alias[$us])) $us = $alias[$us];
$dx = null;

if ($qs('gclid') !== '' || $qs('gbraid') !== '' || $qs('wbraid') !== '' || $qs('gad') !== '' || ($paidMedium && ($us === '' || str_contains($us, 'google')))) {
    $ch = 'Google Ads (payant)'; $source = 'Google Ads';
    $camp = $uc !== '' ? $uc : ($qs('gad') !== '' || $qs('gclid') !== '' ? 'Google Ads (campagne non nommée)' : null);
} elseif ($qs('msclkid') !== '' || ($paidMedium && preg_match('/bing|microsoft/', $us))) {
    $ch = 'Autres publicités'; $source = 'Microsoft Ads'; $camp = $uc ?: null;
} elseif ($qs('tt') !== '' || $qs('li') !== '' || $qs('tw') !== '') {
    $ch = 'Autres publicités'; $source = $qs('tt') !== '' ? 'TikTok Ads' : ($qs('li') !== '' ? 'LinkedIn Ads' : 'X Ads'); $camp = $uc ?: null;
} elseif ($paidMedium) {
    $ch = 'Autres publicités'; $source = ucfirst($us ?: 'Publicité'); $camp = $uc ?: null;
} elseif (preg_match('/^(gbp|gmb|maps|local|fiche|business|google_business)/', $um . $us) || preg_match('/gbp|gmb|fiche|maps/i', $uc)
          || preg_match('/(^|\.)maps\.google\./', $rh) || preg_match('~google\.[a-z.]+/maps~', $r)) {
    $ch = 'Fiche Google (Maps)'; $source = 'Fiche Google'; $camp = $uc ?: null;
} elseif (preg_match('/^(qr|qrcode|qr_code|qr-code|print|imprime|flyer|affiche|carte|carte_visite|vitrine|vehicule|packaging)$/', $um) || preg_match('/^qr/', $us)) {
    $ch = 'QR code / imprimés'; $source = ucfirst($us && !preg_match('/^qr/', $us) ? $us : 'QR code'); $camp = $uc ?: null;
} elseif (preg_match('/^(whatsapp|sms|messaging|messagerie|messenger|telegram|chat)$/', $um) || preg_match('/^(whatsapp|sms|messenger|telegram|signal)$/', $us)) {
    $ch = 'Messageries'; $source = ucfirst(preg_match('/^(whatsapp|sms|messenger|telegram|signal)$/', $us) ? $us : $um); $camp = $uc ?: null;
} elseif ($um === 'email' || $um === 'e-mail' || $um === 'newsletter' || $qs('mc') !== '' || $qs('hs') !== '') {
    $ch = 'E-mail'; $source = ucfirst($us ?: ($qs('mc') !== '' ? 'Mailchimp' : ($qs('hs') !== '' ? 'HubSpot' : 'E-mail'))); $camp = $uc ?: null;
} elseif ($app !== '') {
    [$ch, $source] = $apps[$app] ?? ['Sites référents', 'Application Android (' . substr($app, 0, 60) . ')'];
    $ref = 'android-app://' . substr($app, 0, 80);
} elseif ($rh !== '' && preg_match($payments, $rh)) {
    /* Retour d'une page de paiement : c'est la suite de la même visite */
    $d['i'] = 1; $payReturn = true;
} elseif ($rh !== '' && ($e = $match($rh, $mailers))) {
    $ch = 'E-mail'; $source = $e; $ref = $rh;
} elseif ($rh !== '' && ($e = $match($rh, $messengers))) {
    $ch = 'Messageries'; $source = $e; $ref = $rh;
} elseif ($rh !== '' && ($e = $match($rh, $engines))) {
    $ch = $e === 'Google' ? 'Google (naturel)' : 'Autres moteurs'; $source = $e; $ref = $rh;
} elseif (($rh !== '' && ($sn = $match($rh, $socials))) || $qs('fbclid') !== '' || $qs('ig') !== '' || preg_match('/facebook|instagram|linkedin|tiktok|twitter|youtube|pinterest|snapchat/', $us)) {
    $ch = 'Réseaux sociaux'; $source = $sn ?? ($qs('fbclid') !== '' ? 'Facebook' : ($qs('ig') !== '' ? 'Instagram' : ucfirst($us))); $ref = $rh; $camp = $uc ?: null;
} elseif ($rh !== '') {
    $ch = 'Sites référents'; $source = $rh === 'izeninfo.fr' ? 'IZENINFO' : $rh; $ref = $rh;
} elseif ($us !== '') {
    $ch = 'Campagnes (UTM)'; $source = ucfirst($us); $camp = $uc ?: null;
} elseif ($ia = $match($ua, array_map(fn($x) => $x[1], $inapp))) {
    $ch = $inapp[$ia][0]; $source = $ia . ($ia === 'Google (application)' ? '' : ' (application)');
} elseif ($qs('srs') !== '') {
    $ch = 'Google (naturel)'; $source = 'Google Shopping';
}

$internal = !empty($d['i']) || $kind !== 'pv';
if ($internal) { $source = null; $ref = ''; $camp = null; $ch = $cur['ch'] ?? null; }

/* Canal de la première arrivée du jour pour ce visiteur (hors accès direct) */
$firstCh = function () use ($db, $site, $vid, $now): ?string {
    $st = $db->prepare("SELECT ch FROM hits WHERE site_id=? AND day=? AND vid=? AND kind='pv' AND ch IS NOT NULL AND ch<>'Accès direct' ORDER BY ts LIMIT 1");
    $st->execute([$site['id'], date('Y-m-d', $now), $vid]);
    return $st->fetchColumn() ?: null;
};
if ($internal && $ch === null) {
    /* Page restée ouverte plus de 30 minutes : on rattache la suite à l'arrivée du jour */
    $ch = $firstCh();
    if ($ch === null) { $ch = 'Accès direct'; $dx = empty($payReturn) ? 'Visite reprise (page restée ouverte)' : 'Retour d’une page de paiement'; }
} elseif (!$internal && $ch === 'Accès direct') {
    /* Arrivée sans aucune provenance : on note l'indice le plus probable */
    $nav = (string)($d['n'] ?? '');
    $prev = $firstCh();
    if ($prev !== null) { $ch = $prev; $source = 'Retour direct'; $dx = null; }
    elseif (!empty($d['a'])) $dx = 'Application installée (écran d’accueil)';
    elseif ($nav === 'reload' || $nav === 'back_forward') $dx = 'Onglet rouvert ou page rechargée';
    elseif (preg_match('~^/(index\.(html?|php))?$~', $path)) $dx = 'Adresse tapée, favori ou QR code';
    else $dx = 'Lien partagé (messagerie, e-mail, PDF…) ou favori';
}

/* Ville : déterminée une fois par visite, à partir de l'adresse IP (jamais stockée). */
$city = $cur['city'] ?? null; $region = null; $cc = null;
if (!$cur) {
    $g = izs_geo(izs_client_ip());
    if ($g) { $city = $g['city'] ?: null; $region = $g['region'] ?: null; $cc = $g['cc']; }
}
if (!$city) $city = null;

/* Robots qui exécutent le JavaScript : horloge réglée sur UTC (serveurs) et adresse hors zone connue */
$tz = (string)($d['z'] ?? '');
if (!$cur && !$city && preg_match('~^(UTC|GMT|Etc/(UTC|GMT|Universal|Zulu|GMT[+-]0)|Universal|Zulu)$~', $tz)) { http_response_code(204); exit; }

/* Présence en direct */
$db->prepare('INSERT INTO live(site_id,vid,path,ts,city,ch) VALUES(?,?,?,?,?,?) ON CONFLICT(site_id,vid) DO UPDATE SET path=excluded.path, ts=excluded.ts,
    city=COALESCE(live.city, excluded.city), ch=CASE WHEN ? THEN live.ch ELSE excluded.ch END')
   ->execute([$site['id'], $vid, $path, $now, $city, $ch ?? 'Accès direct', $internal && $cur ? 1 : 0]);
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

$db->prepare('INSERT INTO hits(site_id,ts,day,vid,kind,path,source,ref,device,browser,country,ev,city,region,ch,camp,dx) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
   ->execute([$site['id'], $now, date('Y-m-d', $now), $vid, $kind, $path, $kind === 'pv' ? $source : null, $kind === 'pv' ? $ref : null, $device, $browser, $country, $ev,
              $city, $region, $ch, $camp, $kind === 'pv' ? $dx : null]);
izs_purge();
http_response_code(204);
