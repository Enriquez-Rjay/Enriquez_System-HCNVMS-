// js/auth.js
// Simple client-side validation for the login form.
(function () {
    const form = document.getElementById('loginForm');
    const errorBox = document.getElementById('loginError');
    // show server-side error from URL param `error`
    try {
        const params = new URLSearchParams(window.location.search);
        const serverError = params.get('error');
        if (serverError && errorBox) {
            errorBox.textContent = decodeURIComponent(serverError);
            errorBox.classList.remove('hidden');
        }
    } catch (e) {
        // ignore
    }

    if (!form) return;
    form.addEventListener('submit', function (e) {
        const uEl = document.getElementById('username');
        const pEl = document.getElementById('password');
        const u = uEl ? uEl.value.trim() : '';
        const p = pEl ? pEl.value : '';
        if (!u || !p) {
            e.preventDefault();
            if (errorBox) {
                errorBox.textContent = 'Please enter username and password.';
                errorBox.classList.remove('hidden');
            } else {
                alert('Please enter username and password.');
            }
            return false;
        }
        // optionally disable button to avoid double submits
        const btn = form.querySelector('button[type=submit]');
        if (btn) btn.disabled = true;
    });
})();
