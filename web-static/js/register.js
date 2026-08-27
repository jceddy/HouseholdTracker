(async function () {
    // Gives apiRequest() a chance to catch a maintenance window before the
    // visitor ever submits the form.
    await getCurrentUser();

    const form = document.getElementById('register-form');
    const messageEl = document.getElementById('register-message');

    // Prefills from a household invite email's own registration link
    // (?email=...) -- convenience only, no security-bearing token involved;
    // see "Invite an unregistered email address" in php-app/README.md.
    const prefillEmail = new URLSearchParams(window.location.search).get('email');
    if (prefillEmail) {
        form.email.value = prefillEmail;
    }

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        messageEl.hidden = true;

        const submitButton = form.querySelector('button[type="submit"]');
        submitButton.disabled = true;

        const { response, body } = await apiRequest('/register', {
            method: 'POST',
            body: JSON.stringify({
                username: form.username.value,
                email: form.email.value,
                password: form.password.value,
            }),
        });

        submitButton.disabled = false;

        if (response.ok) {
            form.hidden = true;
            messageEl.textContent = (body && body.message) || 'Check your email to verify your account.';
            messageEl.className = 'message message--success';
            messageEl.hidden = false;
            return;
        }

        messageEl.textContent = (body && body.message) || 'Registration failed.';
        messageEl.className = 'message message--error';
        messageEl.hidden = false;
    });
})();
