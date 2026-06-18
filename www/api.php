<?php
/**
 * JSON API for the video wall.
 *
 *   POST add    { url, category }     -> { ok, id, status }
 *   GET  list                          -> { ok, items: [...] }
 *   GET  status                        -> { ok, items: [{id,status,title,error_message}] }
 *   POST delete { id }                 -> { ok, deleted }
 *
 * Action is taken from ?action=, falling back to POST body 'action'.
 */
require __DIR__ . '/../environment.php';

header('Content-Type: application/json; charset=utf-8');

$repo = new VideoRepo($mysqli);

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = $_GET['action'] ?? $_POST['action'] ?? '';

// Accept JSON bodies too
$body = [];
if ($method === 'POST') {
    $raw = file_get_contents('php://input');
    if ($raw !== false && $raw !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $body = $decoded;
            if (!$action && isset($decoded['action'])) {
                $action = $decoded['action'];
            }
        }
    }
    if (!$body) {
        $body = $_POST;
    }
}

try {
    switch ($action) {
        case 'add':
            require_post($method);
            $url      = trim((string) ($body['url'] ?? ''));
            $category = trim((string) ($body['category'] ?? 'Uncategorized'));
            if ($url === '') {
                respond(400, ['ok' => false, 'error' => 'url is required']);
            }

            // --- Playlist mode -----------------------------------------------
            if (YtDlp::isPlaylistUrl($url)) {
                $entries = YtDlp::expandPlaylist($url);
                if (!$entries) {
                    respond(400, ['ok' => false, 'error' => 'Playlist is empty or could not be read']);
                }
                $added = 0;
                $skipped = 0;
                foreach ($entries as $entry) {
                    $videoUrl = 'https://www.youtube.com/watch?v=' . $entry['video_id'];
                    $existed  = $repo->getByVideoId($entry['video_id']) !== null;
                    $id = $repo->add($videoUrl, $entry['video_id'], $category, $entry['title']);
                    if ($id === null) {
                        continue;
                    }
                    if ($existed) {
                        $skipped++;
                    } else {
                        $added++;
                    }
                }
                respond(200, [
                    'ok'      => true,
                    'mode'    => 'playlist',
                    'added'   => $added,
                    'skipped' => $skipped,
                    'total'   => count($entries),
                ]);
            }

            // --- Single video mode -------------------------------------------
            $videoId = VideoRepo::extractVideoId($url);
            if ($videoId === null) {
                respond(400, ['ok' => false, 'error' => 'Could not extract a YouTube video id from url']);
            }

            $existed = $repo->getByVideoId($videoId) !== null;
            $id = $repo->add($url, $videoId, $category);
            if ($id === null) {
                respond(500, ['ok' => false, 'error' => 'Insert failed']);
            }
            $row = $repo->getById($id);
            respond(200, [
                'ok'      => true,
                'mode'    => 'single',
                'id'      => $id,
                'video'   => $row,
                'added'   => $existed ? 0 : 1,
                'skipped' => $existed ? 1 : 0,
            ]);
            break;

        case 'list':
            $items = $repo->listAll();
            // Attach thumbnail + transcript availability
            foreach ($items as &$it) {
                $it['thumbnail_url'] = 'https://img.youtube.com/vi/' . $it['video_id'] . '/hqdefault.jpg';
            }
            respond(200, ['ok' => true, 'items' => $items]);
            break;

        case 'status':
            respond(200, ['ok' => true, 'items' => $repo->listStatuses()]);
            break;

        case 'delete':
            require_post($method);
            $id = (int) ($body['id'] ?? 0);
            if ($id <= 0) {
                respond(400, ['ok' => false, 'error' => 'id is required']);
            }
            $video = $repo->getById($id);
            if ($video === null) {
                respond(404, ['ok' => false, 'error' => 'Not found']);
            }
            $repo->delete($id);
            // Clean up files
            $vid = $video['video_id'];
            foreach ([
                STORAGE_PATH . '/transcripts/' . $vid . '.txt',
                STORAGE_PATH . '/transcripts/' . $vid . '.vtt',
                STORAGE_PATH . '/video/'       . $vid . '.mp4',
                STORAGE_PATH . '/thumbnails/'  . $vid . '.jpg',
            ] as $f) {
                if (is_file($f)) @unlink($f);
            }
            foreach (glob(STORAGE_PATH . '/audio/' . $vid . '.*') as $f) {
                @unlink($f);
            }
            respond(200, ['ok' => true, 'deleted' => $id]);
            break;

        case 'transcript':
            $id = (int) ($_GET['id'] ?? 0);
            if ($id <= 0) {
                respond(400, ['ok' => false, 'error' => 'id is required']);
            }
            $video = $repo->getById($id);
            if ($video === null) {
                respond(404, ['ok' => false, 'error' => 'Not found']);
            }
            $path = STORAGE_PATH . '/transcripts/' . $video['video_id'] . '.txt';
            if (!is_file($path)) {
                respond(404, ['ok' => false, 'error' => 'Transcript file not found']);
            }
            respond(200, ['ok' => true, 'text' => file_get_contents($path)]);
            break;

        default:
            respond(400, ['ok' => false, 'error' => 'Unknown action']);
    }
} catch (\Throwable $e) {
    respond(500, ['ok' => false, 'error' => $e->getMessage()]);
}


function respond(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function require_post(string $method): void
{
    if ($method !== 'POST') {
        respond(405, ['ok' => false, 'error' => 'POST required']);
    }
}
