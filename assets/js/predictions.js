(function ($) {
    'use strict';

    $('form.pro-screener-form').on('submit', function () {
        var custom = parseInt($(this).find('input[name="result_count_custom"]').val(), 10);
        if (!isNaN(custom) && custom > 0) {
            $(this).find('input[name="result_count"]:checked').prop('checked', false);
            $('<input>').attr({ type: 'hidden', name: 'result_count', value: custom }).appendTo(this);
        }
    });

    var anchor = document.getElementById('screener-run-results');
    if (anchor && (window.location.hash === '#screener-run-results' || /run_id=\d+/.test(window.location.search))) {
        window.requestAnimationFrame(function () {
            anchor.classList.add('pro-run-view-anchor--pulse');
            anchor.scrollIntoView({ behavior: 'smooth', block: 'start' });
            anchor.focus({ preventScroll: true });
            window.setTimeout(function () {
                anchor.classList.remove('pro-run-view-anchor--pulse');
            }, 2200);
        });
    }
})(jQuery);
