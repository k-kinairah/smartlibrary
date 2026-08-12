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

    function prettifyKeywordLabel(label) {
        const raw = String(label || '').trim().toLowerCase();
        if (!raw) return '';

        const replacements = {
            ai: 'AI',
            it: 'IT',
            cs: 'CS'
        };

        return raw.split(' ').filter(Boolean).map((word) => {
            if (replacements[word]) return replacements[word];
            return word.charAt(0).toUpperCase() + word.slice(1);
        }).join(' ');
    }

    function compactLabel(label, maxLen = 56) {
        const clean = String(label || '').trim();
        if (clean.length <= maxLen) return clean;
        return `${clean.slice(0, Math.max(12, maxLen - 1)).trimEnd()}…`;
    }

    function setChipText(id, text) {
        const node = document.getElementById(id);
        if (!node) return;
        node.textContent = String(text || '').trim();
    }

    function empty(el, text) {
        if (!el) return;
        el.innerHTML = `<div class="chart-empty">${esc(text)}</div>`;
    }

    function topRows(labels, values, limit) {
        const rows = safeArray(labels).map((label, i) => ({
            label: String(label || '').trim(),
            value: Number(safeArray(values)[i] || 0)
        })).filter(row => row.label !== '' && row.value >= 0);

        rows.sort((a, b) => b.value - a.value || a.label.localeCompare(b.label));
        return rows.slice(0, Math.max(1, Number(limit || rows.length)));
    }

    function renderHorizontalBars(el, labels, values, options) {
        if (!el) return;

        const cfg = options || {};
        const rows = topRows(labels, values, cfg.limit || 10);
        if (!rows.length) {
            empty(el, cfg.emptyText || 'No data yet.');
            return;
        }

        const max = Math.max(1, ...rows.map(row => row.value));
        const variant = cfg.variant || 'default';
        const listClass = variant === 'search'
            ? 'hbar-list search-intel-list'
            : (variant === 'borrow' ? 'hbar-list borrow-intel-list' : 'hbar-list');

        el.innerHTML = `
            <div class="${listClass}">
                ${rows.map((row, idx) => {
                    const width = Math.max(4, Math.round((row.value / max) * 100));
                    return `
                        <div class="hbar-item">
                            <div class="hbar-head">
                                <span class="hbar-label">${cfg.showRank ? `${idx + 1}. ` : ''}${esc(variant === 'search' ? prettifyKeywordLabel(row.label) : row.label)}</span>
                                <span class="hbar-value">${row.value}</span>
                            </div>
                            <div class="hbar-track ${variant}">
                                <span class="hbar-fill ${variant}" style="width:${width}%;"></span>
                            </div>
                        </div>
                    `;
                }).join('')}
            </div>
        `;
    }

    function renderSearchKeywordInsight(el, labels, values) {
        if (!el) return;

        const rows = topRows(labels, values, 10);
        if (!rows.length) {
            setChipText('search-keywords-chip', 'No search data');
            el.textContent = 'No keyword intelligence available yet.';
            return;
        }

        const total = rows.reduce((sum, row) => sum + Number(row.value || 0), 0);
        const top = rows[0];
        const runnerUp = rows[1] || null;

        const topShare = total > 0 ? Math.round((Number(top.value || 0) / total) * 100) : 0;
        const coverage = rows.length;
        const avgHits = coverage > 0 ? (total / coverage).toFixed(1) : '0.0';
        const focusLevel = topShare >= 40 ? 'focused' : (topShare >= 25 ? 'balanced' : 'broad');

        setChipText('search-keywords-chip', `${total} total searches`);

        let leaderLine = 'Only one clear keyword topic is visible right now.';
        if (runnerUp) {
            const leadGap = Math.max(0, Number(top.value || 0) - Number(runnerUp.value || 0));
            leaderLine = `<strong>${esc(prettifyKeywordLabel(top.label))}</strong> is ahead of <strong>${esc(prettifyKeywordLabel(runnerUp.label))}</strong> by <strong>${leadGap}</strong> searches.`;
        }

        el.innerHTML = `Keyword intelligence grouped related searches into <strong>${coverage}</strong> topics (${total} total hits, avg ${avgHits}). ${leaderLine} Current demand profile is <strong>${focusLevel}</strong>.`;
    }

    function renderMostBorrowedInsight(el, labels, values) {
        if (!el) return;

        const rows = topRows(labels, values, 10);
        if (!rows.length) {
            setChipText('borrowed-books-chip', 'No borrow data');
            el.textContent = 'No borrowing intelligence available yet.';
            return;
        }

        const total = rows.reduce((sum, row) => sum + Number(row.value || 0), 0);
        const top = rows[0];
        const runnerUp = rows[1] || null;
        const topShare = total > 0 ? Math.round((Number(top.value || 0) / total) * 100) : 0;
        const avgBorrows = rows.length > 0 ? (total / rows.length).toFixed(1) : '0.0';

        setChipText('borrowed-books-chip', `${total} total borrows`);

        let leadLine = 'Only one borrowed title appears in the current period.';
        if (runnerUp) {
            const gap = Math.max(0, Number(top.value || 0) - Number(runnerUp.value || 0));
            leadLine = `<strong>${esc(compactLabel(top.label, 52))}</strong> leads <strong>${esc(compactLabel(runnerUp.label, 42))}</strong> by <strong>${gap}</strong> borrows.`;
        }

        const pattern = topShare >= 40 ? 'highly concentrated' : (topShare >= 25 ? 'moderately concentrated' : 'distributed');
        el.innerHTML = `Borrowing intelligence tracked <strong>${total}</strong> checkouts across top titles (avg ${avgBorrows} per title). ${leadLine} Circulation pattern is <strong>${pattern}</strong>.`;
    }

    function renderTrendInsight(el, labels, values, opts) {
        if (!el) return;

        labels = safeArray(labels);
        values = safeArray(values).map(v => Number(v || 0));

        if (!labels.length || !values.length) {
            el.textContent = (opts && opts.emptyText) || 'No trend data yet.';
            return;
        }

        const n = values.length;
        const sum = values.reduce((a, b) => a + b, 0);
        const avg = n > 0 ? sum / n : 0;

        let peakIndex = 0;
        for (let i = 1; i < n; i++) {
            if (values[i] > values[peakIndex]) peakIndex = i;
        }

        const peak = values[peakIndex] || 0;
        const peakDay = labels[peakIndex] || 'N/A';
        const nonZeroDays = values.filter(v => v > 0).length;

        if (peak <= 0) {
            el.textContent = `Searches stayed flat over the last ${n} days with no visible spikes.`;
            return;
        }

        const avgText = avg > 0 ? avg.toFixed(1) : '0.0';
        const multiplier = avg > 0 ? (peak / avg).toFixed(1) : '0.0';

        let sentence1 = `Peak search activity was on ${peakDay} with ${peak} searches (${multiplier}x the ${n}-day average of ${avgText}).`;

        let sentence2 = '';
        if (nonZeroDays <= Math.max(2, Math.round(n * 0.25))) {
            sentence2 = `This spike likely reflects concentrated demand on a few days rather than steady daily searching.`;
        } else {
            const latest = values[n - 1] || 0;
            const prev = n > 1 ? (values[n - 2] || 0) : latest;
            const direction = latest > prev ? 'upward' : (latest < prev ? 'downward' : 'stable');
            sentence2 = `Recent activity is ${direction}, suggesting search demand is continuing beyond the spike.`;
        }

        el.textContent = `${sentence1} ${sentence2}`;
    }
    function renderLineChart(el, labels, values, options) {
        if (!el) return;

        const cfg = options || {};
        labels = safeArray(labels);
        values = safeArray(values).map(v => Number(v || 0));
        const isLightTheme = document.documentElement.getAttribute('data-theme') === 'light';
        const palette = isLightTheme ? {
            gridStroke: 'rgba(121,153,136,0.34)',
            axisLabel: '#5d7f6f',
            areaFill: 'rgba(218,181,62,0.26)',
            lineStroke: '#d9ad2f',
            dotFill: '#f2c94c',
            dotStroke: '#266447'
        } : {
            gridStroke: 'rgba(232,245,237,0.38)',
            axisLabel: '#e5f3eb',
            areaFill: 'rgba(242,201,76,0.26)',
            lineStroke: '#ffd45a',
            dotFill: '#ffd34f',
            dotStroke: '#1f5139'
        };

        if (!labels.length || !values.length) {
            empty(el, cfg.emptyText || 'No trend data yet.');
            return;
        }

        const n = labels.length;
        const width = Math.max(680, 120 + n * 92);
        const height = 310;
        const m = { l: 54, r: 18, t: 18, b: 66 };
        const w = width - m.l - m.r;
        const h = height - m.t - m.b;
        const max = Math.max(1, ...values);

        const xAt = (i) => (n <= 1 ? m.l + w / 2 : m.l + (i * w) / (n - 1));
        const yAt = (v) => m.t + h - (v / max) * h;

        let grid = '';
        for (let i = 0; i <= 4; i++) {
            const y = m.t + (h / 4) * i;
            const val = Math.round(max - (max / 4) * i);
            grid += `<line x1="${m.l}" y1="${y}" x2="${width - m.r}" y2="${y}" stroke="${palette.gridStroke}" stroke-width="1"/>`;
            grid += `<text x="${m.l - 10}" y="${y + 4}" text-anchor="end" font-size="11" fill="${palette.axisLabel}">${val}</text>`;
        }

        const points = values.map((v, i) => ({ x: xAt(i), y: yAt(v), v, l: labels[i] }));
        const line = points.map(p => `${p.x},${p.y}`).join(' ');
        const area = `${m.l},${m.t + h} ${line} ${points[points.length - 1].x},${m.t + h}`;

        let xLabels = '';
        let dots = '';
        const skip = n > 9 ? Math.ceil(n / 8) : 1;

        points.forEach((p, i) => {
            if (i % skip === 0 || i === n - 1) {
                xLabels += `<text x="${p.x}" y="${height - 20}" text-anchor="middle" font-size="12" fill="${palette.axisLabel}">${esc(p.l)}</text>`;
            }
            dots += `<circle cx="${p.x}" cy="${p.y}" r="5.2" fill="${palette.dotFill}" stroke="${palette.dotStroke}" stroke-width="1.8"/>`;
        });

        const svg = `
            <svg class="trend-svg" viewBox="0 0 ${width} ${height}" style="width:100%;height:290px;display:block;">
                ${grid}
                <polygon points="${area}" fill="${palette.areaFill}"/>
                <polyline points="${line}" fill="none" stroke="${palette.lineStroke}" stroke-width="3.8" stroke-linecap="round" stroke-linejoin="round"/>
                ${dots}
                ${xLabels}
            </svg>
        `;

        el.innerHTML = `<div class="trend-scroll">${svg}</div>`;
    }

    function renderStackedBars(el, labels, firstValues, secondValues, options) {
        if (!el) return;

        const cfg = options || {};
        const labelRows = safeArray(labels);
        const aRows = safeArray(firstValues);
        const bRows = safeArray(secondValues);

        const rows = labelRows.map((label, i) => {
            const a = Number(aRows[i] || 0);
            const b = Number(bRows[i] || 0);
            return {
                label: String(label || '').trim(),
                a,
                b,
                total: a + b
            };
        }).filter(row => row.label !== '' && row.total >= 0);

        if (!rows.length) {
            empty(el, cfg.emptyText || 'No course data yet.');
            return;
        }

        const sorted = rows.sort((x, y) => y.total - x.total || y.a - x.a).slice(0, cfg.limit || 10);

        el.innerHTML = `
            <div class="stacked-legend">
                <span class="stacked-legend-item"><span class="stacked-dot borrowed"></span>${esc(cfg.firstLabel || 'Borrowed Titles')}</span>
                <span class="stacked-legend-item"><span class="stacked-dot remaining"></span>${esc(cfg.secondLabel || 'Remaining Titles')}</span>
            </div>
            <div class="stacked-list">
                ${sorted.map(row => {
                    const safeTotal = Math.max(1, row.total);
                    const aPct = Math.round((row.a / safeTotal) * 100);
                    const bPct = Math.max(0, 100 - aPct);
                    return `
                        <div class="stacked-item">
                            <div class="stacked-head">
                                <span class="stacked-label">${esc(row.label)}</span>
                                <span class="stacked-value">${row.total} titles</span>
                            </div>
                            <div class="stacked-track">
                                <span class="stacked-segment borrowed" style="width:${aPct}%;"></span>
                                <span class="stacked-segment remaining" style="width:${bPct}%;"></span>
                            </div>
                        </div>
                    `;
                }).join('')}
            </div>
        `;
    }


    function markReminderSent(btn, message) {
        btn.disabled = true;
        btn.textContent = 'Sent';
        btn.classList.remove('is-error');
        btn.classList.add('is-sent');
        if (message) {
            btn.title = message;
        }
    }

    function bindOverdueReminderButtons() {
        const buttons = document.querySelectorAll('.overdue-reminder-btn');
        if (!buttons.length) return;

        buttons.forEach((btn) => {
            if (btn.dataset.reminderBound === '1') return;
            btn.dataset.reminderBound = '1';

            btn.addEventListener('click', async () => {
                const recordId = Number(btn.dataset.recordId || 0);
                if (!recordId) {
                    btn.title = 'Missing overdue record ID.';
                    return;
                }

                const originalText = btn.textContent.trim() || 'Send Reminder';
                btn.disabled = true;
                btn.classList.remove('is-error');
                btn.textContent = 'Sending...';

                try {
                    const res = await fetch('../send_overdue_reminder_email.php', {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: {
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify({ record_id: recordId })
                    });

                    let payload = {};
                    try {
                        payload = await res.json();
                    } catch (_) {
                        payload = {};
                    }

                    if (payload.status === 'success' || payload.status === 'cooldown') {
                        markReminderSent(btn, payload.message || 'Reminder sent.');
                        return;
                    }

                    throw new Error(payload.message || 'Unable to send reminder right now.');
                } catch (err) {
                    const msg = (err && err.message) ? err.message : 'Failed to send reminder.';
                    btn.disabled = false;
                    btn.textContent = 'Retry';
                    btn.classList.add('is-error');
                    btn.title = msg;

                    window.setTimeout(() => {
                        if (!btn.classList.contains('is-sent')) {
                            btn.textContent = originalText;
                            btn.classList.remove('is-error');
                        }
                    }, 2400);
                }
            });
        });
    }

    function run() {
        bindOverdueReminderButtons();
        const data = parseData();
        if (!data) return;

        renderHorizontalBars(
            document.getElementById('search-keywords-chart'),
            safeArray(data.searchKeywords?.labels),
            safeArray(data.searchKeywords?.values),
            {
                limit: 10,
                showRank: true,
                variant: 'search',
                emptyText: 'No meaningful search keywords yet.'
            }
        );

        
        renderSearchKeywordInsight(
            document.getElementById('search-keywords-insight'),
            safeArray(data.searchKeywords?.labels),
            safeArray(data.searchKeywords?.values)
        );
        renderLineChart(
            document.getElementById('search-trend-chart'),
            safeArray(data.searchTrend?.labels),
            safeArray(data.searchTrend?.values),
            { emptyText: 'No search trend data yet.' }
        );

        renderTrendInsight(
            document.getElementById('search-trend-insight'),
            safeArray(data.searchTrend?.labels),
            safeArray(data.searchTrend?.values),
            {
                emptyText: 'No search trend data yet.',
                metricPlural: 'searches',
                subjectNoun: 'search activity',
                continuationNoun: 'search demand'
            }
        );

        renderHorizontalBars(
            document.getElementById('most-borrowed-top-chart'),
            safeArray(data.mostBorrowedTop?.labels),
            safeArray(data.mostBorrowedTop?.values),
            {
                limit: 10,
                showRank: true,
                variant: 'borrow',
                emptyText: 'No borrowed-book data yet.'
            }
        );

        renderMostBorrowedInsight(
            document.getElementById('most-borrowed-insight'),
            safeArray(data.mostBorrowedTop?.labels),
            safeArray(data.mostBorrowedTop?.values)
        );
        renderLineChart(
            document.getElementById('borrow-trend-chart'),
            safeArray(data.mostBorrowedTrend?.labels),
            safeArray(data.mostBorrowedTrend?.values),
            { emptyText: 'No borrow trend data yet.' }
        );

        renderTrendInsight(
            document.getElementById('borrow-trend-insight'),
            safeArray(data.mostBorrowedTrend?.labels),
            safeArray(data.mostBorrowedTrend?.values),
            {
                emptyText: 'No borrow trend data yet.',
                metricPlural: 'borrow transactions',
                subjectNoun: 'borrowing activity',
                continuationNoun: 'borrowing demand'
            }
        );

        renderStackedBars(
            document.getElementById('course-popularity-stacked-chart'),
            safeArray(data.coursePopularity?.labels),
            safeArray(data.coursePopularity?.borrowedTitles),
            safeArray(data.coursePopularity?.remainingTitles),
            {
                firstLabel: 'Borrowed Titles',
                secondLabel: 'Remaining Titles',
                limit: 10,
                emptyText: 'No course popularity data yet.'
            }
        );

        renderHorizontalBars(
            document.getElementById('course-activity-chart'),
            safeArray(data.courseActivity?.labels),
            safeArray(data.courseActivity?.values),
            {
                limit: 10,
                showRank: false,
                variant: 'course',
                emptyText: 'No course borrowing activity yet.'
            }
        );
    }

    document.addEventListener('DOMContentLoaded', run);
    document.addEventListener('admin-theme-changed', run);
})();












