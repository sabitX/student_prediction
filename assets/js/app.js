// SPTS — shared behaviour for every page.
//  • Prevents double submission of forms (double-clicks, impatient users).
//  • Keeps pop-up dialogs (Bootstrap modals) clickable.
//  • Labels table cells so tables read as cards on phones (app.css).
//  • Adds a menu button that opens the sidebar on small screens.
//  • Adds a "skip to content" link for keyboard users.
//  • Shows a one-time notice (e.g. "Access denied") left by the server.
(function () {
    'use strict';

    // ── Modals above their backdrop ──────────────────────────────────
    // .layout-wrapper has its own z-index, which traps any modal inside it
    // below Bootstrap's backdrop (added to <body>): the dialog shows but
    // greyed out, and clicks hit the backdrop. Moving each modal to <body>
    // as it opens puts it back on top. Listeners and ids move with it.
    document.addEventListener('show.bs.modal', function (e) {
        if (e.target.parentElement !== document.body) document.body.appendChild(e.target);
    });

    // ── Double-submit guard ──────────────────────────────────────────
    // Runs after any inline onsubmit="return confirm(...)" handler; if
    // that cancelled the submit, nothing happens. Buttons are disabled on
    // the next tick so their name/value is still included in the request.
    document.addEventListener('submit', function (e) {
        const form = e.target;
        if (e.defaultPrevented || form.method.toLowerCase() === 'get') return;
        if (form.dataset.submitting === '1') {
            e.preventDefault();
            return;
        }
        form.dataset.submitting = '1';
        setTimeout(function () {
            form.querySelectorAll('button[type="submit"], button:not([type])').forEach(function (btn) {
                btn.disabled = true;
                btn.classList.add('is-submitting');
            });
        }, 0);
    });

    // If the browser restores the page from cache (Back button), re-enable.
    window.addEventListener('pageshow', function (e) {
        if (!e.persisted) return;
        document.querySelectorAll('form[data-submitting]').forEach(function (form) {
            delete form.dataset.submitting;
            form.querySelectorAll('button.is-submitting').forEach(function (btn) {
                btn.disabled = false;
                btn.classList.remove('is-submitting');
            });
        });
    });

    // ── One-time notice ──────────────────────────────────────────────
    // includes/session_check.php sets the spts_notice cookie ("type|text")
    // when it redirects someone away from a page they can't open.
    function showNotice() {
        const match = document.cookie.match(/(?:^|;\s*)spts_notice=([^;]*)/);
        if (!match) return;
        document.cookie = 'spts_notice=; path=/; max-age=0; SameSite=Lax';

        let raw;
        try { raw = decodeURIComponent(match[1]); } catch (e) { return; }
        const sep  = raw.indexOf('|');
        const type = sep > 0 ? raw.slice(0, sep) : 'info';
        const text = sep > 0 ? raw.slice(sep + 1) : raw;
        if (!text) return;

        const denied = type === 'denied';
        const toast = document.createElement('div');
        toast.className = 'spts-notice' + (denied ? ' spts-notice-denied' : '');
        toast.setAttribute('role', denied ? 'alert' : 'status');

        const icon = document.createElement('i');
        icon.className = 'bi ' + (denied ? 'bi-shield-lock-fill' : 'bi-info-circle-fill');
        icon.setAttribute('aria-hidden', 'true');

        const body = document.createElement('div');
        body.className = 'spts-notice-body';
        if (denied) {
            const title = document.createElement('strong');
            title.textContent = 'Access denied';
            body.appendChild(title);
        }
        const msg = document.createElement('span');
        msg.textContent = denied ? text.replace(/^Access denied\s*—\s*/, '') : text;
        body.appendChild(msg);

        const close = document.createElement('button');
        close.type = 'button';
        close.className = 'spts-notice-close';
        close.setAttribute('aria-label', 'Dismiss');
        close.innerHTML = '<i class="bi bi-x-lg" aria-hidden="true"></i>';

        toast.append(icon, body, close);
        document.body.appendChild(toast);

        function dismiss() {
            toast.classList.add('is-leaving');
            setTimeout(function () { toast.remove(); }, 250);
        }
        close.addEventListener('click', dismiss);
        setTimeout(dismiss, 7000);
    }

    // ── Phone-friendly tables ────────────────────────────────────────
    // Gives every cell of a table inside .table-responsive a data-label
    // (its column heading). On phones app.css then shows each row as a
    // card of "label: value" lines. Tables with .table-scroll are skipped.
    function labelTables() {
        document.querySelectorAll('.table-responsive > table:not(.table-scroll)').forEach(function (table) {
            const head = table.tHead && table.tHead.rows[table.tHead.rows.length - 1];
            if (!head) return;
            const labels = [];
            Array.from(head.cells).forEach(function (th) {
                // innerText turns <br> into a break, so "Midterm<br>(30%)"
                // becomes "Midterm (30%)" rather than "Midterm(30%)".
                const text = (th.innerText || th.textContent).replace(/\s+/g, ' ').trim();
                for (let i = 0; i < (th.colSpan || 1); i++) labels.push(text === '#' ? 'No.' : text);
            });
            table.classList.add('table-stack');
            Array.from(table.tBodies).forEach(function (body) {
                Array.from(body.rows).forEach(function (row) {
                    let col = 0;
                    Array.from(row.cells).forEach(function (cell) {
                        if (!cell.hasAttribute('data-label')) {
                            cell.setAttribute('data-label', cell.colSpan > 1 ? '' : (labels[col] || ''));
                        }
                        col += cell.colSpan || 1;
                    });
                });
            });
        });
    }

    // "SPTS — Nilai University" → "SPTS" + a part that narrow screens hide.
    function splitBrand() {
        document.querySelectorAll('.topbar .brand').forEach(function (brand) {
            brand.childNodes.forEach(function (node) {
                if (node.nodeType !== Node.TEXT_NODE || node.textContent.indexOf(' — ') === -1) return;
                // One wrapper, so the brand's flex gap doesn't open up a
                // space between "SPTS" and " — Nilai University".
                const at = node.textContent.indexOf(' — ');
                const wrap = document.createElement('span');
                const long = document.createElement('span');
                long.className = 'brand-long';
                long.textContent = node.textContent.slice(at);
                wrap.append(node.textContent.slice(0, at), long);
                node.replaceWith(wrap);
            });
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        showNotice();
        splitBrand();
        labelTables();
        // Some pages add rows later (search results, live updates).
        let queued = false;
        new MutationObserver(function () {
            if (queued) return;
            queued = true;
            requestAnimationFrame(function () { queued = false; labelTables(); });
        }).observe(document.body, { childList: true, subtree: true });

        const main = document.querySelector('main, .main-content');
        if (main) {
            if (!main.id) main.id = 'main-content';
            const skip = document.createElement('a');
            skip.href = '#' + main.id;
            skip.className = 'skip-link';
            skip.textContent = 'Skip to content';
            document.body.prepend(skip);
        }

        // ── Mobile sidebar ──────────────────────────────────────────
        const sidebar = document.querySelector('.sidebar');
        const brand   = document.querySelector('.topbar .brand');
        if (!sidebar || !brand) return;

        if (!sidebar.id) sidebar.id = 'app-sidebar';
        const toggle = document.createElement('button');
        toggle.type = 'button';
        toggle.className = 'sidebar-toggle';
        toggle.setAttribute('aria-label', 'Open menu');
        toggle.setAttribute('aria-controls', sidebar.id);
        toggle.setAttribute('aria-expanded', 'false');
        toggle.innerHTML = '<i class="bi bi-list" aria-hidden="true"></i>';
        brand.parentNode.insertBefore(toggle, brand);

        const backdrop = document.createElement('div');
        backdrop.className = 'sidebar-backdrop';
        document.body.appendChild(backdrop);

        function setOpen(open) {
            document.body.classList.toggle('sidebar-open', open);
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
            toggle.setAttribute('aria-label', open ? 'Close menu' : 'Open menu');
        }
        toggle.addEventListener('click', function () {
            setOpen(!document.body.classList.contains('sidebar-open'));
        });
        backdrop.addEventListener('click', function () { setOpen(false); });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') setOpen(false);
        });
    });
})();
