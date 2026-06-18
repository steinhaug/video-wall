<?php
/**
 * Thin repository around the `videos` table.
 * All SQL lives here so worker.php / api.php stay business-logic focused.
 */
class VideoRepo
{
    private Mysqli2 $db;

    public function __construct(Mysqli2 $db)
    {
        $this->db = $db;
    }

    /**
     * Extract the 11-character YouTube video id from any common URL form.
     * Returns null if no id can be found.
     */
    public static function extractVideoId(string $url): ?string
    {
        $url = trim($url);

        $patterns = [
            '~(?:youtube\.com/watch\?(?:.*&)?v=)([A-Za-z0-9_-]{11})~i',
            '~(?:youtu\.be/)([A-Za-z0-9_-]{11})~i',
            '~(?:youtube\.com/(?:embed|v|shorts|live)/)([A-Za-z0-9_-]{11})~i',
        ];

        foreach ($patterns as $rx) {
            if (preg_match($rx, $url, $m)) {
                return $m[1];
            }
        }

        // Bare 11-char id pasted directly
        if (preg_match('~^[A-Za-z0-9_-]{11}$~', $url)) {
            return $url;
        }

        return null;
    }

    /**
     * Insert a new video. Returns the new id, or the existing id if video_id already present.
     * Returns null if INSERT failed for any other reason.
     */
    /**
     * Insert a new video. Returns the new id, or the existing id if video_id already present.
     * Pass $title to pre-populate (e.g. from playlist expansion) so the worker can skip
     * the title-fetch step. Returns null only on INSERT failure.
     */
    public function add(string $youtubeUrl, string $videoId, string $category, ?string $title = null): ?int
    {
        $category = $category !== '' ? $category : 'Uncategorized';

        $existing = $this->getByVideoId($videoId);
        if ($existing !== null) {
            return (int) $existing['id'];
        }

        if ($title !== null && $title !== '') {
            $id = $this->db->execute(
                "INSERT INTO `videos` (`youtube_url`, `video_id`, `category`, `title`) VALUES (?, ?, ?, ?)",
                'ssss',
                [$youtubeUrl, $videoId, $category, $title]
            );
        } else {
            $id = $this->db->execute(
                "INSERT INTO `videos` (`youtube_url`, `video_id`, `category`) VALUES (?, ?, ?)",
                'sss',
                [$youtubeUrl, $videoId, $category]
            );
        }

        return is_int($id) && $id > 0 ? $id : null;
    }

    public function getById(int $id): ?array
    {
        return $this->db->execute1(
            "SELECT * FROM `videos` WHERE `id` = ?",
            'i',
            [$id],
            true
        );
    }

    public function getByVideoId(string $videoId): ?array
    {
        return $this->db->execute1(
            "SELECT * FROM `videos` WHERE `video_id` = ?",
            's',
            [$videoId],
            true
        );
    }

    public function listAll(): array
    {
        $rows = $this->db->execute(
            "SELECT `id`, `youtube_url`, `video_id`, `title`, `category`, `status`, `error_message`, `created_at`, `updated_at`
             FROM `videos` ORDER BY `created_at` DESC, `id` DESC"
        );
        return is_array($rows) ? $rows : [];
    }

    public function listStatuses(): array
    {
        $rows = $this->db->execute(
            "SELECT `id`, `status`, `title`, `error_message` FROM `videos`"
        );
        return is_array($rows) ? $rows : [];
    }

    public function getNextPending(): ?array
    {
        return $this->db->execute1(
            "SELECT * FROM `videos` WHERE `status` = 'pending' ORDER BY `id` ASC LIMIT 1",
            '',
            [],
            true
        );
    }

    public function setStatus(int $id, string $status): void
    {
        $this->db->execute(
            "UPDATE `videos` SET `status` = ? WHERE `id` = ?",
            'si',
            [$status, $id]
        );
    }

    public function setTitle(int $id, string $title): void
    {
        $this->db->execute(
            "UPDATE `videos` SET `title` = ? WHERE `id` = ?",
            'si',
            [$title, $id]
        );
    }

    public function setAssemblyaiId(int $id, string $assemblyaiId): void
    {
        $this->db->execute(
            "UPDATE `videos` SET `assemblyai_id` = ? WHERE `id` = ?",
            'si',
            [$assemblyaiId, $id]
        );
    }

    public function setError(int $id, string $message): void
    {
        $this->db->execute(
            "UPDATE `videos` SET `status` = 'error', `error_message` = ? WHERE `id` = ?",
            'si',
            [$message, $id]
        );
    }

    public function clearError(int $id): void
    {
        $this->db->execute(
            "UPDATE `videos` SET `error_message` = NULL WHERE `id` = ?",
            'i',
            [$id]
        );
    }

    /**
     * Reset jobs that were mid-flight when worker crashed → pending.
     * Run once at worker startup. Idempotent.
     */
    public function resetStuckJobs(): int
    {
        $n = $this->db->execute(
            "UPDATE `videos` SET `status` = 'pending', `assemblyai_id` = NULL
             WHERE `status` IN ('downloading','transcribing')"
        );
        return is_int($n) ? $n : 0;
    }

    public function delete(int $id): void
    {
        $this->db->execute(
            "DELETE FROM `videos` WHERE `id` = ?",
            'i',
            [$id]
        );
    }
}
