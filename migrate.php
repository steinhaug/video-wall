<?php
/**
 * One-time DB migration runner.
 * Usage: php migrate.php
 * Idempotent — applies every .sql file under www.appdata/migrations/ in name order.
 * Uses CREATE TABLE IF NOT EXISTS, so re-running is safe.
 */
require __DIR__ . '/environment.php';

$dir = APPDATA_PATH . '/migrations';
$files = glob($dir . '/*.sql');
sort($files);

if (!$files) {
    echo "No migrations found in {$dir}\n";
    exit(0);
}

foreach ($files as $file) {
    $name = basename($file);
    echo "Applying {$name}... ";
    $sql = file_get_contents($file);
    if ($sql === false) {
        echo "FAIL (could not read file)\n";
        exit(1);
    }
    if (!$mysqli->multi_query($sql)) {
        echo "FAIL: " . $mysqli->error . "\n";
        exit(1);
    }
    // Drain result sets
    while ($mysqli->more_results() && $mysqli->next_result()) { /* noop */ }
    echo "OK\n";
}

echo "Done.\n";
