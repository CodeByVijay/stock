(function ($) {
    'use strict';

    var syncPollTimer = null;

    function fmtNum(v, digits) {
        if (v === null || v === undefined || isNaN(v)) {
            return '—';
        }
        return Number(v).toFixed(digits);
    }

    function esc(text) {
        return $('<div/>').text(text == null ? '' : String(text)).html();
    }

    function renderIndicators(container, ind) {
        var items = [
            ['SMA 20', fmtNum(ind.sma_20, 2)],
            ['SMA 50', fmtNum(ind.sma_50, 2)],
            ['SMA 200', fmtNum(ind.sma_200, 2)],
            ['EMA 20', fmtNum(ind.ema_20, 2)],
            ['RSI 14', fmtNum(ind.rsi_14, 2)],
            ['MACD', fmtNum(ind.macd, 4)],
            ['MACD signal', fmtNum(ind.macd_signal, 4)],
            ['Volatility % (20d)', fmtNum(ind.volatility, 2)]
        ];
        var html = '';
        items.forEach(function (pair) {
            html += '<div class="rounded-lg border border-surface-border p-3 bg-surface">' +
                '<p class="text-slate-500 text-xs">' + esc(pair[0]) + '</p>' +
                '<p class="font-mono mt-1">' + esc(pair[1]) + '</p></div>';
        });
        html += '<p class="col-span-full text-xs text-slate-500">Bars used: ' + (ind.history_bars || 0) + '</p>';
        container.html(html);
    }

    function renderInsights(ins) {
        var $grid = $('#insights-grid');
        if (!$grid.length) {
            return;
        }
        if (!ins || !ins.has_data) {
            $grid.html('<p class="text-slate-500 text-sm">' + esc(ins && ins.message ? ins.message : 'No insights yet.') + '</p>');
            return;
        }

        var tiltClass = 'inst-outlook-badge--flat';
        if (ins.short_tilt && ins.short_tilt.key === 'up') {
            tiltClass = 'inst-outlook-badge--up';
        } else if (ins.short_tilt && ins.short_tilt.key === 'down') {
            tiltClass = 'inst-outlook-badge--down';
        }

        var outlookClass = tiltClass;
        if (ins.outlook && ins.outlook.key === 'buy_lean') {
            outlookClass = 'inst-outlook-badge--up';
        } else if (ins.outlook && ins.outlook.key === 'sell_lean') {
            outlookClass = 'inst-outlook-badge--down';
        }

        var pos = ins.position_in_52w_range_pct;
        var marker = pos !== null && pos !== undefined ? Math.min(100, Math.max(0, Number(pos))) : 50;

        var html = '';
        html += '<div class="inst-insight-card inst-insight-card--wide">';
        html += '<p class="inst-insight-card__label">52-week range (stored history)</p>';
        html += '<div class="grid grid-cols-2 md:grid-cols-4 gap-3 mt-2 text-sm">';
        html += '<div><span class="text-slate-500">Low</span><p class="font-mono">' + esc(fmtNum(ins.week52_low, 2)) + '</p></div>';
        html += '<div><span class="text-slate-500">High</span><p class="font-mono">' + esc(fmtNum(ins.week52_high, 2)) + '</p></div>';
        html += '<div><span class="text-slate-500">From high</span><p class="font-mono">' + esc(fmtNum(ins.pct_from_52w_high, 2)) + '%</p></div>';
        html += '<div><span class="text-slate-500">From low</span><p class="font-mono">+' + esc(fmtNum(ins.pct_from_52w_low, 2)) + '%</p></div>';
        html += '</div>';
        html += '<div class="inst-range-bar" aria-hidden="true"><div class="inst-range-bar__fill" style="width:' + marker + '%"></div>';
        html += '<div class="inst-range-bar__marker" style="left:' + marker + '%"></div></div>';
        html += '<p class="inst-insight-card__sub">Gold marker = latest close position between 52W low and high.</p>';
        html += '</div>';

        html += '<div class="inst-insight-card">';
        html += '<p class="inst-insight-card__label">Short-term tilt</p>';
        html += '<p class="inst-insight-card__value">' + esc(ins.short_tilt ? ins.short_tilt.label_hi : '—') + '</p>';
        html += '<span class="inst-outlook-badge ' + tiltClass + '">' + esc(ins.short_tilt ? ins.short_tilt.label : '') + '</span>';
        html += '<p class="inst-insight-card__sub">' + esc(ins.short_tilt ? ins.short_tilt.detail : '') + '</p>';
        html += '</div>';

        html += '<div class="inst-insight-card">';
        html += '<p class="inst-insight-card__label">Research signal (rules)</p>';
        html += '<p class="inst-insight-card__value">' + esc(ins.outlook ? ins.outlook.label : '—') + '</p>';
        html += '<span class="inst-outlook-badge ' + outlookClass + '">' + esc(ins.outlook ? ins.outlook.badge : '') + ' · Score ' + esc(ins.research_score) + '/100</span>';
        html += '<p class="inst-insight-card__sub">' + esc(ins.outlook ? ins.outlook.detail : '') + '</p>';
        html += '</div>';

        html += '<div class="inst-insight-card inst-insight-card--wide">';
        html += '<p class="inst-insight-card__label">Returns (%)</p><div class="inst-returns-row">';
        var ret = ins.returns || {};
        ['1d', '1w', '1m', '3m', '6m', '1y'].forEach(function (k) {
            var v = ret[k];
            var label = k.toUpperCase();
            html += '<span>' + label + ': <strong class="font-mono">' + (v === null || v === undefined ? '—' : esc(fmtNum(v, 2)) + '%') + '</strong></span>';
        });
        html += '</div>';
        if (ins.signals && ins.signals.length) {
            html += '<div class="inst-signal-tags">';
            ins.signals.forEach(function (t) {
                html += '<span class="inst-signal-tag">' + esc(t) + '</span>';
            });
            html += '</div>';
        }
        html += '<p class="inst-insight-card__sub mt-2">' + esc(ins.research_reason) + '</p>';
        if (ins.research_risk) {
            html += '<p class="inst-insight-card__sub text-amber-200/80">' + esc(ins.research_risk) + '</p>';
        }
        html += '<p class="inst-insight-card__sub">' + esc(ins.disclaimer) + '</p>';
        html += '</div>';

        $grid.html(html);
    }

    function renderPriceHeadline(ins) {
        var $h = $('#price-headline');
        if (!$h.length || !ins || !ins.has_data) {
            return;
        }
        var ret1d = ins.returns && ins.returns['1d'] != null ? Number(ins.returns['1d']) : null;
        var chgClass = 'inst-price-headline__chg';
        if (ret1d !== null) {
            chgClass += ret1d >= 0 ? ' inst-price-headline__chg--up' : ' inst-price-headline__chg--down';
        }
        var chgText = ret1d === null ? '' : ((ret1d >= 0 ? '+' : '') + fmtNum(ret1d, 2) + '% (1 day)');
        $h.html(
            '<span class="inst-price-headline__last">₹' + esc(fmtNum(ins.last_close, 2)) + '</span>' +
            (chgText ? '<span class="' + chgClass + '">' + esc(chgText) + '</span>' : '')
        );
    }

    function chartTickLimit() {
        return window.matchMedia('(max-width: 600px)').matches ? 5 : 8;
    }

    function buildLineChart(canvasId, labels, datasets) {
        var ctx = document.getElementById(canvasId);
        if (!ctx || typeof Chart === 'undefined') {
            return null;
        }
        return new Chart(ctx, {
            type: 'line',
            data: { labels: labels, datasets: datasets },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { labels: { color: '#94a3b8', boxWidth: 12 } }
                },
                scales: {
                    x: {
                        ticks: {
                            color: '#64748b',
                            maxTicksLimit: chartTickLimit(),
                            maxRotation: 0,
                            autoSkip: true
                        },
                        grid: { color: 'rgba(41, 56, 77, 0.5)' }
                    },
                    y: {
                        ticks: { color: '#64748b' },
                        grid: { color: 'rgba(41, 56, 77, 0.5)' }
                    }
                }
            }
        });
    }

    function resizeCharts() {
        if (typeof Chart === 'undefined') {
            return;
        }
        ['nav-chart', 'price-chart'].forEach(function (id) {
            var el = document.getElementById(id);
            if (!el) {
                return;
            }
            var chart = Chart.getChart(el);
            if (chart) {
                chart.resize();
            }
        });
    }

    window.addEventListener('portal:layout-change', resizeCharts);
    window.addEventListener('resize', function () {
        window.setTimeout(resizeCharts, 150);
    });

    function stockSyncToast(msg, isError) {
        var $toast = $('#stock-sync-toast');
        if (!$toast.length) {
            return;
        }
        $toast.removeClass('hidden pro-toast--ok pro-toast--err');
        if (isError) {
            $toast.addClass('pro-toast pro-toast--err inst-detail-toast');
        } else {
            $toast.addClass('pro-toast pro-toast--ok inst-detail-toast');
        }
        $toast.text(msg).removeClass('hidden');
    }

    function setSyncProgress(text, show) {
        var $p = $('#stock-sync-progress');
        if (!$p.length) {
            return;
        }
        if (show) {
            $p.text(text).removeClass('hidden');
        } else {
            $p.addClass('hidden').text('');
        }
    }

    function destroyChart(canvasId) {
        var el = document.getElementById(canvasId);
        if (!el || typeof Chart === 'undefined') {
            return;
        }
        var existing = Chart.getChart(el);
        if (existing) {
            existing.destroy();
        }
    }

    function loadInstrumentDetail(instrumentId) {
        return $.getJSON('api/stock-data.php', { instrument_id: instrumentId, days: 365 })
            .done(function (res) {
                if (!res.ok) {
                    $('#price-meta').text(res.error || 'Could not load data.');
                    return;
                }
                var meta = 'Source: ' + (res.source || '—') +
                    ' · Last trade date: ' + (res.last_trade_date || '—') +
                    ' · Fetched: ' + (res.fetched_at || '—');
                $('#price-meta').text(meta);
                $('#stale-warning').addClass('hidden').text('');
                if (res.stale_warning) {
                    $('#stale-warning').text(res.stale_warning).removeClass('hidden');
                }

                renderInsights(res.insights || {});
                renderPriceHeadline(res.insights || {});

                destroyChart('price-chart');
                $('#chart-empty').addClass('hidden');
                if (!res.labels || !res.labels.length) {
                    $('#chart-empty').removeClass('hidden');
                    $('#indicators-grid').html('<p class="text-slate-500 col-span-full">Not enough history for indicators.</p>');
                    return;
                }
                renderIndicators($('#indicators-grid'), res.indicators || {});

                var datasets = [
                    {
                        label: 'Close',
                        data: res.close,
                        borderColor: '#34d399',
                        backgroundColor: 'rgba(52,211,153,0.1)',
                        tension: 0.15,
                        pointRadius: 0
                    },
                    {
                        label: 'SMA 20',
                        data: res.sma_20,
                        borderColor: '#60a5fa',
                        pointRadius: 0,
                        borderWidth: 1
                    },
                    {
                        label: 'SMA 50',
                        data: res.sma_50,
                        borderColor: '#fbbf24',
                        pointRadius: 0,
                        borderWidth: 1
                    }
                ];

                var ins = res.insights || {};
                if (ins.week52_high != null) {
                    datasets.push({
                        label: '52W high',
                        data: res.labels.map(function () { return ins.week52_high; }),
                        borderColor: 'rgba(251, 191, 36, 0.45)',
                        borderDash: [6, 4],
                        pointRadius: 0,
                        borderWidth: 1
                    });
                }
                if (ins.week52_low != null) {
                    datasets.push({
                        label: '52W low',
                        data: res.labels.map(function () { return ins.week52_low; }),
                        borderColor: 'rgba(148, 163, 184, 0.45)',
                        borderDash: [6, 4],
                        pointRadius: 0,
                        borderWidth: 1
                    });
                }

                buildLineChart('price-chart', res.labels, datasets);
            });
    }

    function pollSyncJob(jobId, instrumentId, onDone) {
        if (syncPollTimer) {
            clearInterval(syncPollTimer);
        }
        syncPollTimer = setInterval(function () {
            $.getJSON('api/job-status.php', { job_id: jobId })
                .done(function (res) {
                    if (!res.ok || !res.job) {
                        return;
                    }
                    var job = res.job;
                    if (job.status === 'running' || job.status === 'queued') {
                        var pct = '';
                        if (job.progress_total > 0) {
                            pct = ' (' + (job.progress_done || 0) + '/' + job.progress_total + ')';
                        }
                        setSyncProgress('Downloading in background…' + pct, true);
                        return;
                    }
                    clearInterval(syncPollTimer);
                    syncPollTimer = null;
                    setSyncProgress('', false);
                    if (job.status === 'completed') {
                        var msg = (job.result && job.result.message) ? job.result.message : 'Download finished.';
                        stockSyncToast(msg, false);
                        loadInstrumentDetail(instrumentId);
                    } else {
                        stockSyncToast(job.error_message || 'Download failed.', true);
                    }
                    if (onDone) {
                        onDone();
                    }
                });
        }, 2000);
    }

    function triggerWorkerTick() {
        var csrf = $('#instrument-detail').data('csrf');
        $.post('api/worker-tick.php', { _csrf: csrf, max_jobs: 1 });
    }

    var $detail = $('#instrument-detail');
    if ($detail.length) {
        var instrumentId = $detail.data('instrument-id');
        var csrf = $detail.data('csrf');

        $('#watchlist-toggle').on('click', function () {
            $.post('api/watchlist-toggle.php', { instrument_id: instrumentId, _csrf: csrf })
                .done(function (res) {
                    if (!res.ok) {
                        return;
                    }
                    var on = res.on_watchlist;
                    $('#watchlist-toggle').text(on ? 'Remove from watchlist' : 'Add to watchlist');
                });
        });

        loadInstrumentDetail(instrumentId);

        function runStockSync($btn, mode) {
            if ($btn.prop('disabled')) {
                return;
            }
            var labelFull = 'Download full history';
            var labelRecent = 'Refresh prices';
            var busy = mode === 'full' ? 'Starting download…' : 'Refreshing…';
            $btn.prop('disabled', true).text(busy);
            $('#stock-sync-full, #stock-sync-recent').not($btn).prop('disabled', true);
            setSyncProgress(mode === 'full' ? 'Talking to broker…' : 'Updating latest prices…', true);

            $.ajax({
                url: 'api/stock-sync-instrument.php',
                method: 'POST',
                data: {
                    _csrf: csrf,
                    instrument_id: instrumentId,
                    mode: mode
                },
                timeout: mode === 'recent' ? 120000 : 15000
            }).done(function (res) {
                if (!res.ok) {
                    stockSyncToast(res.error || 'Sync failed.', true);
                    setSyncProgress('', false);
                    return;
                }
                if (res.async && res.job_id) {
                    stockSyncToast(res.message || 'Background download started.', false);
                    triggerWorkerTick();
                    pollSyncJob(res.job_id, instrumentId, function () {
                        $('#stock-sync-full').prop('disabled', false).text(labelFull);
                        $('#stock-sync-recent').prop('disabled', false).text(labelRecent);
                    });
                    return;
                }
                stockSyncToast(res.message || 'Prices synced.', false);
                setSyncProgress('', false);
                loadInstrumentDetail(instrumentId);
            }).fail(function (xhr) {
                var err = (xhr.responseJSON && xhr.responseJSON.error) ? xhr.responseJSON.error : 'Sync request failed or timed out. Try again.';
                stockSyncToast(err, true);
                setSyncProgress('', false);
            }).always(function () {
                if (!syncPollTimer) {
                    $('#stock-sync-full').prop('disabled', false).text(labelFull);
                    $('#stock-sync-recent').prop('disabled', false).text(labelRecent);
                }
            });
        }

        $('#stock-sync-full').on('click', function () {
            runStockSync($(this), 'full');
        });
        $('#stock-sync-recent').on('click', function () {
            runStockSync($(this), 'recent');
        });
    }

    var $fund = $('#fund-detail');
    if ($fund.length) {
        var schemeId = $fund.data('scheme-id');
        $.getJSON('api/fund-data.php', { scheme_id: schemeId, days: 365 })
            .done(function (res) {
                if (!res.ok) {
                    $('#fund-meta').text(res.error || 'Could not load NAV.');
                    return;
                }
                if (res.stale_warning) {
                    $('#fund-stale').text(res.stale_warning).removeClass('hidden');
                }
                if (!res.labels || !res.labels.length) {
                    $('#fund-chart-empty').removeClass('hidden');
                    return;
                }
                buildLineChart('nav-chart', res.labels, [{
                    label: 'NAV',
                    data: res.nav,
                    borderColor: '#14b8a6',
                    backgroundColor: 'rgba(20, 184, 166, 0.12)',
                    tension: 0.15,
                    pointRadius: 0,
                    fill: true
                }]);
                resizeCharts();
            });
    }
})(jQuery);
