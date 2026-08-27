const API_BASE = '/app';
const THEME_STORAGE_KEY = 'themePreference';

// Set before first paint by an inline per-page <script>; this just keeps
// the footer <select> in sync with the same stored preference.
function initThemeSelect() {
    const select = document.getElementById('theme-select');
    if (!select) {
        return;
    }
    select.value = localStorage.getItem(THEME_STORAGE_KEY) || 'system';
    select.addEventListener('change', () => {
        const value = select.value;
        localStorage.setItem(THEME_STORAGE_KEY, value);
        document.documentElement.dataset.theme = value === 'system' ? '' : value;
    });
}

let redirectingToMaintenance = false;

/**
 * The single fetch wrapper every API-calling function funnels through.
 * On a 503 maintenance response, stashes the message/return path and
 * redirects to maintenance.html -- returning a Promise that never
 * resolves, so whatever the caller was about to do with the response
 * never runs while the page is already mid-navigation away.
 */
async function apiRequest(path, options = {}) {
    if (redirectingToMaintenance) {
        return new Promise(() => {});
    }

    const response = await fetch(API_BASE + path, {
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', ...(options.headers || {}) },
        ...options,
    });

    let body = null;
    try {
        body = await response.json();
    } catch {
        body = null;
    }

    if (response.status === 503 && body && body.status === 'maintenance') {
        redirectingToMaintenance = true;
        sessionStorage.setItem('maintenanceMessage', body.message || '');
        sessionStorage.setItem('maintenanceReturnTo', window.location.pathname);
        window.location.href = '/maintenance.html';
        return new Promise(() => {});
    }

    return { response, body };
}

async function getCurrentUser() {
    const { response, body } = await apiRequest('/me');
    return response.ok ? body.user : null;
}

document.addEventListener('DOMContentLoaded', () => {
    initThemeSelect();

    const versionEl = document.getElementById('app-version');
    if (versionEl) {
        fetch('/VERSION', { cache: 'no-store' })
            .then((r) => r.text())
            .then((v) => {
                versionEl.textContent = 'v' + v.trim();
            })
            .catch(() => {});
    }
});
