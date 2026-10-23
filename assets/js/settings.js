(function ($) {
    'use strict';

    function flash($el, message, isError) {
        if (!$el.length) {
            return;
        }
        $el.removeClass('hidden border-emerald-500/30 bg-emerald-500/10 text-emerald-100 border-red-500/30 bg-red-500/10 text-red-100');
        if (isError) {
            $el.addClass('border border-red-500/30 bg-red-500/10 text-red-100');
        } else {
            $el.addClass('border border-emerald-500/30 bg-emerald-500/10 text-emerald-100');
        }
        $el.text(message).removeClass('hidden');
    }

    function formatMeta(meta) {
        if (!meta || typeof meta !== 'object') {
            return '';
        }
        var parts = [];
        if (meta.last_connection_test_at) {
            parts.push('Last connection test: ' + meta.last_connection_test_at + (meta.last_connection_test_ok ? ' (OK)' : ' (failed)'));
        }
        if (meta.last_test_email_at) {
            parts.push('Last test email: ' + meta.last_test_email_at);
        }
        return parts.join(' · ');
    }

    var $mailRoot = $('#settings-email-root');
    if ($mailRoot.length) {
        var mailCsrf = $mailRoot.data('csrf');
        var mailPublic = $mailRoot.data('public') || {};
        var testRecipient = $mailRoot.data('test-recipient') || '';
        var $flash = $('#settings-flash');

        function applyMail(pub) {
            mailPublic = pub;
            $('#mail-enabled').prop('checked', !!pub.enabled);
            $('#mail-host').val(pub.host || '');
            $('#mail-port').val(pub.port || 587);
            $('#mail-user').val(pub.username || '');
            $('#mail-encryption').val(pub.encryption || 'tls');
            $('#mail-from-email').val(pub.from_email || '');
            $('#mail-from-name').val(pub.from_name || '');
            $('#mail-reply').val(pub.reply_to || '');
            $('#mail-pass').val('');
            $('#mail-pass-hint').text(pub.password_set ? 'A password is saved. Enter a new value only to replace it.' : 'No password saved yet.');
            $('#mail-meta').text(formatMeta(pub.meta));
            var badge = $('#mail-status-badge');
            if (pub.enabled && pub.host) {
                badge.text('Configured').removeClass('text-slate-400 border-surface-border').addClass('text-emerald-300 border-emerald-500/40');
            } else {
                badge.text('Not configured').removeClass('text-emerald-300 border-emerald-500/40').addClass('text-slate-400 border-surface-border');
            }
        }

        applyMail(mailPublic);

        $('#form-mail-settings').on('submit', function (e) {
            e.preventDefault();
            var $btn = $(this).find('[type=submit]').prop('disabled', true);
            $.post('api/settings-mail-save.php', {
                _csrf: mailCsrf,
                enabled: $('#mail-enabled').is(':checked') ? '1' : '',
                host: $('#mail-host').val(),
                port: $('#mail-port').val(),
                username: $('#mail-user').val(),
                password: $('#mail-pass').val(),
                encryption: $('#mail-encryption').val(),
                from_email: $('#mail-from-email').val(),
                from_name: $('#mail-from-name').val(),
                reply_to: $('#mail-reply').val()
            }).done(function (res) {
                if (res.ok && res.settings) {
                    applyMail(res.settings);
                    flash($flash, 'SMTP settings saved.', false);
                } else {
                    flash($flash, res.error || 'Save failed.', true);
                }
            }).fail(function () {
                flash($flash, 'Could not save settings.', true);
            }).always(function () {
                $btn.prop('disabled', false);
            });
        });

        $('#btn-mail-test-conn').on('click', function () {
            var $btn = $(this).prop('disabled', true);
            $.post('api/settings-mail-test-connection.php', { _csrf: mailCsrf })
                .done(function (res) {
                    if (res.settings) {
                        applyMail(res.settings);
                    }
                    flash($flash, res.ok ? 'SMTP connection test succeeded.' : (res.error || 'Connection test failed.'), !res.ok);
                })
                .fail(function () {
                    flash($flash, 'Connection test request failed.', true);
                })
                .always(function () {
                    $btn.prop('disabled', false);
                });
        });

        $('#btn-mail-test-send').on('click', function () {
            var to = window.prompt('Send test email to:', testRecipient);
            if (to === null) {
                return;
            }
            to = String(to).trim();
            if (!to) {
                flash($flash, 'Test recipient is required.', true);
                return;
            }
            if (!window.confirm('Send a real test email to ' + to + '?')) {
                return;
            }
            var $btn = $(this).prop('disabled', true);
            $.post('api/settings-mail-send-test.php', {
                _csrf: mailCsrf,
                confirmed: '1',
                test_recipient: to
            }).done(function (res) {
                if (res.settings) {
                    applyMail(res.settings);
                }
                flash($flash, res.ok ? 'Test email sent.' : (res.error || 'Send failed.'), !res.ok);
            }).fail(function () {
                flash($flash, 'Send test request failed.', true);
            }).always(function () {
                $btn.prop('disabled', false);
            });
        });
    }

    var $upRoot = $('#settings-upstox-root');
    if ($upRoot.length) {
        var upCsrf = $upRoot.data('csrf');
        var upPublic = $upRoot.data('public') || {};
        var defaultRedirect = $upRoot.data('default-redirect') || '';
        var $flashU = $('#settings-flash');

        function applyUpstox(pub) {
            upPublic = pub;
            $('#upstox-client-id').val(pub.client_id || '');
            $('#upstox-redirect').val(pub.redirect_uri || defaultRedirect);
            $('#upstox-default-key').val(pub.default_instrument_key || '');
            $('#upstox-default-symbol').val(pub.default_symbol || '');
            $('#upstox-default-name').val(pub.default_name || '');
            $('#upstox-client-secret').val('');
            $('#upstox-token').val('');
            $('#upstox-secret-hint').text(pub.client_secret_set ? 'A client secret is saved.' : 'No client secret saved.');
            var tokenHint = pub.access_token_set ? 'Access token is saved.' : 'No access token — authorize with Upstox or paste manually.';
            if (pub.access_token_expires_at) {
                tokenHint += ' Expires (UTC): ' + pub.access_token_expires_at + '.';
            }
            if (pub.access_token_expired) {
                tokenHint += ' Token appears expired — re-authorize.';
            }
            $('#upstox-token-hint').text(tokenHint);
            $('#upstox-meta').text(formatMeta(pub.meta));
            var badge = $('#upstox-status-badge');
            badge.removeClass('text-emerald-300 text-amber-300 text-red-300 border-emerald-500/40 border-amber-500/40 border-red-500/40 text-slate-400 border-surface-border');
            if (pub.api_last_test_ok) {
                badge.text('API verified').addClass('text-emerald-300 border-emerald-500/40');
            } else if (pub.access_token_expired) {
                badge.text('Token expired').addClass('text-amber-300 border-amber-500/40');
            } else if (pub.access_token_set) {
                badge.text('Token saved — test failed').addClass('text-amber-300 border-amber-500/40');
            } else {
                badge.text('Not connected').addClass('text-slate-400 border-surface-border');
            }
            var $redirectWarn = $('#upstox-redirect-warn');
            if ($redirectWarn.length) {
                if (pub.redirect_uri_mismatch && pub.recommended_redirect_uri) {
                    $redirectWarn.removeClass('hidden').find('[data-recommended]').text(pub.recommended_redirect_uri);
                } else {
                    $redirectWarn.addClass('hidden');
                }
            }
        }

        applyUpstox(upPublic);

        $('#btn-upstox-use-redirect').on('click', function () {
            var uri = upPublic.recommended_redirect_uri || defaultRedirect;
            if (uri) {
                $('#upstox-redirect').val(uri);
            }
        });

        $('#form-upstox-settings').on('submit', function (e) {
            e.preventDefault();
            var $btn = $(this).find('[type=submit]').prop('disabled', true);
            $.post('api/settings-upstox-save.php', {
                _csrf: upCsrf,
                client_id: $('#upstox-client-id').val(),
                client_secret: $('#upstox-client-secret').val(),
                access_token: $('#upstox-token').val(),
                redirect_uri: $('#upstox-redirect').val(),
                default_instrument_key: $('#upstox-default-key').val(),
                default_symbol: $('#upstox-default-symbol').val(),
                default_name: $('#upstox-default-name').val()
            }).done(function (res) {
                if (res.ok && res.settings) {
                    applyUpstox(res.settings);
                    flash($flashU, 'Upstox settings saved.', false);
                } else {
                    flash($flashU, res.error || 'Save failed.', true);
                }
            }).fail(function () {
                flash($flashU, 'Could not save settings.', true);
            }).always(function () {
                $btn.prop('disabled', false);
            });
        });

        $('#btn-upstox-test').on('click', function () {
            var $btn = $(this).prop('disabled', true);
            $.post('api/settings-upstox-test.php', { _csrf: upCsrf })
                .done(function (res) {
                    if (res.settings) {
                        applyUpstox(res.settings);
                    }
                    var msg = res.ok ? 'Upstox API connection OK.' : (res.error || 'Test failed.');
                    if (res.ok && res.detail && res.detail.user_id) {
                        msg += ' User ID: ' + res.detail.user_id + '.';
                    }
                    flash($flashU, msg, !res.ok);
                })
                .fail(function () {
                    flash($flashU, 'Test request failed.', true);
                })
                .always(function () {
                    $btn.prop('disabled', false);
                });
        });

        $('#btn-upstox-clear').on('click', function () {
            if (!window.confirm('Clear stored API secret and access token? Client ID and redirect URI will be kept.')) {
                return;
            }
            var $btn = $(this).prop('disabled', true);
            $.post('api/settings-upstox-clear.php', { _csrf: upCsrf, confirmed: '1' })
                .done(function (res) {
                    if (res.settings) {
                        applyUpstox(res.settings);
                    }
                    flash($flashU, res.ok ? 'Secrets cleared.' : (res.error || 'Clear failed.'), !res.ok);
                })
                .fail(function () {
                    flash($flashU, 'Clear request failed.', true);
                })
                .always(function () {
                    $btn.prop('disabled', false);
                });
        });
    }
})(jQuery);
