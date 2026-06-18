<?php
/**
 * Video transcription worker (CLI).
 *
 * Usage:
 *   php worker.php           # run one pass — pick up one pending job, exit when none left
 *   php worker.php --loop    # poll forever (sleep 5s between empty cycles)
 *
 * Pipeline per job:
 *   pending -> downloading (yt-dlp m4a) -> transcribing (AssemblyAI) -> done
 * On exception:
 *   status -> error, error_message stored, audio cleaned up.
 */
if (PHP_SAPI !== 'cli') {
    die("worker.php must be run from the command line.\n");
}

require __DIR__ . '/environment.php';

$loop = in_array('--loop', $argv, true);

$repo = new VideoRepo($mysqli);
$ai   = new AssemblyAI($assemblyai_api_key);

$reset = $repo->resetStuckJobs();
if ($reset > 0) {
    log_line("Reset {$reset} stuck job(s) to 'pending'.");
}

do {
    $job = $repo->getNextPending();
    if ($job === null) {
        if ($loop) {
            sleep(5);
            continue;
        }
        log_line("No pending jobs. Done.");
        break;
    }

    process_job($job, $repo, $ai);
} while (true);


// -----------------------------------------------------------------------------

function process_job(array $job, VideoRepo $repo, AssemblyAI $ai): void
{
    $id       = (int) $job['id'];
    $videoId  = $job['video_id'];
    $url      = $job['youtube_url'];
    $audioDir = STORAGE_PATH . '/audio';
    $txtDir   = STORAGE_PATH . '/transcripts';

    log_line("[job {$id}] {$videoId} — starting");

    try {
        // -------- Download audio --------
        $repo->setStatus($id, 'downloading');
        $repo->clearError($id);

        $audioPath = download_audio($url, $videoId, $audioDir);
        log_line("[job {$id}] audio: {$audioPath}");

        if (empty($job['title'])) {
            $title = fetch_title($url);
            if ($title !== null) {
                $repo->setTitle($id, $title);
                log_line("[job {$id}] title: {$title}");
            }
        }

        // -------- Transcribe --------
        $repo->setStatus($id, 'transcribing');

        $uploadUrl    = $ai->upload($audioPath);
        $transcriptId = $ai->transcribe($uploadUrl, AssemblyAI::CONFIG_ENGLISH);
        $repo->setAssemblyaiId($id, $transcriptId);
        log_line("[job {$id}] assemblyai_id: {$transcriptId}");

        $transcript = $ai->poll($transcriptId);

        // -------- Save outputs --------
        $speakerText = $ai->buildSpeakerText($transcript);
        $speakerVtt  = $ai->buildSpeakerVtt($transcript);

        file_put_contents($txtDir . '/' . $videoId . '.txt', $speakerText);
        file_put_contents($txtDir . '/' . $videoId . '.vtt', $speakerVtt);

        // -------- Cleanup audio --------
        @unlink($audioPath);

        $repo->setStatus($id, 'done');
        log_line("[job {$id}] DONE");
    } catch (\Throwable $e) {
        $msg = $e->getMessage();
        log_line("[job {$id}] ERROR: {$msg}");
        $repo->setError($id, $msg);

        // Best-effort cleanup
        foreach (glob($audioDir . '/' . $videoId . '.*') as $f) {
            @unlink($f);
        }
    }
}

/**
 * Run yt-dlp to download audio as m4a. Returns absolute path to the file.
 * Throws RuntimeException on failure.
 */
function download_audio(string $url, string $videoId, string $audioDir): string
{
    if (!is_dir($audioDir)) {
        mkdir($audioDir, 0777, true);
    }

    // Clean any previous attempts so the glob lookup below is unambiguous
    foreach (glob($audioDir . '/' . $videoId . '.*') as $f) {
        @unlink($f);
    }

    // Hardcoded .m4a (avoids %(ext)s template clash with Windows cmd.exe % expansion).
    // Since we use --audio-format m4a, the final file is guaranteed to be m4a.
    $output = $audioDir . '/' . $videoId . '.m4a';

    global $yt_dlp_bin;
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

    return $output;
}

function fetch_title(string $url): ?string
{
    global $yt_dlp_bin;
    // Suppress stderr (warnings) to nul/null so they can't contaminate the title.
    $nul = stripos(PHP_OS, 'WIN') === 0 ? 'NUL' : '/dev/null';
    $cmd = sprintf('%s%s --no-playlist --print title --skip-download %s 2>%s',
        escapeshellarg($yt_dlp_bin), YtDlp::cookiesFlag(), escapeshellarg($url), $nul);
    exec($cmd, $lines, $exit);
    if ($exit !== 0) {
        return null;
    }
    // Defensive: filter any leftover WARNING/ERROR lines, take last non-empty
    $clean = array_values(array_filter(array_map('trim', $lines), fn($l) =>
        $l !== '' && stripos($l, 'WARNING:') !== 0 && stripos($l, 'ERROR:') !== 0
    ));
    if (!$clean) {
        return null;
    }
    return end($clean);
}

function log_line(string $msg): void
{
    echo '[' . date('H:i:s') . '] ' . $msg . PHP_EOL;
}
