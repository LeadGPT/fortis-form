<?php
require __DIR__ . '/auth.php';
require_auth_or_show_login();

if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: dashboard.php');
    exit;
}

function load_records() {
    if (!file_exists(DATA_FILE)) return [];
    $lines = file(DATA_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $records = [];
    foreach ($lines as $line) {
        $r = json_decode($line, true);
        if ($r) $records[] = $r;
    }
    // Most recent first
    usort($records, function ($a, $b) {
        return strcmp($b['submitted_at'] ?? '', $a['submitted_at'] ?? '');
    });
    return $records;
}

$records = load_records();
$total = count($records);
$qualified = count(array_filter($records, function ($r) { return !empty($r['qualified']); }));
$notQualified = $total - $qualified;
$rate = $total > 0 ? round(($qualified / $total) * 100) : 0;
$premiumCount = count(array_filter($records, function ($r) { return ($r['tier'] ?? '') === 'premium'; }));
$standardCount = count(array_filter($records, function ($r) { return ($r['tier'] ?? '') === 'standard'; }));
$topCount = count(array_filter($records, function ($r) { return ($r['tier'] ?? '') === 'top'; }));

// --- Progress / drop-off tracking ---
function load_progress() {
    if (!file_exists(PROGRESS_FILE)) return [];
    $raw = file_get_contents(PROGRESS_FILE);
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}
$progress = load_progress();
$stepOrder = ['details' => 1, 'breakdown' => 2, 'objectives' => 3];

$funnel = ['details' => 0, 'breakdown' => 0, 'objectives' => 0, 'completed' => 0];
$dropoffByStep = ['details' => 0, 'breakdown' => 0, 'objectives' => 0];
$sessionsStarted = count($progress);

foreach ($progress as $p) {
    $step = $p['furthest_step'] ?? null;
    $rank = $stepOrder[$step] ?? 0;
    if ($rank >= 1) $funnel['details']++;
    if ($rank >= 2) $funnel['breakdown']++;
    if ($rank >= 3) $funnel['objectives']++;
    if (!empty($p['completed'])) {
        $funnel['completed']++;
    } elseif ($step && isset($dropoffByStep[$step])) {
        $dropoffByStep[$step]++;
    }
}
$completionRate = $sessionsStarted > 0 ? round(($funnel['completed'] / $sessionsStarted) * 100) : 0;

$fieldOrder = ['name', 'email', 'phone', 'city', 'income', 'assets', 'pensionsCount', 'pensionsValue', 'isasValue', 'cashValue', 'otherValue', 'interests', 'notes'];
$stepDisplay = ['details' => 'Contact details', 'breakdown' => 'Asset breakdown', 'objectives' => 'Objectives'];

$incomplete = [];
foreach ($progress as $sid => $p) {
    if (!empty($p['completed'])) continue;
    $fields = is_array($p['fields'] ?? null) ? $p['fields'] : [];
    $filledCount = 0;
    foreach ($fieldOrder as $fk) {
        if (!empty($fields[$fk])) $filledCount++;
    }
    $incomplete[] = [
        'session_id' => $sid,
        'last_seen' => $p['last_seen'] ?? ($p['first_seen'] ?? ''),
        'first_seen' => $p['first_seen'] ?? '',
        'furthest_step' => $p['furthest_step'] ?? '',
        'fields' => $fields,
        'filled_count' => $filledCount,
    ];
}
usort($incomplete, function ($a, $b) { return strcmp($b['last_seen'], $a['last_seen']); });

// Last 14 days trend, by day, split qualified / not qualified
$days = [];
for ($i = 13; $i >= 0; $i--) {
    $d = gmdate('Y-m-d', strtotime("-$i days"));
    $days[$d] = ['qualified' => 0, 'not' => 0];
}
foreach ($records as $r) {
    $d = substr($r['submitted_at'] ?? '', 0, 10);
    if (isset($days[$d])) {
        if (!empty($r['qualified'])) $days[$d]['qualified']++;
        else $days[$d]['not']++;
    }
}
$maxDay = 1;
foreach ($days as $d) { $maxDay = max($maxDay, $d['qualified'] + $d['not']); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Insights, Fortis Wealth Insights</title>
<link rel="stylesheet" href="../css/style.css">
<style>
  body{ align-items: flex-start; }
  .dash{ max-width: 1040px; width: 100%; margin: 40px auto; padding: 0 20px; }
  .dash-header{ display:flex; align-items:center; justify-content:space-between; margin-bottom: 28px; flex-wrap: wrap; gap: 12px; }
  .dash-header a{ color: var(--ink-soft); font-size: 14px; text-decoration: none; }
  .dash-header a:hover{ color: var(--ink); }
  .stat-grid{ display:grid; grid-template-columns: repeat(auto-fit, minmax(180px,1fr)); gap: 16px; margin-bottom: 32px; }
  .stat-card{ background: var(--panel); border-radius: var(--radius); padding: 22px 24px; box-shadow: var(--shadow); }
  .stat-card .num{ font-family: var(--serif); font-size: 34px; color: var(--forest); margin: 0 0 4px; }
  .stat-card .label{ font-size: 13px; color: var(--ink-soft); text-transform: uppercase; letter-spacing: 0.06em; }
  .panel{ background: var(--panel); border-radius: var(--radius); padding: 28px; box-shadow: var(--shadow); margin-bottom: 24px; }
  .panel h3{ font-family: var(--serif); font-weight:400; font-size: 20px; margin: 0 0 20px; }
  .chart{ display:flex; align-items:flex-end; gap: 6px; height: 140px; }
  .chart-col{ flex:1; display:flex; flex-direction:column; justify-content:flex-end; align-items:center; gap:2px; height:100%; }
  .chart-bar{ width: 100%; border-radius: 4px 4px 0 0; background: var(--sand); }
  .chart-bar.q{ background: var(--forest); }
  .chart-bar.n{ background: var(--gold); }
  .chart-label{ font-size: 10px; color: var(--ink-soft); margin-top: 6px; writing-mode: vertical-rl; height: 34px; }
  table{ width:100%; border-collapse: collapse; font-size: 14px; }
  th, td{ text-align:left; padding: 10px 12px; border-bottom: 1px solid var(--sand); vertical-align: top; }
  th{ font-size:12px; text-transform:uppercase; letter-spacing:0.05em; color: var(--ink-soft); }
  .tag{ display:inline-block; padding:3px 10px; border-radius: 999px; font-size:12px; font-weight:600; }
  .tag-yes{ background:#E8F2ED; color: var(--forest-dk); }
  .tag-no{ background:#F5EDE3; color:#8A5A22; }
  .tag-standard{ background:#EEF1F7; color:#3C5A8A; }
  .tag-top{ background:#FBF1E4; color:#8A5A22; font-weight:700; }
  .empty{ color: var(--ink-soft); font-style: italic; padding: 20px 0; }
  .legend{ display:flex; gap:16px; font-size:12px; color:var(--ink-soft); margin-top:10px; }
  .legend span::before{ content:""; display:inline-block; width:10px; height:10px; border-radius:2px; margin-right:6px; vertical-align:middle; }
  .legend .lq::before{ background: var(--forest); }
  .legend .ln::before{ background: var(--gold); }
  .funnel{ display:flex; flex-direction:column; gap:10px; }
  .funnel-row{ display:grid; grid-template-columns: 140px 1fr 60px; align-items:center; gap:12px; }
  .funnel-label{ font-size:13px; color: var(--ink-soft); font-weight:600; }
  .funnel-track{ background: var(--sand); border-radius: 999px; height: 14px; overflow: hidden; }
  .funnel-fill{ background: var(--forest); height: 100%; border-radius: 999px; transition: width 0.4s ease; }
  .funnel-count{ font-size:13px; font-weight:600; color: var(--ink); text-align:right; }
  .dropoff-grid{ display:grid; grid-template-columns: repeat(auto-fit, minmax(150px,1fr)); gap: 12px; margin-top: 20px; }
  .dropoff-card{ background: var(--paper); border-radius: var(--radius-sm); padding: 14px 16px; border: 1px solid var(--sand); }
  .dropoff-card .num{ font-family: var(--serif); font-size: 24px; color: var(--gold); margin: 0 0 2px; }
  .dropoff-card .label{ font-size: 12px; color: var(--ink-soft); }
  .dropoff-detail{ margin-top: 24px; }
  .dropoff-detail table{ font-size: 13px; }
  .step-pill{ display:inline-block; padding:3px 10px; border-radius:999px; font-size:11px; font-weight:600; background:#FBF1E4; color:#8A5A22; }
  .field-chip-list{ display:flex; flex-wrap:wrap; gap:5px; max-width: 320px; }
  .field-chip{ font-size:11px; background:var(--paper); border:1px solid var(--sand); border-radius:6px; padding:2px 7px; color:var(--ink-soft); }
  .field-chip strong{ color:var(--ink); font-weight:600; }
  .progress-count{ font-size:12px; color:var(--ink-soft); white-space:nowrap; }
</style>
</head>
<body>
<div class="backdrop" aria-hidden="true"><div class="blob blob-a"></div><div class="blob blob-b"></div></div>
<main class="dash">

  <div class="dash-header">
    <div>
      <p class="eyebrow" style="margin-bottom:4px;">Fortis Wealth Insights</p>
      <h1 class="display-sm" style="margin:0;">Lead insights</h1>
    </div>
    <div>
      <a href="export.php">Export CSV</a>
      &nbsp;&middot;&nbsp;
      <a href="dashboard.php?logout=1">Sign out</a>
    </div>
  </div>

  <div class="stat-grid">
    <div class="stat-card"><p class="num"><?= $total ?></p><p class="label">Total leads</p></div>
    <div class="stat-card"><p class="num"><?= $qualified ?></p><p class="label">Qualified</p></div>
    <div class="stat-card"><p class="num"><?= $notQualified ?></p><p class="label">Not qualified</p></div>
    <div class="stat-card"><p class="num"><?= $rate ?>%</p><p class="label">Qualification rate</p></div>
    <div class="stat-card"><p class="num"><?= $premiumCount ?></p><p class="label">Premium tier (£200k&ndash;£250k)</p></div>
    <div class="stat-card"><p class="num"><?= $standardCount ?></p><p class="label">Standard tier (£100k&ndash;£200k)</p></div>
    <div class="stat-card"><p class="num"><?= $topCount ?></p><p class="label">Top tier (£250k+)</p></div>
  </div>

  <div class="panel">
    <h3>Last 14 days</h3>
    <div class="chart">
      <?php foreach ($days as $d => $counts): $h = $counts['qualified'] + $counts['not']; ?>
        <div class="chart-col" title="<?= $d ?>: <?= $counts['qualified'] ?> qualified, <?= $counts['not'] ?> not">
          <div style="display:flex; flex-direction:column; justify-content:flex-end; height:100%; width:100%;">
            <?php if ($counts['not'] > 0): ?>
              <div class="chart-bar n" style="height:<?= round(($counts['not']/$maxDay)*100) ?>%;"></div>
            <?php endif; ?>
            <?php if ($counts['qualified'] > 0): ?>
              <div class="chart-bar q" style="height:<?= round(($counts['qualified']/$maxDay)*100) ?>%;"></div>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
    <div class="legend">
      <span class="lq">Qualified</span>
      <span class="ln">Not qualified</span>
    </div>
  </div>

  <div class="panel">
    <h3>Progress &amp; drop-off</h3>
    <?php if ($sessionsStarted === 0): ?>
      <p class="empty">No sessions tracked yet. This fills in as people move through the form.</p>
    <?php else: ?>
    <div class="funnel">
      <div class="funnel-row">
        <span class="funnel-label">Started</span>
        <div class="funnel-track"><div class="funnel-fill" style="width:100%;"></div></div>
        <span class="funnel-count"><?= $sessionsStarted ?></span>
      </div>
      <div class="funnel-row">
        <span class="funnel-label">Reached breakdown</span>
        <div class="funnel-track"><div class="funnel-fill" style="width:<?= $sessionsStarted ? round(($funnel['breakdown']/$sessionsStarted)*100) : 0 ?>%;"></div></div>
        <span class="funnel-count"><?= $funnel['breakdown'] ?></span>
      </div>
      <div class="funnel-row">
        <span class="funnel-label">Reached objectives</span>
        <div class="funnel-track"><div class="funnel-fill" style="width:<?= $sessionsStarted ? round(($funnel['objectives']/$sessionsStarted)*100) : 0 ?>%;"></div></div>
        <span class="funnel-count"><?= $funnel['objectives'] ?></span>
      </div>
      <div class="funnel-row">
        <span class="funnel-label">Completed</span>
        <div class="funnel-track"><div class="funnel-fill" style="width:<?= $sessionsStarted ? round(($funnel['completed']/$sessionsStarted)*100) : 0 ?>%;"></div></div>
        <span class="funnel-count"><?= $funnel['completed'] ?> (<?= $completionRate ?>%)</span>
      </div>
    </div>
    <div class="dropoff-grid">
      <div class="dropoff-card"><p class="num"><?= $dropoffByStep['details'] ?></p><p class="label">Dropped off after contact details</p></div>
      <div class="dropoff-card"><p class="num"><?= $dropoffByStep['breakdown'] ?></p><p class="label">Dropped off during asset breakdown</p></div>
      <div class="dropoff-card"><p class="num"><?= $dropoffByStep['objectives'] ?></p><p class="label">Dropped off on objectives, never submitted</p></div>
    </div>

    <div class="dropoff-detail">
      <h3 style="font-size:16px; margin:0 0 14px;">Who dropped off</h3>
      <?php if (empty($incomplete)): ?>
        <p class="empty">Nobody currently mid-form or abandoned. This fills in as people start but don't finish.</p>
      <?php else: ?>
      <table>
        <thead>
          <tr>
            <th>Last active</th><th>Got to</th><th>Name</th><th>Email</th><th>Phone</th><th>Answered</th><th>What they gave us</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach (array_slice($incomplete, 0, 50) as $row): $f = $row['fields']; ?>
          <tr>
            <td><?= htmlspecialchars(substr($row['last_seen'], 0, 16)) ?></td>
            <td><span class="step-pill"><?= htmlspecialchars($stepDisplay[$row['furthest_step']] ?? $row['furthest_step']) ?></span></td>
            <td><?= htmlspecialchars($f['name'] ?? '—') ?></td>
            <td><?= htmlspecialchars($f['email'] ?? '—') ?></td>
            <td><?= htmlspecialchars($f['phone'] ?? '—') ?></td>
            <td class="progress-count"><?= $row['filled_count'] ?> of <?= count($fieldOrder) ?> fields</td>
            <td>
              <div class="field-chip-list">
                <?php if (!empty($f['city'])): ?><span class="field-chip"><strong>City:</strong> <?= htmlspecialchars($f['city']) ?></span><?php endif; ?>
                <?php if (!empty($f['income'])): ?><span class="field-chip"><strong>Income:</strong> <?= htmlspecialchars($f['income']) ?></span><?php endif; ?>
                <?php if (!empty($f['assets'])): ?><span class="field-chip"><strong>Assets:</strong> <?= htmlspecialchars($f['assets']) ?></span><?php endif; ?>
                <?php if (!empty($f['pensionsValue'])): ?><span class="field-chip"><strong>Pensions:</strong> &pound;<?= htmlspecialchars($f['pensionsValue']) ?></span><?php endif; ?>
                <?php if (!empty($f['isasValue'])): ?><span class="field-chip"><strong>ISAs:</strong> &pound;<?= htmlspecialchars($f['isasValue']) ?></span><?php endif; ?>
                <?php if (!empty($f['cashValue'])): ?><span class="field-chip"><strong>Cash:</strong> &pound;<?= htmlspecialchars($f['cashValue']) ?></span><?php endif; ?>
                <?php if (!empty($f['otherValue'])): ?><span class="field-chip"><strong>Other:</strong> &pound;<?= htmlspecialchars($f['otherValue']) ?></span><?php endif; ?>
                <?php if (!empty($f['interests']) && is_array($f['interests'])): ?><span class="field-chip"><strong>Interests:</strong> <?= htmlspecialchars(implode(', ', $f['interests'])) ?></span><?php endif; ?>
                <?php if (!empty($f['notes'])): ?><span class="field-chip"><strong>Notes:</strong> <?= htmlspecialchars(mb_substr($f['notes'], 0, 60)) ?><?= mb_strlen($f['notes']) > 60 ? '…' : '' ?></span><?php endif; ?>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>

  <div class="panel">
    <h3>Recent submissions</h3>
    <?php if (empty($records)): ?>
      <p class="empty">No submissions yet. Once your form is live, they'll show up here.</p>
    <?php else: ?>
    <table>
      <thead>
        <tr>
          <th>Date</th><th>Name</th><th>Email</th><th>Phone</th><th>City</th><th>Income</th><th>Assets</th><th>Tier</th><th>Breakdown</th><th>Status</th><th>Notes</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach (array_slice($records, 0, 100) as $r): ?>
        <tr>
          <td><?= htmlspecialchars(substr($r['submitted_at'] ?? '', 0, 16)) ?></td>
          <td><?= htmlspecialchars($r['name'] ?? '') ?></td>
          <td><?= htmlspecialchars($r['email'] ?? '') ?></td>
          <td><?= htmlspecialchars($r['phone'] ?? '') ?></td>
          <td><?= htmlspecialchars($r['city'] ?? '') ?></td>
          <td><?= htmlspecialchars($r['income'] ?? '') ?></td>
          <td><?= htmlspecialchars($r['assets'] ?? '') ?></td>
          <td>
            <?php
              $tierLabel = ['top' => 'Top', 'premium' => 'Premium', 'standard' => 'Standard', 'not_qualified' => 'N/A'][$r['tier'] ?? ''] ?? '—';
              $tierClass = ['top' => 'tag-top', 'premium' => 'tag-yes', 'standard' => 'tag-standard', 'not_qualified' => 'tag-no'][$r['tier'] ?? ''] ?? 'tag-no';
            ?>
            <span class="tag <?= $tierClass ?>"><?= htmlspecialchars($tierLabel) ?></span>
          </td>
          <td>
            <?php if (!empty($r['qualified']) && $r['breakdown_sum'] !== null): ?>
              <span style="font-weight:600;">&pound;<?= number_format($r['breakdown_sum'] ?? 0) ?></span>
              <br><span style="font-size:11px; color:var(--ink-soft);">
                <?php if (!empty($r['pensions_value'])): ?>Pensions &pound;<?= number_format($r['pensions_value']) ?><?php if (!empty($r['pensions_count'])): ?> (<?= (int)$r['pensions_count'] ?>)<?php endif; ?><br><?php endif; ?>
                <?php if (!empty($r['isas_value'])): ?>ISAs &pound;<?= number_format($r['isas_value']) ?><br><?php endif; ?>
                <?php if (!empty($r['cash_value'])): ?>Cash &pound;<?= number_format($r['cash_value']) ?><br><?php endif; ?>
                <?php if (!empty($r['other_value'])): ?>Other &pound;<?= number_format($r['other_value']) ?><?php endif; ?>
              </span>
            <?php else: ?>
              <span style="color:var(--ink-soft);">&mdash;</span>
            <?php endif; ?>
          </td>
          <td><?php if (!empty($r['qualified'])): ?><span class="tag tag-yes">Qualified</span><?php else: ?><span class="tag tag-no">Not qualified</span><?php endif; ?></td>
          <td><?= htmlspecialchars($r['notes'] ?? '') ?><?php if (!empty($r['interests'])): ?><br><em style="color:var(--ink-soft); font-size:12px;"><?= htmlspecialchars(implode(', ', $r['interests'])) ?></em><?php endif; ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>

</main>
</body>
</html>
