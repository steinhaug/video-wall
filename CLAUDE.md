# CLAUDE.md

Local single-user tool for batch-transcribing YouTube videos via AssemblyAI (with diarization) and browsing the results in a categorized video wall. Windows + Apache + PHP + MySQL. See `spec.md` for the full design.

## Read first
- `spec.md` — full architecture, schema, pipeline
- `docs-ai/Mysqli2_Compact_v1.9.md` — the `$mysqli` wrapper used everywhere
- `docs-ai/AssemblyAI_v1.0.md` — the transcription client (`$ai`)
- `docs-ai/PHP Coding Rules - AI Instructions.md` — backticks on columns, 0/1 for bools, every file requires `environment.php`

## Don't open
- `/vendor/` — Composer deps, read-only
- `/logs/` — Apache vhost logs, only enter when investigating a logged issue

## Architecture quick reference
```
environment.php          bootstrap: paths, credentials, $mysqli (+ AssemblyAI, VideoRepo, YtDlp classes)
credentials.php          gitignored: API keys, DB creds, $yt_dlp_bin, optional cookies config
worker.php               CLI pipeline: pending -> yt-dlp -> AssemblyAI -> done
worker-loop.bat          double-click launcher: `php worker.php --loop`
migrate.php              one-shot: applies www.appdata/migrations/*.sql
www/                     Apache docroot (http://video-wall.local/)
  index.php              wall UI
  api.php                JSON: add (single OR playlist), list, status, delete, transcript
  media.php              serves /storage from outside docroot, with HTTP Range
  player.php             on-demand mp4 download + HTML5 <video> + VTT track
www.appdata/classes/     VideoRepo, AssemblyAI, YtDlp (all auto-required by environment.php)
www.appdata/migrations/  SQL files, applied in name order
storage/                 audio (temp), transcripts (.txt+.vtt), video (mp4 cache)
```

## Common commands
- `php migrate.php` — apply schema (idempotent)
- `php worker.php` — process one pending job and exit
- `php worker.php --loop` — poll forever (or use `worker-loop.bat`)

## Project-specific gotchas
- **DB connection MUST be `utf8mb4`**, not `utf8` (legacy 3-byte). Emoji in titles otherwise break inserts with "Incorrect string value".
- **yt-dlp `-o` template:** hardcode `.m4a` / `.mp4` literal — `%(ext)s` is mangled by Windows `cmd.exe` (`%(` triggers variable-expansion, drops the `%`). PHP's `escapeshellarg` doesn't help.
- **yt-dlp stderr contamination:** never use `2>&1` when capturing programmatic output (e.g. `--print title`). Redirect to NUL/null or filter `WARNING:` / `ERROR:` lines.
- **Apache does not inherit user PATH on Windows.** All external binaries must be invoked via absolute path (`$yt_dlp_bin`).
- **Apache cannot decrypt Chrome cookies (DPAPI).** Cookies-from-browser only works for CLI scripts (worker.php). `YtDlp::expandPlaylist()` runs cookieless on purpose — private playlists must be made public.
- **Playlist URL detection:** `/playlist?list=...` → full playlist; `watch?v=...&list=...` → single video. The `/playlist` path is the discriminator.
- **Worker crash recovery:** `VideoRepo::resetStuckJobs()` resets `downloading`/`transcribing` rows back to `pending` at startup (idempotent).

## Stack assumptions
- PHP 8.x, MySQL 8.x via the `steinhaug/mysqli` wrapper (`$mysqli->execute()` / `execute1()` — not raw mysqli prepare)
- Frontend: vanilla HTML + JS, no build step, no Node
- All column/table names wrapped in backticks
