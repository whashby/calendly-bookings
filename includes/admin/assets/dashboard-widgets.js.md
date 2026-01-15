# Copy of dashboard-widgets.js

```js
document.addEventListener('DOMContentLoaded', () => {
  renderAvailabilityWidget();
  renderIntegrityWidget();
  renderHealthWidget();
  renderTrendsWidget();
});

function apiFetch(endpoint) {
  return fetch(CB_REST.root + endpoint, {
    headers: {
      'X-WP-Nonce': CB_REST.nonce
    }
  }).then(res => res.json());
}

function renderAvailabilityWidget() {
  apiFetch('dashboard/availability').then(data => {
    const container = document.getElementById('cb-widget-availability');
    container.innerHTML = '';
    data.forEach(item => {
      const slots = item.slots.length ? item.slots.join(', ') : 'No upcoming slots';
      container.innerHTML += `<div><strong>${item.name}</strong>: ${slots}</div>`;
    });
  });
}

function renderIntegrityWidget() {
  apiFetch('dashboard/integrity').then(data => {
    const container = document.getElementById('cb-widget-integrity');
    container.innerHTML = '';

    // Missing UUIDs
    container.innerHTML += '<strong>Missing UUIDs:</strong>';
    if (data.missing_uuid.length) {
      data.missing_uuid.forEach(e => {
        container.innerHTML += `
          <div>
            #${e.id} - ${e.name} 
            (${e.start_time || 'No time'})
            <button class="button cb-fix-uuid" data-id="${e.id}">Fix Now</button>
          </div>`;
      });
    } else {
      container.innerHTML += '<div>None</div>';
    }

    // Duplicates
    container.innerHTML += '<strong>Duplicates:</strong>';
    if (data.duplicates.length) {
      data.duplicates.forEach(d => {
        container.innerHTML += `
          <div>
            ${d.uuid} (${d.count})
            <button class="button cb-fix-dup" data-uuid="${d.uuid}">Fix Now</button>
          </div>`;
      });
    } else {
      container.innerHTML += '<div>None</div>';
    }

    // Attach handlers
    document.querySelectorAll('.cb-fix-uuid').forEach(btn => {
      btn.addEventListener('click', () => {
        const id = btn.dataset.id;
        fetch(CB_REST.root + 'dashboard/fix-missing-uuid', {
          method: 'POST',
          headers: { 'X-WP-Nonce': CB_REST.nonce, 'Content-Type': 'application/json' },
          body: JSON.stringify({ id })
        })
        .then(res => res.json())
        .then(resp => {
          alert(resp.message);
          renderIntegrityWidget(); // refresh
        });
      });
    });

    document.querySelectorAll('.cb-fix-dup').forEach(btn => {
      btn.addEventListener('click', () => {
        const uuid = btn.dataset.uuid;
        fetch(CB_REST.root + 'dashboard/fix-duplicate', {
          method: 'POST',
          headers: { 'X-WP-Nonce': CB_REST.nonce, 'Content-Type': 'application/json' },
          body: JSON.stringify({ uuid })
        })
        .then(res => res.json())
        .then(resp => {
          alert(resp.message);
          renderIntegrityWidget(); // refresh
        });
      });
    });
  });
}

function renderRevenueWidget(months = 1) {
  apiFetch(`dashboard/revenue?months=${months}`).then(data => {
    const container = document.getElementById('cb-widget-revenue');
    container.innerHTML = `
      <div class="cb-revenue-controls">
        <button id="cb-rev-1m" class="button">1M</button>
        <button id="cb-rev-3m" class="button">3M</button>
        <button id="cb-rev-6m" class="button">6M</button>
        <button id="cb-rev-12m" class="button">12M</button>
      </div>
      <div>Total Revenue (${months === 0 ? 'All Time' : months + 'M'}): $${data.revenue.toFixed(2)}</div>
      <strong>Top Event Types:</strong>
      <ul class="cb-list"></ul>
    `;
    const list = container.querySelector('ul');
    data.top_events.forEach(ev => {
      list.innerHTML += `<li>${ev.name}: $${ev.revenue.toFixed(2)}</li>`;
    });

    // Attach filter handlers
    document.getElementById('cb-rev-1m').addEventListener('click', () => renderRevenueWidget(1));
    document.getElementById('cb-rev-3m').addEventListener('click', () => renderRevenueWidget(3));
    document.getElementById('cb-rev-6m').addEventListener('click', () => renderRevenueWidget(6));
    document.getElementById('cb-rev-12m').addEventListener('click', () => renderRevenueWidget(12));
  });
}

document.addEventListener('DOMContentLoaded', () => {
  renderRevenueWidget(1); // default
});


function renderHealthWidget() {
  apiFetch('dashboard/health').then(data => {
    const container = document.getElementById('cb-widget-health');
    container.innerHTML = '';
    container.innerHTML += `<div>Calendly API: ${data.calendly_api}</div>`;
    container.innerHTML += `<div>Last Sync: ${data.last_sync}</div>`;
    container.innerHTML += `<div>Errors (24h): ${data.errors24h}</div>`;
    container.innerHTML += `<button id="cb-sync-btn" class="button">Sync Now</button>`; 

    document.getElementById('cb-sync-btn').addEventListener('click', () => { 
      apiFetch('dashboard/sync', { method: 'POST' }) // use POST if that's how your route is registered
        .then(syncData => { 
          // show success popup instead of alert
          showNotice(syncData.message || 'Sync completed successfully');
          // Refresh widget after sync
          renderHealthWidget(); 
        })
        .catch(err => showNotice('Sync failed: ' + err.message, 'error'));
    }); 
  });
}

function renderPerformanceWidget(months = 1) {
  apiFetch(`dashboard/performance?months=${months}`).then(data => {
    const container = document.getElementById('cb-widget-performance');
    container.innerHTML = `
      <div class="cb-performance-controls">
        <button id="cb-perf-1m" class="button">1M</button>
        <button id="cb-perf-3m" class="button">3M</button>
        <button id="cb-perf-6m" class="button">6M</button>
        <button id="cb-perf-12m" class="button">12M</button>
      </div>
      <table class="cb-table">
        <thead>
          <tr>
            <th>Event Type</th>
            <th>Bookings</th>
            <th>Revenue</th>
          </tr>
        </thead>
        <tbody></tbody>
      </table>
    `;
    const tbody = container.querySelector('tbody');
    data.forEach(row => {
      tbody.innerHTML += `
        <tr>
          <td>${row.name}</td>
          <td>${row.bookings}</td>
          <td>${row.revenue.toFixed(2)}</td>
        </tr>
      `;
    });

    // Attach filter handlers
    document.getElementById('cb-perf-1m').addEventListener('click', () => renderPerformanceWidget(1));
    document.getElementById('cb-perf-3m').addEventListener('click', () => renderPerformanceWidget(3));
    document.getElementById('cb-perf-6m').addEventListener('click', () => renderPerformanceWidget(6));
    document.getElementById('cb-perf-12m').addEventListener('click', () => renderPerformanceWidget(12));
  });
}

document.addEventListener('DOMContentLoaded', () => {
  renderPerformanceWidget(1); // default
});

function renderRecentBookingsWidget() {
  apiFetch('dashboard/recent-bookings').then(data => {
    const container = document.getElementById('cb-widget-recent');
    container.innerHTML = '<ul class="cb-list"></ul>';
    const list = container.querySelector('ul');
    data.forEach(row => {
      list.innerHTML += `
        <li>
          <span class="cb-booking-name">${row.name}</span>
          <span class="cb-booking-time">${row.start_time}</span>
          <span class="cb-booking-status ${row.status}">${row.status}</span>
        </li>
      `;
    });
  });
}

document.addEventListener('DOMContentLoaded', () => {
  renderPerformanceWidget();
  renderRecentBookingsWidget();
});

```
