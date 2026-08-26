(async function () {
    const user = await getCurrentUser();
    if (!user) {
        window.location.href = '/';
        return;
    }

    document.getElementById('current-username').textContent = user.username;

    document.getElementById('logout-button').addEventListener('click', async () => {
        await apiRequest('/logout', { method: 'POST' });
        window.location.href = '/';
    });
})();
