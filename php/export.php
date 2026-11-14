<?php
require __DIR__ . '/auth.php';
require_auth_or_show_login();

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="fortis-leads-' . gmdate('Y-m-d') . '.csv"');

$out = fopen('php://output', 'w');
fputcsv($out, ['Submitted At', 'Name', 'Email', 'Phone', 'City', 'Annual Income', 'Assets', 'Tier', 'Qualified', 'Pensions Count', 'Pensions Value', 'ISAs Value', 'Cash Value', 'Other Investments Value', 'Breakdown Sum', 'Interests', 'Notes']);

if (file_exists(DATA_FILE)) {
    $lines = file(DATA_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $r = json_decode($line, true);
        if (!$r) continue;
        fputcsv($out, [
            $r['submitted_at'] ?? '',
            $r['name'] ?? '',
            $r['email'] ?? '',
            $r['phone'] ?? '',
            $r['city'] ?? '',
            $r['income'] ?? '',
            $r['assets'] ?? '',
            $r['tier'] ?? '',
            !empty($r['qualified']) ? 'Yes' : 'No',
            $r['pensions_count'] ?? '',
            $r['pensions_value'] ?? '',
            $r['isas_value'] ?? '',
            $r['cash_value'] ?? '',
            $r['other_value'] ?? '',
            $r['breakdown_sum'] ?? '',
            !empty($r['interests']) ? implode('; ', $r['interests']) : '',
            $r['notes'] ?? '',
        ]);
    }
}
fclose($out);
