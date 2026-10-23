(function ($) {
    'use strict';

    var $root = $('#dashboard-root');
    if (!$root.length || typeof Chart === 'undefined') {
        return;
    }

    var lineChart = null;
    var doughnutChart = null;
    var selectedDays = 90;
    var selectedInstrumentId = null;
    var chartLoading = false;

    function animateCounter($el, target) {
        var n = parseInt(target, 10);
        if (isNaN(n)) {
            $el.text('—');
            return;
        }
        $({ val: 0 }).animate({ val: n }, {
            duration: 700,
            easing: 'swing',
            step: function () {
                $el.text(Math.floor(this.val).toLocaleString());
            },
            complete: function () {
                $el.text(n.toLocaleString());
            }
        });
    }

    function setKpis(kpis) {
        Object.keys(kpis).forEach(function (key) {
            var $c = $('.dash-counter[data-kpi="' + key + '"]');
            if ($c.length) {
                $c.empty();
                animateCounter($c, kpis[key]);
            }
        });
        [
            ['stocks_total', 'stocks_with_prices'],
            ['etfs_total', 'etfs_with_prices'],
            ['mf_total', 'mf_with_nav']
        ].forEach(function (pair) {
            var total = kpis[pair[0]] || 0;
            var withVal = kpis[pair[1]] || 0;
            var pct = total > 0 ? Math.min(100, Math.round((withVal / total) * 100)) : 0;
            $('.dash-kpi-sub[data-kpi-sub="' + pair[1] + '"]').text(pct + '%');
            $('.dash-progress-fill[data-kpi-bar="' + pair[1] + '"]').css('width', pct + '%');
        });
    }

    function setLastSync(lastSync) {
        var $label = $('#dash-last-sync-label');
        var $time = $('#dash-last-sync-time');
        if (!lastSync || !lastSync.finished_at) {
            $label.text('No completed sync yet');
            $time.text('Use Data Pipeline to import and refresh datasets.');
            return;
        }
        $label.text(lastSync.label || lastSync.job_type);
        $time.text(lastSync.finished_at);
    }

    function renderDoughnut(dist, total) {
        var ctx = document.getElementById('dashboard-doughnut');
        if (!ctx) {
            return;
        }
        if (doughnutChart) {
            doughnutChart.destroy();
        }
        if (total <= 0) {
            $('#dash-distribution-legend').html('<li class="text-slate-500 text-xs">No instruments stored yet.</li>');
            return;
        }
        var labels = ['Stocks', 'ETFs', 'Mutual funds'];
        var data = [dist.stocks, dist.etfs, dist.mutual_funds];
        var colors = ['#22d3ee', '#a78bfa', '#fbbf24'];
        doughnutChart = new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: labels,
                datasets: [{
                    data: data,
                    backgroundColor: colors,
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '62%',
                plugins: { legend: { display: false } },
                animation: { duration: 600 }
            }
        });
        var html = '';
        labels.forEach(function (lab, i) {
            var pct = total > 0 ? ((data[i] / total) * 100).toFixed(1) : '0';
            html += '<li class="flex justify-between text-slate-300"><span><span class="inline-block w-2 h-2 rounded-full mr-2" style="background:' + colors[i] + '"></span>' + lab + '</span><span class="font-mono text-slate-400">' + data[i].toLocaleString() + ' (' + pct + '%)</span></li>';
        });
        $('#dash-distribution-legend').html(html);
    }

    function renderPredictions(rows) {
        var $body = $('#dash-predictions-body');
        if (!rows || !rows.length) {
            $body.html('<tr><td colspan="3" class="py-4 text-slate-500 text-xs">No prediction results yet. Run the screener from Predictions.</td></tr>');
            return;
        }
        var html = '';
        rows.forEach(function (r) {
            var name = r.instrument_name || r.scheme_name || r.symbol || '—';
            var outcome = r.outcome_status || 'Pending';
            html += '<tr><td class="py-2 pr-2"><a class="text-cyan-300 hover:underline" href="predictions.php?run_id=' + r.run_id + '">' + escapeHtml(name) + '</a></td>';
            html += '<td class="py-2 text-right font-mono">' + (r.technical_score != null ? Number(r.technical_score).toFixed(1) : '—') + '</td>';
            html += '<td class="py-2 text-xs text-slate-400">' + escapeHtml(outcome) + '</td></tr>';
        });
        $body.html(html);
    }

    function renderSync(rows) {
        var $body = $('#dash-sync-body');
        if (!rows || !rows.length) {
            $body.html('<tr><td colspan="3" class="py-4 text-slate-500 text-xs">No sync jobs recorded.</td></tr>');
            return;
        }
        var html = '';
        rows.forEach(function (r) {
            html += '<tr><td class="py-2 pr-2 text-slate-300">' + escapeHtml(r.label) + '</td>';
            html += '<td class="py-2 text-xs">' + escapeHtml(r.status) + '</td>';
            html += '<td class="py-2 text-right text-xs text-slate-500">' + escapeHtml(r.finished_at || '—') + '</td></tr>';
        });
        $body.html(html);
    }

    function renderCoverage(cov) {
        var html = '';
        html += '<li class="flex justify-between"><span>Stocks missing price history</span><span class="font-mono">' + (cov.stocks_without_history || 0) + '</span></li>';
        html += '<li class="flex justify-between"><span>ETFs missing price history</span><span class="font-mono">' + (cov.etfs_without_history || 0) + '</span></li>';
        html += '<li class="flex justify-between"><span>MF schemes missing NAV</span><span class="font-mono">' + (cov.mf_without_nav || 0) + '</span></li>';
        if (!cov.coverage_verified) {
            html += '<li class="text-xs text-amber-200/90 pt-2">Full exchange coverage has not been verified against official lists.</li>';
        }
        $('#dash-coverage-list').html(html);
    }

    function renderWatchlist(wl) {
        var $body = $('#dash-watchlist-rows');
        if (!wl.items || !wl.items.length) {
            $body.html('<tr><td colspan="2" class="py-4 text-slate-500 text-xs">Watchlist is empty. Add symbols from stock details.</td></tr>');
            return;
        }
        var html = '';
        wl.items.forEach(function (item) {
            html += '<tr><td class="py-2"><a class="text-cyan-300 hover:underline" href="stock-details.php?id=' + item.id + '">' + escapeHtml(item.symbol) + '</a></td>';
            html += '<td class="py-2 text-right font-mono">' + (item.last_close != null ? Number(item.last_close).toFixed(2) : '—') + '</td></tr>';
        });
        $body.html(html);
    }

    function renderMovers(movers) {
        var $box = $('#dash-movers-content');
        if (!movers.available) {
            $box.html('<p class="text-xs text-slate-500">Insufficient recent OHLCV for session movers. Run a price refresh from Data Pipeline.</p>');
            return;
        }
        function table(title, rows, positive) {
            var h = '<p class="text-xs font-medium text-slate-400 mb-1">' + title + '</p><ul class="text-xs space-y-1 mb-3">';
            rows.forEach(function (r) {
                var cls = positive ? 'text-emerald-300' : 'text-red-300';
                h += '<li class="flex justify-between gap-2"><span>' + escapeHtml(r.symbol) + '</span><span class="font-mono ' + cls + '">' + (r.change_pct > 0 ? '+' : '') + r.change_pct + '%</span></li>';
            });
            h += '</ul>';
            return h;
        }
        $box.html(table('Top gainers', movers.gainers, true) + table('Top losers', movers.losers, false));
    }

    function escapeHtml(s) {
        return $('<div/>').text(s == null ? '' : String(s)).html();
    }

    function populateInstrumentSelect(chartOpts) {
        var $sel = $('#dash-instrument-select');
        $sel.empty();
        if (!chartOpts.instruments || !chartOpts.instruments.length) {
            $sel.append('<option value="">No chartable instruments</option>').prop('disabled', true);
            return;
        }
        chartOpts.instruments.forEach(function (inst) {
            $sel.append('<option value="' + inst.id + '">' + escapeHtml(inst.symbol) + ' · ' + inst.bar_count + ' bars</option>');
        });
        $sel.prop('disabled', false);
        selectedInstrumentId = chartOpts.default_instrument_id || chartOpts.instruments[0].id;
        $sel.val(String(selectedInstrumentId));
    }

    function loadChart() {
        if (!selectedInstrumentId || chartLoading) {
            return;
        }
        chartLoading = true;
        $('#dash-chart-skeleton').removeClass('hidden');
        $('#dashboard-chart').addClass('hidden');
        $('#dash-chart-empty').addClass('hidden');

        $.getJSON('api/stock-data.php', { instrument_id: selectedInstrumentId, days: selectedDays })
            .done(function (res) {
                chartLoading = false;
                $('#dash-chart-skeleton').addClass('hidden');
                if (!res.ok || !res.labels || !res.labels.length) {
                    $('#dash-chart-empty').removeClass('hidden').text('No price data for this range.');
                    return;
                }
                $('#dashboard-chart').removeClass('hidden');
                $('#dash-chart-subtitle').text(res.instrument.symbol + ' · ' + res.instrument.exchange);
                var meta = 'Data through ' + (res.last_trade_date || '—');
                if (res.stale_warning) {
                    meta += ' · ' + res.stale_warning;
                    $('#dash-chart-meta').removeClass('hidden').addClass('text-amber-300/90');
                } else {
                    $('#dash-chart-meta').removeClass('text-amber-300/90');
                }
                $('#dash-chart-meta').text(meta).removeClass('hidden');

                var ctx = document.getElementById('dashboard-chart');
                if (lineChart) {
                    lineChart.destroy();
                }
                lineChart = new Chart(ctx, {
                    type: 'line',
                    data: {
                        labels: res.labels,
                        datasets: [{
                            label: 'Close',
                            data: res.close,
                            borderColor: '#22d3ee',
                            backgroundColor: 'rgba(34, 211, 238, 0.08)',
                            fill: true,
                            pointRadius: 0,
                            tension: 0.25,
                            borderWidth: 2
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        interaction: { mode: 'index', intersect: false },
                        plugins: {
                            legend: { display: false },
                            tooltip: {
                                callbacks: {
                                    label: function (ctx) {
                                        return 'Close: ' + Number(ctx.raw).toFixed(2);
                                    }
                                }
                            }
                        },
                        scales: {
                            x: { ticks: { color: '#64748b', maxTicksLimit: 8 }, grid: { color: 'rgba(51,65,85,0.3)' } },
                            y: { ticks: { color: '#64748b' }, grid: { color: 'rgba(51,65,85,0.3)' } }
                        },
                        animation: { duration: 500 }
                    }
                });
            })
            .fail(function () {
                chartLoading = false;
                $('#dash-chart-skeleton').addClass('hidden');
                $('#dash-chart-empty').removeClass('hidden').text('Could not load chart data.');
            });
    }

    function bindRangeButtons() {
        $('.dash-range-btn').on('click', function () {
            $('.dash-range-btn').removeClass('active');
            $(this).addClass('active');
            selectedDays = parseInt($(this).data('days'), 10) || 90;
            loadChart();
        });
        $('.dash-range-btn[data-days="90"]').addClass('active');
    }

    $.getJSON('api/dashboard-data.php')
        .done(function (res) {
            if (!res.ok) {
                $('#dash-chart-empty').text('Dashboard data unavailable.');
                return;
            }
            setKpis(res.kpis || {});
            setLastSync(res.last_sync);
            renderDoughnut(res.distribution || {}, res.distribution_total || 0);
            renderPredictions(res.recent_predictions);
            renderSync(res.recent_sync);
            renderCoverage(res.coverage || {});
            renderWatchlist(res.watchlist || { items: [] });
            renderMovers(res.movers || {});
            populateInstrumentSelect(res.chart || { instruments: [] });
            bindRangeButtons();
            $('#dash-instrument-select').on('change', function () {
                selectedInstrumentId = parseInt($(this).val(), 10) || null;
                loadChart();
            });
            if (selectedInstrumentId) {
                loadChart();
            } else {
                $('#dash-chart-skeleton').addClass('hidden');
                $('#dash-chart-empty').removeClass('hidden').text('Import stocks or ETFs with daily prices to see a chart.');
            }
        })
        .fail(function () {
            $('#dash-chart-empty').text('Failed to load dashboard. Refresh the page.');
        });
})(jQuery);
