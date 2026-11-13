<?php
require __DIR__ . '/config.php';
require __DIR__ . '/progress_lib.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$raw = file_get_contents('php://input');
$input = json_decode($raw, true);

if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid payload']);
    exit;
}

// --- Honeypot: bots fill hidden fields, humans never see them ---
if (!empty($input['website'])) {
    // Pretend success so bots don't learn anything, but store nothing.
    echo json_encode(['success' => true]);
    exit;
}

function clean($value, $maxLen = 500) {
    $value = is_string($value) ? trim($value) : '';
    $value = strip_tags($value);
    if (function_exists('mb_substr')) {
        $value = mb_substr($value, 0, $maxLen);
    } else {
        $value = substr($value, 0, $maxLen);
    }
    return $value;
}

function cleanMoney($value) {
    if ($value === null || $value === '') return null;
    $num = is_numeric($value) ? (float) $value : null;
    if ($num === null || $num < 0) return null;
    return $num;
}

$sessionId = preg_replace('/[^a-zA-Z0-9\-]/', '', $input['sessionId'] ?? '');
$name  = clean($input['name'] ?? '', 120);
$email = clean($input['email'] ?? '', 180);
$phone = clean($input['phone'] ?? '', 40);
$city  = clean($input['city'] ?? '', 120);
$income = clean($input['income'] ?? '', 60);
$assets = clean($input['assets'] ?? '', 60);
$notes = clean($input['notes'] ?? '', 2000);
$interests = [];
if (!empty($input['interests']) && is_array($input['interests'])) {
    foreach ($input['interests'] as $i) {
        $interests[] = clean($i, 200);
    }
}

$pensionsCount = isset($input['pensionsCount']) && is_numeric($input['pensionsCount']) ? (int) $input['pensionsCount'] : null;
$pensionsValue = cleanMoney($input['pensionsValue'] ?? null);
$isasValue     = cleanMoney($input['isasValue'] ?? null);
$cashValue     = cleanMoney($input['cashValue'] ?? null);
$otherValue    = cleanMoney($input['otherValue'] ?? null);
$breakdownSum  = array_sum(array_filter([$pensionsValue, $isasValue, $cashValue, $otherValue], function ($v) { return $v !== null; }));

function isValidUkPhone($phone) {
    $digits = preg_replace('/\D/', '', $phone);
    if (strpos($digits, '44') === 0) $digits = substr($digits, 2);
    if (strpos($digits, '0') === 0) $digits = substr($digits, 1);
    return strlen($digits) === 9 || strlen($digits) === 10;
}

// --- Required-field validation (mirrors the client-side checks) ---
$errors = [];
if ($name === '') $errors[] = 'name';
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'email';
if (!isValidUkPhone($phone)) $errors[] = 'phone';
if ($city === '') $errors[] = 'city';
$validIncomes = ['Under £30,000', '£30,000 to £50,000', '£50,000 to £70,000', '£70,000 to £90,000', '£90,000 to £110,000', '£110,000 to £130,000', '£130,000 to £150,000', '£150,000 to £250,000', '£250,000 to £500,000', 'Over £500,000'];
if (!in_array($income, $validIncomes, true)) $errors[] = 'income';
$validAssets = ['Under £100,000', '£100,000 to £200,000', '£200,000 to £250,000', 'Over £250,000'];
if (!in_array($assets, $validAssets, true)) $errors[] = 'assets';

// Same form for everyone £100k+, but tracked as three separate tiers
// internally for reporting: standard / premium / top.
function qualification_tier($assets) {
    if ($assets === 'Under £100,000') return 'not_qualified';
    if ($assets === '£100,000 to £200,000') return 'standard';
    if ($assets === '£200,000 to £250,000') return 'premium';
    return 'top'; // Over £250,000
}
$tier = qualification_tier($assets);

// Qualified leads must have a breakdown that adds up to the bracket they selected,
// mirroring the client-side check, so this can't be bypassed by calling the API directly.
$qualified = ($assets !== DISQUALIFYING_TIER);
if ($qualified) {
    $threshold = 250000;
    if ($assets === '£100,000 to £200,000') $threshold = 100000;
    elseif ($assets === '£200,000 to £250,000') $threshold = 200000;
    if ($breakdownSum < $threshold) $errors[] = 'breakdown';
}

if (!empty($errors)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'Validation failed', 'fields' => $errors]);
    exit;
}

$record = [
    'id'             => bin2hex(random_bytes(8)),
    'name'           => $name,
    'email'          => $email,
    'phone'          => $phone,
    'city'           => $city,
    'income'         => $income,
    'assets'         => $assets,
    'tier'           => $tier,
    'qualified'      => $qualified,
    'pensions_count' => $pensionsCount,
    'pensions_value' => $pensionsValue,
    'isas_value'     => $isasValue,
    'cash_value'     => $cashValue,
    'other_value'    => $otherValue,
    'breakdown_sum'  => $qualified ? $breakdownSum : null,
    'interests'      => $interests,
    'notes'          => $notes,
    'submitted_at'   => gmdate('c'),
    'ip'             => $_SERVER['REMOTE_ADDR'] ?? '',
];

// --- Append as newline-delimited JSON (one record per line, file-lock safe) ---
$dataDir = dirname(DATA_FILE);
if (!is_dir($dataDir)) {
    mkdir($dataDir, 0755, true);
}
$fp = fopen(DATA_FILE, 'a');
if ($fp) {
    if (flock($fp, LOCK_EX)) {
        fwrite($fp, json_encode($record) . "\n");
        flock($fp, LOCK_UN);
    }
    fclose($fp);
}

// --- Mark this session as completed in the progress/drop-off tracker ---
if ($sessionId !== '') {
    update_progress($sessionId, function (&$rec) use ($qualified) {
        $now = gmdate('c');
        if (!isset($rec['first_seen'])) $rec['first_seen'] = $now;
        $rec['last_seen'] = $now;
        $rec['completed'] = true;
        $rec['qualified'] = $qualified;
    });
}

// --- Optional email notification ---
if (defined('NOTIFY_EMAIL') && NOTIFY_EMAIL) {
    $subject = ($qualified ? '[Qualified] ' : '[Not qualified] ') . 'New Fortis Wealth Insights lead: ' . $name;
    $body  = "Name: $name\n";
    $body .= "Email: $email\n";
    $body .= "Phone: $phone\n";
    $body .= "City: $city\n";
    $body .= "Annual income: $income\n";
    $body .= "Assets: $assets\n";
    $body .= "Qualified: " . ($qualified ? 'Yes' : 'No') . "\n";
    if ($qualified) {
        $body .= "Pensions: " . ($pensionsCount !== null ? $pensionsCount . ' schemes, ' : '') . '£' . number_format($pensionsValue ?? 0) . "\n";
        $body .= "ISAs: £" . number_format($isasValue ?? 0) . "\n";
        $body .= "Cash: £" . number_format($cashValue ?? 0) . "\n";
        $body .= "Other investments: £" . number_format($otherValue ?? 0) . "\n";
    }
    if (!empty($interests)) $body .= "Interested in: " . implode(', ', $interests) . "\n";
    if ($notes !== '') $body .= "Notes: $notes\n";
    $headers = 'From: no-reply@' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
    @mail(NOTIFY_EMAIL, $subject, $body, $headers);
}

echo json_encode(['success' => true, 'qualified' => $qualified]);
