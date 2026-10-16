<?php
// IZENINFO – envoi du formulaire de devis par e-mail (hébergement OVH, fonction mail()).
header('Content-Type: application/json; charset=utf-8');
header('X-Robots-Tag: noindex');
$TO = 'direction@izenride.com';
$FROM = 'devis@izeninfo.fr'; // adresse du domaine hébergé chez OVH (expéditeur technique)

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['error' => 'Méthode non autorisée.']); exit; }

// Limite simple : 5 envois / 10 minutes / adresse IP
$ip = $_SERVER['REMOTE_ADDR'] ?? 'x';
$f = sys_get_temp_dir() . '/izi_' . md5($ip);
$hits = array_filter(json_decode(@file_get_contents($f) ?: '[]', true) ?: [], fn($t) => $t > time() - 600);
if (count($hits) >= 5) { http_response_code(429); echo json_encode(['error' => 'Trop de demandes. Merci de réessayer dans quelques minutes.']); exit; }
$hits[] = time(); @file_put_contents($f, json_encode(array_values($hits)));

$b = json_decode(file_get_contents('php://input'), true);
if (!is_array($b)) $b = $_POST;
if (!empty($b['site_web'])) { echo json_encode(['ok' => true]); exit; } // piège anti-robots

$clip = fn($k, $n) => mb_substr(trim((string)($b[$k] ?? '')), 0, $n);
$nom = $clip('nom', 120); $ent = $clip('entreprise', 160); $mail = $clip('email', 200);
$formule = $clip('formule', 60); $delai = $clip('delai', 60); $projet = $clip('projet', 5000);
if ($nom === '' || $projet === '') { http_response_code(400); echo json_encode(['error' => 'Nom et description du projet obligatoires.']); exit; }
if (!filter_var($mail, FILTER_VALIDATE_EMAIL)) { http_response_code(400); echo json_encode(['error' => 'Adresse e-mail invalide.']); exit; }
$clean = fn($s) => str_replace(["\r", "\n"], ' ', $s);

$subject = 'Demande de devis · ' . ($formule ?: 'IZENINFO') . ' · ' . $clean($nom);
$body = "Nouvelle demande de devis IZENINFO\n\n"
      . "Nom : $nom\nEntreprise : " . ($ent ?: '—') . "\nE-mail : $mail\nFormule : " . ($formule ?: '—') . "\nÉchéance : " . ($delai ?: '—') . "\n\nProjet :\n$projet\n";
$headers = "From: IZENINFO <$FROM>\r\nReply-To: " . $clean($mail) . "\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit";
$ok = mail($TO, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, $headers, '-f' . $FROM);
if (!$ok) { http_response_code(502); echo json_encode(['error' => "L'envoi a échoué."]); exit; }
echo json_encode(['ok' => true]);
