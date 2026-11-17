// Glucose trend chart with ADA target bands.
// rows: [{label, value, band}], t: {fastingMin, fastingMax, post, hypo, severe}
(function () {
    const CHART_COLORS = {
        line: 'hsl(214 90% 46%)',
        fill: 'rgba(26, 102, 214, 0.10)',
        target: 'rgba(16, 154, 106, 0.10)',
        hypo: 'hsl(32 92% 44%)',
        severe: 'hsl(356 72% 48%)',
        grid: 'rgba(148, 163, 184, 0.28)',
        text: '#475569',
    };
    const POINT_COLORS = {
        severe_low: 'hsl(356 72% 48%)',
        low: 'hsl(32 92% 44%)',
        in_range: 'hsl(214 90% 46%)',
        high: 'hsl(32 92% 44%)',
        very_high: 'hsl(356 72% 48%)',
    };

    function targetBands(t) {
        return {
            id: 'targetBands',
            beforeDatasetsDraw(chart) {
                const { ctx, chartArea, scales } = chart;
                if (!chartArea) return;
                const y = scales.y;
                const drawBand = (from, to, color, label) => {
                    const top = y.getPixelForValue(Math.min(to, y.max));
                    const bottom = y.getPixelForValue(Math.max(from, y.min));
                    if (bottom <= top) return;
                    ctx.save();
                    ctx.fillStyle = color;
                    ctx.fillRect(chartArea.left, top, chartArea.right - chartArea.left, bottom - top);
                    ctx.restore();
                    if (label) {
                        ctx.save();
                        ctx.fillStyle = 'rgba(6, 95, 70, 0.65)';
                        ctx.font = '500 10px Inter, system-ui, sans-serif';
                        ctx.textAlign = 'right';
                        ctx.fillText(label, chartArea.right - 8, top + 12);
                        ctx.restore();
                    }
                };
                drawBand(t.fastingMin, t.fastingMax, CHART_COLORS.target, 'ADA pre-meal target');
                drawBand(0, t.hypo, 'rgba(220, 38, 38, 0.05)', '');
            },
        };
    }

    window.glucoseChart = function (canvasId, rows, t) {
        const canvas = document.getElementById(canvasId);
        if (!canvas || typeof Chart === 'undefined') return;

        const unit = t.unit || 'mg/dL';
        const isMmol = unit === 'mmol/L';

        new Chart(canvas, {
            type: 'line',
            data: {
                labels: rows.map((r) => r.label),
                datasets: [
                    {
                        label: 'Glucose (' + unit + ')',
                        data: rows.map((r) => r.value),
                        borderColor: CHART_COLORS.line,
                        backgroundColor: CHART_COLORS.fill,
                        borderWidth: 2,
                        fill: true,
                        tension: 0.3,
                        pointBackgroundColor: rows.map((r) => POINT_COLORS[r.band] || CHART_COLORS.line),
                        pointBorderColor: '#fff',
                        pointBorderWidth: 2,
                        pointRadius: rows.length > 40 ? 2.5 : 4,
                        pointHoverRadius: 7,
                    },
                    {
                        label: 'Post-meal limit (' + t.post + ')',
                        data: rows.map(() => t.post),
                        borderColor: CHART_COLORS.hypo,
                        borderDash: [6, 5],
                        borderWidth: 1.5,
                        pointRadius: 0,
                        fill: false,
                    },
                    {
                        label: 'Hypoglycaemia (' + t.hypo + ')',
                        data: rows.map(() => t.hypo),
                        borderColor: CHART_COLORS.severe,
                        borderDash: [4, 4],
                        borderWidth: 1.5,
                        pointRadius: 0,
                        fill: false,
                    },
                    {
                        label: 'Severe low (' + t.severe + ')',
                        data: rows.map(() => t.severe),
                        borderColor: CHART_COLORS.severe,
                        borderWidth: 1,
                        borderDash: [1, 5],
                        pointRadius: 0,
                        fill: false,
                    },
                ],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                layout: { padding: { top: 4 } },
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            usePointStyle: true,
                            boxWidth: 7,
                            boxHeight: 7,
                            padding: 14,
                            color: CHART_COLORS.text,
                            font: { size: 11, family: 'Inter, system-ui, sans-serif' },
                            filter: (item) => !String(item.text).startsWith('Severe'),
                        },
                    },
                    tooltip: {
                        backgroundColor: '#0f172a',
                        padding: 10,
                        cornerRadius: 8,
                        titleFont: { size: 11, family: 'Inter, system-ui, sans-serif' },
                        bodyFont: { size: 12, family: 'Inter, system-ui, sans-serif' },
                        callbacks: {
                            label: (ctx) => ctx.datasetIndex === 0
                                ? '  ' + ctx.parsed.y + ' ' + unit
                                : '  ' + ctx.dataset.label,
                        },
                    },
                },
                scales: {
                    y: {
                        suggestedMin: isMmol ? 2 : 40,
                        suggestedMax: isMmol ? Math.max(12, t.post + 3) : Math.max(200, t.post + 40),
                        grid: { color: CHART_COLORS.grid, drawBorder: false },
                        ticks: {
                            color: CHART_COLORS.text,
                            font: { size: 11, family: 'Inter, system-ui, sans-serif' },
                            callback: (v) => v + '',
                        },
                        title: {
                            display: true,
                            text: unit,
                            color: CHART_COLORS.text,
                            font: { size: 11, weight: '500', family: 'Inter, system-ui, sans-serif' },
                        },
                    },
                    x: {
                        grid: { display: false },
                        ticks: {
                            color: CHART_COLORS.text,
                            maxTicksLimit: 8,
                            maxRotation: 0,
                            autoSkip: true,
                            font: { size: 11, family: 'Inter, system-ui, sans-serif' },
                        },
                    },
                },
            },
            plugins: [targetBands(t)],
        });
    };
})();
