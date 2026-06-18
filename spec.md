# YouTube Transcribe Wall — Prosjektspesifikasjon

Lokalt single-user verktøy for å batch-transkribere YouTube-podcaster/forelesninger via AssemblyAI (med diarization), og bla i resultatene i en kategorisert "video-wall".

## Stack (besluttet)
- **Frontend:** HTML + vanilla JS. Ingen Node, ingen build-step.
- **Web-backend:** PHP (tynt JSON-API + serving av video-wall).
- **Worker:** PHP CLI-script (egen prosess, ikke via web). Kaller `yt-dlp` som binary.
- **DB:** SQLite (én fil, portabel).
- **Eksterne avhengigheter:** `yt-dlp` (standalone binary på PATH), AssemblyAI API-nøkkel.

To separate systemer som kun deler SQLite-fila + storage-mappa:
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
  /www                 # http://video-wall.local/ 
    index.php          # video-wall (HTML + JS inline eller egen .js)
    api.php            # JSON-API: add, list, status, delete
    player.php         # lokal video + <track> for "play with subtitles"
  /src
  /www.appdata
    /classes
      AssemblyAI.php   # upload, create, poll, fetch utterances/vtt
  /storage
    /audio             # temp, slettes etter transcribe
    /transcripts       # {video_id}.txt + {video_id}.vtt
    /thumbnails        # {video_id}.jpg
    /video             # kun for videoer brukt i "play with subtitles"
  worker.php           # CLI: plukker pending jobber, kjører hele pipeline
  credentials.php      # API-nøkkler og credentials (gitignored)
  environment.php      # main PHP include file, initiates $mysqli object
  data.sqlite
```
Thumbnail og transcript-filer navngis `{video_id}.*` → ingen path-felt i DB nødvendig.

## SQLite-schema
```sql
CREATE TABLE videos (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  youtube_url   TEXT NOT NULL,
  video_id      TEXT NOT NULL UNIQUE,   -- 11-tegns YouTube-ID, brukes til dedup + filnavn
  title         TEXT,
  category      TEXT DEFAULT 'Uncategorized',
  status        TEXT NOT NULL DEFAULT 'pending',
                -- pending | downloading | transcribing | done | error
  assemblyai_id TEXT,
  error_message TEXT,
  created_at    TEXT DEFAULT (datetime('now')),
  updated_at    TEXT DEFAULT (datetime('now'))
);
CREATE INDEX idx_status   ON videos(status);
CREATE INDEX idx_category ON videos(category);
```
**Dedup:** ekstraher `video_id` fra URL ved add. `INSERT OR IGNORE` på `video_id` → samme video legges aldri til to ganger. Thumbnail trengs ikke lastes ned separat; YouTube serverer `https://img.youtube.com/vi/{video_id}/hqdefault.jpg`. Last den lokalt KUN hvis du vil ha offline-wall.

## Worker-pipeline (worker.php)
Kjøres f.eks. `php worker.php` i en loop / cron / manuelt. Plukker én `pending` om gangen (eller N parallelt hvis ønskelig senere — start med sekvensielt).

```
1. SELECT * FROM videos WHERE status='pending' ORDER BY id LIMIT 1
2. status -> 'downloading'
   yt-dlp -x --audio-format m4a -o "storage/audio/{video_id}.%(ext)s" {youtube_url}
   (hent også tittel: yt-dlp --print title  ELLER --write-info-json)
   UPDATE title hvis tom.
3. status -> 'transcribing'
   a. POST audio-bytes til  https://api.assemblyai.com/v2/upload   -> upload_url
   b. POST https://api.assemblyai.com/v2/transcript
      body: { audio_url, speaker_labels: true, language_code: "en" (eller auto) }
      -> transcript id  (lagre i assemblyai_id)
   c. Poll GET /v2/transcript/{id} hvert ~10s til status == "completed" (eller "error")
4. Lagre to filer:
   - {video_id}.txt : bygg fra response.utterances[] :
        "Speaker {u.speaker}: {u.text}\n\n"  for hver utterance  (← DIARIZATION her)
   - {video_id}.vtt : GET /v2/transcript/{id}/vtt  -> skriv rått til fil
        (rene subs uten speaker-labels, til lokal player)
5. slett storage/audio/{video_id}.*   (ikke lenger nødvendig)
6. status -> 'done'
Ved exception i steg 2–5: status -> 'error', error_message = melding. Audio ryddes.
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
2. Render:
```html
<video controls>
  <source src="/storage/video/{video_id}.mp4" type="video/mp4">
  <track src="/storage/transcripts/{video_id}.vtt" kind="subtitles" srclang="en" default>
</video>
```
`<track>` krever **VTT, ikke SRT** — derfor genererer vi VTT, ikke SRT.

### api.php (JSON)
- `POST add`    { url, category } → ekstraher video_id, INSERT OR IGNORE, status=pending
- `GET  list`   → alle rader (for wall-render)
- `GET  status` → lettvekt poll for kort som ikke er done ennå
- `POST delete` { id } → slett rad + tilhørende filer

## Config / hemmeligheter
`src/config.php` (gitignored):
```php
return [
  'assemblyai_key' => '...',
  'storage'        => __DIR__ . '/../storage',
  'db'             => __DIR__ . '/../data.sqlite',
];
```
Bytte av AssemblyAI-konto = bytt nøkkel her. (Brukers eget ansvar ift. AssemblyAI sine vilkår om multiple gratiskontoer — utenfor spec.)

## Edge cases å håndtere
- Ugyldig / privat / aldersbegrenset YouTube-URL → yt-dlp feiler → status=error med melding.
- Allerede eksisterende video_id → ignorer, ikke dupliser.
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
1. `db.php` + migrasjon (lag tabellen ved første kjøring).
2. `worker.php` (full pipeline, sekvensiell, god feilhåndtering + logging til stdout).
3. `api.php` (add/list/status/delete).
4. `index.php` (wall + modal + kategorifilter).
5. `player.php` (on-demand videonedlasting + HTML5 player med track).

MVP = punkt 1–5 (wall fungerer, "play with subtitles" kan komme sist).

