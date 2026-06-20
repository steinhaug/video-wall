<?php
/**
 * Standalone playlist audio downloader (CLI).
 *
 * Downloads the audio track (m4a) of every video in a YouTube playlist into a
 * dedicated folder. NOT part of the video-wall pipeline: no DB rows, no
 * AssemblyAI transcription, no cleanup. Files are kept for separate use.
 *
 * Usage:
 *   php playlist-audio-download.php <playlist-url>
 *
 * Output:
 *   storage/playlist-audio/<playlist-id>/NN - <title>.m4a
 *     - <playlist-id> is the list= value from the URL
 *     - NN is the 1-based playlist position, zero-padded
 *   Re-runs are safe: existing files are skipped.
 */
if (PHP_SAPI !== 'cli') {
    die("playlist-audio-download.php must be run from the command line.\n");
}

require __DIR__ . '/environment.php';

$url = $argv[1] ?? null;
if ($url === null || trim($url) === '') {
    fwrite(STDERR, "Usage: php playlist-audio-download.php <playlist-url>\n");
    exit(1);
}
$url = trim($url);

if (!YtDlp::isPlaylistUrl($url)) {
    log_line("WARNING: URL is not a /playlist?list=... URL — attempting to expand anyway.");
}

$playlistId = extract_playlist_id($url);
if ($playlistId === null) {
    log_line("WARNING: could not find list= id in URL — using 'playlist' as folder name.");
    $playlistId = 'playlist';
}

$outDir = STORAGE_PATH . '/playlist-audio/' . $playlistId;
if (!is_dir($outDir) && !mkdir($outDir, 0777, true) && !is_dir($outDir)) {
    fwrite(STDERR, "Failed to create output dir: {$outDir}\n");
    exit(1);
}

log_line("Expanding playlist: {$url}");
try {
    $entries = YtDlp::expandPlaylist($url);
} catch (\Throwable $e) {
    fwrite(STDERR, 'Playlist expansion failed: ' . $e->getMessage() . "\n");
    exit(1);
}

$total = count($entries);
if ($total === 0) {
    log_line("Playlist has no downloadable video entries. Nothing to do.");
    exit(0);
}
log_line("Found {$total} video(s). Output: {$outDir}");

$pad        = max(2, strlen((string) $total));
$downloaded = 0;
$skipped    = 0;
$failed     = 0;

foreach ($entries as $i => $entry) {
    $pos     = str_pad((string) ($i + 1), $pad, '0', STR_PAD_LEFT);
    $videoId = $entry['video_id'];
    $title   = $entry['title'] !== '' ? $entry['title'] : $videoId;
    $base    = $pos . ' - ' . sanitize_filename($title);
    $output  = $outDir . '/' . $base . '.m4a';
    $videoUrl = 'https://www.youtube.com/watch?v=' . $videoId;

    if (is_file($output)) {
        log_line("[{$pos}/{$total}] SKIP (exists): {$base}.m4a");
        $skipped++;
        continue;
    }

    log_line("[{$pos}/{$total}] downloading {$videoId} — {$title}");
    try {
        download_audio($videoUrl, $output);
        log_line("[{$pos}/{$total}] OK: {$base}.m4a");
        $downloaded++;
    } catch (\Throwable $e) {
        log_line("[{$pos}/{$total}] ERROR ({$videoId}): " . $e->getMessage());
        @unlink($output); // remove any partial output
        $failed++;
    }
}

log_line("Done. downloaded={$downloaded} skipped={$skipped} failed={$failed} total={$total}");


// -----------------------------------------------------------------------------

/**
 * Run yt-dlp to download audio as m4a to an exact output path.
 * Throws RuntimeException on failure.
 */
function download_audio(string $url, string $output): void
{
    global $yt_dlp_bin;

    // Hardcoded .m4a in the path + --audio-format m4a (avoids the %(ext)s template
    // clash with Windows cmd.exe % expansion). Cookies work here: CLI can decrypt
    // the browser's DPAPI-protected cookie store (unlike Apache).
    $cmd = sprintf(
        '%s%s -x --audio-format m4a --no-playlist -o %s %s 2>&1',
        escapeshellarg($yt_dlp_bin),
        YtDlp::cookiesFlag(),
        escapeshellarg($output),
        escapeshellarg($url)
    );

    exec($cmd, $lines, $exit);
    if ($exit !== 0) {
        throw new RuntimeException('yt-dlp failed: ' . implode("\n", array_slice($lines, -5)));
    }
    if (!is_file($output)) {
        throw new RuntimeException('yt-dlp completed but no audio file found at ' . $output);
    }
}

/**
 * Extract the list= playlist id from a YouTube URL. Returns null if absent.
 */
function extract_playlist_id(string $url): ?string
{
    if (preg_match('~[?&]list=([A-Za-z0-9_-]+)~', $url, $m)) {
        return $m[1];
    }
    return null;
}

/**
 * Make a string safe as a Windows filename component: strip the reserved
 * <>:"/\|?* characters and control chars, collapse whitespace, trim, and cap
 * length so the full path stays well under the MAX_PATH limit.
 *
 * Also strips ! and % : PHP's escapeshellarg() on Windows replaces these with
 * spaces (it neutralises cmd.exe delayed-expansion / %VAR% env expansion), so a
 * filename containing them would reach yt-dlp altered and the resulting file
 * would not match the path we built — making is_file() report a false failure.
 */
function sanitize_filename(string $name): string
{
    $name = preg_replace('~[<>:"/\\\\|?*!%\x00-\x1F]~', '', $name);
    $name = preg_replace('~\s+~', ' ', $name);
    $name = trim($name, " .");
    if ($name === '') {
        $name = 'untitled';
    }
    if (mb_strlen($name) > 120) {
        $name = mb_substr($name, 0, 120);
        $name = trim($name, " .");
    }
    return $name;
}

function log_line(string $msg): void
{
    echo '[' . date('H:i:s') . '] ' . $msg . PHP_EOL;
}
