/* =====================================================================
   Artifact Repository — UI layer (vanilla JS, no framework)
   Talks only to existing endpoints:
     • POST /browser/        (action=upload | mkdir)   — existing PHP handler
     • GET  /artifacts/...   (downloads)               — existing nginx
     • DELETE / MOVE /artifacts/...                    — existing nginx WebDAV methods
   ===================================================================== */
(() => {
  'use strict';

  const cfg  = JSON.parse(document.getElementById('app-config').textContent);
  const bs   = window.bootstrap;
  const root = document.documentElement;
  const $  = (s, c = document) => c.querySelector(s);
  const $$ = (s, c = document) => Array.from(c.querySelectorAll(s));
  const enc = (p) => p.split('/').map(encodeURIComponent).join('/');
  const abs = (u) => location.origin + u;
  // Credentials used in the "Copy curl / wget command" buttons.
  // Anyone who can open this page can read these, so only keep a read-only account here.
  const DL_USER = 'mygart';
  const DL_PASS = 'Yash@123';
  const store = {
    get(k) { try { return localStorage.getItem(k); } catch (e) { return null; } },
    set(k, v) { try { localStorage.setItem(k, v); } catch (e) { /* ignore */ } },
  };

  /* ---------- formatting ---------- */
  const fmtSize = (b) => {
    if (b >= 1073741824) return (b / 1073741824).toFixed(2) + ' GB';
    if (b >= 1048576) return (b / 1048576).toFixed(2) + ' MB';
    if (b >= 1024) return (b / 1024).toFixed(2) + ' KB';
    return b + ' B';
  };
  const fmtDuration = (s) => {
    s = Math.max(0, Math.round(s));
    if (s < 60) return s + 's';
    const m = Math.floor(s / 60);
    return m < 60 ? m + 'm ' + (s % 60) + 's' : Math.floor(m / 60) + 'h ' + (m % 60) + 'm';
  };
  const ago = (ts) => {
    const d = Math.floor(Date.now() / 1000 - ts);
    if (d < 45) return 'just now';
    const steps = [[31536000, 'year'], [2592000, 'month'], [604800, 'week'], [86400, 'day'], [3600, 'hour'], [60, 'minute']];
    for (const [sec, name] of steps) {
      if (d >= sec) { const n = Math.floor(d / sec); return n + ' ' + name + (n > 1 ? 's' : '') + ' ago'; }
    }
    return 'just now';
  };

  /* ---------- toast ---------- */
  const toastHost = $('#toastHost');
  function toast(message, type = 'success') {
    const icons = { success: 'bi-check-circle-fill', danger: 'bi-x-circle-fill', warning: 'bi-exclamation-triangle-fill', info: 'bi-info-circle-fill' };
    const el = document.createElement('div');
    el.className = 'toast toast-' + type;
    el.setAttribute('role', type === 'danger' ? 'alert' : 'status');
    el.setAttribute('aria-live', type === 'danger' ? 'assertive' : 'polite');
    el.setAttribute('aria-atomic', 'true');
    el.innerHTML = '<div class="toast-body"><i class="bi ' + (icons[type] || icons.info) + '" aria-hidden="true"></i>' +
      '<span class="toast-msg flex-grow-1"></span>' +
      '<button type="button" class="btn-close" data-bs-dismiss="toast" aria-label="Close"></button></div>';
    $('.toast-msg', el).textContent = message;
    toastHost.appendChild(el);
    if (bs) {
      const t = new bs.Toast(el, { delay: type === 'danger' ? 6500 : 3600 });
      el.addEventListener('hidden.bs.toast', () => el.remove());
      t.show();
    } else {
      el.classList.add('show');
      setTimeout(() => el.remove(), 4000);
    }
  }

  /* Flash message coming back from the PHP redirect (?msg=&type=) */
  (() => {
    const p = new URLSearchParams(location.search);
    const m = p.get('msg');
    if (!m) return;
    const t = ['success', 'danger', 'warning'].includes(p.get('type')) ? p.get('type') : 'success';
    toast(m, t);
    const keep = new URLSearchParams();
    if (cfg.path) keep.set('path', cfg.path);
    history.replaceState(null, '', location.pathname + (keep.toString() ? '?' + keep : ''));
  })();

  function go(msg, type = 'success') {
    const q = new URLSearchParams();
    if (cfg.path) q.set('path', cfg.path);
    q.set('msg', msg); q.set('type', type);
    location.href = '/browser/?' + q;
  }

  /* ---------- clipboard (works on plain HTTP too) ---------- */
  async function copyText(text) {
    try {
      if (navigator.clipboard && window.isSecureContext) { await navigator.clipboard.writeText(text); return true; }
    } catch (e) { /* fall through */ }
    const ta = document.createElement('textarea');
    ta.value = text; ta.setAttribute('readonly', '');
    ta.style.cssText = 'position:fixed;top:-1000px;opacity:0';
    document.body.appendChild(ta); ta.select();
    let ok = false;
    try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
    ta.remove();
    return ok;
  }
  async function copy(text, label) {
    const ok = await copyText(text);
    toast(ok ? label : 'Copy failed — please copy it manually.', ok ? 'success' : 'danger');
  }

  /* ---------- tooltips (delegated, created lazily) ---------- */
  if (bs) {
    new bs.Tooltip(document.body, {
      selector: '[data-tip]', container: 'body', placement: 'bottom', trigger: 'hover focus',
      delay: { show: 350, hide: 0 },
      title: function () { return this.getAttribute('data-tip'); },
    });
    document.addEventListener('click', (e) => {
      const t = e.target.closest('[data-tip]');
      const inst = t && bs.Tooltip.getInstance(t);
      if (inst) inst.hide();
    });
    document.addEventListener('show.bs.dropdown', (e) => {
      const inst = bs.Tooltip.getInstance(e.target);
      if (inst) inst.hide();
    });
  }

  /* ---------- theme ---------- */
  const themeBtn = $('#themeToggle');
  const setTheme = (t) => {
    root.setAttribute('data-bs-theme', t);
    store.set('ar-theme', t);
    themeBtn && themeBtn.setAttribute('aria-pressed', String(t === 'dark'));
  };
  themeBtn && themeBtn.setAttribute('aria-pressed', String(root.getAttribute('data-bs-theme') === 'dark'));
  themeBtn && themeBtn.addEventListener('click', () => setTheme(root.getAttribute('data-bs-theme') === 'dark' ? 'light' : 'dark'));

  /* ---------- sidebar (collapsible; off-canvas on mobile) ---------- */
  const desktop = matchMedia('(min-width: 992px)');
  const sbToggle = $('#sidebarToggle');
  const sidebarOpen = () => (desktop.matches ? !root.classList.contains('sb-collapsed') : root.classList.contains('sb-open'));
  const syncSb = () => sbToggle && sbToggle.setAttribute('aria-expanded', String(sidebarOpen()));
  function toggleSb(force) {
    const open = typeof force === 'boolean' ? force : !sidebarOpen();
    if (desktop.matches) {
      root.classList.toggle('sb-collapsed', !open);
      store.set('ar-sidebar', open ? 'open' : 'collapsed');
    } else {
      root.classList.toggle('sb-open', open);
    }
    syncSb();
  }
  sbToggle && sbToggle.addEventListener('click', () => toggleSb());
  $('#sidebarClose') && $('#sidebarClose').addEventListener('click', () => { toggleSb(false); sbToggle && sbToggle.focus(); });
  $('#sidebarBackdrop') && $('#sidebarBackdrop').addEventListener('click', () => toggleSb(false));
  desktop.addEventListener('change', () => {
    root.classList.remove('sb-open');
    root.classList.toggle('sb-collapsed', desktop.matches && store.get('ar-sidebar') === 'collapsed');
    syncSb();
  });
  syncSb();

  /* ---------- folder tree ---------- */
  (() => {
    const tree = $('.tree');
    if (!tree) return;
    const setExpanded = (li, open) => {
      li.setAttribute('aria-expanded', String(open));
      const ul = li.querySelector(':scope > ul');
      if (ul) ul.hidden = !open;
    };
    const visibleLinks = () => $$('.tree-link', tree).filter((a) => !a.closest('ul[hidden]'));
    if (!tree.querySelector('.tree-link[tabindex="0"]')) { const f = $('.tree-link', tree); if (f) f.tabIndex = 0; }

    tree.addEventListener('click', (e) => {
      const t = e.target.closest('.tree-toggle');
      if (!t) return;
      const li = t.closest('li');
      setExpanded(li, li.getAttribute('aria-expanded') !== 'true');
    });
    tree.addEventListener('keydown', (e) => {
      const a = e.target.closest('.tree-link');
      if (!a) return;
      const list = visibleLinks();
      const i = list.indexOf(a);
      const li = a.closest('li');
      let next = null;
      switch (e.key) {
        case 'ArrowDown': next = list[i + 1]; break;
        case 'ArrowUp': next = list[i - 1]; break;
        case 'Home': next = list[0]; break;
        case 'End': next = list[list.length - 1]; break;
        case 'ArrowRight':
          if (li.getAttribute('aria-expanded') === 'false') setExpanded(li, true);
          else if (li.getAttribute('aria-expanded') === 'true') next = li.querySelector(':scope > ul .tree-link');
          break;
        case 'ArrowLeft':
          if (li.getAttribute('aria-expanded') === 'true' && li.querySelector(':scope > .tree-row .tree-toggle')) setExpanded(li, false);
          else { const parent = li.parentElement.closest('li'); next = parent && parent.querySelector(':scope > .tree-row .tree-link'); }
          break;
        default: return;
      }
      e.preventDefault();
      if (next) { list.forEach((x) => { x.tabIndex = -1; }); next.tabIndex = 0; next.focus(); }
    });

    const sc = $('.sb-scroll'); const active = $('.tree-row.active');
    if (sc && active) sc.scrollTop = Math.max(0, active.offsetTop - sc.clientHeight / 2);
  })();

  /* ---------- endpoint copy ---------- */
  const endpoint = abs(cfg.baseUrl + '/');
  const epText = $('#endpointText'); if (epText) epText.textContent = endpoint;
  document.addEventListener('click', (e) => {
    if (e.target.closest('[data-action="copy-endpoint"]')) copy(endpoint, 'Endpoint URL copied');
  });

  /* ---------- refresh ---------- */
  const refreshBtn = $('#refreshBtn');
  const refresh = () => { refreshBtn && refreshBtn.classList.add('is-spinning'); setTimeout(() => location.reload(), 250); };
  refreshBtn && refreshBtn.addEventListener('click', refresh);

  /* ---------- relative times ---------- */
  const tickTimes = () => $$('[data-ts]').forEach((el) => { el.textContent = ago(+el.dataset.ts); });
  tickTimes(); setInterval(tickTimes, 60000);

  /* =====================================================================
     File table: instant search · sorting · lazy rendering
     ===================================================================== */
  const tbody = $('#fileRows');
  const rows = tbody ? $$('tr.item-row', tbody) : [];
  const noMatchRow = $('#noMatchRow');
  const resultCount = $('#resultCount');
  const loadMore = $('#loadMore');
  const PAGE = 100;
  const state = { q: '', key: 'name', dir: 1, limit: PAGE };
  rows.forEach((r) => { r._n = r.dataset.name.toLowerCase(); });
  const initialCount = resultCount ? resultCount.textContent : '';
  let moreAvailable = false;

  const nameCmp = (a, b) => a._n.localeCompare(b._n, undefined, { numeric: true, sensitivity: 'base' });
  function cmp(a, b) {
    if (a.dataset.type !== b.dataset.type) return a.dataset.type === 'dir' ? -1 : 1; // folders first
    let r;
    if (state.key === 'size' && a.dataset.type === 'file') r = (+a.dataset.size) - (+b.dataset.size);
    else if (state.key === 'mtime') r = (+a.dataset.mtime) - (+b.dataset.mtime);
    else r = nameCmp(a, b);
    if (r === 0) r = nameCmp(a, b);
    return r * state.dir;
  }

  function render() {
    if (!tbody) return;
    const matched = rows.filter((r) => !state.q || r._n.includes(state.q)).sort(cmp);
    const set = new Set(matched);
    const frag = document.createDocumentFragment();
    matched.forEach((r, i) => { r.hidden = i >= state.limit; frag.appendChild(r); });
    rows.forEach((r) => { if (!set.has(r)) r.hidden = true; });
    tbody.insertBefore(frag, noMatchRow);

    moreAvailable = matched.length > state.limit;
    if (loadMore) loadMore.hidden = !moreAvailable;
    if (noMatchRow) noMatchRow.hidden = !(state.q && matched.length === 0 && rows.length > 0);
    if (resultCount) {
      resultCount.textContent = state.q
        ? matched.length + ' of ' + rows.length + ' item' + (rows.length === 1 ? '' : 's') + ' match “' + state.q + '”'
        : initialCount;
    }
  }

  const search = $('#searchInput');
  if (search) {
    search.addEventListener('input', () => { state.q = search.value.trim().toLowerCase(); state.limit = PAGE; render(); });
    search.addEventListener('keydown', (e) => {
      if (e.key !== 'Escape') return;
      if (search.value) { search.value = ''; state.q = ''; state.limit = PAGE; render(); } else search.blur();
    });
  }

  $$('.th-sort').forEach((btn) => btn.addEventListener('click', () => {
    const key = btn.dataset.sort;
    if (state.key === key) state.dir *= -1;
    else { state.key = key; state.dir = key === 'mtime' ? -1 : 1; }
    $$('.th-sort').forEach((b) => {
      const on = b.dataset.sort === state.key;
      b.classList.toggle('active', on);
      b.closest('th').setAttribute('aria-sort', on ? (state.dir === 1 ? 'ascending' : 'descending') : 'none');
      const ic = $('i', b);
      ic.className = 'bi ' + (on && state.dir === -1 ? 'bi-arrow-down' : 'bi-arrow-up');
    });
    state.limit = PAGE;
    render();
  }));

  const growList = () => { if (moreAvailable) { state.limit += PAGE; render(); } };
  $('#loadMoreBtn') && $('#loadMoreBtn').addEventListener('click', growList);
  if (loadMore && 'IntersectionObserver' in window) {
    new IntersectionObserver((en) => { if (en[0].isIntersecting) growList(); }, { rootMargin: '300px' }).observe(loadMore);
  }
  render();

  /* =====================================================================
     Row actions: copy · rename · delete · properties
     ===================================================================== */
  const reasons = {
    401: 'Authentication required — please sign in again.',
    403: 'Permission denied on the server (check folder ownership).',
    404: 'Not found — it may already have been removed.',
    409: 'The server could not complete this (missing parent folder or permissions).',
    412: 'An item with that name already exists.',
    413: 'The server rejected the request as too large.',
    507: 'The server is out of disk space.',
  };
  const reason = (status) => reasons[status] || 'Server returned HTTP ' + status + '.';
  let pending = null;

  const delEl = $('#deleteModal'), renEl = $('#renameModal'), propEl = $('#propsModal');
  const delModal = bs && delEl && new bs.Modal(delEl);
  const renModal = bs && renEl && new bs.Modal(renEl);
  const propModal = bs && propEl && new bs.Modal(propEl);

  tbody && tbody.addEventListener('click', (e) => {
    const btn = e.target.closest('[data-action]');
    if (!btn) return;
    const row = btn.closest('tr.item-row');
    if (!row) return;
    const d = row.dataset;
    const url = abs(d.url);
    switch (btn.dataset.action) {
      case 'copy-url': copy(url, 'URL copied to clipboard'); break;
      case 'copy-curl': copy("curl -u " + DL_USER + ":'" + DL_PASS + "' -O '" + url + "'", 'curl command copied'); break;
      case 'copy-wget': copy("wget --user=" + DL_USER + " --password='" + DL_PASS + "' '" + url + "'", 'wget command copied'); break;
      case 'copy-upload': copy("curl -u " + DL_USER + ":'" + DL_PASS + "' -T ./FILE '" + url + "'", 'curl upload command copied'); break;
      case 'props': openProps(row); break;
      case 'rename': openRename(row); break;
      case 'delete': openDelete(row); break;
      default: break;
    }
  });

  /* Properties */
  function openProps(row) {
    const d = row.dataset;
    $('#pName').textContent = d.name;
    $('#pType').textContent = d.type === 'dir' ? 'Folder' : 'File';
    $('#pPath').textContent = '/' + d.rel;
    $('#pSize').textContent = d.type === 'dir' ? '—' : d.sizeh + ' (' + Number(d.size).toLocaleString() + ' bytes)';
    $('#pMod').textContent = d.mod;
    $('#pUrl').textContent = abs(d.url);
    pending = row;
    propModal && propModal.show();
  }
  $('#pCopy') && $('#pCopy').addEventListener('click', () => { if (pending) copy(abs(pending.dataset.url), 'URL copied to clipboard'); });

  /* Delete (nginx WebDAV DELETE) */
  const delErr = $('#deleteErr'), delBtn = $('#deleteConfirm');
  function openDelete(row) {
    pending = row;
    $('#deleteName').textContent = row.dataset.name;
    $('#deleteDirNote').hidden = row.dataset.type !== 'dir';
    delErr.hidden = true;
    delModal && delModal.show();
  }
  delBtn && delBtn.addEventListener('click', async () => {
    if (!pending) return;
    const name = pending.dataset.name;
    delBtn.disabled = true; delErr.hidden = true;
    try {
      const res = await fetch(pending.dataset.url, { method: 'DELETE', credentials: 'same-origin' });
      if (res.ok) { go("Deleted '" + name + "'."); return; }
      delErr.textContent = reason(res.status); delErr.hidden = false;
    } catch (err) {
      delErr.textContent = 'Network error — could not reach the server.'; delErr.hidden = false;
    }
    delBtn.disabled = false;
  });

  /* Rename (nginx WebDAV MOVE) */
  const renForm = $('#renameForm'), renInput = $('#renameInput'), renErr = $('#renameErr');
  function renameError(msg) {
    renErr.textContent = msg; renInput.classList.add('is-invalid'); renErr.style.display = 'block';
  }
  function openRename(row) {
    pending = row;
    renInput.value = row.dataset.name;
    renInput.classList.remove('is-invalid'); renErr.style.display = 'none';
    renModal && renModal.show();
  }
  renEl && renEl.addEventListener('shown.bs.modal', () => {
    renInput.focus();
    const dot = renInput.value.lastIndexOf('.');
    renInput.setSelectionRange(0, pending && pending.dataset.type === 'file' && dot > 0 ? dot : renInput.value.length);
  });
  renInput && renInput.addEventListener('input', () => { renInput.classList.remove('is-invalid'); renErr.style.display = 'none'; });
  renForm && renForm.addEventListener('submit', async (e) => {
    e.preventDefault();
    if (!pending) return;
    const d = pending.dataset;
    const nn = renInput.value.trim();
    if (nn === d.name) { renModal && renModal.hide(); return; }
    if (!nn || /[\\/]/.test(nn) || nn === '.' || nn === '..' || nn[0] === '.') {
      renameError('Enter a valid name (no slashes, cannot start with a dot).'); return;
    }
    const isDir = d.type === 'dir';
    const parent = d.rel.split('/').slice(0, -1).join('/');
    const newRel = (parent ? parent + '/' : '') + nn;
    const dest = abs(cfg.baseUrl + '/' + enc(newRel) + (isDir ? '/' : ''));
    const btn = $('#renameConfirm'); btn.disabled = true;
    try {
      const res = await fetch(d.url, { method: 'MOVE', headers: { Destination: dest, Overwrite: 'F' }, credentials: 'same-origin' });
      if (res.ok) { go("Renamed '" + d.name + "' to '" + nn + "'."); return; }
      renameError(reason(res.status));
    } catch (err) {
      renameError('Network error — could not reach the server.');
    }
    btn.disabled = false;
  });

  /* New-folder form: light client-side validation (server validates too) */
  const folderForm = $('#folderForm'), folderName = $('#folderName');
  $('#folderModal') && $('#folderModal').addEventListener('shown.bs.modal', () => folderName.focus());
  folderName && folderName.addEventListener('input', () => folderName.classList.remove('is-invalid'));
  folderForm && folderForm.addEventListener('submit', (e) => {
    const v = folderName.value.trim();
    if (!v || /[\\/]/.test(v) || v[0] === '.') {
      e.preventDefault();
      folderName.classList.add('is-invalid');
      $('#folderErr').textContent = 'Enter a valid name (no slashes, cannot start with a dot).';
      folderName.focus();
    } else folderName.value = v;
  });

  /* =====================================================================
     Upload: drag & drop, browse, queue with live progress
     Uses the same POST fields as the PHP form: action=upload, path, file
     ===================================================================== */
  const upEl = $('#uploadModal');
  const upModal = bs && upEl && new bs.Modal(upEl);
  const upForm = $('#uploadForm');
  const fileInput = $('#fileInput');
  const dropzone = $('#dropzone');
  const queueEl = $('#uploadQueue');
  const overlay = $('#dropOverlay');
  const queue = [];
  let running = false, needsRefresh = false;

  if (fileInput) { fileInput.multiple = true; fileInput.required = false; }
  upForm && upForm.addEventListener('submit', (e) => e.preventDefault());

  const openUpload = () => { if (upModal && !upEl.classList.contains('show')) upModal.show(); };

  function makeItem(file) {
    const li = document.createElement('li');
    li.className = 'uq-item';
    li.innerHTML =
      '<span class="ficon ft-default"><i class="bi bi-file-earmark-arrow-up"></i></span>' +
      '<div class="uq-body">' +
        '<div class="uq-top"><span class="uq-name"></span><span class="uq-size"></span></div>' +
        '<div class="progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" aria-label="Upload progress">' +
          '<div class="progress-bar progress-bar-striped progress-bar-animated" style="width:0%"></div></div>' +
        '<div class="uq-meta">Waiting…</div>' +
      '</div>' +
      '<div class="uq-side"><button type="button" class="icon-btn uq-cancel" data-tip="Cancel" aria-label="Cancel upload"><i class="bi bi-x-lg"></i></button></div>';
    $('.uq-name', li).textContent = file.name;
    $('.uq-name', li).title = file.name;
    $('.uq-size', li).textContent = fmtSize(file.size);
    const item = { file, el: li, state: 'queued', xhr: null };
    $('.uq-cancel', li).addEventListener('click', () => {
      if (item.state === 'uploading' && item.xhr) item.xhr.abort();
      else if (item.state === 'queued') { item.state = 'cancelled'; li.classList.add('is-cancelled'); setMeta(item, 'Cancelled'); $('.uq-side', li).innerHTML = ''; }
    });
    return item;
  }
  const setMeta = (it, t) => { $('.uq-meta', it.el).textContent = t; };
  const setProgress = (it, pct) => {
    $('.progress-bar', it.el).style.width = pct + '%';
    $('.progress', it.el).setAttribute('aria-valuenow', String(Math.round(pct)));
  };

  function addFiles(files) {
    if (!files.length) return;
    queueEl.hidden = false;
    files.forEach((f) => { const it = makeItem(f); queue.push(it); queueEl.appendChild(it.el); });
    pump();
  }

  function pump() {
    if (running) return;
    const next = queue.find((i) => i.state === 'queued');
    if (!next) { finish(); return; }
    upload(next);
  }

  function markDone(it) {
    it.state = 'done'; needsRefresh = true;
    it.el.classList.add('is-done');
    const bar = $('.progress-bar', it.el);
    bar.classList.remove('progress-bar-striped', 'progress-bar-animated');
    setProgress(it, 100);
    setMeta(it, 'Uploaded');
    $('.uq-side', it.el).innerHTML = '<span class="uq-state" aria-hidden="true"><i class="bi bi-check-circle-fill"></i></span>';
  }
  function markFail(it, msg) {
    it.state = 'error';
    it.el.classList.remove('is-failed'); void it.el.offsetWidth; it.el.classList.add('is-failed');
    $('.progress-bar', it.el).classList.remove('progress-bar-striped', 'progress-bar-animated');
    setMeta(it, msg);
    $('.uq-side', it.el).innerHTML =
      '<button type="button" class="icon-btn uq-retry" data-tip="Retry" aria-label="Retry upload"><i class="bi bi-arrow-repeat"></i></button>' +
      '<span class="uq-state" aria-hidden="true"><i class="bi bi-x-circle-fill"></i></span>';
    $('.uq-retry', it.el).addEventListener('click', () => {
      it.state = 'queued'; it.el.classList.remove('is-failed');
      const bar = $('.progress-bar', it.el); bar.classList.add('progress-bar-striped', 'progress-bar-animated');
      setProgress(it, 0); setMeta(it, 'Waiting…');
      $('.uq-side', it.el).innerHTML = '<button type="button" class="icon-btn uq-cancel" aria-label="Cancel upload"><i class="bi bi-x-lg"></i></button>';
      $('.uq-cancel', it.el).addEventListener('click', () => { if (it.state === 'uploading' && it.xhr) it.xhr.abort(); });
      pump();
    });
  }

  function upload(it) {
    running = true; it.state = 'uploading';
    const fd = new FormData();
    fd.append('action', 'upload');
    fd.append('path', cfg.path);
    fd.append('file', it.file);

    const xhr = new XMLHttpRequest();
    it.xhr = xhr;
    const started = Date.now();
    setMeta(it, 'Starting…');

    xhr.upload.onprogress = (e) => {
      if (!e.lengthComputable) return;
      const pct = (e.loaded / e.total) * 100;
      const secs = (Date.now() - started) / 1000 || 1;
      const speed = e.loaded / secs;
      setProgress(it, pct);
      setMeta(it, Math.round(pct) + '% · ' + fmtSize(speed) + '/s · ' + fmtDuration((e.total - e.loaded) / (speed || 1)) + ' left');
    };
    xhr.onload = () => {
      // The PHP handler answers with a redirect carrying ?msg=&type=
      let msg = '', type = '';
      try { const u = new URL(xhr.responseURL || location.href); msg = u.searchParams.get('msg') || ''; type = u.searchParams.get('type') || ''; } catch (e) { /* ignore */ }
      if (xhr.status >= 200 && xhr.status < 300 && msg && type === 'success') markDone(it);
      else if (msg) markFail(it, msg);
      else if (xhr.status === 413) markFail(it, 'Rejected by the server: file too large.');
      else if (xhr.status >= 400) markFail(it, 'Server error (HTTP ' + xhr.status + ').');
      else markFail(it, 'Not saved — the file may exceed PHP limits (upload_max_filesize / post_max_size).');
      done();
    };
    xhr.onerror = () => { markFail(it, 'Network error during upload.'); done(); };
    xhr.onabort = () => {
      it.state = 'cancelled'; it.el.classList.add('is-cancelled');
      setMeta(it, 'Cancelled'); $('.uq-side', it.el).innerHTML = ''; done();
    };
    xhr.open('POST', upForm.getAttribute('action') || '/browser/');
    xhr.send(fd);
  }
  function done() { running = false; pump(); }

  function finish() {
    const ok = queue.filter((i) => i.state === 'done').length;
    const bad = queue.filter((i) => i.state === 'error').length;
    if (!ok && !bad) return;
    if (bad === 0 && needsRefresh) {
      toast(ok + ' file' + (ok === 1 ? '' : 's') + ' uploaded successfully.', 'success');
      setTimeout(() => { if (!running) location.reload(); }, 1100);
    } else if (bad) {
      toast(bad + ' upload' + (bad === 1 ? '' : 's') + ' failed — see details in the upload window.', 'danger');
    }
  }
  upEl && upEl.addEventListener('hidden.bs.modal', () => { if (needsRefresh && !running) location.reload(); });
  window.addEventListener('beforeunload', (e) => { if (running) { e.preventDefault(); e.returnValue = ''; } });

  /* browse */
  fileInput && fileInput.addEventListener('change', () => { addFiles(Array.from(fileInput.files)); fileInput.value = ''; });
  dropzone && dropzone.addEventListener('keydown', (e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); fileInput.click(); } });

  /* drop handling (skips folders: only files can be sent through the PHP handler) */
  function handleDrop(dt) {
    const files = []; let skipped = 0;
    if (dt.items && dt.items.length) {
      for (const it of dt.items) {
        if (it.kind !== 'file') continue;
        const entry = it.webkitGetAsEntry ? it.webkitGetAsEntry() : null;
        if (entry && entry.isDirectory) { skipped++; continue; }
        const f = it.getAsFile(); if (f) files.push(f);
      }
    } else files.push(...Array.from(dt.files));
    if (skipped) toast('Folders can’t be dropped — create the folder first, then upload its files.', 'warning');
    if (files.length) { openUpload(); addFiles(files); }
  }
  const hasFiles = (e) => e.dataTransfer && Array.from(e.dataTransfer.types || []).includes('Files');

  if (dropzone) {
    ['dragenter', 'dragover'].forEach((ev) => dropzone.addEventListener(ev, (e) => { e.preventDefault(); dropzone.classList.add('dragover'); }));
    ['dragleave', 'dragend'].forEach((ev) => dropzone.addEventListener(ev, () => dropzone.classList.remove('dragover')));
    dropzone.addEventListener('drop', (e) => { e.preventDefault(); e.stopPropagation(); dropzone.classList.remove('dragover'); overlay.classList.remove('show'); dragDepth = 0; handleDrop(e.dataTransfer); });
  }
  let dragDepth = 0;
  window.addEventListener('dragenter', (e) => { if (!hasFiles(e)) return; e.preventDefault(); dragDepth++; if (!upEl.classList.contains('show')) overlay.classList.add('show'); });
  window.addEventListener('dragover', (e) => { if (!hasFiles(e)) return; e.preventDefault(); e.dataTransfer.dropEffect = 'copy'; });
  window.addEventListener('dragleave', (e) => { if (!hasFiles(e)) return; dragDepth = Math.max(0, dragDepth - 1); if (!dragDepth) overlay.classList.remove('show'); });
  window.addEventListener('drop', (e) => { if (!hasFiles(e)) return; e.preventDefault(); dragDepth = 0; overlay.classList.remove('show'); handleDrop(e.dataTransfer); });

  /* ---------- keyboard shortcuts ---------- */
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && root.classList.contains('sb-open')) { toggleSb(false); sbToggle && sbToggle.focus(); return; }
    if (e.ctrlKey || e.metaKey || e.altKey) return;
    if (e.target.closest('input, textarea, select, [contenteditable="true"]')) return;
    if (document.body.classList.contains('modal-open')) return;
    switch (e.key) {
      case '/': e.preventDefault(); search && search.focus(); break;
      case 'u': case 'U': e.preventDefault(); upModal && upModal.show(); break;
      case 'n': case 'N': e.preventDefault(); bs && bs.Modal.getOrCreateInstance($('#folderModal')).show(); break;
      case 'r': case 'R': refresh(); break;
      case 'd': case 'D': themeBtn && themeBtn.click(); break;
      default: break;
    }
  });
})();

