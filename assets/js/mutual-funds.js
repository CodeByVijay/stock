(function ($) {
    'use strict';

    var $root = $('#mutual-funds-list');
    var csrf = $root.data('csrf') || '';
    if (!$root.length && !$('#mf-detail-sync').length) {
        return;
    }
    var $toast = $('#mf-sync-toast');

    function toast(msg, isError) {
        if (!$toast.length) {
            return;
        }
        $toast.removeClass('hidden pro-toast--ok pro-toast--err');
        if (isError) {
            $toast.addClass('pro-toast--err');
        } else {
            $toast.addClass('pro-toast--ok');
        }
        $toast.text(msg).removeClass('hidden');
    }

    function formatNav(n) {
        if (n === null || n === undefined || isNaN(n)) {
            return '—';
        }
        return Number(n).toFixed(4);
    }

    $root.on('click', '.mf-sync-btn', function () {
        var $btn = $(this);
        if ($btn.prop('disabled')) {
            return;
        }
        var schemeId = $btn.data('scheme-id');
        var mode = $btn.data('mode') || 'recent';
        var $row = $btn.closest('tr');
        $btn.prop('disabled', true).text('Syncing…');

        $.post('api/mf-sync-scheme.php', {
            _csrf: csrf,
            scheme_id: schemeId,
            mode: mode
        }).done(function (res) {
            if (!res.ok) {
                toast(res.error || 'Sync failed.', true);
                return;
            }
            var stats = res.stats || {};
            toast(res.message || (
                'NAV synced: +' + (stats.inserted || 0) + ' new, ' + (stats.updated || 0) + ' updated.'
            ), false);
            $row.find('.mf-last-nav').text(formatNav(res.last_nav));
            $row.find('.mf-last-nav-date').text(res.last_nav_date || '—');
            $row.find('.mf-nav-badge').text('Yes').removeClass('text-slate-500').addClass('text-emerald-300');
        }).fail(function (xhr) {
            var err = (xhr.responseJSON && xhr.responseJSON.error) ? xhr.responseJSON.error : 'Request failed.';
            toast(err, true);
        }).always(function () {
            $btn.prop('disabled', false).text('Update');
        });
    });

    var $detail = $('#mf-detail-sync');
    if ($detail.length) {
        $detail.on('click', function () {
            var $btn = $(this);
            if ($btn.prop('disabled')) {
                return;
            }
            var schemeId = $btn.data('scheme-id');
            $btn.prop('disabled', true).text('Fetching full history…');
            $.post('api/mf-sync-scheme.php', {
                _csrf: csrf,
                scheme_id: schemeId,
                mode: 'full'
            }).done(function (res) {
                if (res.ok) {
                    toast(res.message || 'Full NAV history synced.', false);
                    window.setTimeout(function () {
                        window.location.reload();
                    }, 800);
                } else {
                    toast(res.error || 'Sync failed.', true);
                    $btn.prop('disabled', false).text('Full NAV backfill');
                }
            }).fail(function (xhr) {
                var err = (xhr.responseJSON && xhr.responseJSON.error) ? xhr.responseJSON.error : 'Sync request failed.';
                toast(err, true);
                $btn.prop('disabled', false).text('Sync full NAV history');
            });
        });
    }
})(jQuery);
