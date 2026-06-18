# YouTube Transcribe Wall — Prosjektspesifikasjon

Lokalt single-user verktøy for å batch-transkribere YouTube-podcaster/forelesninger via AssemblyAI (med diarization), og bla i resultatene i en kategorisert "video-wall".

## Stack (besluttet)
- **Frontend:** HTML + vanilla JS. Ingen Node, ingen build-step.
- **Web-backend:** PHP (tynt JSON-API + serving av video-wall).
- **Worker:** PHP CLI-script (egen prosess, ikke via web). Kaller `yt-dlp` som binary.
- **DB:** MySQL (via `$mysqli` initialisert i `environment.php`, Mysqli2-wrapper).
- **Eksterne avhengigheter:** `yt-dlp` (standalone binary på PATH), AssemblyAI API-nøkkel.

To separate systemer som kun deler MySQL-tabellen + storage-mappa:
1. Web-UI (request/response, rask).
2. Worker (lang-kjørende jobbkø).


## MySQL connection er klar og tilgjengelig fra $mysqli

environment.php initialiserer DB connection via $mysqli.

## yt-dlp

yt-dlp er allerede tilgjengelig fra terminal

## Filstruktur
```
/video-wall
  /docs-ai             # Documentation for Claude Code
  /logs                # Apache weblogs
  /www                 # http://video-wall.local/ (Apache docroot)
    index.php          # video-wall (HTML + JS inline)
    api.php            # JSON-API: add, list, status, delete, transcript
    media.php          # serverer /storage utenfor docroot (med HTTP Range)
    player.php         # lokal video + <track> for "play with subtitles"
  /src
  /www.appdata
    /classes
      AssemblyAI.php   # upload, create, poll, fetch utterances/vtt
      VideoRepo.php    # tynn repo rundt $mysqli for videos-CRUD
    /migrations
      001_videos.sql   # MySQL-schema for videos-tabellen
  /storage
    /audio             # temp, slettes etter transcribe
    /transcripts       # {video_id}.txt + {video_id}.vtt
    /thumbnails        # {video_id}.jpg
    /video             # kun for videoer brukt i "play with subtitles"
  worker.php           # CLI: plukker pending jobber, kjører hele pipeline
  migrate.php          # CLI: kjører .sql-filer fra www.appdata/migrations/
  credentials.php      # API-nøkkler og credentials (gitignored)
  environment.php      # main PHP include file, initiates $mysqli object
```
Thumbnail og transcript-filer navngis `{video_id}.*` → ingen path-felt i DB nødvendig.

## MySQL-schema
Definert i `www.appdata/migrations/001_videos.sql`. Kjøres én gang via `php migrate.php` (idempotent, bruker `CREATE TABLE IF NOT EXISTS`).

```sql
CREATE TABLE IF NOT EXISTS `videos` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `youtube_url`   VARCHAR(500) NOT NULL,
  `video_id`      VARCHAR(20)  NOT NULL,   -- 11-tegns YouTube-ID, brukes til dedup + filnavn
  `title`         VARCHAR(500) DEFAULT NULL,
  `category`      VARCHAR(100) NOT NULL DEFAULT 'Uncategorized',
  `status`        ENUM('pending','downloading','transcribing','done','error') NOT NULL DEFAULT 'pending',
  `assemblyai_id` VARCHAR(64)  DEFAULT NULL,
  `error_message` TEXT         DEFAULT NULL,
  `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_video_id` (`video_id`),
  KEY `idx_status` (`status`),
  KEY `idx_category` (`category`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```
**Dedup:** ekstraher `video_id` fra URL ved add. `UNIQUE KEY` på `video_id` + lookup-før-insert i `VideoRepo::add()` → samme video legges aldri til to ganger (samme video_id → returnerer eksisterende `id`). Thumbnail trengs ikke lastes ned separat; YouTube serverer `https://img.youtube.com/vi/{video_id}/hqdefault.jpg`. Last den lokalt KUN hvis du vil ha offline-wall.

## Worker-pipeline (worker.php)
Kjøres f.eks. `php worker.php` (én pass) eller `php worker.php --loop` (polling, 5s sleep mellom tomme runder). Plukker én `pending` om gangen sekvensielt.

Ved oppstart: `VideoRepo::resetStuckJobs()` setter alle `downloading`/`transcribing` tilbake til `pending` (worker krasj-recovery, idempotent).

```
1. SELECT * FROM videos WHERE status='pending' ORDER BY id ASC LIMIT 1
2. status -> 'downloading'
   yt-dlp -x --audio-format m4a --no-playlist -o "storage/audio/{video_id}.%(ext)s" {youtube_url}
   Hent også tittel hvis tom: yt-dlp --print title --skip-download {youtube_url}
3. status -> 'transcribing'
   AssemblyAI-klassen håndterer hele API-flyten:
   a. $ai->upload($audioPath)                            -> upload_url
   b. $ai->transcribe($uploadUrl, CONFIG_ENGLISH)        -> transcript_id (lagre i assemblyai_id)
   c. $ai->poll($transcriptId)                           -> ferdig transcript-objekt (blokkerer)
4. Lagre to filer:
   - {video_id}.txt : $ai->buildSpeakerText($transcript)
        Format: "[HH:MM:SS]" header hver 10. min + "Speaker {A/B/...}: {text}" per utterance
   - {video_id}.vtt : $ai->buildSpeakerVtt($transcript)
        VTT MED speaker-labels via <v Speaker A> cue-tags (NB: downloadVtt() har IKKE labels)
5. slett storage/audio/{video_id}.*   (ikke lenger nødvendig)
6. status -> 'done'
Ved Throwable i steg 2–5: status -> 'error', error_message = exception-melding. Audio ryddes.
```

## Frontend

### Video-wall (index.php)
- Grid av kort. Kort = thumbnail (`img.youtube.com/...`) + tittel + kategori-badge.
- Grupperbar/filtrerbar på `category` (klientside-JS holder; data fra api.php).
- Kort er **inaktivt** (gråtonet, ingen knapper) når `status != 'done'`. Vis status-tekst i stedet (f.eks. "transcribing...").
- Når `done`, tre knapper per kort:
  - **Play video** → åpne `https://youtube.com/watch?v={video_id}` i ny fane.
  - **Play with subtitles** → `player.php?id={video_id}` (se under).
  - **Open transcript** → modal som fetcher `{video_id}.txt` og viser den.

### player.php — lokal avspilling med undertekster
YouTube IFrame API tillater IKKE egne caption-tracks som overlay, og overlay oppå embed bryter ToS. Eneste fungerende vei:
1. Last ned videoen lokalt med yt-dlp (mp4): kun on-demand når brukeren klikker "play with subtitles", lagre til `storage/video/{video_id}.mp4`.
2. Siden `/storage` ligger utenfor webroot, serveres mediefilene via `media.php?type=video&id={video_id}` (mp4) og `media.php?type=vtt&id={video_id}` (VTT). `media.php` støtter HTTP Range så HTML5-video kan spole.
3. Render:
```html
<video controls>
  <source src="media.php?type=video&id={video_id}" type="video/mp4">
  <track src="media.php?type=vtt&id={video_id}" kind="subtitles" srclang="en" default>
</video>
```
`<track>` krever **VTT, ikke SRT** — derfor genererer vi VTT, ikke SRT.

### api.php (JSON)
- `POST add`        { url, category } → ekstraher video_id, sjekk om eksisterer (returner eksisterende id) ellers INSERT med status=pending
- `GET  list`       → alle rader (for wall-render), inkluderer `thumbnail_url`
- `GET  status`     → lettvekt poll: id, status, title, error_message (for kort som ikke er done)
- `GET  transcript` { id } → returnerer innholdet av `{video_id}.txt` for modal
- `POST delete`     { id } → slett rad + tilhørende filer (txt, vtt, mp4, audio-rester)

## Config / hemmeligheter
`credentials.php` i prosjektroten (gitignored, lastes av `environment.php`):
```php
$assemblyai_api_key = '...';

$mysql_host     = 'localhost';
$mysql_port     = '3306';
$mysql_user     = '...';
$mysql_password = '...';
$mysql_database = 'video_wall';
```
Bytte av AssemblyAI-konto = bytt `$assemblyai_api_key` her. (Brukers eget ansvar ift. AssemblyAI sine vilkår om multiple gratiskontoer — utenfor spec.)

## Edge cases å håndtere
- Ugyldig / privat / aldersbegrenset YouTube-URL → yt-dlp feiler → status=error med melding.
- Allerede eksisterende video_id → ikke dupliser (returner eksisterende `id` til UI).
- AssemblyAI status "error" i poll → status=error, lagre `response.error`.
- Worker krasjer midt i jobb → ved restart: rad står i `downloading`/`transcribing`. Enkleste reset: ved oppstart sett alle ikke-`done`/ikke-`error` tilbake til `pending` (idempotent siden upload på nytt er greit), eller la worker resume på `assemblyai_id` hvis satt. Start enkelt: reset til pending.
- Store filer / lang transcribe-tid → derfor er dette en worker, ikke en web-request.


## Mysqli class documentation

Enhanced MySQLi wrapper with simplified prepared statements and smart return values. Inherits all standard MySQLi methods.

/docs-ai/Mysqli2_Compact_v1.9.md

## AssemblyAI class documentation

Integrasjon mot AssemblyAI er allerede laget og aktivert i prosjektet. Dokumentasjon for bruk finner du her:

/docs-ai/AssemblyAI_v1.0.md

## Hva Claude Code skal bygge (rekkefølge)
1. `www.appdata/migrations/001_videos.sql` + `migrate.php` (kjøres én gang for å opprette tabellen).
2. `www.appdata/classes/VideoRepo.php` (tynn repo rundt `$mysqli` for videos-CRUD).
3. `worker.php` (full pipeline, sekvensiell, god feilhåndtering + logging til stdout).
4. `www/api.php` (add/list/status/delete/transcript).
5. `www/index.php` (wall + modal + kategorifilter).
6. `www/media.php` (serverer `/storage` fra utenfor docroot, med HTTP Range for video-seeking).
7. `www/player.php` (on-demand videonedlasting + HTML5 player med track).

MVP = punkt 1–7 (wall fungerer, "play with subtitles" kan komme sist).

