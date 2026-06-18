# AssemblyAI.php — Claude Code Reference

This document is the complete reference for using `AssemblyAI.php` in this project.
Do not call the AssemblyAI REST API directly. Use this class exclusively.

---

## Setup

```php
require_once 'AssemblyAI.php';

$ai = new AssemblyAI($assemblyai_api_key);
```

`$assemblyai_api_key` is available as a variable in the project. Do not hardcode it.

---

## Audio formats

Send files in their native format. Do not convert. All common formats are supported:
`.mp3`, `.mp4`, `.m4a`, `.wav`, `.flac`, `.ogg`, `.aac`, `.wma`, and more.

---

## Transcription configs

Two predefined configs are available as class constants.

### English (default for podcasts and lectures)

```php
AssemblyAI::CONFIG_ENGLISH
```

- Model: Universal-3 Pro with Universal-2 fallback
- Language: English
- Speaker labels: enabled
- Prompt: tuned for multi-speaker podcasts, correct proper noun spelling

### Norwegian

```php
AssemblyAI::CONFIG_NORWEGIAN
```

- Model: Universal-2 (best available for Norwegian)
- Language: `no`
- Speaker labels: enabled

### Custom config

Build your own array. `speech_models` is always required.

```php
$config = [
    'speech_models'  => ['universal-3-pro', 'universal-2'],
    'language_code'  => 'en',
    'speaker_labels' => true,
    'prompt'         => 'Custom prompt here.',
];
```

---

## Methods

### `transcribeFile($filePath, $config)` — one-shot convenience method

Upload, submit, and poll in a single call. Returns the completed transcript object.
Use this when you have a local file and want results without managing steps manually.

```php
$transcript = $ai->transcribeFile('/path/to/episode.mp3', AssemblyAI::CONFIG_ENGLISH);
```

Returns: `array` — the full transcript object (see Transcript object below).
Blocks until transcription is complete. A 1-hour audio file typically takes 2–4 minutes.

---

### `upload($filePath)` — step 1

Upload a local file. Returns the `upload_url` string.

```php
$uploadUrl = $ai->upload('/path/to/episode.mp3');
```

---

### `transcribe($uploadUrl, $config)` — step 2

Submit a transcription job. Returns the `transcript_id` string.

```php
$transcriptId = $ai->transcribe($uploadUrl, AssemblyAI::CONFIG_ENGLISH);
```

---

### `poll($transcriptId)` — step 3

Block until transcription completes. Returns the full transcript object.
Throws `RuntimeException` if the job fails or times out (default timeout: 3 hours).

```php
$transcript = $ai->poll($transcriptId);
```

---

### `buildSpeakerText($transcript, $timestampEveryMs)` — generate readable text file

Build a speaker-labelled text file from the transcript object.
This is the primary output format for use as a source document.

```php
$text = $ai->buildSpeakerText($transcript);
file_put_contents('transcript.txt', $text);
```

**Output format:**

```
[00:00:00]
Speaker A: Welcome to the show.
Speaker B: Thanks for having me.

[00:10:00]
Speaker A: Let's talk about the first topic.
```

- A `[HH:MM:SS]` timestamp header appears at the start and every 10 minutes by default.
- Change the interval with the second parameter (in milliseconds):

```php
// Timestamp every 5 minutes
$text = $ai->buildSpeakerText($transcript, 300000);

// Timestamp every 15 minutes
$text = $ai->buildSpeakerText($transcript, 900000);
```

Requires `speaker_labels: true` in the transcription config. Throws if utterances are missing.

---

### `buildSpeakerVtt($transcript)` — generate VTT with speaker labels

Build a WebVTT subtitle file where each cue is prefixed with the speaker identifier.
AssemblyAI's native `downloadVtt()` does not include speaker labels — use this instead.

```php
$vtt = $ai->buildSpeakerVtt($transcript);
file_put_contents('transcript.vtt', $vtt);
```

**Output format:**

```
WEBVTT

1
00:00:00.000 --> 00:00:04.210
<v Speaker A>Welcome to the show.

2
00:00:04.500 --> 00:00:07.880
<v Speaker B>Thanks for having me.
```

Requires `speaker_labels: true` in the transcription config. Throws if utterances are missing.

---

### `downloadVtt($transcriptId, $charsPerCaption)` — native VTT without speaker labels

Download VTT directly from the API. Does **not** include speaker labels.
Use `buildSpeakerVtt()` instead unless you specifically need the unspeakered version.

```php
$vtt = $ai->downloadVtt($transcriptId);
```

---

### `downloadText($transcriptId)` — plain text without speaker labels

Download plain text from the API. No speaker labels, no timestamps.
Use `buildSpeakerText()` instead for the labelled version.

```php
$plainText = $ai->downloadText($transcriptId);
```

---

## Transcript object

The object returned by `poll()` and `transcribeFile()` is an associative array.
Key fields:

| Field | Type | Description |
|---|---|---|
| `id` | string | Transcript ID |
| `status` | string | Always `completed` when returned from `poll()` |
| `text` | string | Full transcript as plain text |
| `utterances` | array | Speaker-segmented utterances (present when `speaker_labels: true`) |
| `words` | array | Word-level data with `start`, `end`, `text`, `confidence` |
| `audio_duration` | float | Duration in seconds |
| `language_code` | string | Detected or specified language |

Each utterance in `utterances[]`:

| Field | Type | Description |
|---|---|---|
| `speaker` | string | Speaker label (`"A"`, `"B"`, etc.) |
| `text` | string | What the speaker said |
| `start` | int | Start time in milliseconds |
| `end` | int | End time in milliseconds |
| `words` | array | Word-level breakdown for this utterance |

---

## Standard usage pattern

```php
require_once 'AssemblyAI.php';

$ai = new AssemblyAI($assemblyai_api_key);

// Transcribe a local English podcast file
$transcript = $ai->transcribeFile('/audio/episode-42.mp3', AssemblyAI::CONFIG_ENGLISH);

// Save the speaker-labelled text (primary source document)
$speakerText = $ai->buildSpeakerText($transcript);
file_put_contents('/output/episode-42.txt', $speakerText);

// Save the VTT with speaker labels
$speakerVtt = $ai->buildSpeakerVtt($transcript);
file_put_contents('/output/episode-42.vtt', $speakerVtt);
```

---

## Norwegian audio

```php
$transcript = $ai->transcribeFile('/audio/episode-no.mp3', AssemblyAI::CONFIG_NORWEGIAN);

$speakerText = $ai->buildSpeakerText($transcript);
file_put_contents('/output/episode-no.txt', $speakerText);

$speakerVtt = $ai->buildSpeakerVtt($transcript);
file_put_contents('/output/episode-no.vtt', $speakerVtt);
```

---

## Error handling

All methods throw `RuntimeException` on failure. Wrap in try/catch:

```php
try {
    $transcript = $ai->transcribeFile('/audio/episode.mp3', AssemblyAI::CONFIG_ENGLISH);
} catch (RuntimeException $e) {
    error_log('Transcription failed: ' . $e->getMessage());
    // Handle or rethrow
}
```

Common failure reasons:
- File not found or unreadable → thrown immediately in `upload()`
- API key invalid → thrown as HTTP 401 error
- Audio format unsupported → transcript status `error` with message
- Transcription service error → transcript status `error` with message
- Timeout → thrown after 3 hours of polling

---

## What NOT to do

- Do not call the AssemblyAI REST API directly with curl or file_get_contents
- Do not hardcode the API key
- Do not convert audio files before uploading — send native format
- Do not use `downloadVtt()` when you need speaker labels — use `buildSpeakerVtt()`
- Do not use `downloadText()` when you need speaker labels — use `buildSpeakerText()`
- Do not submit a config without `speech_models` — it will throw immediately