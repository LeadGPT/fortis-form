<?php
// TEMPORARY one-off importer. Delete after use.
require __DIR__ . '/config.php';
header('Content-Type: application/json');
$tok = getenv('IMPORT_TOKEN');
$given = $_SERVER['HTTP_X_IMPORT_TOKEN'] ?? '';
if (!$tok || !hash_equals($tok, $given) || $_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(403); echo '{"error":"forbidden"}'; exit; }
$rows = json_decode(file_get_contents('php://input'), true);
if (!is_array($rows)) { http_response_code(400); echo '{"error":"bad json"}'; exit; }
$dir = dirname(DATA_FILE);
if (!is_dir($dir)) mkdir($dir, 0755, true);
$have = [];
if (is_file(DATA_FILE)) foreach (file(DATA_FILE, FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES) as $l) { $r = json_decode($l, true); if (isset($r['id'])) $have[$r['id']] = 1; }
$fp = fopen(DATA_FILE, 'a'); flock($fp, LOCK_EX); $added = 0;
foreach ($rows as $r) { if (!isset($r['id']) || isset($have[$r['id']])) continue; fwrite($fp, json_encode($r) . "\n"); $added++; }
flock($fp, LOCK_UN); fclose($fp);
@chown(DATA_FILE, 'www-data');
echo json_encode(['added' => $added, 'total_lines' => count(file(DATA_FILE))]);
