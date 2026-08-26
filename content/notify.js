/*!
 * notify.js – Centralized Notification & Confirm-Modal System
 * Loaded globally via content/nav.php so every page has access.
 *
 * Toast API  : showSuccess(msg), showError(msg), showWarning(msg), showInfo(msg)
 * Confirm API: nmConfirm(msg) → Promise<bool>
 *              nmNavConfirm(event, msg)   – for <a> onclick
 *              nmBtnConfirm(event, msg)   – for <button type="submit"> onclick
 * Forms      : add data-confirm="..." attribute instead of onsubmit="return confirm(...)"
 */
(function () {
    // Guard: only define once even if a page loads an older notification.js too
    if (window.__nmLoaded) return;
    window.__nmLoaded = true;

    /* ──────────────────────────────────────────
       DOM SETUP
    ────────────────────────────────────────── */
    function setup() {
        // Toast container
        if (!document.getElementById('nm-toast-ct')) {
            var ct = document.createElement('div');
            ct.id = 'nm-toast-ct';
            document.body.appendChild(ct);
        }

        // Confirm modal
        if (!document.getElementById('nm-modal')) {
            var modal = document.createElement('div');
            modal.id = 'nm-modal';
            modal.setAttribute('role', 'dialog');
            modal.setAttribute('aria-modal', 'true');
            modal.innerHTML =
                '<div id="nm-modal-bg" onclick="window.nmCancelConfirm()"></div>' +
                '<div id="nm-modal-box">' +
                '  <div id="nm-modal-icon">' +
                '    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">' +
                '      <path d="M12 9v4m0 3.5h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>' +
                '    </svg>' +
                '  </div>' +
                '  <p id="nm-modal-msg"></p>' +
                '  <div id="nm-modal-btns">' +
                '    <button id="nm-modal-ok" onclick="window.nmOkConfirm()">Confirm</button>' +
                '    <button id="nm-modal-no" onclick="window.nmCancelConfirm()">Cancel</button>' +
                '  </div>' +
                '</div>';
            document.body.appendChild(modal);
        }

        // Keyboard handler: Escape = Cancel, Enter = Confirm
        document.addEventListener('keydown', function (e) {
            if (!document.getElementById('nm-modal').classList.contains('nm-modal-show')) return;
            if (e.key === 'Escape') window.nmCancelConfirm();
            if (e.key === 'Enter')  window.nmOkConfirm();
        });

        // Form data-confirm delegation (replaces onsubmit="return confirm(...)")
        document.addEventListener('submit', function (e) {
            var form = e.target;
            var msg = form.getAttribute('data-confirm');
            if (!msg) return;
            if (form._nmConfirmed) { form._nmConfirmed = false; return; }
            e.preventDefault();
            window.nmConfirm(msg).then(function (ok) {
                if (ok) { form._nmConfirmed = true; form.submit(); }
            });
        }, true);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', setup);
    } else {
        setup();
    }

    /* ──────────────────────────────────────────
       CONFIRM MODAL
    ────────────────────────────────────────── */
    var _resolve = null;

    window.nmConfirm = function (msg) {
        return new Promise(function (resolve) {
            _resolve = resolve;
            document.getElementById('nm-modal-msg').textContent = msg || 'Are you sure?';
            document.getElementById('nm-modal').classList.add('nm-modal-show');
            // Focus the OK button for keyboard accessibility
            setTimeout(function () {
                var ok = document.getElementById('nm-modal-ok');
                if (ok) ok.focus();
            }, 50);
        });
    };

    window.nmOkConfirm = function () {
        document.getElementById('nm-modal').classList.remove('nm-modal-show');
        if (_resolve) { _resolve(true); _resolve = null; }
    };

    window.nmCancelConfirm = function () {
        document.getElementById('nm-modal').classList.remove('nm-modal-show');
        if (_resolve) { _resolve(false); _resolve = null; }
    };

    /** For <a href="..."> onclick: nmNavConfirm(event, 'Are you sure?') */
    window.nmNavConfirm = function (event, message) {
        var href = (event.currentTarget || event.target).href;
        event.preventDefault();
        window.nmConfirm(message).then(function (ok) {
            if (ok) window.location.href = href;
        });
    };

    /** For <button type="submit"> onclick: nmBtnConfirm(event, 'Are you sure?') */
    window.nmBtnConfirm = function (event, message) {
        var btn  = event.currentTarget || event.target;
        var form = btn.form || (btn.closest ? btn.closest('form') : null);
        event.preventDefault();
        window.nmConfirm(message).then(function (ok) {
            if (!ok || !form) return;
            form._nmConfirmed = true;
            form.submit();
        });
        return false;
    };

    /* ──────────────────────────────────────────
       TOAST NOTIFICATIONS
    ────────────────────────────────────────── */
    function _esc(t) {
        var d = document.createElement('div');
        d.textContent = String(t || '');
        return d.innerHTML;
    }

    var _icons = {
        success: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20 6L9 17l-5-5"/></svg>',
        error:   '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M18 6L6 18M6 6l12 12"/></svg>',
        failed:  '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 8v5m0 3.5h.01"/><path d="M10.3 3.9l-8 14a1.5 1.5 0 001.3 2.2h16.8a1.5 1.5 0 001.3-2.2l-8-14a1.5 1.5 0 00-2.6 0z"/></svg>',
        warning: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 9v4m0 3.5h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>',
        info:    '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>',
        delete:  '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M3 6h18M8 6V4h8v2M19 6l-1 14H6L5 6"/></svg>',
        save:    '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20 6L9 17l-5-5"/></svg>',
        update:  '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>'
    };

    function _show(msg, type, dur) {
        var requestedType = String(type || 'info').toLowerCase();
        var mappedTypes = {
            danger: 'error',
            faild: 'failed'
        };
        var toastType = mappedTypes[requestedType] || requestedType;
        if (!_icons[toastType]) toastType = 'info';

        var ct = document.getElementById('nm-toast-ct');
        if (!ct) return;
        var el = document.createElement('div');
        el.className = 'nm-toast nm-toast-' + toastType;
        el.innerHTML =
            '<span class="nm-toast-icon">' + (_icons[toastType] || _icons.info) + '</span>' +
            '<span class="nm-toast-msg">'  + _esc(msg) + '</span>' +
            '<button class="nm-toast-x" onclick="this.parentElement.remove()">\u00D7</button>';
        ct.appendChild(el);
        requestAnimationFrame(function () {
            requestAnimationFrame(function () { el.classList.add('nm-toast-show'); });
        });
        var delay = (typeof dur === 'number') ? dur : 4000;
        setTimeout(function () {
            el.classList.remove('nm-toast-show');
            el.classList.add('nm-toast-out');
            setTimeout(function () { if (el.parentElement) el.remove(); }, 420);
        }, delay);
    }

    window.showSuccess = function (m, d) { _show(m, 'success', d != null ? d : 4000); };
    window.showError   = function (m, d) { _show(m, 'error',   d != null ? d : 5000); };
    window.showFailed  = function (m, d) { _show(m, 'failed',  d != null ? d : 5000); };
    window.showFaild   = function (m, d) { _show(m, 'failed',  d != null ? d : 5000); };
    window.showWarning = function (m, d) { _show(m, 'warning', d != null ? d : 5000); };
    window.showInfo    = function (m, d) { _show(m, 'info',    d != null ? d : 4000); };

    /* ── Action-specific solid-background toasts ── */
    window.showDelete = function (m, d) { _show(m, 'delete', d != null ? d : 4000); };
    window.showSave   = function (m, d) { _show(m, 'save',   d != null ? d : 3500); };
    window.showUpdate = function (m, d) { _show(m, 'update', d != null ? d : 3500); };

    /* Compat shims used by legacy bilty notification.js references */
    window.showNotification      = _show;
    window.closeNotification     = function (el) { if (el && el.parentElement) el.remove(); };
    window.clearAllNotifications = function () {
        var c = document.getElementById('nm-toast-ct');
        if (c) c.innerHTML = '';
    };
    /* Also expose old container id for bilty/create notification.js compat */
    window.createNotificationContainer = function () {
        if (!document.getElementById('notification-container')) {
            var alias = document.createElement('div');
            alias.id = 'notification-container';
            alias.style.display = 'none'; // hidden, nm-toast-ct is the real one
            document.body.appendChild(alias);
        }
    };
})();
