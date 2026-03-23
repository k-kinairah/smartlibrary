(function () {
    function parseData() {
        const node = document.getElementById('dashboard-data');
        if (!node) return null;
        try {
            return JSON.parse(node.textContent || '{}');
        } catch (_) {
            return null;
        }
    }

    function safeArray(v) {
        return Array.isArray(v) ? v : [];
    }

    function esc(v) {
        const div = document.createElement('div');
        div.textContent = v == null ? '' : String(v);
        return div.innerHTML;
    }

    function empty(el, text) {
        if (!el) return;
        el.innerHTML = `<div class="chart-empty">${esc(text)}</div>`;
    }

    function renderMostBorrowed(el, labels, values) {
        if (!el) return;
        if (!labels.length) {
            empty(el, 'No books yet.');
            return;
        }

        const n = labels.length;
        const width = Math.max(760, 140 + n * 86);
        const height = 330;
        const m = { l: 58, r: 16, t: 16, b: 108 };
        const w = width - m.l - m.r;
        const h = height - m.t - m.b;

        const nums = values.map(v => Number(v || 0));
        const max = Math.max(1, ...nums);
        const step = w / n;
        const barW = Math.min(52, step * 0.58);

        let grid = '';
        for (let i = 0; i <= 4; i++) {
            const y = m.t + (h / 4) * i;
            const val = Math.round(max - (max / 4) * i);
            grid += `<line x1="${m.l}" y1="${y}" x2="${width - m.r}" y2="${y}" stroke="rgba(232,245,237,0.26)" stroke-width="1"/>`;
            grid += `<text x="${m.l - 10}" y="${y + 4}" text-anchor="end" font-size="11" fill="#d0e2d7">${val}</text>`;
        }

        let bars = '';
        for (let i = 0; i < n; i++) {
            const v = nums[i];
            const ratio = v > 0 ? v / max : 0;
            const bh = Math.max(10, Math.round(ratio * h));
            const x = m.l + i * step + (step - barW) / 2;
            const y = m.t + h - bh;
            const label = labels[i];

            bars += `<rect x="${x}" y="${y}" width="${barW}" height="${bh}" rx="7" fill="#f2c94c"/>`;
            bars += `<text x="${x + barW / 2}" y="${y - 6}" text-anchor="middle" font-size="11" fill="#ecf7ef" font-weight="700">${v}</text>`;
            bars += `<g transform="translate(${x + barW / 2},${height - m.b + 16}) rotate(-30)">
                <text text-anchor="end" font-size="11" fill="#cfe1d6">${esc(label)}</text>
            </g>`;
        }

        const svg = `
            <svg viewBox="0 0 ${width} ${height}" style="width:100%;height:300px;display:block;">
                ${grid}
                <line x1="${m.l}" y1="${m.t + h}" x2="${width - m.r}" y2="${m.t + h}" stroke="rgba(232,245,237,0.5)" stroke-width="1.2"/>
                ${bars}
            </svg>
        `;

        el.innerHTML = `<div style="overflow-x:auto;overflow-y:hidden;">${svg}</div>`;
    }

    function renderTrend(el, labels, values) {
        if (!el) return;
        if (!labels.length) {
            empty(el, 'No trend data yet.');
            return;
        }

        const n = labels.length;
        const width = Math.max(680, 120 + n * 104);
        const height = 310;
        const m = { l: 54, r: 18, t: 16, b: 64 };
        const w = width - m.l - m.r;
        const h = height - m.t - m.b;
        const nums = values.map(v => Number(v || 0));
        const max = Math.max(1, ...nums);

        const xAt = (i) => (n === 1 ? m.l + w / 2 : m.l + (i * w) / (n - 1));
        const yAt = (v) => m.t + h - (v / max) * h;

        let grid = '';
        for (let i = 0; i <= 4; i++) {
            const y = m.t + (h / 4) * i;
            const val = Math.round(max - (max / 4) * i);
            grid += `<line x1="${m.l}" y1="${y}" x2="${width - m.r}" y2="${y}" stroke="rgba(232,245,237,0.24)" stroke-width="1"/>`;
            grid += `<text x="${m.l - 10}" y="${y + 4}" text-anchor="end" font-size="11" fill="#d0e2d7">${val}</text>`;
        }

        const points = nums.map((v, i) => ({ x: xAt(i), y: yAt(v), v, l: labels[i] }));
        const line = points.map(p => `${p.x},${p.y}`).join(' ');
        const area = `${m.l},${m.t + h} ${line} ${points[points.length - 1].x},${m.t + h}`;

        let xLabels = '';
        let dots = '';
        points.forEach((p) => {
            xLabels += `<text x="${p.x}" y="${height - 20}" text-anchor="middle" font-size="12" fill="#cfe1d6">${esc(p.l)}</text>`;
            dots += `<circle cx="${p.x}" cy="${p.y}" r="5" fill="#f2c94c" stroke="#0d3a27" stroke-width="1.6"/>
                     <text x="${p.x}" y="${p.y - 10}" text-anchor="middle" font-size="11" fill="#ecf7ef" font-weight="700">${p.v}</text>`;
        });

        const svg = `
            <svg viewBox="0 0 ${width} ${height}" style="width:100%;height:290px;display:block;">
                ${grid}
                <polygon points="${area}" fill="rgba(242,201,76,0.18)"/>
                <polyline points="${line}" fill="none" stroke="#f2c94c" stroke-width="3.2" stroke-linecap="round" stroke-linejoin="round"/>
                ${dots}
                ${xLabels}
            </svg>
        `;

        el.innerHTML = `<div style="overflow-x:auto;overflow-y:hidden;">${svg}</div>`;
    }

    function renderCategory(donutEl, legendEl, labels, values) {
        if (!donutEl || !legendEl) return;
        if (!labels.length) {
            empty(donutEl, 'No categories yet.');
            legendEl.innerHTML = '';
            return;
        }

        const palette = ['#f2c94c', '#0f6a34', '#4db6ac', '#5c8dff', '#ff9f68', '#b39ddb', '#4dd0e1', '#ffd166', '#81c784', '#90a4ae'];
        const nums = values.map(v => Number(v || 0));
        const total = nums.reduce((s, x) => s + x, 0) || 1;

        let acc = 0;
        const stops = nums.map((v, i) => {
            const start = (acc / total) * 100;
            acc += v;
            const end = (acc / total) * 100;
            const c = palette[i % palette.length];
            return `${c} ${start.toFixed(2)}% ${end.toFixed(2)}%`;
        }).join(', ');

        donutEl.innerHTML = `
            <div class="donut-ring" style="background:conic-gradient(${stops});width:240px;height:240px;border-radius:50%;display:grid;place-items:center;">
                <div style="width:126px;height:126px;border-radius:50%;display:grid;place-items:center;background:rgba(10,34,25,.86);border:1px solid rgba(225,240,232,.26);text-align:center;">
                    <div style="font-size:34px;font-weight:800;line-height:1;color:#f4fff8;">${total}</div>
                    <div style="font-size:12px;color:#c5d8cd;">Books</div>
                </div>
            </div>
        `;

        legendEl.innerHTML = labels.map((label, i) => {
            const v = nums[i];
            const pct = Math.round((v / total) * 100);
            const c = palette[i % palette.length];
            return `
                <div class="legend-item" style="display:grid;grid-template-columns:14px minmax(80px,1fr) auto;gap:10px;align-items:center;padding:7px 10px;border-radius:10px;background:rgba(235,247,239,.08);border:1px solid rgba(219,237,227,.18);">
                    <span style="width:10px;height:10px;border-radius:50%;background:${c};display:inline-block;"></span>
                    <span style="color:#e6f3ea;font-size:13px;font-weight:600;">${esc(label)}</span>
                    <span style="color:#bfd4c6;font-size:12px;">${v} (${pct}%)</span>
                </div>
            `;
        }).join('');
    }

    function run() {
        const data = parseData();
        if (!data) return;

        renderMostBorrowed(
            document.getElementById('most-borrowed-chart'),
            safeArray(data.mostBorrowed?.labels),
            safeArray(data.mostBorrowed?.values)
        );

        renderTrend(
            document.getElementById('monthly-trend-chart'),
            safeArray(data.monthlyTrend?.labels),
            safeArray(data.monthlyTrend?.values)
        );

        renderCategory(
            document.getElementById('category-donut'),
            document.getElementById('category-legend'),
            safeArray(data.categoryDistribution?.labels),
            safeArray(data.categoryDistribution?.values)
        );
    }

    document.addEventListener('DOMContentLoaded', run);
})();
