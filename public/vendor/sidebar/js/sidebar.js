/*!
 * Sidebar Menu Template — FathyHesham (https://github.com/FathyHesham/Sidebar-Menu-Template)
 * Comportamiento integrado al panel de "Una Barbería Muy Chimbita".
 *
 * - Escritorio/portátil (>= 992px): expandido por defecto; el botón lo contrae
 *   y la elección se recuerda en este navegador.
 * - Tablet/teléfono: oculto; el botón hamburguesa lo abre con overlay.
 *   Se cierra con el overlay, un clic fuera, la tecla Escape, el botón del
 *   encabezado o al elegir una opción.
 * - Buscador del menú y modo oscuro de todo el panel (también recordado).
 * Se inicializa una sola vez aunque el archivo se cargue más de una vez.
 */
(function () {
    'use strict';

    if (window.ChimbitaSidebar) return;

    var KEY_COLLAPSED = 'sidebar:collapsed';
    var KEY_THEME = 'admin:theme';
    var DESKTOP = '(min-width: 992px)';

    var store = {
        get: function (k) { try { return window.localStorage.getItem(k); } catch (e) { return null; } },
        set: function (k, v) { try { window.localStorage.setItem(k, v); } catch (e) { /* modo privado */ } }
    };

    function normalize(text) {
        return (text || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').trim();
    }

    function init() {
        var sidebar = document.getElementById('sidebar');
        if (!sidebar || sidebar.dataset.sidebarReady === '1') return;
        sidebar.dataset.sidebarReady = '1';

        var root = document.documentElement;
        var body = document.body;
        var mq = window.matchMedia(DESKTOP);
        var overlay = document.querySelector('[data-sidebar-overlay]');
        var openers = Array.prototype.slice.call(document.querySelectorAll('[data-sidebar-open]'));
        var collapseBtn = sidebar.querySelector('[data-sidebar-collapse]');
        var lastFocus = null;

        function isDesktop() { return mq.matches; }
        function isMobileOpen() { return sidebar.classList.contains('is-open'); }

        /* ---------- Escritorio: contraer / expandir ---------- */
        function setCollapsed(collapsed, persist) {
            sidebar.classList.toggle('close', collapsed);
            if (collapseBtn) {
                var label = collapsed ? 'Expandir menú' : 'Contraer menú';
                collapseBtn.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
                collapseBtn.setAttribute('aria-label', label);
                collapseBtn.setAttribute('title', label);
            }
            if (persist) store.set(KEY_COLLAPSED, collapsed ? '1' : '0');
        }

        /* ---------- Móvil: abrir / cerrar ---------- */
        function setMobileOpen(open, restoreFocus) {
            if (open === isMobileOpen()) return;
            sidebar.classList.toggle('is-open', open);
            body.classList.toggle('sidebar-open', open);
            if (overlay) overlay.setAttribute('aria-hidden', open ? 'false' : 'true');
            openers.forEach(function (b) { b.setAttribute('aria-expanded', open ? 'true' : 'false'); });
            if (collapseBtn && !isDesktop()) {
                collapseBtn.setAttribute('aria-label', 'Cerrar menú');
                collapseBtn.setAttribute('title', 'Cerrar menú');
            }
            if (open) {
                lastFocus = document.activeElement;
                var target = sidebar.querySelector('.menu-link.active') || sidebar.querySelector('.menu-link');
                if (target) window.setTimeout(function () { target.focus({ preventScroll: true }); }, 60);
            } else if (restoreFocus && lastFocus && typeof lastFocus.focus === 'function') {
                lastFocus.focus({ preventScroll: true });
            }
        }

        /* Estado según el tamaño de pantalla */
        function syncViewport() {
            if (isDesktop()) {
                setMobileOpen(false, false);
                setCollapsed(store.get(KEY_COLLAPSED) === '1', false);
            } else {
                setMobileOpen(false, false);
                if (collapseBtn) {
                    collapseBtn.setAttribute('aria-label', 'Cerrar menú');
                    collapseBtn.setAttribute('title', 'Cerrar menú');
                }
            }
        }
        syncViewport();
        if (mq.addEventListener) mq.addEventListener('change', syncViewport);
        else if (mq.addListener) mq.addListener(syncViewport);

        if (collapseBtn) {
            collapseBtn.addEventListener('click', function () {
                if (isDesktop()) setCollapsed(!sidebar.classList.contains('close'), true);
                else setMobileOpen(false, true);
            });
        }

        openers.forEach(function (btn) {
            btn.addEventListener('click', function (e) {
                e.stopPropagation();
                if (!isDesktop()) setMobileOpen(!isMobileOpen(), true);
            });
        });

        if (overlay) overlay.addEventListener('click', function () { setMobileOpen(false, true); });

        // Clic fuera del menú (por si el overlay no recibe el evento).
        document.addEventListener('click', function (e) {
            if (isDesktop() || !isMobileOpen()) return;
            if (sidebar.contains(e.target)) return;
            if (openers.some(function (b) { return b.contains(e.target); })) return;
            setMobileOpen(false, false);
        });

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && !isDesktop() && isMobileOpen()) setMobileOpen(false, true);
        });

        // Al elegir una opción en móvil se cierra el menú.
        sidebar.addEventListener('click', function (e) {
            var link = e.target.closest('a.menu-link, a.sidebar-icon-btn');
            if (link && !isDesktop()) setMobileOpen(false, false);
        });

        /* ---------- Buscador ---------- */
        var searchForm = sidebar.querySelector('.search-form');
        var search = sidebar.querySelector('.search-form .search');
        var empty = sidebar.querySelector('[data-sidebar-empty]');

        function filterMenu() {
            var q = normalize(search.value);
            var shown = 0;
            sidebar.querySelectorAll('.menu-section').forEach(function (section) {
                var visible = 0;
                section.querySelectorAll('.menu-item').forEach(function (item) {
                    var link = item.querySelector('.menu-link');
                    var text = normalize((link && (link.dataset.label || link.textContent)) || '');
                    var ok = !q || text.indexOf(q) !== -1;
                    item.hidden = !ok;
                    if (ok) visible++;
                });
                section.hidden = visible === 0;
                shown += visible;
            });
            if (empty) empty.hidden = shown !== 0;
        }

        if (searchForm && search) {
            searchForm.addEventListener('submit', function (e) { e.preventDefault(); });
            search.addEventListener('input', filterMenu);
            search.addEventListener('search', filterMenu);
            search.addEventListener('keydown', function (e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    var first = sidebar.querySelector('.menu-item:not([hidden]) .menu-link');
                    if (first) window.location.href = first.href;
                } else if (e.key === 'Escape' && search.value) {
                    e.stopPropagation();
                    search.value = '';
                    filterMenu();
                }
            });
            // Igual que el original: con el menú contraído, el buscador lo expande.
            searchForm.addEventListener('click', function () {
                if (isDesktop() && sidebar.classList.contains('close')) {
                    setCollapsed(false, true);
                    window.setTimeout(function () { search.focus(); }, 380);
                }
            });
        }

        /* ---------- Modo oscuro ---------- */
        var themeBtn = sidebar.querySelector('.button-change-theme');
        function syncTheme() {
            var dark = root.classList.contains('admin-dark');
            if (!themeBtn) return;
            themeBtn.setAttribute('aria-pressed', dark ? 'true' : 'false');
            var text = themeBtn.querySelector('.theme-text');
            var icon = themeBtn.querySelector('.icon-theme i');
            if (text) text.textContent = dark ? 'Modo claro' : 'Modo oscuro';
            if (icon) icon.className = dark ? 'bi bi-sun' : 'bi bi-moon-stars';
            themeBtn.setAttribute('title', dark ? 'Cambiar a modo claro' : 'Cambiar a modo oscuro');
            themeBtn.setAttribute('aria-label', dark ? 'Cambiar a modo claro' : 'Cambiar a modo oscuro');
        }
        if (themeBtn) {
            themeBtn.addEventListener('click', function () {
                var dark = !root.classList.contains('admin-dark');
                root.classList.toggle('admin-dark', dark);
                if (dark) root.setAttribute('data-bs-theme', 'dark');
                else root.removeAttribute('data-bs-theme');
                store.set(KEY_THEME, dark ? 'dark' : 'light');
                syncTheme();
                document.dispatchEvent(new CustomEvent('admin:themechange', { detail: { dark: dark } }));
            });
        }
        syncTheme();

        // Activa las animaciones después del primer pintado (sin saltos al cargar).
        var enableMotion = function () { root.classList.remove('sidebar-preload'); };
        window.requestAnimationFrame(function () { window.requestAnimationFrame(enableMotion); });
        window.setTimeout(enableMotion, 150);

        window.ChimbitaSidebar = {
            open: function () { setMobileOpen(true, true); },
            close: function () { setMobileOpen(false, true); },
            collapse: function (v) { if (isDesktop()) setCollapsed(v !== false, true); }
        };
    }

    window.ChimbitaSidebar = { ready: false };
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init, { once: true });
    else init();
})();
