<?php
/**
 * Read-modify-write helper for the progress file. File-locked so
 * concurrent submissions don't clobber each other. Used by both
 * progress.php (step pings) and submit.php (marking completion).
 */
function update_progress($sessionId, callable $mutator) {
    $dir = dirname(PROGRESS_FILE);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $fp = fopen(PROGRESS_FILE, 'c+');
    if (!$fp) return;
    if (flock($fp, LOCK_EX)) {
        $size = filesize(PROGRESS_FILE);
        $raw = $size > 0 ? fread($fp, $size) : '';
        $data = $raw !== '' ? json_decode($raw, true) : [];
        if (!is_array($data)) $data = [];

        if (!isset($data[$sessionId]) || !is_array($data[$sessionId])) {
            $data[$sessionId] = [];
        }
        $mutator($data[$sessionId]);

        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($data));
        flock($fp, LOCK_UN);
    }
    fclose($fp);
}
