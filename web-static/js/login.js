(async function () {
    const user = await getCurrentUser();
    if (user) {
        window.location.href = '/app.html';
        return;
    }

    const form = document.getElementById('login-form');
    const messageEl = document.getElementById('login-message');

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        messageEl.hidden = true;

        const submitButton = form.querySelector('button[type="submit"]');
        submitButton.disabled = true;

        const { response, body } = await apiRequest('/login', {
            method: 'POST',
            body: JSON.stringify({
                username: form.username.value,
                password: form.password.value,
            }),
        });

        submitButton.disabled = false;

        if (response.ok) {
            window.location.href = '/app.html';
            return;
        }

        messageEl.textContent = (body && body.message) || 'Login failed.';
        messageEl.className = 'message message--error';
        messageEl.hidden = false;
    });
})();
