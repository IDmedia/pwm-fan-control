/* PWM Fan Control - settings page */
(function ($) {
  'use strict';

  const root = document.getElementById('pfc');
  if (!root) return;
  const API = root.dataset.api;
  const CSRF = root.dataset.csrf;
  const COLORS = ['#f0820a', '#3b82f6', '#16a34a', '#a855f7', '#d9534f', '#0891b2'];

  let state = null;       // last response from the server
  let draft = null;       // config being edited
  let dirty = false;
  let history = [];
  let groupsBuilt = false;
  let showUnnamed = false; // group editor: also offer outputs without a name
  let pwmSig = '';
  let rpmSig = '';
  let sourceSig = '';
  let testDismissed = null;
  let timer = null;
  let historyTick = 0;

  /* ------------------------------------------------------------ helpers */

  const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const pct = pwm => Math.round(pwm * 100 / 255);
  const toPwm = p => Math.max(0, Math.min(255, Math.round(p * 255 / 100)));
  const clone = o => JSON.parse(JSON.stringify(o));
  const focused = el => el === document.activeElement;
  const fmtTemp = t => (t == null ? 'n/a' : (Math.round(t * 10) / 10) + '°C');

  function getPath(obj, path) { return path.split('.').reduce((o, k) => (o == null ? undefined : o[k]), obj); }
  function setPath(obj, path, value) {
    const keys = path.split('.');
    const last = keys.pop();
    keys.reduce((o, k) => (o[k] = o[k] || {}), obj)[last] = value;
  }

  function post(data) {
    return $.post(API, Object.assign({ csrf_token: CSRF }, data))
      .then(r => (typeof r === 'string' ? JSON.parse(r) : r))
      .then(r => { if (!r.ok) throw new Error(r.error || 'Request failed'); return r; },
            xhr => { throw new Error((xhr && xhr.responseJSON && xhr.responseJSON.error) || 'Request failed'); });
  }

  function showError(err) {
    const msg = err && err.message ? err.message : String(err);
    if (typeof swal === 'function') swal({ title: 'PWM Fan Control', text: msg, type: 'error' });
    else alert(msg);
  }

  function ago(ts) {
    if (!ts) return 'never';
    const s = Math.max(0, Math.round(Date.now() / 1000 - ts));
    if (s < 60) return s + 's ago';
    if (s < 3600) return Math.round(s / 60) + ' min ago';
    return new Date(ts * 1000).toLocaleString();
  }

  const sourceById = id => state.sources.find(s => s.id === id) || null;
  const isDisk = id => id === 'array' || id === 'unassigned' || id === 'flash' || id.indexOf('pool:') === 0;
  const diskSourceName = id => id.indexOf('pool:') === 0 ? 'Pool: ' + id.slice(5) : ({ array: 'Array', unassigned: 'Unassigned disks', flash: 'Flash' }[id] || id);
  const sourceName = id => { const s = sourceById(id); return s ? (s.label || s.name) : (isDisk(id) ? diskSourceName(id) : 'Unavailable source'); };
  const groupById = id => draft.groups.find(g => g.id === id) || null;
  const pwmByKey = key => state.pwm.find(p => p.key === key) || null;
  const rpmByKey = key => state.rpm.find(r => r.key === key) || null;
  const rpmName = r => (r ? (r.label || r.driver_label || (r.chip + ' fan' + r.index)) : '');
  const rpmFullName = r => ((r.label || r.driver_label) ? rpmName(r) + ' (fan' + r.index + ')' : rpmName(r));
  const fanName = key => { const p = pwmByKey(key); return p ? (p.label || p.chip + ' PWM ' + p.index) : key; };

  function defaultBinding(source) {
    if (source === 'array' || source === 'unassigned') return { source, low: 35, high: 48, min_pwm: 170, max_pwm: 255, idle_pwm: 170 };
    if (isDisk(source)) return { source, low: 45, high: 65, min_pwm: 90, max_pwm: 255, idle_pwm: 90 }; // SSD/NVMe pools, flash
    if (source.indexOf('nvidia:') === 0) return { source, low: 45, high: 75, min_pwm: 90, max_pwm: 255 };
    return { source, low: 40, high: 70, min_pwm: 90, max_pwm: 255 };
  }

  function curvePwm(temp, b) {
    if (temp <= b.low) return b.min_pwm;
    if (temp >= b.high) return b.max_pwm;
    return Math.round(b.min_pwm + (temp - b.low) * (b.max_pwm - b.min_pwm) / (b.high - b.low));
  }

  /** Speed a binding asks for right now, mirrors pfc_binding_demand() in PHP. */
  function bindingDemand(b) {
    const s = sourceById(b.source);
    if (!s || s.state === 'error') return 255;
    if (s.state === 'parity') return b.max_pwm;
    if (s.state === 'idle') return b.idle_pwm != null ? b.idle_pwm : b.min_pwm;
    if (s.state === 'waiting') return Math.max(b.min_pwm, b.idle_pwm != null ? b.idle_pwm : b.min_pwm);
    return curvePwm(s.temp, b);
  }

  /* -------------------------------------------------------------- load */

  function load(withHistory) {
    const params = { action: 'state' };
    if (withHistory) params.history = 1;
    return $.getJSON(API, params).then(data => {
      state = data;
      if (data.history) history = data.history;
      // Only rebuild the editor when the saved config actually changed, so focus isn't lost
      if (!dirty && JSON.stringify(data.config) !== JSON.stringify(draft)) { draft = clone(data.config); groupsBuilt = false; }
      render();
    }).always(schedule);
  }

  function schedule() {
    clearTimeout(timer);
    const testing = state && state.fantest && state.fantest.phase !== 'done';
    const interval = testing ? 1000 : Math.max(2000, Math.min(5000, ((state && state.config.interval) || 5) * 1000));
    timer = setTimeout(() => {
      historyTick = (historyTick + 1) % 15;
      load(historyTick === 0);
    }, interval);
  }

  function markDirty() {
    dirty = true;
    $('#pfc-applybar').removeClass('pfc-hidden');
  }

  /* ------------------------------------------------------------ render */

  function render() {
    $('#pfc-applybar').toggleClass('pfc-hidden', !dirty);
    renderBanners();
    renderStatus();
    if (!groupsBuilt) buildGroups();
    updateGroups();
    renderPwmTable();
    renderRpmTable();
    renderFanTest();
    renderSources();
    renderDisks();
    renderHardware();
    fillCfgInputs();
  }

  function renderBanners() {
    const items = [];
    if (state.old_plugin) items.push(['warn', 'The old "Array Fan Control" plugin is still installed. Remove it under Plugins before enabling PWM Fan Control, otherwise both plugins will fight over the fans.']);
    if (state.migrated) items.push(['info', 'Your settings from "Array Fan Control" were imported as the group "Array fans". Check them, then enable fan control.']);
    state.errors.forEach(e => items.push(['error', e]));
    (state.status && state.status.warnings || []).forEach(w => items.push(['error', w]));
    if (state.config.enabled && !state.running) items.push(['error', 'Fan control is enabled but the service is not running. The watchdog will try to restart it within a minute. Check the syslog.']);
    const hw = state.hardware;
    if (!hw.writable) items.push(['error', 'No controllable fan outputs found. ' + (hw.hint || '') + ' See the hardware check at the bottom of this page.']);
    // Fans in a group that the service can't watch: no RPM sensor means no stall or moved-fan warnings
    const grouped = new Set(state.config.groups.flatMap(g => g.fans));
    const blind = state.pwm.filter(p => grouped.has(p.key) && !p.rpm_confirmed).map(p => p.label || p.key);
    if (blind.length) items.push(['info', 'Without a confirmed RPM sensor, the plugin can\'t tell if these fans stop or were moved to another header: ' +
      blind.join(', ') + '. Run Identify on them, or pick the sensor in the RPM sensor column ("none" if the fan has no sensor).']);
    $('#pfc-banners').html(items.map(i => '<div class="pfc-banner pfc-banner-' + i[0] + '">' + esc(i[1]) + '</div>').join(''));
  }

  function renderHardware() {
    const hw = state.hardware;
    const yes = t => '<span class="pfc-ok-text"><i class="fa fa-check"></i> ' + esc(t) + '</span>';
    const no = t => '<span class="pfc-error-text"><i class="fa fa-times"></i> ' + esc(t) + '</span>';
    const needLax = hw.conflicts.length && !hw.writable;
    const muted = t => '<span class="pfc-muted">' + esc(t) + '</span>';
    const rows = [
      ['Fan controller driver', hw.drivers.length ? yes(hw.drivers.join(', ')) : no('none loaded')],
      ['Fan outputs', hw.writable ? yes(hw.writable + ' found') : no(hw.pwm ? hw.pwm + ' found, none writable' : 'none found')],
      ['ACPI resource conflicts in the kernel log', hw.conflicts.length
        ? (needLax ? no('found') : muted(hw.lax ? 'found, handled by the lax option' : 'found, but fan outputs work')) +
          '<div class="pfc-log">' + esc(hw.conflicts.join('\n')) + '</div>'
        : yes('none')],
      ['acpi_enforce_resources=lax', hw.lax ? yes('set') : (needLax ? no('not set, needed') : muted('not set, not needed'))],
    ];
    if (hw.hint) rows.push(['What to do', esc(hw.hint)]);
    $('#pfc-hw-table tbody').html(rows.map(r => '<tr><td>' + esc(r[0]) + '</td><td>' + r[1] + '</td></tr>').join(''));
  }

  function renderStatus() {
    const st = state.status;
    const live = state.running && st;
    let html = '<div class="pfc-stat"><span class="pfc-stat-label">Service</span><span class="pfc-stat-value">' +
      (state.running ? 'Running' : (state.config.enabled ? 'Not running' : 'Disabled')) + '</span>' +
      '<span class="pfc-muted pfc-small">' + (live ? 'Checks every ' + st.interval + 's · updated ' + ago(st.time) : 'Showing a preview, fans are not changed') + '</span></div>';

    state.config.groups.forEach((g, i) => {
      const s = live && st.groups[g.id];
      const p = state.preview[g.id];
      const pwm = s ? s.pwm : (p ? p.pwm : null);
      const reason = s ? s.reason : (p ? p.reason : '');
      let note = '';
      if (s && s.slow_down_in != null) note = 'Slowing down to ' + pct(s.demand) + '% in ' + s.slow_down_in + 's';
      html += '<div class="pfc-stat" style="border-top:3px solid ' + COLORS[i % COLORS.length] + '">' +
        '<span class="pfc-stat-label">' + esc(g.name) + ' · ' + g.fans.length + ' fan' + (g.fans.length === 1 ? '' : 's') + '</span>' +
        '<span class="pfc-stat-value">' + (pwm == null ? '-' : pct(pwm) + '%' + (live ? '' : ' <span class="pfc-muted pfc-small">(preview)</span>')) + '</span>' +
        '<span class="pfc-small">' + esc(reason) + '</span>' +
        (note ? '<span class="pfc-muted pfc-small">' + esc(note) + '</span>' : '') + '</div>';
    });
    if (!state.config.groups.length) html += '<div class="pfc-stat"><span class="pfc-stat-label">Groups</span><span class="pfc-small">No fan groups yet. Add one below.</span></div>';
    $('#pfc-status').html(html);
  }

  /* -------------------------------------------------------- group editor */

  function sourceOptions(group) {
    const used = new Set(group.sources.map(b => b.source));
    const opt = s => '<option value="' + esc(s.id) + '">' + esc((s.label || s.name) + (s.temp != null ? ' (' + fmtTemp(s.temp) + ')' : '')) + '</option>';
    const avail = state.sources.filter(s => !used.has(s.id));
    const sections = [
      ['Disks', avail.filter(s => s.type === 'disk')],
      ['GPUs', avail.filter(s => s.type === 'nvidia')],
      ['Sensors', avail.filter(s => s.type === 'hwmon')],
    ];
    return '<option value="">+ Add temperature source...</option>' +
      sections.filter(x => x[1].length).map(x => '<optgroup label="' + x[0] + '">' + x[1].map(opt).join('') + '</optgroup>').join('');
  }

  function buildGroups() {
    groupsBuilt = true;
    const owner = {};
    draft.groups.forEach(g => g.fans.forEach(f => { owner[f] = g; }));

    const html = draft.groups.map((g, gi) => {
      // Only named outputs are offered (plus the group's own fans), unnamed ones are usually empty headers
      const unnamed = state.pwm.filter(p => !p.label && g.fans.indexOf(p.key) < 0).map(p => p.key);
      const fanKeys = state.pwm.map(p => p.key).filter(k => showUnnamed || unnamed.indexOf(k) < 0);
      g.fans.forEach(k => { if (fanKeys.indexOf(k) < 0) fanKeys.push(k); }); // missing fans stay visible
      let fans = fanKeys.map(k => {
        const other = owner[k] && owner[k] !== g ? owner[k] : null;
        const p = pwmByKey(k);
        return '<label class="pfc-chip' + (g.fans.indexOf(k) >= 0 ? ' pfc-chip-on' : '') + (other ? ' pfc-chip-off' : '') + '"' +
          (other ? ' title="Already in ' + esc(other.name) + '"' : '') + '>' +
          '<input type="checkbox" class="pfc-g-fan" value="' + esc(k) + '"' + (g.fans.indexOf(k) >= 0 ? ' checked' : '') + (other ? ' disabled' : '') + '> ' +
          esc(fanName(k)) + (p ? '' : ' <i class="fa fa-exclamation-triangle" title="Not found"></i>') +
          (other ? ' <span class="pfc-muted">(' + esc(other.name) + ')</span>' : '') + '</label>';
      }).join('');
      if (!fans && unnamed.length) fans = '<span class="pfc-muted">No named fans yet. Name your fans in the Fans section below, then pick them here.</span>';
      if (unnamed.length) {
        fans += '<a href="#" class="pfc-toggle-unnamed pfc-small">' + (showUnnamed ? 'Hide unnamed outputs' :
          'Show ' + unnamed.length + ' unnamed output' + (unnamed.length === 1 ? '' : 's')) + '</a>';
      }

      const hasArray = g.sources.some(b => isDisk(b.source));
      const rows = g.sources.map((b, bi) => {
        const isArray = isDisk(b.source);
        const num = (field, val, min, max, unit) => '<input type="number" class="pfc-b-field pfc-narrow" data-b="' + bi + '" data-field="' + field + '" min="' + min + '" max="' + max + '" value="' + val + '">' + (unit || '');
        return '<tr data-b="' + bi + '">' +
          '<td><span class="pfc-swatch" style="background:' + COLORS[bi % COLORS.length] + '"></span>' + esc(sourceName(b.source)) + '</td>' +
          '<td>' + num('low', b.low, 0, 120) + '</td>' +
          '<td>' + num('high', b.high, 0, 120) + '</td>' +
          '<td>' + num('min_pwm', pct(b.min_pwm), 0, 100, '%') + '</td>' +
          '<td>' + num('max_pwm', pct(b.max_pwm), 0, 100, '%') + '</td>' +
          (hasArray ? '<td>' + (isArray ? num('idle_pwm', pct(b.idle_pwm), 0, 100, '%') : '<span class="pfc-muted">-</span>') + '</td>' : '') +
          '<td class="pfc-b-temp"></td><td class="pfc-b-wants"></td>' +
          '<td><button type="button" class="pfc-b-remove pfc-icon-btn" data-b="' + bi + '" title="Remove source"><i class="fa fa-times"></i></button></td></tr>';
      }).join('');

      return '<div class="pfc-group" data-g="' + gi + '">' +
        '<div class="pfc-group-head">' +
          '<span class="pfc-swatch pfc-swatch-lg" style="background:' + COLORS[gi % COLORS.length] + '"></span>' +
          '<input type="text" class="pfc-g-name" maxlength="40" value="' + esc(g.name) + '" placeholder="Group name">' +
          '<label class="pfc-muted">Slow-down delay <input type="number" class="pfc-g-ramp pfc-narrow" min="0" max="600" value="' + g.ramp_down + '"> s</label>' +
          '<span class="pfc-g-now"></span>' +
          '<button type="button" class="pfc-g-delete"><i class="fa fa-trash"></i> Delete group</button>' +
        '</div>' +
        '<div class="pfc-group-section"><div class="pfc-group-label">Fans</div><div class="pfc-chips">' + (fans || '<span class="pfc-muted">No fan outputs found.</span>') + '</div></div>' +
        '<div class="pfc-group-section"><div class="pfc-group-label">Temperature sources</div>' +
          '<div class="pfc-table-wrap"><table class="pfc-table pfc-bind-table"><thead><tr>' +
          '<th>Source</th><th>Low °C</th><th>High °C</th><th>Min speed</th><th>Max speed</th>' + (hasArray ? '<th>All disks spun down</th>' : '') + '<th>Now</th><th>Wants</th><th></th>' +
          '</tr></thead><tbody>' + (rows || '<tr><td colspan="9" class="pfc-muted">No sources yet. Add one below.</td></tr>') + '</tbody></table></div>' +
          '<select class="pfc-g-addsrc">' + sourceOptions(g) + '</select>' +
          '<p class="pfc-muted pfc-small">Below <b>Low</b> a source asks for the minimum speed, above <b>High</b> for the maximum speed, and in between for a speed on a straight line.</p>' +
          '<p class="pfc-g-hint pfc-warn-text pfc-small"></p>' +
        '</div>' +
        '<div class="pfc-charts">' +
          '<div class="pfc-chart-box"><div class="pfc-chart-title">Fan curves</div><svg class="pfc-chart pfc-curve" viewBox="0 0 560 260"></svg></div>' +
          '<div class="pfc-chart-box"><div class="pfc-chart-title">Last 24 hours <span class="pfc-muted pfc-small">(since reboot)</span></div><svg class="pfc-chart pfc-history" viewBox="0 0 560 260"></svg>' +
          '<div class="pfc-legend"><span class="pfc-key pfc-key-fan"></span>Fan speed (%) <span class="pfc-muted">· coloured lines: source temperatures (°C)</span></div></div>' +
        '</div></div>';
    }).join('');
    $('#pfc-groups').html(html || '<p class="pfc-muted">No fan groups yet.</p>');
  }

  /** Updates live values in the group editor without rebuilding it (keeps focus while typing). */
  function updateGroups() {
    const st = state.running && state.status;
    draft.groups.forEach((g, gi) => {
      const $g = $('#pfc-groups .pfc-group').filter(function () { return +this.dataset.g === gi; });
      g.sources.forEach((b, bi) => {
        const s = sourceById(b.source);
        const $tr = $g.find('.pfc-bind-table tr').filter(function () { return this.dataset.b === String(bi); });
        let temp = s ? fmtTemp(s.temp) : 'n/a';
        if (s && s.state !== 'ok') temp += ' <span class="pfc-muted pfc-small">' + esc(s.detail) + '</span>';
        if (!s) temp = '<span class="pfc-error-text">not available</span>';
        $tr.find('.pfc-b-temp').html(temp);
        $tr.find('.pfc-b-wants').text(pct(bindingDemand(b)) + '%');
      });
      const live = st && st.groups[g.id];
      $g.find('.pfc-g-now').html(live ? 'Now <b>' + live.percent + '%</b>' : '');

      // Fans that may not start again at the lowest speed of this group (from their speed test)
      const lowest = Math.min(...g.sources.map(b => Math.min(b.min_pwm, b.idle_pwm != null ? b.idle_pwm : 255)));
      const hints = g.fans.map(pwmByKey).filter(p => { const from = fanSpinsFrom(p); return from != null && lowest > 0 && lowest < from; })
        .map(p => '"' + (p.label || fanName(p.key)) + '" needs ' + pct(fanSpinsFrom(p)) + '% to start');
      $g.find('.pfc-g-hint').html(hints.length ? '<i class="fa fa-exclamation-triangle"></i> The lowest speed in this group is ' + pct(lowest) +
        '%, but ' + esc(hints.join(', ')) + ' (speed test). Below that the fan may stop and not start again until the speed goes up. Set 0% if you want it to stop.' : '');
      drawCurve($g.find('.pfc-curve')[0], g);
      drawHistory($g.find('.pfc-history')[0], g);
    });
  }

  /* ------------------------------------------------------------- charts */

  const SVGNS = 'http://www.w3.org/2000/svg';
  const W = 560, H = 260, PAD = { l: 44, r: 14, t: 12, b: 30 };

  function svgEl(tag, attrs, text) {
    const el = document.createElementNS(SVGNS, tag);
    Object.keys(attrs).forEach(k => el.setAttribute(k, attrs[k]));
    if (text != null) el.textContent = text;
    return el;
  }

  function axes(svg, x0, x1, y0, y1, xTicks, yTicks, xFmt, yFmt) {
    svg.innerHTML = '';
    const sx = v => PAD.l + (v - x0) * (W - PAD.l - PAD.r) / ((x1 - x0) || 1);
    const sy = v => H - PAD.b - (v - y0) * (H - PAD.t - PAD.b) / ((y1 - y0) || 1);
    yTicks.forEach(v => {
      svg.appendChild(svgEl('line', { x1: PAD.l, x2: W - PAD.r, y1: sy(v), y2: sy(v), class: 'pfc-grid' }));
      svg.appendChild(svgEl('text', { x: PAD.l - 6, y: sy(v) + 4, 'text-anchor': 'end', class: 'pfc-axis' }, yFmt(v)));
    });
    xTicks.forEach(v => {
      svg.appendChild(svgEl('line', { x1: sx(v), x2: sx(v), y1: PAD.t, y2: H - PAD.b, class: 'pfc-grid' }));
      svg.appendChild(svgEl('text', { x: sx(v), y: H - PAD.b + 18, 'text-anchor': 'middle', class: 'pfc-axis' }, xFmt(v)));
    });
    return { sx, sy };
  }

  function pathD(points, sx, sy) {
    let d = '', pen = false;
    points.forEach(p => {
      if (p[1] == null) { pen = false; return; }
      d += (pen ? 'L' : 'M') + sx(p[0]).toFixed(1) + ',' + sy(p[1]).toFixed(1);
      pen = true;
    });
    return d;
  }

  function drawCurve(svg, g) {
    if (!svg) return;
    const valid = g.sources.filter(b => b.low < b.high);
    if (!valid.length) {
      axes(svg, 0, 1, 0, 100, [], [0, 25, 50, 75, 100], v => v, v => v + '%');
      svg.appendChild(svgEl('text', { x: W / 2, y: H / 2, 'text-anchor': 'middle', class: 'pfc-axis' }, 'Add a temperature source to see its curve'));
      return;
    }
    const lo = Math.min(...valid.map(b => b.low)), hi = Math.max(...valid.map(b => b.high));
    const x0 = Math.max(0, Math.floor((lo - 8) / 5) * 5), x1 = Math.ceil((hi + 8) / 5) * 5;
    const step = x1 - x0 > 60 ? 10 : 5;
    const xt = []; for (let t = x0; t <= x1; t += step) xt.push(t);
    const { sx, sy } = axes(svg, x0, x1, 0, 100, xt, [0, 25, 50, 75, 100], v => v + '°', v => v + '%');

    g.sources.forEach((b, bi) => {
      if (!(b.low < b.high)) return;
      const color = COLORS[bi % COLORS.length];
      const pts = [];
      for (let t = x0; t <= x1; t += 0.5) pts.push([t, pct(curvePwm(t, b))]);
      svg.appendChild(svgEl('path', { d: pathD(pts, sx, sy), class: 'pfc-line', stroke: color }));
      const s = sourceById(b.source);
      if (s && s.temp != null && s.state === 'ok') {
        const t = Math.max(x0, Math.min(x1, s.temp));
        const dot = svgEl('circle', { cx: sx(t), cy: sy(pct(curvePwm(s.temp, b))), r: 5, class: 'pfc-now', fill: color });
        dot.appendChild(svgEl('title', {}, sourceName(b.source) + ': ' + fmtTemp(s.temp) + ' → ' + pct(curvePwm(s.temp, b)) + '%'));
        svg.appendChild(dot);
      }
    });
  }

  function drawHistory(svg, g) {
    if (!svg) return;
    const rows = history.filter(h => h.g && h.g[g.id] != null);
    if (rows.length < 2) {
      axes(svg, 0, 1, 0, 100, [], [0, 25, 50, 75, 100], v => v, v => v);
      svg.appendChild(svgEl('text', { x: W / 2, y: H / 2, 'text-anchor': 'middle', class: 'pfc-axis' }, 'No history yet. Enable fan control and check back later.'));
      return;
    }
    const t0 = rows[0].t, t1 = rows[rows.length - 1].t, span = t1 - t0;
    const step = span > 43200 ? 3 * 3600 : span > 10800 ? 3600 : span > 3600 ? 1800 : 600;
    const xt = []; for (let t = Math.ceil(t0 / step) * step; t <= t1; t += step) xt.push(t);
    const fmt = t => { const d = new Date(t * 1000); return ('0' + d.getHours()).slice(-2) + ':' + ('0' + d.getMinutes()).slice(-2); };
    const temps = [];
    rows.forEach(h => g.sources.forEach(b => { const v = h.s && h.s[b.source]; if (v != null) temps.push(v); }));
    const yMax = Math.max(100, Math.ceil(Math.max(0, ...temps) / 25) * 25);
    const yt = []; for (let v = 0; v <= yMax; v += 25) yt.push(v);
    const { sx, sy } = axes(svg, t0, t1, 0, yMax, xt, yt, fmt, v => v);

    const series = get => {
      const out = [];
      rows.forEach((h, i) => {
        if (i && h.t - rows[i - 1].t > 180) out.push([h.t, null]); // gap: service was stopped
        out.push([h.t, get(h)]);
      });
      return out;
    };
    g.sources.forEach((b, bi) => {
      svg.appendChild(svgEl('path', { d: pathD(series(h => (h.s && h.s[b.source] != null ? h.s[b.source] : null)), sx, sy), class: 'pfc-line pfc-line-thin', stroke: COLORS[bi % COLORS.length] }));
    });
    svg.appendChild(svgEl('path', { d: pathD(series(h => pct(h.g[g.id])), sx, sy), class: 'pfc-line pfc-line-fan' }));
  }

  /* ---------------------------------------------------------- fan table */

  function rpmOptions(p) {
    const prefix = p.key.slice(0, p.key.lastIndexOf('/') + 1);
    let html = '<option value="">Auto' + (p.guess_rpm ? ' (fan' + p.guess_rpm.split('/fan')[1] + ')' : '') + '</option><option value="none">None</option>';
    state.rpm.filter(r => r.key.indexOf(prefix) === 0).forEach(r => { html += '<option value="' + esc(r.key) + '">' + esc(rpmFullName(r)) + '</option>'; });
    return html;
  }

  /** Short text for a speed test result, e.g. "Stops below 20%, starts at 30%". */
  function testSummary(t) {
    if (!t || !t.done) return '';
    if (t.start_pwm == null) return 'Never stops, ' + t.max_rpm + ' RPM max';
    return 'Stops below ' + pct(t.min_pwm) + '%, starts at ' + pct(t.start_pwm) + '%';
  }

  /** Lowest speed at which a fan reliably runs, from its speed test (null without a test). */
  const fanSpinsFrom = p => (p && p.test && p.test.done ? (p.test.start_pwm != null ? p.test.start_pwm : p.test.min_pwm) : null);

  function renderPwmTable() {
    const $tb = $('#pfc-pwm-table tbody');
    if (!state.pwm.length) { $tb.html('<tr><td colspan="7" class="pfc-muted">No controllable fan outputs found.</td></tr>'); pwmSig = ''; return; }
    const sig = state.pwm.map(p => p.key).join('|') + '#' + state.rpm.map(r => r.key + '=' + r.label).join('|');
    if (sig !== pwmSig) {
      pwmSig = sig;
      $tb.html(state.pwm.map(p =>
        '<tr data-key="' + esc(p.key) + '">' +
        '<td><input type="text" class="pfc-label" maxlength="40" placeholder="e.g. GPU intake"></td>' +
        '<td><span class="pfc-output"></span><br><span class="pfc-muted pfc-small pfc-path"></span></td>' +
        '<td class="pfc-speed"></td><td class="pfc-setting"></td>' +
        '<td><select class="pfc-rpm">' + rpmOptions(p) + '</select></td>' +
        '<td class="pfc-group-cell"></td>' +
        '<td class="pfc-nowrap"><button type="button" class="pfc-test-btn" data-mode="identify" title="Slow the fan down, then run it at full speed">Identify</button>' +
        '<button type="button" class="pfc-test-btn" data-mode="speed" title="Measure the RPM from 100% down to 0%: where the fan stops and where it starts again">Speed test</button></td></tr>').join(''));
    }
    const owner = {};
    draft.groups.forEach(g => g.fans.forEach(f => { owner[f] = g.name; }));
    const testing = state.fantest && state.fantest.phase !== 'done' ? state.fantest.key : null;

    state.pwm.forEach(p => {
      const $tr = $tb.find('tr').filter(function () { return this.dataset.key === p.key; });
      const label = $tr.find('.pfc-label')[0];
      if (!focused(label) && !label.dataset.dirty) label.value = p.label;
      $tr.find('.pfc-output').text(p.chip + ' · PWM ' + p.index);
      $tr.find('.pfc-path').text(p.hwmon + '/pwm' + p.index);
      const rpmTxt = p.rpm_key === 'none' ? '-' : (p.rpm == null ? 'n/a' : p.rpm + ' RPM');
      const guess = p.rpm_key && p.rpm_key !== 'none' && !p.rpm_confirmed;
      const summary = testSummary(p.test);
      $tr.find('.pfc-speed').html(esc(rpmTxt) +
        (guess ? ' <span class="pfc-muted pfc-small" title="Not confirmed yet. Use Identify to confirm.">(guess)</span>' : '') +
        (summary ? '<br><span class="pfc-muted pfc-small" title="Result of the last speed test">' + esc(summary) + '</span>' : ''));
      $tr.find('.pfc-setting').html(p.value == null ? 'n/a' : esc(pct(p.value) + '% (' + p.value + ')') + '<br><span class="pfc-muted pfc-small">' + esc(p.enable_name) +
        (p.mode === 0 ? ' · <span class="pfc-error-text" title="This header is in DC mode. Set it to PWM in the BIOS.">DC mode</span>' : '') + '</span>');
      const sel = $tr.find('.pfc-rpm')[0];
      if (!focused(sel)) sel.value = p.rpm_confirmed ? p.rpm_key : '';
      $tr.find('.pfc-group-cell').html(owner[p.key] ? esc(owner[p.key]) : '<span class="pfc-muted">-</span>');
      $tr.toggleClass('pfc-active-row', testing === p.key);
      $tr.find('.pfc-test-btn').prop('disabled', !!testing || !p.writable);
    });
  }

  function renderRpmTable() {
    const used = new Set(state.pwm.map(p => p.rpm_key).filter(Boolean));
    const others = state.rpm.filter(r => !used.has(r.key));
    $('#pfc-rpm-section').toggleClass('pfc-hidden', !others.length);
    const $tb = $('#pfc-rpm-table tbody');
    const sig = others.map(r => r.key).join('|');
    if (sig !== rpmSig) {
      rpmSig = sig;
      $tb.html(others.map(r =>
        '<tr data-key="' + esc(r.key) + '"><td><input type="text" class="pfc-rpm-label" maxlength="40" placeholder="e.g. Pump"></td>' +
        '<td>' + esc(r.chip + ' · fan' + r.index) + (r.driver_label ? '<br><span class="pfc-muted pfc-small">' + esc(r.driver_label) + '</span>' : '') + '</td>' +
        '<td class="pfc-speed"></td></tr>').join(''));
    }
    others.forEach(r => {
      const $tr = $tb.find('tr').filter(function () { return this.dataset.key === r.key; });
      const input = $tr.find('.pfc-rpm-label')[0];
      if (!focused(input) && !input.dataset.dirty) input.value = r.label;
      $tr.find('.pfc-speed').text(r.rpm == null ? 'n/a' : r.rpm + ' RPM');
    });
  }

  /** The Identify / speed test panel below the fan table. */
  function renderFanTest() {
    const ft = state.fantest;
    const $box = $('#pfc-fantest');
    if (!ft || !ft.key || (ft.phase === 'done' && testDismissed === ft.time)) { $box.addClass('pfc-hidden'); return; }
    const running = ft.phase !== 'done';
    const speed = ft.mode === 'speed';
    const f = v => (v == null ? '-' : v);

    let html = '<div class="pfc-fantest-head"><b>' + (speed ? 'Speed test: ' : 'Identifying ') + esc(fanName(ft.key)) + '</b> ';
    if (running) {
      const step = ft.phase === 'starting' ? 'Starting' :
        ft.phase === 'low' ? 'Slowing down to ' + pct(ft.low_pwm || 0) + '%' :
        ft.phase === 'high' ? 'Full speed' :
        (ft.phase === 'up' ? 'Starting again at ' : 'Running at ') + pct(ft.pwm || 0) + '%';
      html += '<span class="pfc-muted">' + esc(step) + '...</span><button type="button" id="pfc-fantest-stop">Stop</button>';
    } else {
      html += '<button type="button" id="pfc-fantest-close">Close</button>';
    }
    html += '</div>';
    if (running) html += '<div class="pfc-progress"><div style="width:' + (ft.progress || 0) + '%"></div></div>';

    const samples = ft.samples || [];
    const current = samples.length ? samples[samples.length - 1].rpm : (ft.before || {});
    const keys = Object.keys(ft.before || current || {});
    if (keys.length) {
      // Identify: the last RPM of the slow and the full speed step. Speed test: at 100% and at the lowest
      // speed reached going down (the test stops going down once the fan stands still).
      const at = {};
      let lowest = 255;
      samples.forEach(s => {
        if (!speed) { at[s.phase] = s.rpm; return; }
        if (s.phase !== 'down') return;
        at[s.pwm] = s.rpm;
        lowest = Math.min(lowest, s.pwm);
      });
      const cols = speed ? [['100%', 255], [pct(lowest) + '%', lowest]] : [['Slow', 'low'], ['Full', 'high']];
      html += '<div class="pfc-fantest-body"><table class="pfc-table pfc-fantest-table"><thead><tr><th>RPM sensor</th><th>Before</th><th>Now</th>' +
        cols.map(c => '<th>' + c[0] + '</th>').join('') + '<th></th></tr></thead><tbody>';
      keys.forEach(k => {
        const r = rpmByKey(k);
        const reacted = (ft.reacted || []).indexOf(k) >= 0;
        html += '<tr class="' + (reacted ? 'pfc-reacted' : '') + '"><td>' + esc(r ? rpmFullName(r) : k) + '</td><td>' + f((ft.before || {})[k]) + '</td><td>' + f(current[k]) + '</td>' +
          cols.map(c => '<td>' + f((at[c[1]] || {})[k]) + '</td>').join('') +
          '<td>' + (reacted ? '<i class="fa fa-check"></i> reacted' : '') + '</td></tr>';
      });
      html += '</tbody></table>';
      if (speed && ft.result) html += '<div class="pfc-chart-box pfc-speed-chart"><svg class="pfc-chart" viewBox="0 0 560 260"></svg></div>';
      html += '</div>';
    } else {
      html += '<p class="pfc-muted">This chip has no RPM sensors, so watch or listen to the fans.</p>';
    }

    if (!running) {
      let result;
      const restored = ' The fan was restored to its previous setting.';
      if (ft.error === 'Stopped') result = 'Stopped.' + restored;
      else if (ft.error) result = ft.error + restored;
      else if (speed) result = 'Done. ' + testSummary(ft.result) + '. Keep the min speed of its group at or above the speed it starts at, so it always comes back after it stopped.' + restored;
      else if (ft.paired) result = 'Done. Paired with ' + rpmFullName(rpmByKey(ft.paired) || { label: ft.paired, index: '' }) + '.' + restored;
      else if ((ft.reacted || []).length > 1) result = 'Several sensors reacted. Pick the right one in the RPM sensor column.';
      else result = 'No RPM sensor reacted. Either nothing is connected, the fan has no RPM wire, or the header is set to DC instead of PWM in the BIOS. Did you see or hear a fan change speed?';
      html += '<p>' + esc(result) + '</p>';
    }
    $box.removeClass('pfc-hidden').html(html);
    if (speed && ft.result) drawSpeedTest($box.find('svg')[0], ft.result);
  }

  /** RPM at every speed step of a speed test. */
  function drawSpeedTest(svg, t) {
    const pts = t.points.filter(p => p[1] != null).map(p => [pct(p[0]), p[1]]).sort((a, b) => a[0] - b[0]);
    const yMax = Math.max(500, Math.ceil(Math.max(...pts.map(p => p[1])) / 500) * 500);
    const yStep = yMax > 3000 ? 1000 : 500;
    const yt = []; for (let v = 0; v <= yMax; v += yStep) yt.push(v);
    const { sx, sy } = axes(svg, 0, 100, 0, yMax, [0, 20, 40, 60, 80, 100], yt, v => v + '%', v => v);
    if (t.start_pwm != null) {
      const x = sx(pct(t.start_pwm));
      svg.appendChild(svgEl('line', { x1: x, x2: x, y1: PAD.t, y2: H - PAD.b, class: 'pfc-marker' }));
      svg.appendChild(svgEl('text', { x: x + 4, y: PAD.t + 12, class: 'pfc-axis' }, 'starts at ' + pct(t.start_pwm) + '%'));
    }
    svg.appendChild(svgEl('path', { d: pathD(pts, sx, sy), class: 'pfc-line', stroke: COLORS[0] }));
    pts.forEach(p => {
      const dot = svgEl('circle', { cx: sx(p[0]), cy: sy(p[1]), r: 4, class: 'pfc-now', fill: COLORS[0] });
      dot.appendChild(svgEl('title', {}, p[0] + '%: ' + p[1] + ' RPM'));
      svg.appendChild(dot);
    });
  }

  /* ------------------------------------------------------ sources table */

  function renderSources() {
    const showAll = $('#pfc-show-all-sensors').prop('checked');
    const usedBy = {};
    draft.groups.forEach(g => g.sources.forEach(b => { (usedBy[b.source] = usedBy[b.source] || []).push(g.name); }));
    const list = state.sources.filter(s => showAll || s.type !== 'hwmon' || usedBy[s.id] || s.label);
    const hiddenCount = state.sources.length - list.length;

    const nv = state.nvidia;
    $('#pfc-nvidia-note').text(!nv.available ? 'No Nvidia driver found (nvidia-smi is missing), so no GPUs are listed.' : (nv.error ? 'Nvidia: ' + nv.error : ''));

    const $tb = $('#pfc-source-table tbody');
    const sig = list.map(s => s.id + '=' + s.label).join('|');
    if (sig !== sourceSig) {
      sourceSig = sig;
      const type = { disk: 'Disks', nvidia: 'Nvidia GPU', hwmon: 'Kernel sensor' };
      $tb.html(list.map(s =>
        '<tr data-key="' + esc(s.id) + '"><td><input type="text" class="pfc-source-label" maxlength="40" placeholder="' + esc(s.name) + '"></td>' +
        '<td>' + esc(s.name) + '<br><span class="pfc-muted pfc-small">' + esc(type[s.type] || s.type) + '</span></td>' +
        '<td class="pfc-s-temp"></td><td class="pfc-s-used"></td></tr>').join('') +
        (hiddenCount ? '<tr><td colspan="4" class="pfc-muted pfc-small">' + hiddenCount + ' more kernel sensor' + (hiddenCount === 1 ? '' : 's') + ' hidden.</td></tr>' : ''));
    }
    list.forEach(s => {
      const $tr = $tb.find('tr').filter(function () { return this.dataset.key === s.id; });
      const input = $tr.find('.pfc-source-label')[0];
      if (input && !focused(input) && !input.dataset.dirty) input.value = s.label;
      $tr.find('.pfc-s-temp').html(esc(fmtTemp(s.temp)) + (s.detail ? ' <span class="pfc-muted pfc-small">' + esc(s.detail) + '</span>' : ''));
      $tr.find('.pfc-s-used').html(usedBy[s.id] ? esc(usedBy[s.id].join(', ')) : '<span class="pfc-muted">-</span>');
    });
  }

  function renderDisks() {
    const excluded = draft.array.exclude;
    const disks = state.disks.filter(d => d.device);
    const $tb = $('#pfc-disk-table tbody');
    if (!disks.length) { $tb.html('<tr><td colspan="5" class="pfc-muted">No disks found.</td></tr>'); return; }
    $tb.html(disks.map(d => {
      const usable = !!d.source;
      const use = usable && excluded.indexOf(d.name) < 0;
      const src = usable ? sourceById(d.source) : null;
      const hot = src && src.detail === 'hottest is ' + d.name;
      return '<tr class="' + (use ? '' : 'pfc-dim') + '">' +
        '<td><input type="checkbox" class="pfc-disk-use" data-name="' + esc(d.name) + '"' + (use ? ' checked' : '') + (usable ? '' : ' disabled title="' + esc(d.type) + ' disks are not used"') + '></td>' +
        '<td>' + esc(d.name) + (hot ? ' <i class="fa fa-fire pfc-hot" title="Hottest disk of ' + esc(sourceName(d.source)) + '"></i>' : '') + '<br><span class="pfc-muted pfc-small">' + esc(d.device) + '</span></td>' +
        '<td>' + (usable ? esc(sourceName(d.source)) : '<span class="pfc-muted">-</span>') + '<br><span class="pfc-muted pfc-small">' + esc(d.type) + '</span></td><td>' + (d.spundown ? 'Standby' : 'Active') + '</td>' +
        '<td>' + (d.spundown ? 'Spun down' : esc(fmtTemp(d.temp))) + '</td></tr>';
    }).join(''));
  }

  function fillCfgInputs() {
    $('[data-cfg]', root).each(function () {
      if (focused(this)) return;
      const v = getPath(draft, this.dataset.cfg);
      if (this.type === 'checkbox') this.checked = !!v;
      else this.value = String(this.dataset.type === 'pct' ? pct(v) : v);
    });
  }

  /* ------------------------------------------------------------- events */

  const $groups = $('#pfc-groups');
  const gIndex = el => +$(el).closest('.pfc-group')[0].dataset.g;
  const rebuild = () => { buildGroups(); updateGroups(); renderPwmTable(); renderSources(); renderStatus(); };

  $('#pfc-add-group').on('click', () => {
    const id = 'g' + Math.random().toString(16).slice(2, 10);
    draft.groups.push({ id, name: draft.groups.length ? 'New group' : 'Array fans', fans: [], ramp_down: draft.groups.length ? 30 : 0, sources: [] });
    markDirty();
    rebuild();
  });

  $groups
    .on('input', '.pfc-g-name', function () { draft.groups[gIndex(this)].name = this.value; markDirty(); })
    .on('change', '.pfc-g-name', () => rebuild())
    .on('input', '.pfc-g-ramp', function () { draft.groups[gIndex(this)].ramp_down = parseInt(this.value, 10) || 0; markDirty(); })
    .on('change', '.pfc-g-fan', function () {
      const g = draft.groups[gIndex(this)];
      g.fans = g.fans.filter(k => k !== this.value);
      if (this.checked) g.fans.push(this.value);
      markDirty();
      rebuild();
    })
    .on('input', '.pfc-b-field', function () {
      const g = draft.groups[gIndex(this)];
      const b = g.sources[+this.dataset.b];
      const field = this.dataset.field;
      const v = parseFloat(this.value);
      if (isNaN(v)) return;
      b[field] = /_pwm$/.test(field) ? toPwm(v) : Math.round(v);
      markDirty();
      updateGroups();
    })
    .on('click', '.pfc-b-remove', function () {
      draft.groups[gIndex(this)].sources.splice(+this.dataset.b, 1);
      markDirty();
      rebuild();
    })
    .on('change', '.pfc-g-addsrc', function () {
      if (!this.value) return;
      draft.groups[gIndex(this)].sources.push(defaultBinding(this.value));
      markDirty();
      rebuild();
    })
    .on('click', '.pfc-g-delete', function () {
      const gi = gIndex(this);
      if (!confirm('Delete the group "' + draft.groups[gi].name + '"? After you click Apply, its fans run at full speed until the next reboot, then the BIOS controls them again.')) return;
      draft.groups.splice(gi, 1);
      markDirty();
      rebuild();
    });

  $(root).on('change input', '[data-cfg]', function () {
    let v = this.type === 'checkbox' ? this.checked : this.value;
    if (this.dataset.type === 'bool') v = v === 'true' || v === true;
    if (this.dataset.type === 'int') v = parseInt(v, 10) || 0;
    if (this.dataset.type === 'pct') v = toPwm(parseFloat(v) || 0);
    setPath(draft, this.dataset.cfg, v);
    markDirty();
    if (/^array\./.test(this.dataset.cfg)) renderDisks();
  });

  $('#pfc-disk-table').on('change', '.pfc-disk-use', function () {
    const list = draft.array.exclude.filter(n => n !== this.dataset.name);
    if (!this.checked) list.push(this.dataset.name);
    draft.array.exclude = list;
    markDirty();
    renderDisks();
  });

  $(root).on('click', '.pfc-apply', function () {
    $('.pfc-apply').prop('disabled', true);
    post({ action: 'save_config', config: JSON.stringify(draft) })
      .then(() => { dirty = false; return load(true); }, showError)
      .always(() => $('.pfc-apply').prop('disabled', false));
  });

  $(root).on('click', '.pfc-reset', () => {
    dirty = false;
    draft = clone(state.config);
    groupsBuilt = false;
    render();
  });

  // Names are saved right away (they don't change how fans are controlled)
  function labelSaver(selector, action, after) {
    $(root)
      .on('input', selector, function () { this.dataset.dirty = '1'; })
      .on('keydown', selector, function (e) { if (e.key === 'Enter') this.blur(); })
      .on('change', selector, function () {
        const el = this;
        post({ action, key: $(el).closest('tr')[0].dataset.key, label: el.value })
          .then(() => { delete el.dataset.dirty; return load(); }, showError)
          .then(() => { if (after) after(); });
      });
  }
  labelSaver('.pfc-label', 'save_fan', () => { buildGroups(); updateGroups(); });
  labelSaver('.pfc-rpm-label', 'save_rpm_label');
  labelSaver('.pfc-source-label', 'save_source_label', () => { buildGroups(); updateGroups(); });

  $('#pfc-pwm-table')
    .on('change', '.pfc-rpm', function () { post({ action: 'save_fan', key: $(this).closest('tr')[0].dataset.key, rpm: this.value }).then(() => load(), showError); })
    .on('click', '.pfc-test-btn', function () {
      const key = $(this).closest('tr')[0].dataset.key;
      const mode = this.dataset.mode;
      if (mode === 'speed' && !confirm('Speed test "' + fanName(key) + '"?\n\nThe fan is stepped from 100% down to 0% and back up, which takes about a minute. ' +
        'It stops for a while, so don\'t run this on a fan that is cooling something hot right now.')) return;
      testDismissed = null;
      post({ action: 'fantest', key, mode }).then(() => load(), showError);
    });

  $('#pfc-fantest')
    .on('click', '#pfc-fantest-stop', () => post({ action: 'fantest_stop' }).then(() => load(), showError))
    .on('click', '#pfc-fantest-close', () => { testDismissed = state.fantest.time; renderFanTest(); });

  $('#pfc-show-all-sensors').on('change', () => { sourceSig = ''; renderSources(); });
  $(root).on('click', '.pfc-toggle-unnamed', e => { e.preventDefault(); showUnnamed = !showUnnamed; buildGroups(); updateGroups(); });

  window.addEventListener('beforeunload', e => { if (dirty) { e.preventDefault(); e.returnValue = ''; } });

  load(true);
})(jQuery);
