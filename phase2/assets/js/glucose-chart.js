// Glucose trend chart. rows: [{label, value, band}], t: {hypo, post}
function glucoseChart(canvasId, rows, t) {
    const colors = { severe_low: '#d9435b', low: '#e8a317', in_range: '#1a66d6', high: '#e8a317', very_high: '#d9435b' };
    new Chart(document.getElementById(canvasId), {
        type: 'line',
        data: {
            labels: rows.map(r => r.label),
            datasets: [
                {
                    label: 'Glucose (mg/dL)', data: rows.map(r => r.value),
                    borderColor: '#3b82f0', backgroundColor: 'rgba(59,130,240,.12)', fill: true, tension: 0.25,
                    pointBackgroundColor: rows.map(r => colors[r.band] || '#1a66d6'), pointBorderColor: '#fff',
                    pointRadius: 5, pointHoverRadius: 7
                },
                { label: 'Low limit', data: rows.map(() => t.hypo), borderColor: '#e8a317', borderDash: [6, 5], pointRadius: 0, borderWidth: 1.5 },
                { label: 'Post-meal max', data: rows.map(() => t.post), borderColor: '#d9435b', borderDash: [6, 5], pointRadius: 0, borderWidth: 1.5 }
            ]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            plugins: { legend: { position: 'bottom', labels: { usePointStyle: true, boxWidth: 8 } } },
            scales: { y: { suggestedMin: 40, title: { display: true, text: 'mg/dL' } }, x: { ticks: { maxTicksLimit: 8 } } }
        }
    });
}
