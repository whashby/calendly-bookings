# Copy of dashboard-charts.js

```js
let trendsChart;

function renderTrendsWidget(months = 1) {
  apiFetch(`dashboard/trends?months=${months}`).then(data => {
    const ctx = document.getElementById('cb-widget-trends-chart').getContext('2d');
    const labels = data.map(item => item.day);
    const counts = data.map(item => item.count);

    if (trendsChart) trendsChart.destroy();

    trendsChart = new Chart(ctx, {
      type: 'line',
      data: {
        labels: labels,
        datasets: [{
          label: `Bookings (${months} month${months > 1 ? 's' : ''})`,
          data: counts,
          borderColor: '#2271b1',
          backgroundColor: 'rgba(34,113,177,0.2)',
          fill: true,
          tension: 0.3
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: {
          x: { title: { display: true, text: 'Date' } },
          y: { title: { display: true, text: 'Bookings' }, beginAtZero: true }
        }
      }
    });
  });
}

document.addEventListener('DOMContentLoaded', () => {
  renderTrendsWidget(1);

  document.getElementById('cb-trends-1m').addEventListener('click', () => renderTrendsWidget(1));
  document.getElementById('cb-trends-3m').addEventListener('click', () => renderTrendsWidget(3));
  document.getElementById('cb-trends-6m').addEventListener('click', () => renderTrendsWidget(6));
  document.getElementById('cb-trends-12m').addEventListener('click', () => renderTrendsWidget(12));
});

```
