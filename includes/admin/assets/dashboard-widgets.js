(function($){
  const REST    = CB_REST;           // e.g. "https://yoursite.com/wp-json/calendly-bookings/v1/"
  const HEADERS = { 'X-WP-Nonce': CB_REST_NONCE };
  const currencyOr = v => v || '—';

  function renderList($el, items, map){
    $el.empty();
    if(!items || !items.length){
    $el.append('<p>No data</p>');
      return;
    } 
  const ul = $('<ul class="cb-list"></ul>');
  items.forEach(item => ul.append(map(item)));
  $el.append(ul);
}

  function fetchJSON(path, params = {}){
    const url = new URL(REST + path.replace(/^\//,''));
    Object.entries(params).forEach(([k,v]) => url.searchParams.set(k,v));
    return fetch(url.toString(), { headers: HEADERS, credentials: 'same-origin' })
    .then(r => r.json());
  }

  // Format last sync into "x ago (full date)" or fallback
  function formatLastSync(raw) {
    if (!raw) return '—';
      const ts = isNaN(raw) ? Date.parse(raw) : parseInt(raw, 10) * (raw.length === 10 ? 1000 : 1);
    if (isNaN(ts)) return raw; // fallback to raw if parsing fails

      const dateObj = new Date(ts);

      // Relative time
      const diffMs = Date.now() - dateObj.getTime();
      const diffMins = Math.floor(diffMs / 60000);
      let relative;
      if (diffMins < 1) relative = 'just now';
      else if (diffMins < 60) relative = `${diffMins} min${diffMins !== 1 ? 's' : ''} ago`;
      else if (diffMins < 1440) relative = `${Math.floor(diffMins / 60)} hour${Math.floor(diffMins / 60) !== 1 ? 's' : ''} ago`;
      else relative = `${Math.floor(diffMins / 1440)} day${Math.floor(diffMins / 1440) !== 1 ? 's' : ''} ago`;

      // Absolute date/time in local timezone
      const abs = dateObj.toLocaleString(undefined, {
      weekday: 'long',
      year: 'numeric',
      month: 'long',
      day: 'numeric',
      hour: 'numeric',
      minute: '2-digit'
    });

    return `${relative} (${abs})`;
  }

  function loadSummary(){
    fetchJSON('summary').then(data=>{
      renderList($('#cb-today-bookings'), data.today, i =>
        $('<li></li>').html(
        `<div class="row"><span class="when">${i.when}</span><span class="name">${i.name}</span><span class="status ${i.status}">${i.status}</span></div>`
        )
      );
      renderList($('#cb-upcoming-meetings'), data.upcoming, i =>
        $('<li></li>').html(
        `<div class="row"><span class="when">${i.when}</span><span class="name">${i.name}</span><span class="status ${i.status}">${i.status}</span></div>`
        )
      );
      $('#cb-revenue-month').text(currencyOr(data.revenue.this_month));
      $('#cb-revenue-mom').text(currencyOr(data.revenue.mom));
      $('#cb-api-status').text(data.health.api);
      $('#cb-last-sync').text(formatLastSync(data.health.last_sync));
      $('#cb-errors-24h').text(data.health.errors24h);
    });
  }

  function loadTrends(){
    const ctx=document.getElementById('cb-booking-trends');
    if(!ctx || typeof Chart==='undefined')return;
    fetchJSON('trends',{days:30}).then(series=>{
      new Chart(ctx,{
        type:'line',
        data:{
          labels:series.labels,
          datasets:[{
            label:'Bookings',
            data:series.counts,
            borderColor:'#3f8cff',
            backgroundColor:'rgba(63,140,255,0.1)',
            tension:0.25,
            fill:true
          }]
        },
        options:{
          responsive:true,
          scales:{
            x:{grid:{display:false}},
            y:{beginAtZero:true,ticks:{precision:0}}
          },
          plugins:{legend:{display:false}}
        }
      });
    });
  }

function loadAvailability(){
	fetchJSON('availability').then(rows=>{
		renderList($('#cb-availability'), rows, r =>
				   $('<li></li>').html(
			`<div class="row"><span class="name"><a href="${r.url}">${r.name}</a></span><span class="uuid">${r.uuid}</span><span class="slot">${r.next_slot || '—'}</span><span class="price">${r.price || ''}</span><span class="duration">${r.duration ? r.duration + 'm' : ''}</span></div>`
		)
				  );
	});
}

function loadIntegrity(){
  fetchJSON('integrity').then(data => {
    const $el = $('#cb-data-integrity');
    $el.empty();

    const blocks = $('<div class="cb-columns"></div>');

    // Duplicates block
    const dupes = $('<div class="cb-col"><h4>Duplicates</h4></div>');
    if (!data.duplicates || !data.duplicates.length) {
      dupes.append('<p>None</p>');
    } else {
      data.duplicates.forEach(d => {
        dupes.append(`<p><strong>UUID:</strong> ${d.uuid}</p>`);
        const ul = $('<ul class="cb-list small"></ul>');
        d.products.forEach(p => {
          ul.append(`<li><a href="${p.url}">${p.name}</a> (#${p.product_id})</li>`);
        });
        dupes.append(ul);
      });
    }

    // Missing UUID block
    const missing = $('<div class="cb-col"><h4>Missing UUID</h4></div>');
    if (!data.missing || !data.missing.length) {
    missing.append('<p>None</p>');
    } else {
    const ul = $('<ul class="cb-list small"></ul>');
    data.missing.forEach(m => {
    ul.append(`<li><a href="${m.url}">${m.name}</a> (#${m.product_id})</li>`);
    });
    missing.append(ul);
    }

    blocks.append(dupes).append(missing);
    $el.append(blocks);
  }).catch(err => {
    console.error('Error loading data integrity', err);
    $('#cb-data-integrity').html('<p>Error loading data integrity.</p>');
  });
}

// UPCOMING MEETINGS: direct from scheduled-events endpoint
function loadUpcomingMeetings(){
fetchJSON('upcoming-events',{limit:5}).then(events=>{
renderList($('#cb-upcoming-meetings'), events, ev =>
$('<li></li>').html(
`<div class="row"><span class="when">${ev.when}</span><span class="name">${ev.name}</span><span class="status ${ev.status}">${ev.status}</span></div>`
)
);
});
}

// Auto-refresh helper
function autoRefresh(fn, intervalMs){
fn(); // initial load
setInterval(fn, intervalMs);
}

$(function(){
// Initial loads
loadSummary();
loadTrends();
loadAvailability();
loadIntegrity();

// Auto-refresh certain widgets
autoRefresh(loadSummary, 5 * 60 * 1000);          // every 5 min
autoRefresh(loadUpcomingMeetings, 60 * 1000);     // every 1 min
autoRefresh(loadAvailability, 10 * 60 * 1000);    // every 10 min
autoRefresh(loadIntegrity, 15 * 60 * 1000);       // every 15 min

$('#cb-sync-now').on('click', function(e){
e.preventDefault();
const $btn = $(this);
$btn.prop('disabled', true).text('Syncing…');

fetch(CB_REST + 'sync', {
method: 'POST',
headers: {
'X-WP-Nonce': CB_REST_NONCE
},
credentials: 'same-origin'
})
.then(r => r.json())
.then(data => {
if (data.success) {
alert('Sync complete!');
$('#cb-last-sync').text(formatLastSync(data.last_sync));
// Optionally refresh summary widget
loadSummary();
} else {
alert('Sync failed: ' + (data.message || 'Unknown error'));
}
})
.catch(err => {
console.error(err);
alert('Sync request failed.');
})
.finally(() => {
$btn.prop('disabled', false).text('Sync Now');
});
});
});

})(jQuery);
