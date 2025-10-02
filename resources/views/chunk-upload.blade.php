<!doctype html>
<html>
<head>
  <meta name="csrf-token" content="{{ csrf_token() }}">
  <title>Chunked Image Upload</title>
  <style>
    .dropzone { border: 2px dashed #bbb; padding: 40px; text-align:center; cursor:pointer; }
    .progress { width: 100%; background:#eee; height: 20px; border-radius:3px; overflow:hidden; }
    .bar { height:100%; width:0; background: #4caf50; }
  </style>
</head>
<body>
  <div class="container" style="max-width:700px;margin:40px auto;">
    <h3>Drag & drop image (chunked + resumable)</h3>
    <div id="drop" class="dropzone">Drop file here or click to choose</div>
    <input id="fileInput" type="file" accept="image/*" style="display:none" />
    <div style="margin-top:10px;">
      <div class="progress"><div id="bar" class="bar"></div></div>
      <div id="status"></div>
    </div>
    <button id="startBtn">Start Upload</button>
  </div>

<script>
const CHUNK_SIZE = 1024 * 1024 * 2; // 2MB per chunk (tune as needed)
let file = null;
let uploadId = null;
let chunks = [];
let totalChunks = 0;
let uploadedIndices = new Set();

const drop = document.getElementById('drop');
const fileInput = document.getElementById('fileInput');
const bar = document.getElementById('bar');
const status = document.getElementById('status');
const startBtn = document.getElementById('startBtn');

drop.addEventListener('click', () => fileInput.click());
drop.addEventListener('dragover', (e) => { e.preventDefault(); drop.style.borderColor = '#666'; });
drop.addEventListener('dragleave', () => drop.style.borderColor = '#bbb');
drop.addEventListener('drop', (e) => { e.preventDefault(); drop.style.borderColor = '#bbb'; handleFile(e.dataTransfer.files[0]); });
fileInput.addEventListener('change', (e) => handleFile(e.target.files[0]));
startBtn.addEventListener('click', startUpload);

function log(msg) { status.innerText = msg; }

function handleFile(f) {
  file = f;
  drop.innerText = `Selected: ${f.name} (${Math.round(f.size/1024)} KB)`;
  // compute uploadId deterministically: name+size+lastModified hashed
  computeHashString(`${file.name}-${file.size}-${file.lastModified}`).then(h => {
    uploadId = h.slice(0, 32); // shorter id ok
    prepareChunks();
  });
}

async function computeHashString(str) {
  const enc = new TextEncoder();
  const data = enc.encode(str);
  const hashBuffer = await crypto.subtle.digest('SHA-256', data);
  const hashArray = Array.from(new Uint8Array(hashBuffer));
  return hashArray.map(b => b.toString(16).padStart(2,'0')).join('');
}

// compute actual file checksum (sha256 of whole file) — used to validate server
async function computeFileChecksum(file) {
  const chunkSize = 1024 * 1024 * 4; // 4MB for hashing to not OOM
  const chunks = Math.ceil(file.size / chunkSize);
  const hashBuffer = new Uint8Array();
  // We'll compute using subtle by concatenating ArrayBuffers progressively:
  // But Subtle doesn't allow incremental hashing; full-file hashing in browser requires libs
  // For practicality we'll read whole file at once when <= 100MB; else skip
  if (file.size > 200 * 1024 * 1024) {
    // too big for client-side hash in browser reliably
    return null;
  }
  const arrayBuffer = await file.arrayBuffer();
  const hash = await crypto.subtle.digest('SHA-256', arrayBuffer);
  const hArray = Array.from(new Uint8Array(hash));
  return hArray.map(b => b.toString(16).padStart(2,'0')).join('');
}

function prepareChunks() {
  if (!file) return;
  chunks = [];
  totalChunks = Math.ceil(file.size / CHUNK_SIZE);
  for (let i=0;i<totalChunks;i++) {
    const start = i * CHUNK_SIZE;
    const end = Math.min(file.size, start + CHUNK_SIZE);
    chunks.push({ index: i, start, end });
  }
  log(`Prepared ${totalChunks} chunks`);
}

async function startUpload() {
  if (!file) return log('No file selected');
  if (!uploadId) return log('No uploadId');

  // 1) compute file checksum (optional but preferred)
  log('Computing file checksum (sha256)...');
  const checksum = await computeFileChecksum(file);
  log('Checksum: ' + (checksum || 'skipped'));

  // 2) call /uploads/init to register upload
  await fetch('/uploads/init', {
    method: 'POST',
    headers: {
      'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
      'Content-Type': 'application/json'
    },
    body: JSON.stringify({
      upload_id: uploadId,
      filename: file.name,
      size: file.size,
      total_chunks: totalChunks,
      checksum: checksum
    })
  }).then(r => r.json()).then(j => console.log('init', j)).catch(e => console.error(e));

  // 3) ask server which chunks exist (resume)
  const statusResp = await fetch(`/uploads/status/${uploadId}`);
  const statusJson = await statusResp.json();
  const existing = new Set(statusJson.uploaded.map(x => parseInt(x)));

  // mark uploadedIndices
  uploadedIndices = existing;

  // 4) upload chunks one by one with retry (could parallelize)
  for (const c of chunks) {
    if (uploadedIndices.has(c.index.toString()) || uploadedIndices.has(c.index)) {
      updateProgress();
      continue;
    }

    // slice
    const blob = file.slice(c.start, c.end);
    let attempts = 0;
    const maxAttempts = 5;
    let ok = false;
    while (attempts < maxAttempts && !ok) {
      attempts++;
      try {
        const fd = new FormData();
        fd.append('upload_id', uploadId);
        fd.append('index', c.index);
        fd.append('total', totalChunks);
        fd.append('chunk', blob, file.name + '.part.' + c.index);

        const res = await fetch('/uploads/chunk', {
          method: 'POST',
          headers: {
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
          },
          body: fd
        });
        if (!res.ok) throw new Error('Upload chunk failed: ' + res.status);
        const data = await res.json();
        if (data.success) {
          ok = true;
          uploadedIndices.add(c.index);
          updateProgress();
        } else {
          throw new Error(data.message || 'Unknown');
        }
      } catch (err) {
        console.warn('Chunk', c.index, 'attempt', attempts, 'failed', err);
        await new Promise(r => setTimeout(r, 1000 * attempts)); // backoff
      }
    }
    if (!ok) {
      return log('Failed uploading chunk ' + c.index);
    }
  }

  // 5) tell server to complete (validate checksum & create variants)
  log('All chunks uploaded. Requesting completion...');
  const completeResp = await fetch('/uploads/complete', {
    method: 'POST',
    headers: {
      'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
      'Content-Type': 'application/json'
    },
    body: JSON.stringify({
      upload_id: uploadId,
      checksum: checksum, // optional but helpful
      // optionally attach entity link:
      entity_type: null,
      entity_id: null,
      is_primary: true
    })
  });
  const completeJson = await completeResp.json();
  if (completeJson.success) {
    log('Upload completed and processed');
  } else {
    log('Complete error: ' + (completeJson.message || 'unknown'));
  }
}

function updateProgress() {
  const done = uploadedIndices.size;
  const pct = Math.round((done / totalChunks) * 100);
  bar.style.width = pct + '%';
  log(`Uploaded chunks: ${done}/${totalChunks} (${pct}%)`);
}
</script>
</body>
</html>
