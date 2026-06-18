<?php
require __DIR__ . '/../environment.php';
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>YouTube Transcribe Wall</title>
<style>
:root {
  --bg: #0f1115;
  --bg-card: #1a1d24;
  --bg-card-pending: #15171c;
  --border: #2a2e38;
  --text: #e6e8ec;
  --text-dim: #8b91a0;
  --accent: #4f8cff;
  --accent-hover: #6ba0ff;
  --error: #ff6b6b;
  --success: #4caf50;
  --warning: #f0a500;
}
* { box-sizing: border-box; }
body {
  margin: 0;
  font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
  background: var(--bg);
  color: var(--text);
  min-height: 100vh;
}
header {
  padding: 16px 24px;
  border-bottom: 1px solid var(--border);
  display: flex;
  align-items: center;
  gap: 16px;
  flex-wrap: wrap;
  background: var(--bg-card);
}
header h1 {
  margin: 0;
  font-size: 18px;
  font-weight: 600;
}
.add-form {
  display: flex;
  gap: 8px;
  flex: 1;
  min-width: 320px;
}
.add-form input {
  background: var(--bg);
  color: var(--text);
  border: 1px solid var(--border);
  padding: 8px 12px;
  border-radius: 6px;
  font-size: 14px;
}
.add-form input[name="url"] { flex: 1; }
.add-form input[name="category"] { width: 160px; }
button {
  background: var(--accent);
  color: white;
  border: none;
  padding: 8px 16px;
  border-radius: 6px;
  font-size: 14px;
  cursor: pointer;
  font-weight: 500;
}
button:hover { background: var(--accent-hover); }
button.ghost {
  background: transparent;
  color: var(--text-dim);
  border: 1px solid var(--border);
}
button.ghost:hover { color: var(--text); border-color: var(--text-dim); background: transparent; }
button.danger { background: transparent; color: var(--error); border: 1px solid var(--border); }
button.danger:hover { background: var(--error); color: white; }

.add-msg {
  font-size: 13px;
  color: var(--text-dim);
  opacity: 0;
  transition: opacity 0.3s;
  margin-left: 8px;
  white-space: nowrap;
}
.add-msg.show { opacity: 1; }
.add-msg.success { color: var(--success); }
.add-msg.warn    { color: var(--warning); }
.add-msg.error   { color: var(--error); }
.add-form.busy input, .add-form.busy button { opacity: 0.6; pointer-events: none; }

.filters {
  padding: 12px 24px;
  display: flex;
  gap: 8px;
  flex-wrap: wrap;
  border-bottom: 1px solid var(--border);
}
.chip {
  background: var(--bg-card);
  border: 1px solid var(--border);
  color: var(--text-dim);
  padding: 6px 12px;
  border-radius: 999px;
  font-size: 13px;
  cursor: pointer;
}
.chip.active {
  background: var(--accent);
  color: white;
  border-color: var(--accent);
}

.grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
  gap: 16px;
  padding: 24px;
}

.card {
  background: var(--bg-card);
  border: 1px solid var(--border);
  border-radius: 10px;
  overflow: hidden;
  display: flex;
  flex-direction: column;
}
.card.pending {
  background: var(--bg-card-pending);
  opacity: 0.7;
}
.card .thumb {
  width: 100%;
  aspect-ratio: 16 / 9;
  background: #000;
  object-fit: cover;
  display: block;
}
.card .body { padding: 12px; flex: 1; display: flex; flex-direction: column; gap: 8px; }
.card .title {
  font-size: 14px;
  font-weight: 500;
  line-height: 1.35;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
  min-height: 38px;
}
.card .meta {
  display: flex;
  justify-content: space-between;
  align-items: center;
  font-size: 12px;
  color: var(--text-dim);
}
.badge {
  display: inline-block;
  padding: 3px 8px;
  border-radius: 4px;
  background: var(--border);
  color: var(--text);
  font-size: 11px;
  font-weight: 500;
}
.status {
  font-size: 11px;
  padding: 2px 8px;
  border-radius: 4px;
  font-weight: 500;
}
.status.pending      { background: #2a2e38; color: var(--text-dim); }
.status.downloading  { background: rgba(240,165,0,0.15); color: var(--warning); }
.status.transcribing { background: rgba(79,140,255,0.15); color: var(--accent); }
.status.done         { background: rgba(76,175,80,0.15); color: var(--success); }
.status.error        { background: rgba(255,107,107,0.15); color: var(--error); }

.actions { display: flex; gap: 6px; flex-wrap: wrap; margin-top: auto; }
.actions button { font-size: 12px; padding: 6px 10px; }
.error-msg { color: var(--error); font-size: 11px; }

/* Modal */
.modal-bg {
  position: fixed; inset: 0;
  background: rgba(0,0,0,0.7);
  display: none;
  align-items: center;
  justify-content: center;
  padding: 24px;
  z-index: 100;
}
.modal-bg.open { display: flex; }
.modal {
  background: var(--bg-card);
  border: 1px solid var(--border);
  border-radius: 10px;
  max-width: 800px;
  width: 100%;
  max-height: 85vh;
  display: flex;
  flex-direction: column;
}
.modal-head {
  padding: 14px 18px;
  border-bottom: 1px solid var(--border);
  display: flex;
  justify-content: space-between;
  align-items: center;
}
.modal-head h2 { margin: 0; font-size: 15px; font-weight: 600; }
.modal-body {
  padding: 18px;
  overflow: auto;
  font-family: ui-monospace, "Cascadia Code", "Consolas", monospace;
  font-size: 13px;
  line-height: 1.5;
  white-space: pre-wrap;
  color: var(--text);
}
.empty {
  padding: 60px 24px;
  text-align: center;
  color: var(--text-dim);
}
</style>
</head>
<body>

<header>
  <h1>YouTube Transcribe Wall</h1>
  <form class="add-form" id="addForm">
    <input type="text" name="url" placeholder="YouTube URL or video id" required>
    <input type="text" name="category" placeholder="Category" value="Uncategorized">
    <button type="submit">Add</button>
  </form>
  <button class="ghost" id="refreshBtn" title="Refresh">↻</button>
  <div id="addMsg" class="add-msg"></div>
</header>

<div class="filters" id="filters"></div>

<div id="grid" class="grid"></div>

<div class="modal-bg" id="modal">
  <div class="modal">
    <div class="modal-head">
      <h2 id="modalTitle">Transcript</h2>
      <button class="ghost" onclick="closeModal()">Close</button>
    </div>
    <div class="modal-body" id="modalBody">Loading…</div>
  </div>
</div>

<script>
const API = 'api.php';
let videos = [];
let activeCategory = 'all';

async function fetchList() {
  const r = await fetch(API + '?action=list');
  const j = await r.json();
  if (j.ok) {
    videos = j.items;
    render();
  }
}

async function pollStatuses() {
  // Cheap poll for active jobs only
  const hasActive = videos.some(v => v.status !== 'done' && v.status !== 'error');
  if (!hasActive) return;
  const r = await fetch(API + '?action=status');
  const j = await r.json();
  if (!j.ok) return;
  const byId = new Map(j.items.map(i => [i.id, i]));
  let changed = false;
  for (const v of videos) {
    const s = byId.get(v.id);
    if (s && (s.status !== v.status || s.title !== v.title || s.error_message !== v.error_message)) {
      v.status = s.status;
      v.title = s.title;
      v.error_message = s.error_message;
      changed = true;
    }
  }
  if (changed) render();
}

function render() {
  // Categories
  const cats = new Set(videos.map(v => v.category || 'Uncategorized'));
  const filtersEl = document.getElementById('filters');
  filtersEl.innerHTML = '';
  const allChip = chip('All (' + videos.length + ')', 'all');
  filtersEl.appendChild(allChip);
  [...cats].sort().forEach(c => {
    const count = videos.filter(v => (v.category || 'Uncategorized') === c).length;
    filtersEl.appendChild(chip(c + ' (' + count + ')', c));
  });

  // Grid
  const grid = document.getElementById('grid');
  grid.innerHTML = '';
  const visible = videos.filter(v => activeCategory === 'all' || (v.category || 'Uncategorized') === activeCategory);
  if (visible.length === 0) {
    grid.innerHTML = '<div class="empty">No videos yet. Paste a YouTube URL above to start.</div>';
    return;
  }
  for (const v of visible) grid.appendChild(card(v));
}

function chip(label, value) {
  const el = document.createElement('div');
  el.className = 'chip' + (activeCategory === value ? ' active' : '');
  el.textContent = label;
  el.onclick = () => { activeCategory = value; render(); };
  return el;
}

function card(v) {
  const el = document.createElement('div');
  el.className = 'card' + (v.status !== 'done' ? ' pending' : '');

  const img = document.createElement('img');
  img.className = 'thumb';
  img.src = v.thumbnail_url;
  img.alt = '';
  img.loading = 'lazy';
  el.appendChild(img);

  const body = document.createElement('div');
  body.className = 'body';

  const t = document.createElement('div');
  t.className = 'title';
  t.textContent = v.title || '(no title yet)';
  body.appendChild(t);

  const meta = document.createElement('div');
  meta.className = 'meta';
  const b = document.createElement('span');
  b.className = 'badge';
  b.textContent = v.category || 'Uncategorized';
  meta.appendChild(b);
  const s = document.createElement('span');
  s.className = 'status ' + v.status;
  s.textContent = v.status;
  meta.appendChild(s);
  body.appendChild(meta);

  if (v.status === 'error' && v.error_message) {
    const e = document.createElement('div');
    e.className = 'error-msg';
    e.textContent = v.error_message;
    body.appendChild(e);
  }

  const actions = document.createElement('div');
  actions.className = 'actions';
  if (v.status === 'done') {
    actions.appendChild(btn('Play on YouTube', () => window.open('https://youtube.com/watch?v=' + v.video_id, '_blank')));
    actions.appendChild(btn('Play with subtitles', () => window.open('player.php?id=' + v.id, '_blank')));
    actions.appendChild(btn('Transcript', () => openTranscript(v)));
  }
  actions.appendChild(btnDanger('Delete', () => del(v)));
  body.appendChild(actions);

  el.appendChild(body);
  return el;
}

function btn(label, fn) {
  const b = document.createElement('button');
  b.textContent = label;
  b.onclick = fn;
  return b;
}
function btnDanger(label, fn) {
  const b = btn(label, fn);
  b.className = 'danger';
  return b;
}

async function openTranscript(v) {
  document.getElementById('modalTitle').textContent = v.title || v.video_id;
  document.getElementById('modalBody').textContent = 'Loading…';
  document.getElementById('modal').classList.add('open');
  const r = await fetch(API + '?action=transcript&id=' + v.id);
  const j = await r.json();
  document.getElementById('modalBody').textContent = j.ok ? j.text : ('Error: ' + j.error);
}
function closeModal() { document.getElementById('modal').classList.remove('open'); }
document.getElementById('modal').addEventListener('click', e => {
  if (e.target.id === 'modal') closeModal();
});

async function del(v) {
  if (!confirm('Delete "' + (v.title || v.video_id) + '" and all its files?')) return;
  const r = await fetch(API + '?action=delete', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ id: v.id }),
  });
  const j = await r.json();
  if (j.ok) fetchList();
  else alert('Delete failed: ' + j.error);
}

function showAddMsg(text, kind) {
  const el = document.getElementById('addMsg');
  el.textContent = text;
  el.className = 'add-msg show ' + (kind || '');
  clearTimeout(showAddMsg._t);
  showAddMsg._t = setTimeout(() => el.classList.remove('show'), 5000);
}

document.getElementById('addForm').addEventListener('submit', async (e) => {
  e.preventDefault();
  const f = e.target;
  const isPlaylist = /\/playlist\?(?:.*&)?list=/i.test(f.url.value);
  f.classList.add('busy');
  showAddMsg(isPlaylist ? 'Expanding playlist…' : 'Adding…', '');
  const payload = { url: f.url.value, category: f.category.value || 'Uncategorized' };
  try {
    const r = await fetch(API + '?action=add', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
    });
    const j = await r.json();
    if (!j.ok) {
      showAddMsg('Failed: ' + j.error, 'error');
      return;
    }
    f.url.value = '';
    if (j.mode === 'playlist') {
      const parts = [`Added ${j.added}`];
      if (j.skipped) parts.push(`skipped ${j.skipped} duplicate${j.skipped === 1 ? '' : 's'}`);
      showAddMsg(parts.join(', ') + ` (playlist: ${j.total} items)`, j.added ? 'success' : 'warn');
    } else if (j.skipped) {
      showAddMsg('Already in queue', 'warn');
    } else {
      showAddMsg('Added', 'success');
    }
    fetchList();
  } catch (err) {
    showAddMsg('Request failed: ' + err.message, 'error');
  } finally {
    f.classList.remove('busy');
  }
});

document.getElementById('refreshBtn').addEventListener('click', fetchList);

fetchList();
setInterval(pollStatuses, 5000);
</script>
</body>
</html>
