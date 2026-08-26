(function () {
    const form = document.getElementById('forgot-password-form');
    const messageEl = document.getElementById('forgot-password-message');

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        messageEl.hidden = true;

        const submitButton = form.querySelector('button[type="submit"]');
        submitButton.disabled = true;

        const { response, body } = await apiRequest('/forgot-password', {
            method: 'POST',
            body: JSON.stringify({ email: form.email.value }),
        });

        submitButton.disabled = false;

        // Always shown as success -- the API itself is enumeration-resistant
        // (same generic response whether or not the address is registered).
        messageEl.textContent = response.ok
            ? (body && body.message) || 'If an account with that email exists, a reset link has been sent.'
            : (body && body.message) || 'Something went wrong. Please try again.';
        messageEl.className = response.ok ? 'message message--success' : 'message message--error';
        messageEl.hidden = false;
    });
})();
