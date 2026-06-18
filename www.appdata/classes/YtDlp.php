<?php
/**
 * Helpers for invoking the yt-dlp binary.
 * Centralises the cookies/JS-runtime flag building and the playlist expansion
 * call so worker.php, player.php and api.php don't duplicate the logic.
 */
class YtDlp
{
    /**
     * Build the auth/runtime flag string for yt-dlp invocations.
     * Returns either empty string or " --cookies-from-browser ... --js-runtimes ...".
     *
     * Relies on credential globals defined in credentials.php:
     *   $yt_dlp_browser, $yt_dlp_browser_profile, $yt_dlp_js_runtime
     */
    public static function cookiesFlag(): string
    {
        global $yt_dlp_browser, $yt_dlp_browser_profile, $yt_dlp_js_runtime;

        if (empty($yt_dlp_browser)) {
            return '';
        }

        $spec = $yt_dlp_browser;
        if (!empty($yt_dlp_browser_profile)) {
            $spec .= ':' . $yt_dlp_browser_profile;
        }
        $flags = ' --cookies-from-browser ' . escapeshellarg($spec);

        if (!empty($yt_dlp_js_runtime)) {
            $flags .= ' --js-runtimes ' . escapeshellarg($yt_dlp_js_runtime);
        }
        return $flags;
    }

    /**
     * True for URLs that point at a full playlist (youtube.com/playlist?list=...).
     * A normal watch URL with &list= is intentionally NOT treated as a playlist —
     * the user can still add a single video that happens to live in a playlist.
     */
    public static function isPlaylistUrl(string $url): bool
    {
        return (bool) preg_match(
            '~^https?://(?:www\.)?youtube\.com/playlist\?(?:.*&)?list=[A-Za-z0-9_-]+~i',
            trim($url)
        );
    }

    /**
     * Expand a YouTube playlist URL into a list of {video_id, title} entries
     * using `yt-dlp --flat-playlist -J`. No video data is downloaded.
     *
     * @return array<int, array{video_id: string, title: string}>
     * @throws RuntimeException on yt-dlp failure or unparseable output.
     */
    public static function expandPlaylist(string $url): array
    {
        global $yt_dlp_bin;

        // Capture stderr to a temp file so we can include the real error in the
        // exception when yt-dlp fails. Stdout (JSON) stays clean.
        $errFile = tempnam(sys_get_temp_dir(), 'ytdlp_err_');
        // No cookies: playlist expansion only needs public metadata. Apache cannot
        // decrypt Chrome's DPAPI-protected cookies anyway (runs as a different user).
        $cmd = sprintf(
            '%s --flat-playlist --no-warnings -J %s 2>%s',
            escapeshellarg($yt_dlp_bin),
            escapeshellarg($url),
            escapeshellarg($errFile)
        );

        exec($cmd, $lines, $exit);
        $stderr = @file_get_contents($errFile);
        @unlink($errFile);

        if ($exit !== 0) {
            $msg = trim((string) $stderr);
            if ($msg === '') {
                $msg = 'no stderr output';
            }
            throw new RuntimeException('yt-dlp playlist expansion failed (exit ' . $exit . '): ' . $msg);
        }

        $json = implode("\n", $lines);
        $data = json_decode($json, true);
        if (!is_array($data) || !isset($data['entries']) || !is_array($data['entries'])) {
            throw new RuntimeException('yt-dlp returned no playlist entries. stderr: ' . trim((string) $stderr));
        }

        $out = [];
        foreach ($data['entries'] as $entry) {
            $vid = $entry['id'] ?? null;
            if (!is_string($vid) || !preg_match('~^[A-Za-z0-9_-]{11}$~', $vid)) {
                continue; // skip non-video entries (e.g. unavailable, members-only)
            }
            $out[] = [
                'video_id' => $vid,
                'title'    => isset($entry['title']) ? (string) $entry['title'] : '',
            ];
        }

        return $out;
    }
}
