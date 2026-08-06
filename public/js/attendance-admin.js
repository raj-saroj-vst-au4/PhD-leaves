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

  async function api(path, opts = {}) {
    // The forwarder routes on ?p=<encoded path> (see attapi/index.php).
    const res = await fetch(API + encodeURIComponent(path), {
      credentials: 'same-origin',
      ...opts,
      headers: { Authorization: 'Bearer ' + ticket, ...(opts.headers || {}) },
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
    return `<div class="atta-card" style="padding:1rem;text-align:center;margin-bottom:0">
      <div class="text-2xl font-bold text-${color}-600">${value}</div>
      <div class="atta-muted">${label}</div></div>`;
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
    body.innerHTML = '<div class="col-span-3 atta-empty">Loading…</div>';
    modal.classList.remove('hidden');
    const imgs = [];
    for (let i = 0; i < 6; i++) {
      const res = await fetch(API + encodeURIComponent(`/admin/frame?dir=${dir}&index=${i}`), {
        credentials: 'same-origin',
        headers: { Authorization: 'Bearer ' + ticket },
      });
      if (!res.ok) break;
      imgs.push(URL.createObjectURL(await res.blob()));
    }
    body.innerHTML = imgs.length
      ? imgs.map((u) => `<img src="${u}" class="rounded border object-cover w-full">`).join('')
      : '<div class="col-span-3 atta-empty">Frames unavailable (purged or none stored).</div>';
  };

  async function loadDay() {
    const { status, data } = await api('/admin/today?day=' + dateInput.value);
    if (status !== 200) return;
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
    if (!row.enrolled) return '<span class="atta-chip bg-gray-200 text-gray-600">not enrolled</span>';
    const map = {
      self: ['bg-green-100 text-green-800', 'Present ' + (row.marked_at_ist || '')],
      manual: ['bg-blue-100 text-blue-800', 'Manual ' + (row.marked_at_ist || '')],
      excused: ['bg-amber-100 text-amber-800', 'Excused'],
      absent: ['bg-red-100 text-red-700', 'Absent'],
    };
    const [cls, label] = map[row.status] || map.absent;
    return `<span class="atta-chip ${cls}">${esc(label.trim())}</span>`;
  }

  function renderToday() {
    const w = day.window;
    const head = `
      <div class="atta-card" style="display:flex;flex-wrap:wrap;align-items:center;gap:.75rem">
        <span class="font-medium">${day.date}</span>
        ${w.working_day
          ? `<span>Window <b>${w.start}–${w.end}</b>${w.extension ? ` <span class="text-amber-700">(+${w.extension}m extended)</span>` : ''}</span>`
          : `<span class="text-gray-500">Non-working day${w.label ? ' — ' + esc(w.label) : ''}</span>`}
        <button id="atta-extend" class="atta-btn atta-btn-amber ml-auto">
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
        <td class="atta-mono">${esc(r.roll)}</td>
        <td>${esc(r.name)}</td>
        <td>${statusChip(r)}</td>
        <td class="atta-muted">${r.match_score != null ? 'match ' + r.match_score : ''}${r.reason ? ' · ' + esc(r.reason) : ''}</td>
        <td class="atta-num">
          <span class="atta-actions">
          ${r.frames_dir ? `<button class="atta-link" data-frames="${esc(r.frames_dir)}" data-frames-title="${esc(r.name)}">photos</button>` : ''}
          ${isToday && ['self', 'manual', 'excused'].includes(r.status)
            ? `<button class="atta-link atta-link-red" data-act="reset" data-sid="${r.student_id}" data-name="${esc(r.name)}">reset</button>`
            : ''}
          <button class="atta-link atta-link-gray" data-act="mark" data-sid="${r.student_id}" data-name="${esc(r.name)}">override</button>
          ${r.enrolled
            ? `<button class="atta-link atta-link-red" data-act="revoke" data-sid="${r.student_id}" data-name="${esc(r.name)}">revoke</button>`
            : `<button class="atta-link atta-link-amber" data-act="enroll" data-sid="${r.student_id}" data-name="${esc(r.name)}">enroll code</button>`}
          </span>
        </td>
      </tr>`).join('');

    document.getElementById('atta-panel-today').innerHTML = head + `
      <div class="atta-card atta-card-flush">
        <table class="atta-table">
          <thead>
            <tr><th>Roll</th><th>Name</th><th>Status</th><th>Detail</th>
                <th class="atta-num">Actions</th></tr>
          </thead>
          <tbody>${rows}</tbody>
        </table>
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
      panel.innerHTML = '<div class="atta-card atta-empty">No pending face reviews. Borderline matches will appear here instead of being marked absent.</div>';
      return;
    }
    panel.innerHTML = day.pending.map((p) => `
      <div class="atta-card" style="display:flex;flex-wrap:wrap;align-items:center;gap:1rem">
        <div>
          <div class="font-medium">${esc(p.name)} <span class="atta-mono text-gray-500">${esc(p.roll)}</span></div>
          <div class="atta-muted">at ${p.at_ist} · match ${p.match_score.toFixed(2)} · liveness ${p.liveness.toFixed(2)}</div>
        </div>
        <button class="atta-link" data-frames="${esc(p.frames_dir)}" data-frames-title="${esc(p.name)}">view photos</button>
        <div class="ml-auto flex gap-2">
          <button class="atta-btn atta-btn-green" data-rev="approve" data-id="${p.attempt_id}">Approve</button>
          <button class="atta-btn atta-btn-red" data-rev="reject" data-id="${p.attempt_id}">Reject</button>
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
      panel.innerHTML = '<div class="atta-card atta-empty">No failed attempts on this day.</div>';
      return;
    }
    panel.innerHTML = `
      <div class="atta-card atta-card-flush">
      <table class="atta-table">
        <thead>
          <tr><th>Time</th><th>Roll</th><th>Code</th>
              <th>Cross-match</th><th>IP</th><th></th></tr>
        </thead><tbody>
        ${day.failed.map((f) => `
          <tr>
            <td>${f.at_ist || ''}</td>
            <td class="atta-mono">${esc(f.roll || '?')}</td>
            <td><span class="atta-chip bg-red-100 text-red-700">${esc(f.code)}</span></td>
            <td class="atta-muted">${f.crossmatch_student_id ? '<b class="text-red-700">matched another student (#' + f.crossmatch_student_id + ')</b>' : ''}</td>
            <td class="atta-mono">${esc(f.source_ip || '')}</td>
            <td>${f.frames_dir ? `<button class="atta-link" data-frames="${esc(f.frames_dir)}" data-frames-title="Attempt #${f.attempt_id}">photos</button>` : ''}</td>
          </tr>`).join('')}
        </tbody></table></div>
      <p class="atta-footnote">Every failed attempt is stored with its photos — a spoof attempt is its own evidence.</p>`;
  }

  // -------------------------------------------------------------- config

  async function loadConfig() {
    const { data } = await api('/admin/settings');
    if (!data.ok) return;
    const s = data.settings;
    const weekdays = ['', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

    document.getElementById('atta-panel-config').innerHTML = `
      <div class="atta-grid atta-grid-2">
        <div class="atta-card">
          <h3 class="atta-card-title"><i class="fas fa-wifi mr-2 text-blue-600"></i>Office WiFi (allowed IPs)</h3>
          <p class="atta-card-note">Marking only works from these WAN IPs. Old entries are deactivated, never deleted.</p>
          ${data.allowed_ips.map((i) => `
            <div class="atta-row ${i.active ? '' : 'opacity-40 line-through'}">
              <span class="atta-mono atta-row-label">${esc(i.ip)}</span>
              <span class="atta-muted">${esc(i.label)}</span>
              ${i.active ? `<button class="atta-link atta-link-red" data-deact="${i.id}">deactivate</button>` : '<span class="atta-muted">inactive</span>'}
            </div>`).join('') || '<div class="atta-empty-warn">No IPs configured — nobody can mark attendance yet.</div>'}
          <div class="atta-form-row">
            <input id="atta-ip" placeholder="e.g. 10.119.4.27" class="atta-input atta-input-mono" style="flex:1 1 auto">
            <input id="atta-ip-label" placeholder="label" class="atta-input w-28">
            <button id="atta-ip-add" class="atta-btn">Add</button>
          </div>
        </div>

        <div class="atta-card">
          <h3 class="atta-card-title"><i class="fas fa-clock mr-2 text-blue-600"></i>Weekly window</h3>
          <p class="atta-card-note">Start inclusive, end exclusive. Holidays come from the portal's holiday calendar automatically.</p>
          ${data.windows.map((w) => `
            <div class="atta-row">
              <span class="w-10 font-medium">${weekdays[w.weekday]}</span>
              <input type="checkbox" data-wd-working="${w.weekday}" ${w.working ? 'checked' : ''}>
              <input type="time" value="${w.start}" data-wd-start="${w.weekday}" class="atta-input">
              <span>–</span>
              <input type="time" value="${w.end}" data-wd-end="${w.weekday}" class="atta-input">
              <button class="atta-link ml-auto" data-wd-save="${w.weekday}">save</button>
            </div>`).join('')}
        </div>

        <div class="atta-card">
          <h3 class="atta-card-title"><i class="fas fa-sliders-h mr-2 text-blue-600"></i>Verification thresholds</h3>
          <p class="atta-card-note">Between the review and match thresholds, marks go to the Reviews queue instead of failing — nobody is auto-marked absent by a borderline score.</p>
          ${[['face_match_threshold', 'Auto-accept match ≥'], ['face_review_threshold', 'Send to review ≥'],
             ['liveness_threshold', 'Liveness ≥'], ['crossmatch_margin', 'Cross-match margin'],
             ['max_attempts_per_day', 'Max failed attempts/day'], ['frame_retention_days', 'Photo retention (days)'],
             ['enrollment_min_frames', 'Min enrollment frames']].map(([k, label]) => `
            <div class="atta-row">
              <span class="atta-row-label">${label}</span>
              <input value="${esc(s[k] || '')}" data-set="${k}" class="atta-input w-20" style="text-align:right">
              <button class="atta-link" data-set-save="${k}">save</button>
            </div>`).join('')}
        </div>

        <div class="atta-card">
          <h3 class="atta-card-title"><i class="fas fa-shield-alt mr-2 text-blue-600"></i>Modes</h3>
          <div class="atta-row">
            <div class="atta-row-label"><b>Attendance enabled</b><div class="atta-muted">Master switch.</div></div>
            <input type="checkbox" data-toggle="attendance_enabled" ${s.attendance_enabled === '1' ? 'checked' : ''}>
          </div>
          <div class="atta-row">
            <div class="atta-row-label"><b>Strict mode</b><div class="atta-muted">Also require the rotating code from the office screen. Stops remote marking via a friend's tethered phone.</div></div>
            <input type="checkbox" data-toggle="strict_mode" ${s.strict_mode === '1' ? 'checked' : ''}>
          </div>
          <div class="atta-row" style="display:block">
            <b>Report recipients</b>
            <div class="atta-form-row" style="margin-top:.25rem">
              <input id="atta-recipients" value='${esc(s.report_recipients || '[]')}' class="atta-input atta-input-mono" style="flex:1 1 auto">
              <button id="atta-recipients-save" class="atta-btn">Save</button>
            </div>
            <div class="atta-muted" style="margin-top:.25rem">JSON array, e.g. ["somoffice@iitb.ac.in"]</div>
          </div>
          <div class="atta-row" style="display:block">
            <b>Date override</b>
            <div class="atta-form-row" style="margin-top:.25rem">
              <input type="date" id="atta-ov-date" class="atta-input">
              <select id="atta-ov-working" class="atta-input">
                <option value="">working: inherit</option><option value="1">working: yes</option><option value="0">working: no</option>
              </select>
              <input type="time" id="atta-ov-start" class="atta-input">
              <input type="time" id="atta-ov-end" class="atta-input">
              <input id="atta-ov-label" placeholder="label" class="atta-input w-28">
              <button id="atta-ov-save" class="atta-btn">Set</button>
            </div>
            ${(data.overrides || []).map((o) => `
              <div class="atta-muted" style="margin-top:.25rem">${o.date}: ${o.working == null ? 'inherit' : (o.working ? 'working' : 'off')}
                ${o.start ? o.start + '–' + o.end : ''} ${esc(o.label || '')}
                <button class="atta-link atta-link-red" data-ov-del="${o.date}">remove</button></div>`).join('')}
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
      <div class="atta-card" style="display:flex;flex-wrap:wrap;align-items:flex-end;gap:.75rem">
        <div><label class="block atta-muted" style="margin-bottom:.25rem">Year</label>
          <input id="atta-rep-year" type="number" value="${prev.getFullYear()}" class="atta-input w-20"></div>
        <div><label class="block atta-muted" style="margin-bottom:.25rem">Month</label>
          <input id="atta-rep-month" type="number" min="1" max="12" value="${prev.getMonth() + 1}" class="atta-input w-20"></div>
        <button id="atta-rep-gen" class="atta-btn"><i class="fas fa-file-excel"></i>Generate now</button>
        <span class="atta-muted">Also generated automatically on the 1st at 08:00 and emailed to the recipients configured above.</span>
      </div>
      <div class="atta-card atta-card-flush">
        <table class="atta-table">
          <thead>
            <tr><th>Period</th><th>v</th><th>Generated</th>
                <th>Days</th><th>Students</th><th>Email</th>
                <th>SHA-256</th><th></th></tr>
          </thead><tbody>
          ${(data.reports || []).map((r) => `
            <tr>
              <td class="font-medium">${r.period_year}-${String(r.period_month).padStart(2, '0')}</td>
              <td>${r.version}</td>
              <td>${r.generated_at.replace('T', ' ').slice(0, 16)}</td>
              <td>${r.working_days}</td>
              <td>${r.student_count}</td>
              <td>${esc(r.email_status)}</td>
              <td class="atta-mono" title="${esc(r.xlsx_sha256 || '')}">${esc((r.xlsx_sha256 || '').slice(0, 12))}…</td>
              <td><button class="atta-link" data-dl="${r.id}">download</button></td>
            </tr>`).join('')}
          </tbody></table></div>`;

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
      <div class="atta-card atta-card-flush">
        <table class="atta-table">
          <thead>
            <tr><th>When (UTC)</th><th>Actor</th><th>Action</th>
                <th>Student</th><th>IP</th><th>Details</th></tr>
          </thead><tbody>
          ${(data.entries || []).map((e) => `
            <tr>
              <td class="whitespace-nowrap">${e.at.replace('T', ' ').slice(0, 19)}</td>
              <td>${esc(e.actor)}${e.user_id ? '#' + e.user_id : ''}</td>
              <td class="font-medium">${esc(e.action)}</td>
              <td>${e.student_id || ''}</td>
              <td class="atta-mono">${esc(e.source_ip || '')}</td>
              <td class="atta-muted max-w-md break-all">${esc(e.details || '')}</td>
            </tr>`).join('')}
          </tbody></table></div>
      <p class="atta-footnote">This log is append-only at the database level — entries cannot be edited or deleted, by anyone.</p>`;
  }

  // ---------------------------------------------------------------- init

  // header.php caps <main> at max-w-7xl (1280px), which is narrow for this many
  // table columns. Opt this page out — see main.atta-wide in attendance.css.
  root.closest('main')?.classList.add('atta-wide');

  loadDay();
  setInterval(() => {
    if (!document.getElementById('atta-panel-today').classList.contains('hidden') &&
        dateInput.value === new Date().toISOString().slice(0, 10)) {
      loadDay();
    }
  }, 30 * 1000);
})();
