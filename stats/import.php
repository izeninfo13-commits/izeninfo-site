<?php
/* IZENINFO – import ponctuel de l'historique d'audience (journaux du serveur).
   Protégé par une clé secrète (seule son empreinte figure ici) et désactivé après la date limite. */
define('IZS', 1);
require __DIR__ . '/../espace-client/_lib.php';

const IMPORT_KEY_SHA256 = '32cce3826b8e3567e5a1a6906553d9903d3a5311f08e6aece78e50dfa94561f9';
const IMPORT_UNTIL = '2026-10-23 23:59:59';

header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-store');
header('Content-Type: application/json; charset=utf-8');
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit; }
if (time() > strtotime(IMPORT_UNTIL)) { http_response_code(410); echo '{"error":"Import fermé."}'; exit; }
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); exit; }
if (!izs_rate('imp', 30, 600)) { http_response_code(429); echo '{"error":"Trop de requêtes."}'; exit; }

$d = json_decode((string)file_get_contents('php://input', false, null, 0, 8 * 1024 * 1024), true);
if (!is_array($d) || !hash_equals(IMPORT_KEY_SHA256, hash('sha256', (string)($d['token'] ?? '')))) { http_response_code(403); echo '{"error":"Clé invalide."}'; exit; }

$db = izs_db();
$st = $db->prepare('SELECT id, domain FROM sites WHERE skey=?');
$st->execute([(string)($d['site'] ?? '')]);
$site = $st->fetch();
if (!$site) { http_response_code(404); echo '{"error":"Site inconnu."}'; exit; }
$sid = (int)$site['id'];

/* L'historique s'arrête là où la mesure en direct commence : pas de double comptage. */
$st = $db->prepare("SELECT MIN(ts) FROM hits WHERE site_id=? AND vid NOT LIKE 'imp%'");
$st->execute([$sid]);
$cutoff = (int)($st->fetchColumn() ?: time());

if (($d['action'] ?? '') === 'info') {
    $st = $db->prepare("SELECT COUNT(*) n, MIN(ts) a, MAX(ts) b FROM hits WHERE site_id=? AND vid LIKE 'imp%'");
    $st->execute([$sid]);
    echo json_encode(['site' => $site['domain'], 'cutoff' => $cutoff, 'cutoff_date' => date('c', $cutoff), 'imported' => $st->fetch()]);
    exit;
}

$map = ['Google' => '/(^|\.)google\./', 'Bing' => '/(^|\.)bing\.com$/', 'Yahoo' => '/(^|\.)yahoo\./', 'DuckDuckGo' => '/duckduckgo\.com$/', 'Ecosia' => '/ecosia\.org$/', 'Qwant' => '/qwant\.com$/',
    'Facebook' => '/(facebook\.com|fb\.me|fb\.com)$/', 'Instagram' => '/instagram\.com$/', 'LinkedIn' => '/(linkedin\.com|lnkd\.in)$/', 'X / Twitter' => '/(twitter\.com|t\.co|x\.com)$/',
    'TikTok' => '/tiktok\.com$/', 'YouTube' => '/(youtube\.com|youtu\.be)$/', 'Pinterest' => '/pinterest\./', 'IZENINFO' => '/izeninfo\.fr$/'];

$db->beginTransaction();
if (!empty($d['reset'])) $db->prepare("DELETE FROM hits WHERE site_id=? AND vid LIKE 'imp%'")->execute([$sid]);
$ins = $db->prepare('INSERT INTO hits(site_id,ts,day,vid,kind,path,source,ref,device,browser,country,ev) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)');
$n = 0; $skipped = 0;
foreach (($d['rows'] ?? []) as $r) {
    /* [ts, jour, visiteur, type, chemin, site référent, appareil, navigateur, entrée(1/0)] */
    if (!is_array($r) || count($r) < 9) { $skipped++; continue; }
    [$ts, $day, $vid, $kind, $path, $rh, $dev, $br, $entry] = $r;
    $ts = (int)$ts;
    if ($ts <= 0 || $ts >= $cutoff || !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$day)) { $skipped++; continue; }
    $kind = $kind === 'form' ? 'ev' : 'pv';
    $rh = izs_norm_domain((string)$rh);
    $source = null; $ref = '';
    if ($kind === 'pv' && $entry) {
        $source = 'Accès direct';
        if ($rh !== '' && $rh !== $site['domain']) {
            $source = $rh; $ref = $rh;
            foreach ($map as $name => $re) { if (preg_match($re, $rh)) { $source = $name; break; } }
        }
    }
    $dev = in_array($dev, ['Mobile', 'Ordinateur', 'Tablette'], true) ? $dev : 'Ordinateur';
    $br = substr(preg_replace('/[^\w .-]/u', '', (string)$br), 0, 30) ?: 'Autre';
    $ins->execute([$sid, $ts, $day, 'imp' . substr(preg_replace('/[^a-f0-9]/', '', (string)$vid), 0, 17), $kind, substr((string)$path, 0, 200) ?: '/',
        $source, $ref, $dev, $br, 'Non mesuré', $kind === 'ev' ? 'form' : null]);
    $n++;
}
$db->commit();
echo json_encode(['ok' => true, 'inserted' => $n, 'skipped' => $skipped, 'cutoff' => date('c', $cutoff)]);
