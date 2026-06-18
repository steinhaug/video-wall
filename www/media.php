<?php
/**
 * Serves files from /storage (which lives outside the webroot).
 *
 *   /media.php?type=video&id={video_id}   -> storage/video/{video_id}.mp4
 *   /media.php?type=vtt&id={video_id}     -> storage/transcripts/{video_id}.vtt
 *
 * Supports HTTP Range requests so HTML5 <video> seeking works.
 * id is validated to the 11-char YouTube id charset — no path traversal possible.
 */
require __DIR__ . '/../environment.php';

$type = $_GET['type'] ?? '';
$id   = $_GET['id']   ?? '';

if (!preg_match('~^[A-Za-z0-9_-]{11}$~', $id)) {
    http_response_code(400);
    exit('Invalid id');
}

$map = [
    'video' => [STORAGE_PATH . '/video/'       . $id . '.mp4', 'video/mp4'],
    'vtt'   => [STORAGE_PATH . '/transcripts/' . $id . '.vtt', 'text/vtt; charset=utf-8'],
];

if (!isset($map[$type])) {
    http_response_code(400);
    exit('Invalid type');
}

[$path, $mime] = $map[$type];

if (!is_file($path)) {
    http_response_code(404);
    exit('File not found');
}

$size = filesize($path);
header('Content-Type: ' . $mime);
header('Accept-Ranges: bytes');

$range = $_SERVER['HTTP_RANGE'] ?? '';
if ($range && preg_match('/bytes=(\d+)-(\d*)/', $range, $m)) {
    $start = (int) $m[1];
    $end   = $m[2] !== '' ? (int) $m[2] : $size - 1;
    if ($start > $end || $end >= $size) {
        http_response_code(416);
        header("Content-Range: bytes */{$size}");
        exit;
    }
    $length = $end - $start + 1;
    http_response_code(206);
    header("Content-Range: bytes {$start}-{$end}/{$size}");
    header("Content-Length: {$length}");

    $fp = fopen($path, 'rb');
    fseek($fp, $start);
    $remaining = $length;
    while ($remaining > 0 && !feof($fp)) {
        $chunk = min(8192, $remaining);
        echo fread($fp, $chunk);
        $remaining -= $chunk;
        flush();
    }
    fclose($fp);
    exit;
}

header("Content-Length: {$size}");
readfile($path);
