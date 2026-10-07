/**
 * Office attendance dashboard. Renders /admin/today, resolves pending face
 * reviews, manages config, generates reports, browses the audit trail.
 */
(function () {
  'use strict';

  const root = document.getElementById('atta-root');
  if (!root) return;

  const API = root.dataset.apiBase;
  let ticket = root.dataset.ticket;
  const ticketUrl = root.dataset.ticketUrl;

  async function refreshTicket() {
    try {
      const r = await fetch(ticketUrl, { credentials: 'same-origin' });
      const d = await r.json();
      if (d.ok) ticket = d.ticket;
    } catch (e) {}
  }
  setInterval(refreshTicket, 8 * 60 * 1000);

  // True only while the 30-second Today poll is running. Every other request
  // here is something the admin clicked, and tells the portal so: a GET through
  // attapi does not count as activity unless marked, or an admin who only reads
  // (dates, tabs, reports) for an hour would be signed out mid-use.
  let polling = false;

  async function api(path, opts = {}) {
    // The forwarder routes on ?p=<encoded path> (see attapi/index.php).
    const res = await fetch(API + encodeURIComponent(path), {
      credentials: 'same-origin',
      ...opts,
      headers: {
        Authorization: 'Bearer ' + ticket,
        ...(polling ? {} : { 'X-User-Action': '1' }),
        ...(opts.headers || {}),
      },
    });
    let data = {};
    try { data = await res.json(); } catch (e) {}
    if (res.status === 401) await refreshTicket();
    return { status: res.status, data };
  }

  function post(path, fields) {
    const form = new FormData();
    Object.entries(fields).forEach(([k, v]) => form.append(k, v));
    return api(path, { method: 'POST', body: form });
  }

  function esc(s) {
    const d = document.createElement('div');
    d.textContent = s == null ? '' : String(s);
    // innerHTML escapes < > &, but NOT quotes — and these values are also
    // interpolated into HTML attributes (data-name="…") and into single-quoted
    // inline onclick arguments. Escape both quote styles so an attribute cannot
    // be broken out of, whoever wrote the value (the Python backend stores some
    // of these fields without PHP's sanitize()).
    return d.innerHTML.replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }

  const dateInput = document.getElementById('atta-date');
  dateInput.value = new Date().toISOString().slice(0, 10);
  dateInput.addEventListener('change', loadDay);

  // ------------------------------------------------------------------- tabs

  document.querySelectorAll('.atta-tab').forEach((btn) => {
    btn.addEventListener('click', () => {
      document.querySelectorAll('.atta-tab').forEach((b) => b.classList.remove('is-active'));
      btn.classList.add('is-active');
      document.querySelectorAll('.atta-panel').forEach((p) => p.classList.add('hidden'));
      document.getElementById('atta-panel-' + btn.dataset.tab).classList.remove('hidden');
      if (btn.dataset.tab === 'config') loadConfig();
      if (btn.dataset.tab === 'reports') loadReports();
      if (btn.dataset.tab === 'audit') loadAudit();
    });
  });

  // ------------------------------------------------------------- day view

  let day = null;

  function counter(label, value, color) {
    return `<div class="card px-4 py-3.5">
      <div class="flex items-center gap-2"><span class="h-2 w-2 shrink-0 rounded-full bg-${color}-500"></span><span class="truncate text-xs font-medium uppercase tracking-wide text-gray-500">${label}</span></div>
      <div class="mt-1.5 text-2xl font-semibold tabular-nums tracking-tight text-gray-900">${value}</div></div>`;
  }

  // Frame viewers are wired by delegation on [data-frames] rather than an inline
  // onclick. Interpolating a name into onclick="…('x')" has no safe escaping:
  // leave the quotes raw and a name containing one injects script; entity-encode
  // them and the HTML parser hands JS a broken literal. A data attribute has one
  // unambiguous context.
  document.addEventListener('click', (e) => {
    const btn = e.target.closest('[data-frames]');
    if (btn) window.attaShowFrames(btn.dataset.frames, btn.dataset.framesTitle || '');
  });

  // Images can't carry the Authorization header; fetch them as blobs instead.
  window.attaShowFrames = async function (dir, title) {
    const modal = document.getElementById('atta-frames-modal');
    const body = document.getElementById('atta-frames-body');
    document.getElementById('atta-frames-title').textContent = title;
    body.innerHTML = '<div class="col-span-3 py-10 text-center text-sm text-gray-500"><i class="fas fa-circle-notch fa-spin mr-2 text-blue-500"></i>Loading…</div>';
    modal.classList.remove('hidden');
    // Every stored frame: a marking burst is 2 per challenge step (up to 8;
    // liveness v2 sends 6 — 13 with the optional colour flash), an
    // enrollment capture up to 20. The endpoint 404s past the last one.
    // Shown as data: URLs, not blob: ones. Production's nginx sends
    // `Content-Security-Policy: default-src https: data: ...`, which has no
    // blob: — so <img src="blob:..."> was silently refused and every frame
    // came out blank, although each one had downloaded fine. A page cannot
    // loosen a server-set policy, but data: is already allowed.
    const toDataUrl = (blob) => new Promise((resolve, reject) => {
      const reader = new FileReader();
      reader.onload = () => resolve(reader.result);
      reader.onerror = () => reject(reader.error);
      reader.readAsDataURL(blob);
    });
    const imgs = [];
    for (let i = 0; i < 24; i++) {
      const res = await fetch(API + encodeURIComponent(`/admin/frame?dir=${dir}&index=${i}`), {
        credentials: 'same-origin',
        headers: { Authorization: 'Bearer ' + ticket },
      });
      if (!res.ok) break;
      imgs.push(await toDataUrl(await res.blob()));
    }
    body.innerHTML = imgs.length
      ? imgs.map((u) => `<img src="${u}" class="aspect-[3/4] w-full rounded-lg bg-gray-100 object-cover ring-1 ring-gray-200">`).join('')
      : '<div class="col-span-3 py-10 text-center text-sm text-gray-500">Frames unavailable (purged or none stored).</div>';
  };

  async function loadDay() {
    const { status, data } = await api('/admin/today?day=' + dateInput.value);
    if (status !== 200) {
      // Say so on the page: returning silently left the office looking at a
      // blank dashboard whenever the backend was down.
      document.getElementById('atta-counters').innerHTML = '';
      document.getElementById('atta-panel-today').innerHTML =
        '<div class="alert alert-error"><i class="fas fa-exclamation-triangle mt-0.5"></i><div>'
        + '<b class="font-semibold">Attendance service unavailable.</b> '
        + esc((data && data.message) || `The attendance backend did not respond (HTTP ${status}).`)
        + ' Try again in a minute; if it persists, check the backend (see the attendance RUNBOOK).</div></div>';
      return;
    }
    day = data;

    document.getElementById('atta-counters').innerHTML =
      counter('Students', data.counts.total, 'gray') +
      counter('Present', data.counts.present, 'green') +
      counter('Excused', data.counts.excused, 'amber') +
      counter('Absent', data.counts.absent, 'red') +
      counter('Reviews', data.counts.pending_review, 'amber') +
      counter('Failed tries', data.counts.failed_attempts, 'purple');

    const badge = document.getElementById('atta-review-badge');
    badge.textContent = data.counts.pending_review;
    badge.classList.toggle('hidden', !data.counts.pending_review);

    renderToday();
    renderReviews();
    renderAttempts();
  }

  function statusChip(row) {
    if (!row.enrolled) return '<span class="badge badge-neutral">not enrolled</span>';
    const map = {
      self: ['badge-approved', 'Present ' + (row.marked_at_ist || '')],
      manual: ['badge-brand', 'Manual ' + (row.marked_at_ist || '')],
      excused: ['badge-pending', 'Excused'],
      absent: ['badge-rejected', 'Absent'],
    };
    const [cls, label] = map[row.status] || map.absent;
    return `<span class="badge ${cls}">${esc(label.trim())}</span>`;
  }

  function renderToday() {
    const w = day.window;
    const head = `
      <div class="card flex flex-wrap items-center gap-x-5 gap-y-3 px-5 py-3.5 text-sm">
        <span class="inline-flex items-center gap-2 font-semibold tabular-nums text-gray-900"><i class="far fa-calendar text-gray-400"></i>${day.date}</span>
        ${w.working_day
          ? `<span class="inline-flex flex-wrap items-center gap-2 text-gray-600"><i class="far fa-clock text-gray-400"></i>Window <b class="font-semibold tabular-nums text-gray-900">${w.start}–${w.end}</b>${w.extension ? ` <span class="badge badge-pending">+${w.extension}m extended</span>` : ''}</span>`
          : `<span class="inline-flex items-center gap-2 text-gray-500"><i class="fas fa-mug-hot text-gray-400"></i>Non-working day${w.label ? ' — ' + esc(w.label) : ''}</span>`}
        <button id="atta-extend" class="btn btn-sm ml-auto bg-amber-50 text-amber-800 ring-1 ring-inset ring-amber-200 hover:bg-amber-100">
          <i class="fas fa-clock"></i>Extend today
        </button>
      </div>`;

    // "reset" only appears for TODAY and where there is a mark to clear. It
    // voids the record so the student can mark again. Deliberately not offered
    // on past dates: a canned-reason void there would quietly turn a present
    // into an absence in a month the stipend report may already cover — use
    // "override" (which demands a typed reason) for that.
    const isToday = day.date === new Date().toISOString().slice(0, 10);
    const rows = day.rows.map((r) => `
      <tr>
        <td class="whitespace-nowrap font-mono text-xs text-gray-500">${esc(r.roll)}</td>
        <td class="whitespace-nowrap font-medium text-gray-900">${esc(r.name)}</td>
        <td class="whitespace-nowrap">${statusChip(r)}</td>
        <td class="min-w-[10rem] text-xs text-gray-500">${r.match_score != null ? 'match ' + r.match_score : ''}${r.reason ? ' · ' + esc(r.reason) : ''}</td>
        <td class="whitespace-nowrap text-right">
          <span class="inline-flex justify-end gap-1">
          ${r.frames_dir ? `<button class="btn btn-ghost btn-sm px-2" data-frames="${esc(r.frames_dir)}" data-frames-title="${esc(r.name)}"><i class="fas fa-images"></i>Photos</button>` : ''}
          ${isToday && ['self', 'manual', 'excused'].includes(r.status)
            ? `<button class="btn btn-ghost btn-sm px-2 text-rose-600 hover:bg-rose-50 hover:text-rose-700" data-act="reset" data-sid="${r.student_id}" data-name="${esc(r.name)}"><i class="fas fa-undo"></i>Reset</button>`
            : ''}
          <button class="btn btn-ghost btn-sm px-2" data-act="mark" data-sid="${r.student_id}" data-name="${esc(r.name)}"><i class="fas fa-user-edit"></i>Override</button>
          ${r.enrolled
            ? `<button class="btn btn-ghost btn-sm px-2 text-rose-600 hover:bg-rose-50 hover:text-rose-700" data-act="revoke" data-sid="${r.student_id}" data-name="${esc(r.name)}"><i class="fas fa-user-slash"></i>Revoke</button>`
            : `<button class="btn btn-ghost btn-sm px-2 text-amber-700 hover:bg-amber-50 hover:text-amber-800" data-act="enroll" data-sid="${r.student_id}" data-name="${esc(r.name)}"><i class="fas fa-key"></i>Enroll code</button>`}
          </span>
        </td>
      </tr>`).join('');

    document.getElementById('atta-panel-today').innerHTML = head + `
      <div class="card overflow-hidden">
        <div class="overflow-x-auto">
        <table class="table-modern">
          <thead>
            <tr><th>Roll</th><th>Name</th><th>Status</th><th>Detail</th>
                <th class="text-right">Actions</th></tr>
          </thead>
          <tbody>${rows}</tbody>
        </table>
        </div>
      </div>`;

    document.getElementById('atta-extend').onclick = async () => {
      const minutes = parseInt(prompt('Extend today\'s window by how many minutes?', '30'), 10);
      if (!minutes) return;
      const reason = prompt('Reason (mandatory, goes in the audit log):');
      if (!reason) return;
      const r = await post('/admin/extend', { minutes, reason, day: day.date });
      alert(r.data.ok ? 'Window now ends at ' + r.data.new_end : (r.data.message || 'Failed'));
      loadDay();
    };

    document.querySelectorAll('#atta-panel-today [data-act]').forEach((btn) => {
      btn.onclick = () => act(btn.dataset.act, parseInt(btn.dataset.sid, 10), btn.dataset.name);
    });
  }

  async function act(action, sid, name) {
    if (action === 'enroll') {
      const r = await post('/enroll/start', { student_id: sid });
      if (r.data.ok) {
        alert(`Enrollment code for ${name}:\n\n        ${r.data.token}\n\n`
          + `Valid 20 minutes, single use, and it replaces any earlier code for this student.\n\n`
          + `The student must be ON THE OFFICE WiFi and enter it on their own phone, over the `
          + `https:// address:\n  Student → Attendance → One-time enrollment.\n\n`
          + `Enrollment is refused off the office WiFi, so this code cannot be used to enroll a face remotely.`);
      } else alert(r.data.message || 'Failed');
    } else if (action === 'revoke') {
      const reason = prompt(`Revoke ${name}'s enrollment (lost/changed phone). Reason:`);
      if (!reason) return;
      const r = await post('/admin/revoke', { student_id: sid, reason });
      alert(r.data.ok ? 'Revoked. Issue a new enrollment code when they visit.' : (r.data.message || 'Failed'));
      loadDay();
    } else if (action === 'reset') {
      // A void version, not a delete: att_records is append-only, so the
      // original mark stays in the history with this void row superseding it.
      if (!confirm(`Clear ${name}'s attendance for ${day.date}?\n\n`
        + `They will show as absent and can mark again today. The original mark stays `
        + `in the audit history. Intended for testing.`)) return;
      const r = await post('/admin/mark', {
        student_id: sid, day: day.date, kind: 'void',
        reason: 'Reset by office so the student can mark again (testing)',
      });
      alert(r.data.ok ? `Cleared. ${name} can mark again today.` : (r.data.message || 'Failed'));
      loadDay();
    } else if (action === 'mark') {
      const kind = prompt(`Override for ${name} on ${day.date}.\nType one: manual (present), excused, void (cancel today's mark)`);
      if (!kind || !['manual', 'excused', 'void'].includes(kind.trim())) return;
      const reason = prompt('Reason (mandatory, appears in the monthly report):');
      if (!reason) return;
      const r = await post('/admin/mark', { student_id: sid, day: day.date, kind: kind.trim(), reason });
      alert(r.data.ok ? 'Recorded.' : (r.data.message || 'Failed'));
      loadDay();
    }
  }

  // ------------------------------------------------------------- reviews

  function renderReviews() {
    const panel = document.getElementById('atta-panel-review');
    if (!day.pending.length) {
      panel.innerHTML = '<div class="card p-5"><div class="empty-state"><div class="empty-state-icon"><i class="fas fa-user-check"></i></div>'
        + '<p class="empty-state-title">No pending face reviews.</p>'
        + '<p class="empty-state-description">Borderline matches will appear here instead of being marked absent.</p></div></div>';
      return;
    }
    panel.innerHTML = day.pending.map((p) => `
      <div class="card flex flex-col gap-4 p-4 sm:flex-row sm:items-center sm:p-5">
        <div class="flex min-w-0 flex-1 items-center gap-4">
          <button class="flex h-20 w-16 shrink-0 flex-col items-center justify-center gap-1.5 rounded-xl bg-gray-100 text-gray-400 ring-1 ring-inset ring-gray-200 transition hover:bg-blue-50 hover:text-blue-600 hover:ring-blue-200" data-frames="${esc(p.frames_dir)}" data-frames-title="${esc(p.name)}" title="View photos" aria-label="View photos">
            <i class="fas fa-images text-xl"></i><span class="text-[0.6875rem] font-medium">Photos</span>
          </button>
          <div class="min-w-0">
            <p class="font-semibold text-gray-900">${esc(p.name)} <span class="ml-1 font-mono text-xs font-normal text-gray-500">${esc(p.roll)}</span></p>
            <p class="mt-1 text-sm text-gray-500">at ${p.at_ist} · match ${p.match_score.toFixed(2)} · liveness ${p.liveness.toFixed(2)}</p>
          </div>
        </div>
        <div class="flex gap-2 sm:shrink-0">
          <button class="btn btn-success btn-sm flex-1 sm:flex-none" data-rev="approve" data-id="${p.attempt_id}"><i class="fas fa-check"></i>Approve</button>
          <button class="btn btn-soft-danger btn-sm flex-1 sm:flex-none" data-rev="reject" data-id="${p.attempt_id}"><i class="fas fa-times"></i>Reject</button>
        </div>
      </div>`).join('');

    panel.querySelectorAll('[data-rev]').forEach((btn) => {
      btn.onclick = async () => {
        const decision = btn.dataset.rev;
        const reason = decision === 'reject' ? prompt('Reason for rejection (mandatory):') : (prompt('Note (optional):') || '');
        if (decision === 'reject' && !reason) return;
        const r = await post('/admin/review', { attempt_id: btn.dataset.id, decision, reason });
        alert(r.data.ok ? 'Done.' : (r.data.message || 'Failed'));
        loadDay();
      };
    });
  }

  // ------------------------------------------------------------ attempts

  function renderAttempts() {
    const panel = document.getElementById('atta-panel-attempts');
    if (!day.failed.length) {
      panel.innerHTML = '<div class="card p-5"><div class="empty-state"><div class="empty-state-icon"><i class="fas fa-shield-alt"></i></div>'
        + '<p class="empty-state-title">No failed attempts on this day.</p></div></div>';
      return;
    }
    panel.innerHTML = `
      <div class="card overflow-hidden"><div class="overflow-x-auto">
      <table class="table-modern">
        <thead>
          <tr><th>Time</th><th>Roll</th><th>Code</th>
              <th>Cross-match</th><th>IP</th><th></th></tr>
        </thead><tbody>
        ${day.failed.map((f) => `
          <tr>
            <td class="whitespace-nowrap tabular-nums">${f.at_ist || ''}</td>
            <td class="whitespace-nowrap font-mono text-xs text-gray-500">${esc(f.roll || '?')}</td>
            <td><span class="badge badge-rejected font-mono">${esc(f.code)}</span></td>
            <td class="text-xs text-gray-500">${f.crossmatch_student_id ? '<span class="font-semibold text-rose-700">matched another student (#' + f.crossmatch_student_id + ')</span>' : ''}</td>
            <td class="whitespace-nowrap font-mono text-xs text-gray-500">${esc(f.source_ip || '')}</td>
            <td class="text-right">${f.frames_dir ? `<button class="btn btn-ghost btn-sm px-2" data-frames="${esc(f.frames_dir)}" data-frames-title="Attempt #${f.attempt_id}"><i class="fas fa-images"></i>Photos</button>` : ''}</td>
          </tr>`).join('')}
        </tbody></table></div></div>
      <p class="flex items-center gap-2 px-1 text-xs text-gray-500"><i class="fas fa-info-circle text-gray-400"></i>Every failed attempt is stored with its photos — a spoof attempt is its own evidence.</p>`;
  }

  // -------------------------------------------------------------- config

  async function loadConfig() {
    const { data } = await api('/admin/settings');
    if (!data.ok) return;
    const s = data.settings;
    const weekdays = ['', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

    document.getElementById('atta-panel-config').innerHTML = `
      <div class="grid gap-4 lg:grid-cols-2">
        <div class="card p-5">
          <h3 class="card-title flex items-center gap-2.5"><span class="icon-chip-sm chip-brand"><i class="fas fa-wifi"></i></span>Office WiFi (allowed IPs)</h3>
          <p class="mt-2 mb-3 text-xs leading-relaxed text-gray-500">Marking only works from these WAN IPs. Old entries are deactivated, never deleted.</p>
          <div class="divide-y divide-gray-100">
          ${data.allowed_ips.map((i) => `
            <div class="flex items-center gap-2 py-2 text-sm ${i.active ? '' : 'opacity-40 line-through'}">
              <span class="min-w-0 flex-1 truncate font-mono text-xs text-gray-900">${esc(i.ip)}</span>
              <span class="text-xs text-gray-500">${esc(i.label)}</span>
              ${i.active ? `<button class="btn btn-ghost btn-sm px-2 text-rose-600 hover:bg-rose-50 hover:text-rose-700" data-deact="${i.id}">Deactivate</button>` : '<span class="text-xs text-gray-500">inactive</span>'}
            </div>`).join('') || '<div class="flex items-center gap-2 py-2 text-sm font-medium text-rose-600"><i class="fas fa-exclamation-triangle"></i>No IPs configured — nobody can mark attendance yet.</div>'}
          </div>
          <div class="mt-3 flex flex-wrap items-center gap-2">
            <input id="atta-ip" placeholder="e.g. 10.119.4.27" class="form-input min-w-[10rem] flex-1 py-2 font-mono">
            <input id="atta-ip-label" placeholder="label" class="form-input w-28 py-2">
            <button id="atta-ip-add" class="btn btn-primary btn-sm py-2"><i class="fas fa-plus"></i>Add</button>
          </div>
        </div>

        <div class="card p-5">
          <h3 class="card-title flex items-center gap-2.5"><span class="icon-chip-sm chip-brand"><i class="fas fa-clock"></i></span>Weekly window</h3>
          <p class="mt-2 mb-3 text-xs leading-relaxed text-gray-500">Start inclusive, end exclusive. Holidays come from the portal's holiday calendar automatically.</p>
          <div class="divide-y divide-gray-100">
          ${data.windows.map((w) => `
            <div class="flex flex-wrap items-center gap-1.5 py-2 text-sm sm:gap-2">
              <span class="w-9 font-medium text-gray-900 sm:w-10">${weekdays[w.weekday]}</span>
              <input type="checkbox" class="h-4 w-4 rounded border-gray-300" data-wd-working="${w.weekday}" ${w.working ? 'checked' : ''}>
              <input type="time" value="${w.start}" data-wd-start="${w.weekday}" class="form-input w-auto px-2 py-1.5 sm:px-3">
              <span class="text-gray-400">–</span>
              <input type="time" value="${w.end}" data-wd-end="${w.weekday}" class="form-input w-auto px-2 py-1.5 sm:px-3">
              <button class="btn btn-ghost btn-sm px-2 text-blue-600 hover:bg-blue-50 hover:text-blue-700 ml-auto" data-wd-save="${w.weekday}">Save</button>
            </div>`).join('')}
          </div>
        </div>

        <div class="card p-5">
          <h3 class="card-title flex items-center gap-2.5"><span class="icon-chip-sm chip-brand"><i class="fas fa-sliders-h"></i></span>Verification thresholds</h3>
          <p class="mt-2 mb-3 text-xs leading-relaxed text-gray-500">Between the review and match thresholds, marks go to the Reviews queue instead of failing — nobody is auto-marked absent by a borderline score.</p>
          <div class="divide-y divide-gray-100">
          ${[['face_match_threshold', 'Auto-accept match ≥'], ['face_review_threshold', 'Send to review ≥'],
             ['liveness_threshold', 'Liveness ≥'], ['crossmatch_margin', 'Cross-match margin'],
             ['max_attempts_per_day', 'Max failed attempts/day'], ['frame_retention_days', 'Photo retention (days)'],
             ['enrollment_min_frames', 'Min enrollment frames'],
             // Liveness v2 only — see the RUNBOOK before changing these.
             ['review_skip_match', 'v2: no review when match ≥'],
             ['blink_closed_min', 'Blink: shut eyes score ≥'], ['blink_open_max', 'Blink: open eyes score ≤'],
             ['blink_min_diff', 'Blink: shut − open ≥'],
             ['antispoof_review_threshold', 'Anti-spoof: review at ≥'],
             ['flash_min_signal', 'Flash (off): min colour change'], ['flash_min_correlation', 'Flash (off): min correlation']].map(([k, label]) => `
            <div class="flex items-center gap-2 py-2 text-sm">
              <span class="min-w-0 flex-1 text-gray-700">${label}</span>
              <input value="${esc(s[k] || '')}" data-set="${k}" class="form-input w-20 py-1.5 text-right tabular-nums">
              <button class="btn btn-ghost btn-sm px-2 text-blue-600 hover:bg-blue-50 hover:text-blue-700" data-set-save="${k}">Save</button>
            </div>`).join('')}
          </div>
        </div>

        <div class="card p-5">
          <h3 class="card-title flex items-center gap-2.5"><span class="icon-chip-sm chip-brand"><i class="fas fa-shield-alt"></i></span>Modes</h3>
          <div class="mt-2 divide-y divide-gray-100">
          <div class="flex items-start gap-3 py-3 text-sm">
            <div class="min-w-0 flex-1"><p class="font-medium text-gray-900">Attendance enabled</p><p class="mt-0.5 text-xs text-gray-500">Master switch.</p></div>
            <input type="checkbox" class="mt-1 h-4 w-4 rounded border-gray-300" data-toggle="attendance_enabled" ${s.attendance_enabled === '1' ? 'checked' : ''}>
          </div>
          <div class="flex items-start gap-3 py-3 text-sm">
            <div class="min-w-0 flex-1"><p class="font-medium text-gray-900">Strict mode</p><p class="mt-0.5 text-xs leading-relaxed text-gray-500">Also require the rotating code from the office screen. Stops remote marking via a friend's tethered phone.</p></div>
            <input type="checkbox" class="mt-1 h-4 w-4 rounded border-gray-300" data-toggle="strict_mode" ${s.strict_mode === '1' ? 'checked' : ''}>
          </div>
          <div class="flex items-start gap-3 py-3 text-sm">
            <div class="min-w-0 flex-1"><p class="font-medium text-gray-900">Liveness v2 for everyone</p><p class="mt-0.5 text-xs leading-relaxed text-gray-500">One named head turn (left or right) and a blink, instead of the original turn-either-way check. Off: only the pilot students below get it.</p></div>
            <input type="checkbox" class="mt-1 h-4 w-4 rounded border-gray-300" data-toggle="liveness_v2" ${s.liveness_v2 === '1' ? 'checked' : ''}>
          </div>
          <div class="flex items-start gap-3 py-3 text-sm">
            <div class="min-w-0 flex-1"><p class="font-medium text-gray-900">Colour flash (liveness v2)</p><p class="mt-0.5 text-xs leading-relaxed text-gray-500">Off by default: adds ~3 s, and in bright rooms it cannot be judged. On: an extra step where the screen flashes colours.</p></div>
            <input type="checkbox" class="mt-1 h-4 w-4 rounded border-gray-300" data-toggle="flash_enabled" ${s.flash_enabled === '1' ? 'checked' : ''}>
          </div>
          <div class="py-3 text-sm">
            <p class="font-medium text-gray-900">Liveness v2 pilot students</p>
            <div class="mt-2 flex flex-wrap items-center gap-2">
              <input id="atta-lv2-students" value='${esc(s.liveness_v2_students || '[]')}' class="form-input min-w-[12rem] flex-1 py-2 font-mono text-xs">
              <button id="atta-lv2-save" class="btn btn-primary btn-sm py-2">Save</button>
            </div>
            <p class="mt-1.5 text-xs text-gray-500">JSON list of student ids (students.id), e.g. [12, 40]</p>
          </div>
          <div class="py-3 text-sm">
            <p class="font-medium text-gray-900">Report recipients</p>
            <div class="mt-2 flex flex-wrap items-center gap-2">
              <input id="atta-recipients" value='${esc(s.report_recipients || '[]')}' class="form-input min-w-[12rem] flex-1 py-2 font-mono text-xs">
              <button id="atta-recipients-save" class="btn btn-primary btn-sm py-2">Save</button>
            </div>
            <p class="mt-1.5 text-xs text-gray-500">JSON array, e.g. ["somoffice@iitb.ac.in"]</p>
          </div>
          <div class="py-3 text-sm">
            <p class="font-medium text-gray-900">Date override</p>
            <div class="mt-2 flex flex-wrap items-center gap-2">
              <input type="date" id="atta-ov-date" class="form-input w-auto py-2">
              <select id="atta-ov-working" class="form-select w-auto py-2">
                <option value="">working: inherit</option><option value="1">working: yes</option><option value="0">working: no</option>
              </select>
              <input type="time" id="atta-ov-start" class="form-input w-auto py-2">
              <input type="time" id="atta-ov-end" class="form-input w-auto py-2">
              <input id="atta-ov-label" placeholder="label" class="form-input w-28 py-2">
              <button id="atta-ov-save" class="btn btn-primary btn-sm py-2">Set</button>
            </div>
            ${(data.overrides || []).map((o) => `
              <div class="mt-2 flex flex-wrap items-center gap-x-2 text-xs text-gray-500">${o.date}: ${o.working == null ? 'inherit' : (o.working ? 'working' : 'off')}
                ${o.start ? o.start + '–' + o.end : ''} ${esc(o.label || '')}
                <button class="font-medium text-rose-600 hover:text-rose-700 hover:underline" data-ov-del="${o.date}">remove</button></div>`).join('')}
          </div>
          </div>
        </div>
      </div>`;

    const panel = document.getElementById('atta-panel-config');
    panel.querySelector('#atta-ip-add').onclick = async () => {
      const r = await post('/admin/allowlist', {
        action: 'add', ip: panel.querySelector('#atta-ip').value, label: panel.querySelector('#atta-ip-label').value,
      });
      if (!r.data.ok) alert(r.data.message || 'Failed');
      loadConfig();
    };
    panel.querySelectorAll('[data-deact]').forEach((b) => {
      b.onclick = async () => {
        if (!confirm('Deactivate this IP? Marking from it will stop immediately.')) return;
        await post('/admin/allowlist', { action: 'deactivate', entry_id: b.dataset.deact });
        loadConfig();
      };
    });
    panel.querySelectorAll('[data-wd-save]').forEach((b) => {
      b.onclick = async () => {
        const wd = b.dataset.wdSave;
        const r = await post('/admin/window', {
          weekday: wd,
          working: panel.querySelector(`[data-wd-working="${wd}"]`).checked ? 1 : 0,
          start: panel.querySelector(`[data-wd-start="${wd}"]`).value,
          end: panel.querySelector(`[data-wd-end="${wd}"]`).value,
        });
        if (!r.data.ok) alert(r.data.message || 'Failed');
      };
    });
    panel.querySelectorAll('[data-set-save]').forEach((b) => {
      b.onclick = async () => {
        const k = b.dataset.setSave;
        const r = await post('/admin/settings', { key: k, value: panel.querySelector(`[data-set="${k}"]`).value });
        if (!r.data.ok) alert(r.data.message || 'Failed');
      };
    });
    panel.querySelectorAll('[data-toggle]').forEach((t) => {
      t.onchange = async () => {
        await post('/admin/settings', { key: t.dataset.toggle, value: t.checked ? '1' : '0' });
      };
    });
    panel.querySelector('#atta-lv2-save').onclick = async () => {
      const r = await post('/admin/settings', { key: 'liveness_v2_students', value: panel.querySelector('#atta-lv2-students').value });
      alert(r.data.ok ? 'Saved.' : (r.data.message || 'Failed'));
    };
    panel.querySelector('#atta-recipients-save').onclick = async () => {
      const r = await post('/admin/settings', { key: 'report_recipients', value: panel.querySelector('#atta-recipients').value });
      alert(r.data.ok ? 'Saved.' : (r.data.message || 'Failed'));
    };
    panel.querySelector('#atta-ov-save').onclick = async () => {
      const r = await post('/admin/override', {
        day: panel.querySelector('#atta-ov-date').value,
        working: panel.querySelector('#atta-ov-working').value,
        start: panel.querySelector('#atta-ov-start').value,
        end: panel.querySelector('#atta-ov-end').value,
        label: panel.querySelector('#atta-ov-label').value,
        remove: 0,
      });
      if (!r.data.ok) alert(r.data.message || 'Failed');
      loadConfig();
    };
    panel.querySelectorAll('[data-ov-del]').forEach((b) => {
      b.onclick = async () => { await post('/admin/override', { day: b.dataset.ovDel, remove: 1 }); loadConfig(); };
    });
  }

  // ------------------------------------------------------------- reports

  async function loadReports() {
    const { data } = await api('/admin/reports');
    const panel = document.getElementById('atta-panel-reports');
    const now = new Date();
    const prev = new Date(now.getFullYear(), now.getMonth() - 1, 1);

    panel.innerHTML = `
      <div class="card flex flex-wrap items-end gap-3 p-5">
        <div><label for="atta-rep-year" class="form-label text-xs">Year</label>
          <input id="atta-rep-year" type="number" value="${prev.getFullYear()}" class="form-input w-24 py-2"></div>
        <div><label for="atta-rep-month" class="form-label text-xs">Month</label>
          <input id="atta-rep-month" type="number" min="1" max="12" value="${prev.getMonth() + 1}" class="form-input w-20 py-2"></div>
        <button id="atta-rep-gen" class="btn btn-primary py-2"><i class="fas fa-file-excel"></i>Generate now</button>
        <p class="basis-full text-xs leading-relaxed text-gray-500 sm:basis-0 sm:flex-1 sm:self-center">Also generated automatically on the 1st at 08:00 and emailed to the recipients configured above.</p>
      </div>
      <div class="card overflow-hidden"><div class="overflow-x-auto">
        <table class="table-modern">
          <thead>
            <tr><th>Period</th><th>v</th><th>Generated</th>
                <th>Days</th><th>Students</th><th>Email</th>
                <th>SHA-256</th><th></th></tr>
          </thead><tbody>
          ${(data.reports || []).map((r) => `
            <tr>
              <td class="whitespace-nowrap font-medium tabular-nums text-gray-900">${r.period_year}-${String(r.period_month).padStart(2, '0')}</td>
              <td>${r.version}</td>
              <td class="whitespace-nowrap tabular-nums">${r.generated_at.replace('T', ' ').slice(0, 16)}</td>
              <td class="tabular-nums">${r.working_days}</td>
              <td class="tabular-nums">${r.student_count}</td>
              <td>${esc(r.email_status)}</td>
              <td class="font-mono text-xs text-gray-500" title="${esc(r.xlsx_sha256 || '')}">${esc((r.xlsx_sha256 || '').slice(0, 12))}…</td>
              <td class="text-right"><button class="btn btn-ghost btn-sm px-2 text-blue-600 hover:bg-blue-50 hover:text-blue-700" data-dl="${r.id}"><i class="fas fa-download"></i>Download</button></td>
            </tr>`).join('')}
          </tbody></table></div></div>`;

    panel.querySelector('#atta-rep-gen').onclick = async () => {
      const r = await post('/admin/report', {
        year: panel.querySelector('#atta-rep-year').value,
        month: panel.querySelector('#atta-rep-month').value,
      });
      alert(r.data.ok ? `Generated v${r.data.version} (email: ${r.data.email_status}).` : (r.data.message || 'Failed'));
      loadReports();
    };
    panel.querySelectorAll('[data-dl]').forEach((b) => {
      b.onclick = async () => {
        const res = await fetch(API + encodeURIComponent(`/admin/report/download?report_id=${b.dataset.dl}`), {
          credentials: 'same-origin', headers: { Authorization: 'Bearer ' + ticket },
        });
        if (!res.ok) { alert('Download failed'); return; }
        const blob = await res.blob();
        const a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = (res.headers.get('content-disposition') || '').split('filename=')[1] || 'attendance.xlsx';
        a.click();
      };
    });
  }

  // --------------------------------------------------------------- audit

  async function loadAudit() {
    const { data } = await api('/admin/audit?limit=200');
    const panel = document.getElementById('atta-panel-audit');
    panel.innerHTML = `
      <div class="card overflow-hidden"><div class="overflow-x-auto">
        <table class="table-modern">
          <thead>
            <tr><th>When (UTC)</th><th>Actor</th><th>Action</th>
                <th>Student</th><th>IP</th><th>Details</th></tr>
          </thead><tbody>
          ${(data.entries || []).map((e) => `
            <tr>
              <td class="whitespace-nowrap font-mono text-xs text-gray-600">${e.at.replace('T', ' ').slice(0, 19)}</td>
              <td class="whitespace-nowrap">${esc(e.actor)}${e.user_id ? '#' + e.user_id : ''}</td>
              <td class="whitespace-nowrap font-medium text-gray-900">${esc(e.action)}</td>
              <td class="tabular-nums">${e.student_id || ''}</td>
              <td class="whitespace-nowrap font-mono text-xs text-gray-500">${esc(e.source_ip || '')}</td>
              <td class="min-w-[16rem] max-w-md break-all font-mono text-xs text-gray-500">${esc(e.details || '')}</td>
            </tr>`).join('')}
          </tbody></table></div></div>
      <p class="flex items-center gap-2 px-1 text-xs text-gray-500"><i class="fas fa-lock text-gray-400"></i>This log is append-only at the database level — entries cannot be edited or deleted, by anyone.</p>`;
  }

  // ---------------------------------------------------------------- init

  // header.php caps <main> at max-w-7xl (1280px), which is narrow for this many
  // table columns. Opt this page out — see main.atta-wide in attendance.css.
  root.closest('main')?.classList.add('atta-wide');

  loadDay();
  setInterval(() => {
    if (!document.getElementById('atta-panel-today').classList.contains('hidden') &&
        dateInput.value === new Date().toISOString().slice(0, 10)) {
      polling = true;
      loadDay().finally(() => { polling = false; });
    }
  }, 30 * 1000);
})();
