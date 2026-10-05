let trendsChart;
function renderTrendsWidget(months = cbDashboardPeriods.trends) {
  cbDashboardPeriods.trends = months;
  const canvas = document.getElementById('cb-widget-trends-chart'); if (!canvas) return Promise.resolve();
  return apiFetch('dashboard/trends?months=' + months).then(data => {
    if (typeof Chart !== 'function') throw new Error('The booking chart library could not be loaded.');
    if (trendsChart) trendsChart.destroy();
    trendsChart = new Chart(canvas.getContext('2d'), {
      type: 'line', data: { labels: data.map(row => row.day), datasets: [{ label: 'Bookings (' + months + 'M)', data: data.map(row => Number(row.count)), borderColor: '#2271b1', backgroundColor: 'rgba(34,113,177,0.2)', fill: true, tension: 0.3 }] },
      options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { x: { title: { display: true, text: 'Meeting date (' + (CB_REST.timezone || 'UTC') + ')' } }, y: { title: { display: true, text: 'Bookings' }, beginAtZero: true, ticks: { precision: 0 } } } }
    });
    canvas.parentElement.querySelector('.cb-chart-error')?.remove();
  }).catch(error => {
    let message = canvas.parentElement.querySelector('.cb-chart-error');
    if (!message) { message = document.createElement('p'); message.className = 'cb-chart-error'; canvas.after(message); }
    message.textContent = error.message;
  });
}
document.addEventListener('DOMContentLoaded', () => {
  [1,3,6,12].forEach(month => document.getElementById('cb-trends-' + month + 'm')?.addEventListener('click', () => renderTrendsWidget(month)));
});
