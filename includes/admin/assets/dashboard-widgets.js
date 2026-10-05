function apiFetch(endpoint, options = {}) {
  const parts = endpoint.split('?');
  const url = new URL(CB_REST.root + parts[0], window.location.href);
  if (parts[1]) new URLSearchParams(parts[1]).forEach((value, key) => url.searchParams.set(key, value));
  return fetch(url, { credentials: 'same-origin', ...options, headers: { 'X-WP-Nonce': CB_REST.nonce, ...options.headers } }).then(async response => {
    const data = await response.json();
    if (!response.ok || data.status === 'error') throw new Error(data.message || 'Unable to load dashboard data.');
    return data;
  });
}
function cbDashboardEscape(value) {
  const node = document.createElement('span'); node.textContent = value == null ? '' : String(value);
  return node.innerHTML.replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}
function formatLocalTime(isoString) {
  if (!isoString) return 'Never';
  const date = new Date(isoString); if (!Number.isFinite(date.getTime())) return 'Unknown';
  const options = { year: 'numeric', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit', hour12: true };
  try { return date.toLocaleString(undefined, { ...options, timeZone: CB_REST.timezone || 'UTC' }); }
  catch (_) { return new Date(date.getTime() + (CB_REST.utc_offset || 0) * 60000).toLocaleString(undefined, { ...options, timeZone: 'UTC' }); }
}
function cbDashboardMoney(value, currency = CB_REST.currency || 'USD') {
  return new Intl.NumberFormat(undefined, { style: 'currency', currency }).format(Number(value) || 0);
}
const cbDashboardPeriods = { revenue: 1, performance: 1, trends: 1 };
function cbDashboardLoad(id, endpoint, render) {
  const container = document.getElementById(id); if (!container) return Promise.resolve();
  container.setAttribute('aria-live', 'polite');
  if (!container.hasChildNodes()) container.textContent = 'Loading…';
  return apiFetch(endpoint).then(data => { container.classList.remove('cb-widget-error'); return render(container, data); }).catch(error => {
    container.textContent = error.message; container.classList.add('cb-widget-error');
  });
}
function renderAvailabilityWidget() {
  return cbDashboardLoad('cb-widget-availability', 'dashboard/availability', (container, data) => {
    container.innerHTML = '<p>Times shown in ' + cbDashboardEscape(CB_REST.timezone || 'UTC') + '. Cached availability from the last availability sync.</p><table class="cb-table"><thead><tr><th>Event</th><th>Next available slot</th></tr></thead><tbody>' + data.map(row => '<tr><td>' + cbDashboardEscape(row.name) + '</td><td>' + cbDashboardEscape(row.slots.length ? formatLocalTime(row.slots[0]) : 'No upcoming cached slots') + '</td></tr>').join('') + '</tbody></table>';
  });
}
function renderIntegrityWidget() {
  return cbDashboardLoad('cb-widget-integrity', 'dashboard/integrity', (container, data) => {
    container.innerHTML = '<strong>Missing UUIDs (up to 10 shown):</strong>' + (data.missing_uuid.map(row => '<div>#' + Number(row.id) + ' — ' + cbDashboardEscape(row.name) + ' (' + cbDashboardEscape(row.start_time || 'No time') + ') <button class="button cb-fix-uuid" data-id="' + Number(row.id) + '">Fix Now</button></div>').join('') || '<p>None</p>') + '<strong>Duplicates (up to 10 shown):</strong>' + (data.duplicates.map(row => '<div>' + cbDashboardEscape(row.uuid) + ' (' + Number(row.count) + ') <button class="button cb-fix-dup" data-uuid="' + cbDashboardEscape(row.uuid) + '">Fix Now</button></div>').join('') || '<p>None</p>');
    container.querySelectorAll('.cb-fix-uuid, .cb-fix-dup').forEach(button => button.addEventListener('click', async () => {
      button.disabled = true;
      const missing = button.classList.contains('cb-fix-uuid');
      try { await apiFetch('dashboard/' + (missing ? 'fix-missing-uuid' : 'fix-duplicate'), { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(missing ? { id: Number(button.dataset.id) } : { uuid: button.dataset.uuid }) }); await renderIntegrityWidget(); }
      catch (error) { window.alert(error.message); button.disabled = false; }
    }));
  });
}
function cbDashboardControls(type, selected) {
  return '<div class="cb-period-controls">' + [1,3,6,12].map(month => '<button type="button" class="button' + (month === selected ? ' button-primary' : '') + '" data-period="' + month + '">' + month + 'M</button>').join(' ') + '</div>';
}
function cbDashboardBindPeriods(container, render) {
  container.querySelectorAll('[data-period]').forEach(button => button.addEventListener('click', () => render(Number(button.dataset.period))));
}
function renderRevenueWidget(months = cbDashboardPeriods.revenue) {
  cbDashboardPeriods.revenue = months;
  return cbDashboardLoad('cb-widget-revenue', 'dashboard/revenue?months=' + months, (container, data) => {
    container.innerHTML = cbDashboardControls('revenue', months) + '<p><strong>Net meeting sales (' + cbDashboardEscape(data.period) + '): ' + cbDashboardEscape(cbDashboardMoney(data.total_revenue, data.currency)) + '</strong></p><p>' + cbDashboardEscape(data.revenue_basis) + '</p>' + (data.excluded_currency_orders ? '<p>Orders in other currencies excluded: ' + Number(data.excluded_currency_orders) + '</p>' : '') + '<table class="cb-table"><thead><tr><th>Event</th><th>Net sales</th><th>Last meeting</th></tr></thead><tbody>' + data.events.map(row => '<tr><td>' + cbDashboardEscape(row.name) + '</td><td>' + cbDashboardEscape(cbDashboardMoney(row.revenue, data.currency)) + '</td><td>' + cbDashboardEscape(row.last_booking ? formatLocalTime(row.last_booking) : '—') + '</td></tr>').join('') + '</tbody></table>';
    cbDashboardBindPeriods(container, renderRevenueWidget);
  });
}
let cbDashboardSyncing = false;
function renderHealthWidget() {
  if (cbDashboardSyncing) return Promise.resolve();
  return cbDashboardLoad('cb-widget-health', 'dashboard/health', (container, data) => {
    const labels = { master: 'Master sync', scheduled_events: 'Scheduled events', invitees: 'Invitees', event_types: 'Event types', locations: 'Locations' };
    const schedules = Object.entries(data.schedules).map(([key, job]) => '<tr><td>' + labels[key] + '</td><td>' + cbDashboardEscape(job.enabled ? job.frequency_label : 'Disabled') + '</td><td>' + cbDashboardEscape(job.next_run ? formatLocalTime(new Date(job.next_run * 1000).toISOString()) + (job.next_run * 1000 < Date.now() ? ' (due)' : '') : '—') + '</td><td>' + cbDashboardEscape(job.last_success ? formatLocalTime(job.last_success) + (job.legacy ? ' (legacy)' : '') : 'Never') + '</td></tr>').join('');
    const errors = Object.entries(data.errors || {}).map(([key, values]) => '<p class="cb-widget-error">' + cbDashboardEscape(labels[key] || key) + ': ' + cbDashboardEscape(values.join('; ') || 'Sync failed.') + '</p>').join('');
    container.innerHTML = '<p>API / sync status: ' + cbDashboardEscape(data.calendly_api) + '</p><p>Last successful sync: ' + cbDashboardEscape(formatLocalTime(data.last_sync)) + '</p><p>Last attempt: ' + cbDashboardEscape(formatLocalTime(data.last_attempt)) + '</p><p>Times shown in ' + cbDashboardEscape(data.timezone) + '.</p>' + errors + '<div class="cb-widget-scroll"><table class="cb-table"><thead><tr><th>Background sync</th><th>Schedule</th><th>Next run</th><th>Last success</th></tr></thead><tbody>' + schedules + '</tbody></table></div><p>' + (data.wp_cron_disabled ? 'Automatic WP-Cron is disabled; an external scheduler must run the jobs.' : 'Background jobs run through WP-Cron when WordPress receives traffic.') + '</p><button type="button" class="button" data-sync="sync">Sync now</button> <button type="button" class="button" data-sync="refresh">Refresh all data</button><p class="cb-sync-feedback" role="status"></p>';
    container.querySelectorAll('[data-sync]').forEach(button => button.addEventListener('click', async () => {
      if (cbDashboardSyncing) return;
      cbDashboardSyncing = true;
      container.querySelectorAll('[data-sync]').forEach(control => { control.disabled = true; });
      const feedback = container.querySelector('.cb-sync-feedback'); feedback.textContent = 'Syncing…';
      try { const response = await apiFetch('dashboard/' + button.dataset.sync, { method: 'POST' }); feedback.textContent = response.message; cbDashboardSyncing = false; await cbDashboardRefresh(); }
      catch (error) { cbDashboardSyncing = false; feedback.textContent = error.message; container.querySelectorAll('[data-sync]').forEach(control => { control.disabled = false; }); }
    }));
  });
}
function renderPerformanceWidget(months = cbDashboardPeriods.performance) {
  cbDashboardPeriods.performance = months;
  return cbDashboardLoad('cb-widget-performance', 'dashboard/performance?months=' + months, (container, data) => {
    container.innerHTML = cbDashboardControls('performance', months) + '<p>Bookings by meeting date; net sales from linked order lines, excluding tax.</p><table class="cb-table"><thead><tr><th>Event</th><th>Bookings</th><th>Net sales</th></tr></thead><tbody>' + data.map(row => '<tr><td>' + cbDashboardEscape(row.name) + '</td><td>' + Number(row.bookings) + '</td><td>' + cbDashboardEscape(cbDashboardMoney(row.revenue)) + '</td></tr>').join('') + '</tbody></table>';
    cbDashboardBindPeriods(container, renderPerformanceWidget);
  });
}
function renderRecentBookingsWidget() {
  return cbDashboardLoad('cb-widget-recent', 'dashboard/recent-bookings', (container, data) => {
    container.innerHTML = '<p>Latest records added, including future meetings.</p><table class="cb-table"><thead><tr><th>Invitee / Event</th><th>Meeting time</th><th>Status</th></tr></thead><tbody>' + data.map(row => '<tr><td>' + cbDashboardEscape(row.invitee) + '<br>' + cbDashboardEscape(row.event_name) + '</td><td>' + cbDashboardEscape(formatLocalTime(row.scheduled)) + '</td><td>' + cbDashboardEscape(row.status || 'Unknown') + '</td></tr>').join('') + '</tbody></table>';
  });
}
function cbDashboardRefresh() {
  return Promise.all([renderAvailabilityWidget(), renderIntegrityWidget(), renderHealthWidget(), renderRevenueWidget(), renderPerformanceWidget(), renderRecentBookingsWidget(), typeof renderTrendsWidget === 'function' ? renderTrendsWidget() : Promise.resolve()]);
}
document.addEventListener('DOMContentLoaded', () => {
  cbDashboardRefresh();
  window.setInterval(() => { if (!document.hidden && !cbDashboardSyncing) cbDashboardRefresh(); }, 60000);
});
