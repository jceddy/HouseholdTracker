(function () {
    const messageEl = document.getElementById('maintenance-message');
    const returnTo = sessionStorage.getItem('maintenanceReturnTo') || '/';
    messageEl.textContent = sessionStorage.getItem('maintenanceMessage')
        || 'HouseholdTracker is being updated and will be back shortly. Please try again in a few minutes.';

    async function checkIfBack() {
        try {
            const response = await fetch('/app/me', { credentials: 'same-origin' });
            if (response.status !== 503) {
                window.location.href = returnTo;
            }
        } catch {
            // Network hiccup -- just wait for the next poll.
        }
    }

    document.getElementById('retry-button').addEventListener('click', checkIfBack);
    setInterval(checkIfBack, 15000);
})();
