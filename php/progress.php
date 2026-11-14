<?php
require __DIR__ . '/config.php';
require __DIR__ . '/progress_lib.php';

header('Content-Type: application/json');

// sendBeacon (used on tab-close) posts as text/plain, so accept POST regardless of content-type.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false]);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    echo json_encode(['success' => false]);
    exit;
}

$sessionId = preg_replace('/[^a-zA-Z0-9\-]/', '', $input['sessionId'] ?? '');
$step = preg_replace('/[^a-z]/', '', $input['step'] ?? '');
$validSteps = ['details', 'breakdown', 'objectives'];

if ($sessionId === '' || !in_array($step, $validSteps, true)) {
    echo json_encode(['success' => false]);
    exit;
}

function clean_field($value, $maxLen = 300) {
    if (is_array($value)) {
        return array_slice(array_map(function ($v) { return clean_field($v, 120); }, $value), 0, 20);
    }
    $value = is_string($value) || is_numeric($value) ? trim((string) $value) : '';
    $value = strip_tags($value);
    if (function_exists('mb_substr')) {
        $value = mb_substr($value, 0, $maxLen);
    } else {
        $value = substr($value, 0, $maxLen);
    }
    return $value;
}

// Only accept known field keys, whatever the person has filled in so far.
$allowedFields = ['name', 'email', 'phone', 'city', 'income', 'assets', 'pensionsCount', 'pensionsValue', 'isasValue', 'cashValue', 'otherValue', 'interests', 'notes'];
$fields = [];
if (!empty($input['fields']) && is_array($input['fields'])) {
    foreach ($allowedFields as $key) {
        if (isset($input['fields'][$key]) && $input['fields'][$key] !== '') {
            $fields[$key] = clean_field($input['fields'][$key], $key === 'notes' ? 1000 : 300);
        }
    }
}

update_progress($sessionId, function (&$record) use ($step, $fields) {
    $now = gmdate('c');
    if (!isset($record['first_seen'])) {
        $record['first_seen'] = $now;
        $record['completed'] = false;
    }
    $record['furthest_step'] = $step;
    $record['last_seen'] = $now;
    if (!isset($record['fields']) || !is_array($record['fields'])) {
        $record['fields'] = [];
    }
    // Merge rather than replace, so a field already captured doesn't vanish
    // if a later snapshot happens to omit it.
    foreach ($fields as $k => $v) {
        $record['fields'][$k] = $v;
    }
});

echo json_encode(['success' => true]);
