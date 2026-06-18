<?php
/**
 * On-demand local player with VTT captions.
 *
 *   /player.php?id={video_pk}
 *
 * First call for a given video triggers a yt-dlp mp4 download.
 * Subsequent calls reuse the cached file under storage/video/.
 */
require __DIR__ . '/../environment.php';

$repo = new VideoRepo($mysqli);

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    exit('Missing id');
}

$video = $repo->getById($id);
if ($video === null) {
    http_response_code(404);
    exit('Video not found');
}

if ($video['status'] !== 'done') {
    http_response_code(409);
    exit('Video transcription is not done yet (status: ' . htmlspecialchars($video['status']) . ').');
}

$videoId  = $video['video_id'];
$videoDir = STORAGE_PATH . '/video';
$mp4Path  = $videoDir . '/' . $videoId . '.mp4';
$vttPath  = STORAGE_PATH . '/transcripts/' . $videoId . '.vtt';

if (!is_dir($videoDir)) {
    mkdir($videoDir, 0777, true);
}

$downloadError = null;

if (!is_file($mp4Path)) {
    // Output as literal .mp4 (avoids %(ext)s template clash with Windows cmd.exe).
    // --merge-output-format mp4 ensures yt-dlp produces .mp4 even when fetching
    // separate video+audio streams from YouTube.
    $cmd = sprintf(
        '%s%s -f "bv*[ext=mp4]+ba[ext=m4a]/b[ext=mp4]/b" --merge-output-format mp4 --no-playlist -o %s %s 2>&1',
        escapeshellarg($yt_dlp_bin),
        YtDlp::cookiesFlag(),
        escapeshellarg($mp4Path),
        escapeshellarg($video['youtube_url'])
    );
    exec($cmd, $lines, $exit);
    if ($exit !== 0 || !is_file($mp4Path)) {
        $downloadError = 'yt-dlp failed: ' . htmlspecialchars(implode("\n", array_slice($lines, -10)));
    }
}

// Files live outside webroot; serve via media.php
$mp4WebPath = $downloadError ? null : ('media.php?type=video&id=' . urlencode($videoId));
$vttWebPath = is_file($vttPath) ? 'media.php?type=vtt&id=' . urlencode($videoId) : null;
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?= htmlspecialchars($video['title'] ?: $videoId) ?> — Player</title>
<style>
body {
  margin: 0;
  background: #0f1115;
  color: #e6e8ec;
  font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
  display: flex;
  flex-direction: column;
  min-height: 100vh;
}
header {
  padding: 12px 20px;
  border-bottom: 1px solid #2a2e38;
  display: flex;
  gap: 16px;
  align-items: center;
}
header h1 { margin: 0; font-size: 16px; font-weight: 500; flex: 1; }
a.back { color: #8b91a0; text-decoration: none; font-size: 13px; }
a.back:hover { color: #e6e8ec; }
.wrap { flex: 1; display: flex; justify-content: center; align-items: center; padding: 20px; }
video { max-width: 100%; max-height: 80vh; background: #000; }
.error { padding: 40px; color: #ff6b6b; text-align: center; }
</style>
</head>
<body>
<header>
  <a class="back" href="index.php">← Back to wall</a>
  <h1><?= htmlspecialchars($video['title'] ?: $videoId) ?></h1>
</header>
<div class="wrap">
<?php if ($downloadError): ?>
  <div class="error">
    <p>Could not download video for local playback.</p>
    <pre><?= $downloadError ?></pre>
  </div>
<?php else: ?>
  <video controls autoplay>
    <source src="<?= htmlspecialchars($mp4WebPath) ?>" type="video/mp4">
    <?php if ($vttWebPath): ?>
      <track src="<?= htmlspecialchars($vttWebPath) ?>" kind="subtitles" srclang="en" label="English" default>
    <?php endif; ?>
    Your browser does not support HTML5 video.
  </video>
<?php endif; ?>
</div>
</body>
</html>
