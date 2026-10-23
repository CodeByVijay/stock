(function () {
    'use strict';

    var sidebar = document.getElementById('portal-sidebar');
    var overlay = document.getElementById('portal-nav-overlay');
    var openBtn = document.getElementById('portal-nav-open');
    var closeBtn = document.getElementById('portal-nav-close');
    var shell = document.querySelector('.portal-shell');

    function notifyLayoutChange() {
        window.dispatchEvent(new Event('resize'));
        window.dispatchEvent(new CustomEvent('portal:layout-change'));
    }

    function isDrawerMode() {
        return window.matchMedia('(max-width: 600px)').matches;
    }

    function setMenuExpanded(expanded) {
        if (!openBtn) {
            return;
        }
        openBtn.setAttribute('aria-expanded', expanded ? 'true' : 'false');
        openBtn.setAttribute(
            'aria-label',
            expanded ? 'Close navigation menu' : 'Open navigation menu'
        );
    }

    function openDrawer() {
        if (!sidebar || !overlay) {
            return;
        }
        sidebar.classList.add('is-open');
        overlay.classList.add('is-visible');
        overlay.setAttribute('aria-hidden', 'false');
        document.body.classList.add('portal-nav-open');
        setMenuExpanded(true);
    }

    function closeDrawer() {
        if (!sidebar || !overlay) {
            return;
        }
        sidebar.classList.remove('is-open');
        overlay.classList.remove('is-visible');
        overlay.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('portal-nav-open');
        setMenuExpanded(false);
        notifyLayoutChange();
    }

    function isSidebarVisible() {
        if (isDrawerMode()) {
            return sidebar && sidebar.classList.contains('is-open');
        }
        return shell && !shell.classList.contains('is-sidebar-collapsed');
    }

    function toggleNav() {
        if (!sidebar) {
            return;
        }
        if (isDrawerMode()) {
            if (sidebar.classList.contains('is-open')) {
                closeDrawer();
            } else {
                openDrawer();
            }
            return;
        }
        if (!shell) {
            return;
        }
        shell.classList.toggle('is-sidebar-collapsed');
        setMenuExpanded(!shell.classList.contains('is-sidebar-collapsed'));
        notifyLayoutChange();
    }

    function resetNavForBreakpoint() {
        if (!sidebar) {
            return;
        }
        if (isDrawerMode()) {
            if (shell) {
                shell.classList.remove('is-sidebar-collapsed');
            }
            closeDrawer();
        } else {
            sidebar.classList.remove('is-open');
            if (overlay) {
                overlay.classList.remove('is-visible');
                overlay.setAttribute('aria-hidden', 'true');
            }
            document.body.classList.remove('portal-nav-open');
            setMenuExpanded(isSidebarVisible());
            notifyLayoutChange();
        }
    }

    if (openBtn) {
        openBtn.addEventListener('click', function () {
            toggleNav();
        });
    }

    if (closeBtn) {
        closeBtn.addEventListener('click', function () {
            if (isDrawerMode()) {
                closeDrawer();
            }
        });
    }

    if (overlay) {
        overlay.addEventListener('click', function () {
            if (isDrawerMode()) {
                closeDrawer();
            }
        });
    }

    if (sidebar) {
        sidebar.addEventListener('click', function (e) {
            var link = e.target.closest('a.portal-nav-link');
            if (link && isDrawerMode()) {
                closeDrawer();
            }
        });
    }

    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') {
            return;
        }
        if (isDrawerMode() && sidebar && sidebar.classList.contains('is-open')) {
            closeDrawer();
            return;
        }
        if (!isDrawerMode() && shell && !shell.classList.contains('is-sidebar-collapsed')) {
            shell.classList.add('is-sidebar-collapsed');
            setMenuExpanded(false);
            notifyLayoutChange();
        }
    });

    var resizeTimer;
    window.addEventListener('resize', function () {
        window.clearTimeout(resizeTimer);
        resizeTimer = window.setTimeout(resetNavForBreakpoint, 100);
    });

    setMenuExpanded(isSidebarVisible());
})();
