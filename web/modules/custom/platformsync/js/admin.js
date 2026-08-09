/**
 * @file
 * Admin dashboard sparkline chart using Chart.js.
 */
(function () {
  'use strict';

  document.addEventListener('DOMContentLoaded', () => {
    const canvas = document.getElementById('platformsync-daily-chart');
    if (!canvas) return;

    let rawData = {};
    try { rawData = JSON.parse(canvas.dataset.values || '{}'); } catch (e) { return; }

    const labels = Object.keys(rawData);
    const values = Object.values(rawData).map(Number);

    if (typeof Chart === 'undefined') return;

    new Chart(canvas, {
      type: 'bar',
      data: {
        labels,
        datasets: [{
          label: 'Requests',
          data: values,
          backgroundColor: 'rgba(55, 138, 221, 0.6)',
          borderColor: 'rgba(55, 138, 221, 1)',
          borderWidth: 1,
          borderRadius: 3,
        }],
      },
      options: {
        responsive: true,
        plugins: { legend: { display: false } },
        scales: {
          y: { beginAtZero: true, ticks: { precision: 0 } },
          x: { ticks: { maxTicksLimit: 10 } },
        },
      },
    });
  });
}());
