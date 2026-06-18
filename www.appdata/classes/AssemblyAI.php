<?php

/**
 * AssemblyAI PHP Client
 *
 * Handles upload, transcription, polling, and export for pre-recorded audio files.
 * Supports speaker diarization and multiple output formats.
 *
 * @see documentation.md for Claude Code usage instructions
 */
class AssemblyAI
{
    private string $apiKey;
    private string $baseUrl = 'https://api.assemblyai.com';

    // Default config for English podcasts / lectures
    public const CONFIG_ENGLISH = [
        'speech_models'  => ['universal-3-pro', 'universal-2'],
        'language_code'  => 'en',
        'speaker_labels' => true,
        'prompt'         => 'Transcribe this audio. This is a podcast with multiple speakers discussing various topics including science, culture, and current events. Mandatory: Use standard spelling and the most contextually correct spelling of all proper nouns, names, and guest names.',
    ];

    // Config for Norwegian audio (Universal-2 only — U3 Pro Norwegian support is limited)
    public const CONFIG_NORWEGIAN = [
        'speech_models'  => ['universal-2'],
        'language_code'  => 'no',
        'speaker_labels' => true,
    ];

    // How often to poll while waiting for transcription (seconds)
    private int $pollIntervalSeconds = 5;

    // How many seconds to wait before giving up (default: 3 hours)
    private int $maxPollSeconds = 10800;


    public function __construct(string $apiKey)
    {
        $this->apiKey = $apiKey;
    }


    // -------------------------------------------------------------------------
    // PUBLIC API
    // -------------------------------------------------------------------------

    /**
     * Upload a local audio file to AssemblyAI.
     *
     * @param  string $filePath Absolute or relative path to the audio file.
     * @return string           The upload_url returned by AssemblyAI.
     * @throws RuntimeException On HTTP error or missing upload_url.
     */
    public function upload(string $filePath): string
    {
        if (!file_exists($filePath)) {
            throw new RuntimeException("File not found: {$filePath}");
        }

        $fileContents = file_get_contents($filePath);
        if ($fileContents === false) {
            throw new RuntimeException("Could not read file: {$filePath}");
        }

        $response = $this->request('POST', '/v2/upload', $fileContents, 'application/octet-stream');

        if (empty($response['upload_url'])) {
            throw new RuntimeException('Upload succeeded but no upload_url returned.');
        }

        return $response['upload_url'];
    }


    /**
     * Submit a transcription job.
     *
     * @param  string $audioUrl  URL to the audio (from upload() or a public URL).
     * @param  array  $config    Transcription config. Use AssemblyAI::CONFIG_ENGLISH or CONFIG_NORWEGIAN,
     *                           or build your own. Must include 'speech_models'.
     * @return string            Transcript ID for use with poll() and download methods.
     * @throws RuntimeException  On HTTP error or missing id.
     */
    public function transcribe(string $audioUrl, array $config): string
    {
        if (empty($config['speech_models'])) {
            throw new RuntimeException("Config must include 'speech_models'.");
        }

        $payload = array_merge(['audio_url' => $audioUrl], $config);
        $response = $this->request('POST', '/v2/transcript', $payload);

        if (empty($response['id'])) {
            throw new RuntimeException('Transcription job submitted but no id returned.');
        }

        return $response['id'];
    }


    /**
     * Poll until the transcript is complete, then return the full transcript object.
     *
     * Blocks until done or throws on error / timeout.
     *
     * @param  string $transcriptId
     * @return array  The full transcript object from the API.
     * @throws RuntimeException On transcription error or timeout.
     */
    public function poll(string $transcriptId): array
    {
        $waited = 0;

        while ($waited < $this->maxPollSeconds) {
            $transcript = $this->request('GET', "/v2/transcript/{$transcriptId}");

            switch ($transcript['status']) {
                case 'completed':
                    return $transcript;

                case 'error':
                    throw new RuntimeException(
                        "Transcription failed: " . ($transcript['error'] ?? 'Unknown error')
                    );

                case 'queued':
                case 'processing':
                    sleep($this->pollIntervalSeconds);
                    $waited += $this->pollIntervalSeconds;
                    break;

                default:
                    throw new RuntimeException("Unknown transcript status: {$transcript['status']}");
            }
        }

        throw new RuntimeException(
            "Transcription timed out after {$this->maxPollSeconds} seconds."
        );
    }


    /**
     * Download the transcript as a VTT subtitle file.
     *
     * Note: AssemblyAI's native VTT does not include speaker labels.
     * Use buildSpeakerVtt() if you need speaker labels in the VTT.
     *
     * @param  string $transcriptId
     * @param  int    $charsPerCaption  Max characters per caption block (default: 32).
     * @return string VTT content.
     * @throws RuntimeException On HTTP error.
     */
    public function downloadVtt(string $transcriptId, int $charsPerCaption = 32): string
    {
        return $this->requestRaw(
            'GET',
            "/v2/transcript/{$transcriptId}/vtt?chars_per_caption={$charsPerCaption}"
        );
    }


    /**
     * Download the transcript as plain text (no speaker labels, no timestamps).
     *
     * @param  string $transcriptId
     * @return string Plain text transcript.
     * @throws RuntimeException On HTTP error.
     */
    public function downloadText(string $transcriptId): string
    {
        return $this->requestRaw('GET', "/v2/transcript/{$transcriptId}/text");
    }


    /**
     * Build a speaker-labelled text file from the transcript object.
     *
     * Format:
     *   [00:00:00]
     *   Speaker A: First thing said.
     *   Speaker B: Response.
     *
     *   [00:10:00]
     *   Speaker A: ...
     *
     * A timestamp header is prepended whenever the elapsed time since the last
     * timestamp exceeds $timestampEverySeconds (default: 600 = every 10 minutes).
     *
     * Requires the transcript to have been submitted with speaker_labels: true.
     *
     * @param  array $transcript        The full transcript object returned by poll().
     * @param  int   $timestampEveryMs  How often to inject a timestamp, in milliseconds (default: 600000 = 10 min).
     * @return string                   Formatted text with speaker labels and periodic timestamps.
     * @throws RuntimeException         If utterances are missing (speaker_labels was not enabled).
     */
    public function buildSpeakerText(array $transcript, int $timestampEveryMs = 600000): string
    {
        if (!isset($transcript['utterances']) || !is_array($transcript['utterances'])) {
            throw new RuntimeException(
                "No utterances in transcript. Was speaker_labels enabled when transcribing?"
            );
        }

        $lines             = [];
        $lastTimestampMs   = -$timestampEveryMs; // Force a timestamp at the very start

        foreach ($transcript['utterances'] as $utterance) {
            $startMs = (int) $utterance['start'];
            $speaker = $utterance['speaker'] ?? 'Speaker ?';
            $text    = trim($utterance['text'] ?? '');

            if (empty($text)) {
                continue;
            }

            // Inject a timestamp header when enough time has elapsed
            if (($startMs - $lastTimestampMs) >= $timestampEveryMs) {
                if (!empty($lines)) {
                    $lines[] = ''; // blank line before timestamp
                }
                $lines[]         = '[' . $this->formatTimestamp($startMs) . ']';
                $lastTimestampMs = $startMs;
            }

            $lines[] = "Speaker {$speaker}: {$text}";
        }

        return implode("\n", $lines) . "\n";
    }


    /**
     * Build a VTT file that includes speaker labels in each caption cue.
     *
     * Standard VTT from downloadVtt() does not include speaker labels.
     * This method generates VTT directly from the utterances array so each
     * cue is prefixed with the speaker identifier.
     *
     * Requires speaker_labels: true in the original transcription config.
     *
     * @param  array $transcript  The full transcript object returned by poll().
     * @return string             VTT content with speaker labels.
     * @throws RuntimeException   If utterances are missing.
     */
    public function buildSpeakerVtt(array $transcript): string
    {
        if (!isset($transcript['utterances']) || !is_array($transcript['utterances'])) {
            throw new RuntimeException(
                "No utterances in transcript. Was speaker_labels enabled when transcribing?"
            );
        }

        $lines   = ['WEBVTT', ''];
        $counter = 1;

        foreach ($transcript['utterances'] as $utterance) {
            $startMs = (int) $utterance['start'];
            $endMs   = (int) $utterance['end'];
            $speaker = $utterance['speaker'] ?? '?';
            $text    = trim($utterance['text'] ?? '');

            if (empty($text)) {
                continue;
            }

            $lines[] = (string) $counter;
            $lines[] = $this->formatVttTimestamp($startMs) . ' --> ' . $this->formatVttTimestamp($endMs);
            $lines[] = "<v Speaker {$speaker}>{$text}";
            $lines[] = '';

            $counter++;
        }

        return implode("\n", $lines);
    }


    /**
     * One-shot: upload, transcribe, poll, and return the completed transcript object.
     *
     * Convenience wrapper for the common case of transcribing a local file.
     *
     * @param  string $filePath  Path to the audio file.
     * @param  array  $config    Transcription config (e.g. AssemblyAI::CONFIG_ENGLISH).
     * @return array             Completed transcript object.
     * @throws RuntimeException  On any step failing.
     */
    public function transcribeFile(string $filePath, array $config): array
    {
        $uploadUrl    = $this->upload($filePath);
        $transcriptId = $this->transcribe($uploadUrl, $config);
        return $this->poll($transcriptId);
    }


    // -------------------------------------------------------------------------
    // CONFIGURATION
    // -------------------------------------------------------------------------

    /**
     * Override the polling interval (default: 5 seconds).
     */
    public function setPollInterval(int $seconds): void
    {
        $this->pollIntervalSeconds = max(1, $seconds);
    }

    /**
     * Override the maximum time to wait for transcription (default: 10800 = 3 hours).
     */
    public function setMaxPollSeconds(int $seconds): void
    {
        $this->maxPollSeconds = max(10, $seconds);
    }


    // -------------------------------------------------------------------------
    // PRIVATE HELPERS
    // -------------------------------------------------------------------------

    /**
     * Make a JSON API request. Returns decoded array.
     */
    private function request(string $method, string $path, mixed $body = null, string $contentType = 'application/json'): array
    {
        $url  = $this->baseUrl . $path;
        $opts = [
            'http' => [
                'method'        => $method,
                'header'        => implode("\r\n", [
                    "Authorization: {$this->apiKey}",
                    "Content-Type: {$contentType}",
                ]),
                'ignore_errors' => true,
            ],
        ];

        if ($body !== null) {
            $opts['http']['content'] = ($contentType === 'application/json')
                ? json_encode($body)
                : $body;
        }

        $context  = stream_context_create($opts);
        $raw      = file_get_contents($url, false, $context);

        if ($raw === false) {
            throw new RuntimeException("HTTP request failed: {$method} {$path}");
        }

        $decoded = json_decode($raw, true);

        if (!is_array($decoded)) {
            throw new RuntimeException("Non-JSON response from {$method} {$path}: {$raw}");
        }

        // Surface API-level errors
        $statusLine = $http_response_header[0] ?? '';
        preg_match('/HTTP\/\S+ (\d+)/', $statusLine, $m);
        $httpStatus = (int) ($m[1] ?? 0);

        if ($httpStatus >= 400) {
            $message = $decoded['error'] ?? $decoded['message'] ?? $raw;
            throw new RuntimeException("API error {$httpStatus} on {$method} {$path}: {$message}");
        }

        return $decoded;
    }


    /**
     * Make a raw (non-JSON) request. Returns response body as string.
     * Used for /text and /vtt endpoints.
     */
    private function requestRaw(string $method, string $path): string
    {
        $url  = $this->baseUrl . $path;
        $opts = [
            'http' => [
                'method'        => $method,
                'header'        => "Authorization: {$this->apiKey}",
                'ignore_errors' => true,
            ],
        ];

        $context = stream_context_create($opts);
        $raw     = file_get_contents($url, false, $context);

        if ($raw === false) {
            throw new RuntimeException("HTTP request failed: {$method} {$path}");
        }

        $statusLine = $http_response_header[0] ?? '';
        preg_match('/HTTP\/\S+ (\d+)/', $statusLine, $m);
        $httpStatus = (int) ($m[1] ?? 0);

        if ($httpStatus >= 400) {
            throw new RuntimeException("API error {$httpStatus} on {$method} {$path}: {$raw}");
        }

        return $raw;
    }


    /**
     * Format milliseconds as HH:MM:SS for the speaker text file.
     */
    private function formatTimestamp(int $ms): string
    {
        $totalSeconds = intdiv($ms, 1000);
        $hours        = intdiv($totalSeconds, 3600);
        $minutes      = intdiv($totalSeconds % 3600, 60);
        $seconds      = $totalSeconds % 60;

        return sprintf('%02d:%02d:%02d', $hours, $minutes, $seconds);
    }


    /**
     * Format milliseconds as HH:MM:SS.mmm for VTT timestamps.
     */
    private function formatVttTimestamp(int $ms): string
    {
        $totalSeconds = intdiv($ms, 1000);
        $remainder    = $ms % 1000;
        $hours        = intdiv($totalSeconds, 3600);
        $minutes      = intdiv($totalSeconds % 3600, 60);
        $seconds      = $totalSeconds % 60;

        return sprintf('%02d:%02d:%02d.%03d', $hours, $minutes, $seconds, $remainder);
    }
}