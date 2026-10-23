/**
 * Keeps sync_jobs processing without manual CLI — pairs with api/worker-tick.php and background spawn.
 */
(function (global) {
    'use strict';

    function SyncWorkerClient(options) {
        this.csrf = options.csrf || '';
        this.onQueueUpdate = options.onQueueUpdate || null;
        this.tickTimer = null;
        this.pollTimer = null;
        this.trackedJobId = null;
        this.active = false;
    }

    SyncWorkerClient.prototype.stop = function () {
        if (this.tickTimer) {
            clearInterval(this.tickTimer);
            this.tickTimer = null;
        }
        if (this.pollTimer) {
            clearInterval(this.pollTimer);
            this.pollTimer = null;
        }
        this.active = false;
    };

    SyncWorkerClient.prototype.trackJob = function (jobId) {
        this.trackedJobId = jobId;
    };

    SyncWorkerClient.prototype.handleStartResponse = function (res) {
        var self = this;
        if (!res || !res.ok) {
            return { message: (res && res.error) ? res.error : 'Could not start sync.', isError: true };
        }

        var msg = res.message || 'Sync started.';
        var isError = false;

        if (res.info && !res.worker.running) {
            msg = (res.message || msg) + ' Keep this page open while jobs run in this tab.';
        } else if (res.warning) {
            msg = res.warning;
        } else if (res.worker && res.worker.mode === 'background' && res.worker.running) {
            msg = (res.message || 'Sync started.') + ' Background worker is running.';
        } else if (res.worker && res.worker.mode === 'browser') {
            msg = (res.message || 'Sync started.') + ' Processing in this tab (keep it open).';
            if (res.worker.error) {
                msg += ' ' + res.worker.error;
            }
        }

        if (res.job_id) {
            this.trackJob(res.job_id);
        } else if (res.job_ids && res.job_ids.length) {
            this.trackJob(res.job_ids[0]);
        }

        this.start(res.worker || {});

        return { message: msg, isError: isError };
    };

    SyncWorkerClient.prototype.start = function (workerInfo) {
        var self = this;
        this.stop();
        this.active = true;

        var useBrowserTick = !workerInfo.running || workerInfo.mode === 'browser';

        function pollStatus() {
            if (!self.trackedJobId) {
                $.getJSON('api/queue-status.php').done(function (res) {
                    if (res.ok && self.onQueueUpdate) {
                        self.onQueueUpdate(res, null);
                    }
                    if (res.ok && res.queue.queued === 0 && res.queue.running === 0) {
                        self.stop();
                    }
                });
                return;
            }
            $.getJSON('api/job-status.php', { job_id: self.trackedJobId })
                .done(function (res) {
                    if (!res.ok || !res.job) {
                        return;
                    }
                    if (self.onQueueUpdate) {
                        self.onQueueUpdate(null, res.job);
                    }
                    var terminal = res.job.status === 'completed' || res.job.status === 'failed';
                    if (terminal && !useBrowserTick) {
                        $.getJSON('api/queue-status.php').done(function (q) {
                            if (q.ok && q.queue.queued === 0 && q.queue.running === 0) {
                                self.stop();
                                if (self.onQueueUpdate) {
                                    self.onQueueUpdate(q, res.job);
                                }
                            }
                        });
                    }
                });
        }

        function tickWorker(force) {
            $.post('api/worker-tick.php', {
                _csrf: self.csrf,
                max_jobs: 1,
                force: force ? '1' : '0'
            })
                .done(function (res) {
                    if (!res.ok) {
                        return;
                    }
                    if (res.worker_running) {
                        useBrowserTick = false;
                    }
                    pollStatus();
                    if (res.queue && res.queue.queued === 0 && res.queue.running === 0) {
                        self.stop();
                    }
                });
        }

        pollStatus();
        this.pollTimer = setInterval(pollStatus, 2000);

        if (useBrowserTick) {
            tickWorker(true);
            this.tickTimer = setInterval(function () { tickWorker(false); }, 2500);
        } else {
            // Nudge queue if background worker died mid-drain.
            this.tickTimer = setInterval(function () {
                $.getJSON('api/queue-status.php').done(function (q) {
                    if (!q.ok) {
                        return;
                    }
                    if (!q.worker_running && (q.queue.queued > 0 || q.queue.running > 0)) {
                        tickWorker(true);
                    }
                });
            }, 4000);
        }
    };

    global.StockSyncWorkerClient = SyncWorkerClient;
})(window);
