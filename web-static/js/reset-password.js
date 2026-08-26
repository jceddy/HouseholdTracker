(function () {
    const form = document.getElementById('reset-password-form');
    const messageEl = document.getElementById('reset-password-message');
    const token = new URLSearchParams(window.location.search).get('token');

    if (!token) {
        form.querySelector('button[type="submit"]').disabled = true;
        messageEl.textContent = 'This link is missing its reset token. Request a new one from the forgot password page.';
        messageEl.className = 'message message--error';
        messageEl.hidden = false;
        return;
    }

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        messageEl.hidden = true;

        const submitButton = form.querySelector('button[type="submit"]');
        submitButton.disabled = true;

        const { response, body } = await apiRequest('/reset-password', {
            method: 'POST',
            body: JSON.stringify({ token, password: form.password.value }),
        });

        submitButton.disabled = false;

        if (response.ok) {
            form.hidden = true;
            messageEl.textContent = (body && body.message) || 'Your password has been reset.';
            messageEl.className = 'message message--success';
            messageEl.hidden = false;
            return;
        }

        messageEl.textContent = (body && body.message) || 'Could not reset your password.';
        messageEl.className = 'message message--error';
        messageEl.hidden = false;
    });
})();
