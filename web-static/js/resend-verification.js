(function () {
    const form = document.getElementById('resend-verification-form');
    const messageEl = document.getElementById('resend-verification-message');

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        messageEl.hidden = true;

        const submitButton = form.querySelector('button[type="submit"]');
        submitButton.disabled = true;

        const { response, body } = await apiRequest('/resend-verification', {
            method: 'POST',
            body: JSON.stringify({ email: form.email.value }),
        });

        submitButton.disabled = false;

        // Always shown as success -- enumeration-resistant, same reasoning
        // as forgot-password.js.
        messageEl.textContent = response.ok
            ? (body && body.message) || 'If an account with that email exists and needs verification, a new email has been sent.'
            : (body && body.message) || 'Something went wrong. Please try again.';
        messageEl.className = response.ok ? 'message message--success' : 'message message--error';
        messageEl.hidden = false;
    });
})();
