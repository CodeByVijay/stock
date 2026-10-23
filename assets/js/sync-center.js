(function ($) {
    'use strict';

    var $root = $('#sync-center');
    if (!$root.length) {
        return;
    }

    var csrf = $root.data('csrf');
    var sources = {};
    var activeCategories = {};
    try {
        sources = JSON.parse($root.attr('data-sources') || '{}');
        activeCategories = JSON.parse($root.attr('data-active-categories') || '{}');
    } catch (e) {
        sources = {};
        activeCategories = {};
    }
    var intradayEnabled = $root.data('intraday') === 1 || $root.data('intraday') === '1';
    var trackedCategory = null;
    var lastProgressKey = '';
    var lastProgressAt = Date.now();

    var JOB_LABELS = {
        sync_stocks: 'Updating company shares',
        sync_etfs: 'Updating ETFs',
        sync_mutual_funds: 'Updating mutual funds',
        upstox_nse_stocks: 'Downloading NSE share list',
        upstox_bse_stocks: 'Downloading BSE share list',
        upstox_nse_etfs: 'Downloading NSE ETF list',
        nse_equity_master: 'Importing NSE list from file',
        bse_equity_master: 'Importing BSE list from file',
        etf_master: 'Importing ETF list from file',
        mf_scheme_master: 'Downloading fund names',
        mf_nav_backfill: 'Downloading full NAV history',
        mf_nav_daily: 'Refreshing latest NAV',
        equity_ohlcv_csv: 'Importing prices from file',
        equity_daily_upstox: 'Refreshing share prices',
        etf_daily_upstox: 'Refreshing ETF prices',
        history_backfill: 'Filling missing price history',
        intraday: 'Updating intraday charts',
        recalculate_indicators: 'Refreshing chart indicators',
        sync_all_available: 'Running full setup'
    };

    var STATUS_LABELS = {
        queued: 'Waiting',
        running: 'Working…',
        completed: 'Done',
        failed: 'Problem'
    };

    var client = new StockSyncWorkerClient({
        csrf: csrf,
        onQueueUpdate: function (queueRes, job) {
            if (queueRes && queueRes.active_categories) {
                applyCategoryLocks(queueRes.active_categories);
            }
            if (queueRes && queueRes.latest_job && queueRes.queue && (queueRes.queue.running > 0 || queueRes.queue.queued > 0)) {
                job = queueRes.latest_job;
                client.trackJob(job.id);
            }
            if (queueRes && queueRes.reclaimed_stale_jobs > 0) {
                showMsg('A stuck task was cleared — the queue is moving again.', false);
            }
            if (queueRes && queueRes.open_jobs) {
                renderOpenJobs(queueRes.open_jobs);
            }
            if (job) {
                renderActive(job);
                updateStuckPanel(job);
                if (job.status === 'completed') {
                    $.getJSON('api/queue-status.php').done(function (q) {
                        if (q.ok && q.active_categories) {
                            applyCategoryLocks(q.active_categories);
                        }
                        if (q.ok && q.queue.queued === 0 && q.queue.running === 0) {
                            showMsg('Done! You can open Markets from the menu to view data.', false);
                            clearCategoryBusy();
                            setTimeout(function () {
                                window.location.reload();
                            }, 1800);
                        } else {
                            showMsg('This step finished. Another task is still working…', false);
                        }
                    });
                } else if (job.status === 'failed') {
                    showMsg('Something went wrong. Read the message below, then try the same button again.', true);
                    clearCategoryBusy();
                }
            }
            if (queueRes && queueRes.queue) {
                renderQueueSummary(queueRes);
            }
        }
    });

    applyCategoryLocks(activeCategories);

    function jobLabel(type) {
        return JOB_LABELS[type] || 'Data update';
    }

    function statusLabel(status) {
        return STATUS_LABELS[status] || status;
    }

    function applyCategoryLocks(cats) {
        activeCategories = cats || {};
        $('.mdc-primary-sync').each(function () {
            var $btn = $(this);
            var cat = $btn.data('category');
            var busy = !!activeCategories[cat];
            $btn.prop('disabled', busy);
            $btn.attr('aria-busy', busy ? 'true' : 'false');
            var idle = $btn.data('idle-label') || jobLabel($btn.data('job-type'));
            $btn.find('.mdc-btn-label').text(busy ? 'Please wait…' : idle);
        });
    }

    function setSecondaryBusy(busy) {
        $('.sync-start-btn').not('.mdc-primary-sync').prop('disabled', busy);
        $('.sync-start-all').prop('disabled', busy);
    }

    function markCategoryBusy(category) {
        trackedCategory = category || null;
        if (category) {
            activeCategories[category] = true;
            applyCategoryLocks(activeCategories);
        }
    }

    function clearCategoryBusy() {
        trackedCategory = null;
        setSecondaryBusy(false);
    }

    function resetSetupButton($btn) {
        if (!$btn || !$btn.length || !$btn.hasClass('pro-setup-btn')) {
            return;
        }
        var idle = $btn.data('idle-setup-title');
        if (idle) {
            $btn.find('.pro-setup-btn__title').text(idle);
        }
        $btn.prop('disabled', false).attr('aria-busy', 'false');
    }

    function markSetupButtonQueued($btn) {
        if (!$btn.hasClass('pro-setup-btn')) {
            return;
        }
        var $title = $btn.find('.pro-setup-btn__title');
        if (!$btn.data('idle-setup-title')) {
            $btn.data('idle-setup-title', $title.text());
        }
        $title.text('Queued…');
        $btn.attr('aria-busy', 'true');
    }

    function showMsg(text, isError) {
        var $m = $('#sync-status-msg');
        $m.removeClass('hidden pro-toast--ok pro-toast--err');
        $m.text(text);
        if (isError) {
            $m.addClass('pro-toast--err');
        } else {
            $m.addClass('pro-toast--ok');
        }
    }

    function renderQueueSummary(res) {
        var q = res.queue;
        var $panel = $('#queue-summary-panel');
        if (q.queued === 0 && q.running === 0 && !res.worker_running) {
            $panel.addClass('hidden').empty();
            return;
        }
        var html = '<span class="inline-flex items-center gap-2">';
        html += '<span class="w-2 h-2 rounded-full bg-emerald-400 animate-pulse" aria-hidden="true"></span>';
        html += res.worker_running ? '<span>Working in background</span>' : '<span>Working in this browser tab</span>';
        html += '</span>';
        if (q.queued > 0 || q.running > 0) {
            html += ' · ' + q.running + ' active, ' + q.queued + ' waiting';
        }
        $panel.html(html).removeClass('hidden');
    }

    function progressPercent(job) {
        if (job.progress_total === null || job.progress_total === undefined || job.progress_total <= 0) {
            return null;
        }
        var done = job.progress_done || 0;
        return Math.min(100, Math.round((done / job.progress_total) * 100));
    }

    function renderActive(job) {
        var label = jobLabel(job.job_type);
        var pct = progressPercent(job);
        var html = '<div class="space-y-3 animate-fade-in">';
        html += '<div class="flex flex-wrap items-baseline justify-between gap-2">';
        html += '<p class="text-base font-medium text-slate-100">' + $('<div/>').text(label).html() + '</p>';
        html += '<span class="text-xs uppercase tracking-wide px-2 py-0.5 rounded-full ';
        if (job.status === 'running') {
            html += 'bg-sky-500/20 text-sky-200">' + statusLabel('running');
        } else if (job.status === 'completed') {
            html += 'bg-emerald-500/20 text-emerald-200">' + statusLabel('completed');
        } else if (job.status === 'failed') {
            html += 'bg-red-500/20 text-red-200">' + statusLabel('failed');
        } else {
            html += 'bg-slate-700 text-slate-300">' + $('<div/>').text(statusLabel(job.status)).html();
        }
        html += '</span></div>';

        if (pct !== null) {
            html += '<div role="progressbar" aria-valuenow="' + pct + '" aria-valuemin="0" aria-valuemax="100" class="space-y-1">';
            html += '<div class="h-3 rounded-full bg-slate-800 overflow-hidden">';
            html += '<div class="h-full bg-emerald-500 transition-all duration-500 rounded-full" style="width:' + pct + '%"></div></div>';
            html += '<p class="text-xs text-slate-400">' + (job.progress_done || 0) + ' of ' + job.progress_total + ' steps</p></div>';
        }

        html += '<dl class="grid grid-cols-2 sm:grid-cols-4 gap-3 text-sm">';
        html += '<div><dt class="text-slate-500 text-xs">New rows</dt><dd class="font-mono text-emerald-200">' + resultVal(job, 'inserted') + '</dd></div>';
        html += '<div><dt class="text-slate-500 text-xs">Updated</dt><dd class="font-mono text-sky-200">' + resultVal(job, 'updated') + '</dd></div>';
        html += '<div><dt class="text-slate-500 text-xs">Skipped</dt><dd class="font-mono text-slate-300">' + resultVal(job, 'skipped') + '</dd></div>';
        html += '<div><dt class="text-slate-500 text-xs">Problems</dt><dd class="font-mono text-red-300">' + resultVal(job, 'failed') + '</dd></div>';
        html += '</dl>';

        if (job.started_at) {
            html += '<p class="text-xs text-slate-500">Started: ' + $('<div/>').text(job.started_at).html() + '</p>';
        }

        if (job.result && job.result.notes && job.result.notes.length) {
            html += '<ul class="text-xs text-slate-400 list-disc pl-4 space-y-0.5">';
            job.result.notes.forEach(function (n) {
                html += '<li>' + $('<div/>').text(n).html() + '</li>';
            });
            html += '</ul>';
        }

        if (job.result && job.result.skip_reasons && typeof job.result.skip_reasons === 'object') {
            html += '<p class="text-xs text-slate-500 mt-2">Skip reasons: ';
            html += $('<div/>').text(Object.keys(job.result.skip_reasons).map(function (k) {
                return k + ' (' + job.result.skip_reasons[k] + ')';
            }).join(', ')).html();
            html += '</p>';
        }

        if (job.error_message) {
            var softRetry = /resuming automatically|queued for retry/i.test(job.error_message);
            var errClass = job.status === 'queued' || softRetry
                ? 'text-amber-100 bg-amber-500/10 border-amber-500/30'
                : 'text-red-200 bg-red-500/10 border-red-500/30';
            html += '<p class="text-sm ' + errClass + ' border rounded-lg px-3 py-2" role="alert">';
            html += $('<div/>').text(job.error_message).html() + '</p>';
        }
        html += '</div>';
        $('#active-job-panel').html(html);
    }

    function updateStuckPanel(job) {
        var $p = $('#sync-stuck-panel');
        if (!$p.length || !job || job.status !== 'running') {
            if ($p.length) {
                $p.addClass('hidden');
            }
            return;
        }
        var key = String(job.id) + ':' + String(job.progress_done || 0);
        if (key !== lastProgressKey) {
            lastProgressKey = key;
            lastProgressAt = Date.now();
        }
        var ageMin = 0;
        if (job.started_at) {
            var started = new Date(String(job.started_at).replace(' ', 'T'));
            if (!isNaN(started.getTime())) {
                ageMin = (Date.now() - started.getTime()) / 60000;
            }
        }
        var staleMin = (Date.now() - lastProgressAt) / 60000;
        if (ageMin > 40 || staleMin > 12) {
            $p.removeClass('hidden');
        } else {
            $p.addClass('hidden');
        }
    }

    function resultVal(job, key) {
        if (job.result && job.result[key] !== undefined && job.result[key] !== null) {
            return job.result[key];
        }
        if (key === 'inserted' || key === 'updated') {
            return job.status === 'running' ? '…' : '0';
        }
        return '0';
    }

    function postJob(data) {
        data._csrf = csrf;
        return $.post('api/start-job.php', data);
    }

    function renderOpenJobs(jobs) {
        var $panel = $('#queue-jobs-panel');
        var $list = $('#queue-jobs-list');
        if (!$list.length) {
            return;
        }
        if (!jobs || !jobs.length) {
            $panel.addClass('hidden');
            $list.empty();
            return;
        }
        $panel.removeClass('hidden');
        var html = '';
        jobs.forEach(function (job) {
            var st = statusLabel(job.status);
            var prog = '';
            if (job.progress_total > 0) {
                prog = ' · ' + (job.progress_done || 0) + '/' + job.progress_total;
            }
            var label = job.label_simple || jobLabel(job.job_type);
            html += '<li class="pro-queue-jobs__item' + (job.status === 'running' ? ' pro-queue-jobs__item--running' : '') + '">';
            html += '<div><strong>#' + job.id + '</strong> ' + $('<div/>').text(label).html();
            html += ' <span class="pro-queue-jobs__meta">(' + $('<div/>').text(st).html() + prog + ')</span></div>';
            html += '<button type="button" class="portal-btn portal-btn--ghost portal-btn--sm queue-run-one" data-job-id="' + job.id + '">Run now</button>';
            html += '</li>';
        });
        $list.html(html);
    }

    function afterQueueAction(res) {
        if (!res || !res.ok) {
            showMsg((res && res.error) ? res.error : 'Action failed.', true);
            return;
        }
        showMsg(res.message || 'Done.', false);
        lastProgressAt = Date.now();
        if (res.active_categories) {
            applyCategoryLocks(res.active_categories);
        }
        if (res.queue) {
            renderQueueSummary({ queue: res.queue, worker_running: res.worker_running });
        }
        if (res.open_jobs) {
            renderOpenJobs(res.open_jobs);
        }
        client.start({ running: !!res.worker_running, mode: res.worker_running ? 'background' : 'browser' });
        $.getJSON('api/queue-status.php').done(function (q) {
            if (!q.ok) {
                return;
            }
            if (q.latest_job) {
                client.trackJob(q.latest_job.id);
                renderActive(q.latest_job);
                updateStuckPanel(q.latest_job);
            }
            if (q.open_jobs) {
                renderOpenJobs(q.open_jobs);
            }
            if (q.active_categories) {
                applyCategoryLocks(q.active_categories);
            }
            renderQueueSummary(q);
        });
    }

    function forceRunQueue(maxJobs) {
        return $.post('api/worker-tick.php', {
            _csrf: csrf,
            max_jobs: maxJobs,
            force: '1'
        }).done(afterQueueAction).fail(function () {
            showMsg('Could not run queue. Try Fix stuck queue.', true);
        });
    }

    function humanWorkerMessage(res) {
        if (!res.ok) {
            return { message: res.error || 'Could not start update.', isError: true };
        }
        var msg = res.message || 'Update started.';
        var isError = false;
        if (res.worker && res.worker.running && res.worker.mode === 'background') {
            msg = (res.message || 'Update started.') + ' A background worker is processing the queue.';
        } else if (res.worker && res.worker.mode === 'browser') {
            msg = (res.message || 'Update queued.') + ' Keep this page open while jobs run in this tab.';
        }
        if (res.warning) {
            msg = res.warning.replace(/Background worker/gi, 'Automatic processing');
            isError = true;
        }
        return { message: msg, isError: isError };
    }

    function onStartDone(res, $btn) {
        if (!res.ok) {
            showMsg(res.error || 'Could not start update.', true);
            if ($btn && $btn.hasClass('mdc-primary-sync')) {
                activeCategories[$btn.data('category')] = false;
                applyCategoryLocks(activeCategories);
            }
            clearCategoryBusy();
            return;
        }
        client.handleStartResponse(res);
        var feedback = humanWorkerMessage(res);
        showMsg(feedback.message, feedback.isError);
        if (res.job_id) {
            renderActive({
                id: res.job_id,
                job_type: res.job_type || ($btn ? $btn.data('job-type') : ''),
                status: 'queued',
                records_processed: 0,
                progress_done: null,
                progress_total: null,
                result: {}
            });
        }
        if ($btn && $btn.hasClass('pro-setup-btn')) {
            clearCategoryBusy();
            resetSetupButton($btn);
            showMsg(
                feedback.message + ' Watch “Current task” below. Use “Run next job now” if nothing moves.',
                feedback.isError
            );
            var $panel = $('#active-job-panel');
            if ($panel.length) {
                $panel[0].scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            }
        }
    }

    function validateAction($btn) {
        var req = $btn.data('requires');
        if (!req) {
            return true;
        }
        if (req === 'intraday' && !intradayEnabled) {
            showMsg('Intraday updates are not enabled.', true);
            return false;
        }
        if (sources[req] === false || sources[req] === undefined) {
            showMsg('This action is not set up yet. See Advanced details.', true);
            return false;
        }
        return true;
    }

    $('.sync-start-btn').on('click', function () {
        var $btn = $(this);
        if ($btn.prop('disabled')) {
            return;
        }
        if (!validateAction($btn)) {
            return;
        }
        var type = $btn.data('job-type');
        var category = $btn.data('category');
        if ($btn.hasClass('pro-setup-btn')) {
            markSetupButtonQueued($btn);
            $btn.prop('disabled', true);
        } else if (category) {
            markCategoryBusy(category);
        } else {
            setSecondaryBusy(true);
        }
        postJob({ job_type: type })
            .done(function (res) {
                res.job_type = type;
                onStartDone(res, $btn);
            })
            .fail(function (xhr) {
                var detail = '';
                if (xhr.responseJSON && xhr.responseJSON.error) {
                    detail = xhr.responseJSON.error;
                } else if (xhr.responseText && xhr.responseText.length < 400) {
                    detail = xhr.responseText.replace(/<[^>]+>/g, ' ').trim();
                }
                var msg = detail
                    ? detail
                    : 'Could not reach the server (' + xhr.status + ').';
                showMsg(msg, true);
                clearCategoryBusy();
                resetSetupButton($btn);
                applyCategoryLocks(activeCategories);
            });
    });

    var $dialog = document.getElementById('mdc-confirm-dialog');

    function runSyncAll() {
        setSecondaryBusy(true);
        postJob({ sync_all: '1' })
            .done(onStartDone)
            .fail(function () {
                showMsg('Could not start full sync.', true);
                clearCategoryBusy();
            });
    }

    $('.sync-start-all').on('click', function () {
        if ($dialog && typeof $dialog.showModal === 'function') {
            $dialog.showModal();
        } else if (window.confirm('Run the full pipeline including historical backfill? This may take a long time.')) {
            runSyncAll();
        }
    });

    $('#mdc-confirm-sync-all').on('click', function () {
        if ($dialog) {
            $dialog.close();
        }
        runSyncAll();
    });

    $('#sync-run-next-btn').on('click', function () {
        var $btn = $(this).prop('disabled', true);
        forceRunQueue(1).always(function () { $btn.prop('disabled', false); });
    });

    $('#sync-run-batch-btn').on('click', function () {
        var $btn = $(this).prop('disabled', true);
        forceRunQueue(3).always(function () { $btn.prop('disabled', false); });
    });

    $('#sync-resume-btn').on('click', function () {
        var $btn = $(this).prop('disabled', true);
        $.post('api/sync-resume.php', { _csrf: csrf })
            .done(afterQueueAction)
            .fail(function () {
                showMsg('Could not reach the server.', true);
            })
            .always(function () {
                $btn.prop('disabled', false);
                $('#sync-stuck-panel').addClass('hidden');
            });
    });

    $('#sync-spawn-btn').on('click', function () {
        var $btn = $(this).prop('disabled', true);
        $.post('api/sync-resume.php', { _csrf: csrf, spawn_only: '1' })
            .done(afterQueueAction)
            .fail(function () { showMsg('Could not start worker.', true); })
            .always(function () { $btn.prop('disabled', false); });
    });

    $(document).on('click', '.queue-run-one', function () {
        var jobId = $(this).data('job-id');
        client.trackJob(jobId);
        forceRunQueue(1);
    });

    $.getJSON('api/queue-status.php').done(function (res) {
        if (!res.ok) {
            return;
        }
        if (res.reclaimed_stale_jobs > 0) {
            showMsg('Recovered a stuck background task — sync should continue.', false);
        }
        if (res.active_categories) {
            applyCategoryLocks(res.active_categories);
        }
        if (res.open_jobs) {
            renderOpenJobs(res.open_jobs);
        }
        if (res.queue.queued > 0 || res.queue.running > 0) {
            if (res.latest_job) {
                client.trackJob(res.latest_job.id);
                renderActive(res.latest_job);
                updateStuckPanel(res.latest_job);
            }
            client.start({ running: res.worker_running, mode: res.worker_running ? 'background' : 'browser' });
            renderQueueSummary(res);
        }
    });
})(jQuery);
