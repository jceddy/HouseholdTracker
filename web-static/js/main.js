(async function () {
    let currentHouseholdId = null;

    const user = await getCurrentUser();
    if (!user) {
        window.location.href = '/';
        return;
    }

    document.getElementById('current-username').textContent = user.username;

    function buildListItem(label) {
        const li = document.createElement('li');
        const labelEl = document.createElement('span');
        labelEl.textContent = label;
        const actions = document.createElement('span');
        actions.className = 'li-actions';
        li.appendChild(labelEl);
        li.appendChild(actions);
        return { li, actions };
    }

    function buildButton(text, onClick) {
        const button = document.createElement('button');
        button.type = 'button';
        button.textContent = text;
        button.addEventListener('click', onClick);
        return button;
    }

    async function loadInvites() {
        const { response, body } = await apiRequest('/households/invites');
        const list = document.getElementById('invites-list');
        const section = document.getElementById('invites-section');
        list.innerHTML = '';

        if (!response.ok || !body.invites || body.invites.length === 0) {
            section.hidden = true;
            return;
        }

        section.hidden = false;
        for (const invite of body.invites) {
            const { li, actions } = buildListItem(`${invite.household_name} — invited by ${invite.invited_by_username}`);
            actions.appendChild(buildButton('Accept', () => respondToInvite(invite.id, 'accept')));
            actions.appendChild(buildButton('Decline', () => respondToInvite(invite.id, 'decline')));
            list.appendChild(li);
        }
    }

    async function respondToInvite(inviteId, action) {
        await apiRequest('/households/invites/respond', {
            method: 'POST',
            body: JSON.stringify({ invite_id: inviteId, action }),
        });
        await loadInvites();
        await loadHouseholds();
    }

    async function loadHouseholds() {
        const { response, body } = await apiRequest('/households');
        const list = document.getElementById('households-list');
        list.innerHTML = '';

        if (!response.ok) {
            return;
        }

        if (body.households.length === 0) {
            const li = document.createElement('li');
            li.textContent = 'You are not part of any household yet. Create one below.';
            list.appendChild(li);
            return;
        }

        for (const household of body.households) {
            const { li, actions } = buildListItem(`${household.name} (${household.role})`);
            actions.appendChild(buildButton('View', () => openHouseholdDetail(household.id, household.name)));
            list.appendChild(li);
        }
    }

    async function openHouseholdDetail(householdId, householdName) {
        currentHouseholdId = householdId;
        document.getElementById('household-detail-name').textContent = householdName;
        document.getElementById('household-settings-name').value = householdName;
        document.getElementById('household-detail-section').hidden = false;
        // Hidden while a household's detail is open -- shown underneath the
        // detail panel, it was easy to mistake for part of it and got in the
        // way of the invite form.
        document.getElementById('create-household-section').hidden = true;
        await loadMembers(householdId);
        await loadNotes(householdId);
        await loadPets(householdId);
    }

    function closeHouseholdDetail() {
        document.getElementById('household-detail-section').hidden = true;
        document.getElementById('create-household-section').hidden = false;
        currentHouseholdId = null;
    }

    async function loadMembers(householdId) {
        const { response, body } = await apiRequest('/households/members?household_id=' + householdId);
        const list = document.getElementById('household-members-list');
        list.innerHTML = '';

        if (!response.ok) {
            return;
        }

        const isOwner = body.members.some((member) => member.user_id === user.id && member.role === 'owner');

        for (const member of body.members) {
            const { li, actions } = buildListItem(`${member.username} (${member.role})`);
            const isSelf = member.user_id === user.id;
            // Only the owner can remove other members (self-leave is always
            // allowed) -- see HouseholdService::removeMember(). A non-owner
            // gets no button at all for other members, rather than one that
            // just fails when clicked.
            if (isSelf || isOwner) {
                actions.appendChild(buildButton(isSelf ? 'Leave' : 'Remove', async () => {
                    await apiRequest('/households/members/remove', {
                        method: 'POST',
                        body: JSON.stringify({ household_id: householdId, user_id: member.user_id }),
                    });
                    if (isSelf) {
                        closeHouseholdDetail();
                    } else {
                        await loadMembers(householdId);
                    }
                    await loadHouseholds();
                }));
            }
            list.appendChild(li);
        }
    }

    async function loadNotes(householdId) {
        const { response, body } = await apiRequest('/households/notes?household_id=' + householdId);
        const list = document.getElementById('household-notes-list');
        list.innerHTML = '';

        if (!response.ok) {
            return;
        }

        for (const note of body.notes) {
            const label = `${note.author_username} (${note.visibility}): ${note.body}`;
            const { li, actions } = buildListItem(label);
            if (Number(note.author_user_id) === user.id) {
                actions.appendChild(buildButton('Delete', async () => {
                    await apiRequest('/households/notes/delete', {
                        method: 'POST',
                        body: JSON.stringify({ note_id: note.id }),
                    });
                    await loadNotes(householdId);
                }));
            }
            list.appendChild(li);
        }
    }

    async function loadPets(householdId) {
        const { response, body } = await apiRequest('/households/pets?household_id=' + householdId);
        const list = document.getElementById('household-pets-list');
        list.innerHTML = '';

        if (!response.ok) {
            return;
        }

        for (const pet of body.pets) {
            const details = [pet.species, pet.breed, pet.birthday].filter(Boolean).join(', ');
            const label = details ? `${pet.name} (${details})` : pet.name;
            const { li, actions } = buildListItem(label);
            actions.appendChild(buildButton('Delete', async () => {
                await apiRequest('/households/pets/delete', {
                    method: 'POST',
                    body: JSON.stringify({ pet_id: pet.id }),
                });
                await loadPets(householdId);
            }));
            list.appendChild(li);
        }
    }

    document.getElementById('logout-button').addEventListener('click', async () => {
        await apiRequest('/logout', { method: 'POST' });
        window.location.href = '/';
    });

    document.getElementById('create-household-form').addEventListener('submit', async (event) => {
        event.preventDefault();
        const form = event.target;
        const messageEl = document.getElementById('create-household-message');
        messageEl.hidden = true;

        const { response, body } = await apiRequest('/households', {
            method: 'POST',
            body: JSON.stringify({ name: form.name.value }),
        });

        if (response.ok) {
            form.reset();
            await loadHouseholds();
            return;
        }

        messageEl.textContent = (body && body.message) || 'Could not create household.';
        messageEl.className = 'message message--error';
        messageEl.hidden = false;
    });

    document.getElementById('invite-form').addEventListener('submit', async (event) => {
        event.preventDefault();
        const form = event.target;
        const messageEl = document.getElementById('invite-message');
        messageEl.hidden = true;

        const { response, body } = await apiRequest('/households/invite', {
            method: 'POST',
            body: JSON.stringify({
                household_id: currentHouseholdId,
                username_or_email: form.username_or_email.value,
            }),
        });

        messageEl.textContent = (body && body.message) || (response.ok ? 'Invite sent.' : 'Could not send invite.');
        messageEl.className = response.ok ? 'message message--success' : 'message message--error';
        messageEl.hidden = false;

        if (response.ok) {
            form.reset();
        }
    });

    document.getElementById('household-settings-form').addEventListener('submit', async (event) => {
        event.preventDefault();
        const form = event.target;
        const messageEl = document.getElementById('household-settings-message');
        messageEl.hidden = true;

        const { response, body } = await apiRequest('/households/settings', {
            method: 'POST',
            body: JSON.stringify({ household_id: currentHouseholdId, name: form.name.value }),
        });

        if (response.ok) {
            document.getElementById('household-detail-name').textContent = body.household.name;
            await loadHouseholds();
            return;
        }

        messageEl.textContent = (body && body.message) || 'Could not save settings.';
        messageEl.className = 'message message--error';
        messageEl.hidden = false;
    });

    document.getElementById('household-note-form').addEventListener('submit', async (event) => {
        event.preventDefault();
        const form = event.target;
        const messageEl = document.getElementById('household-note-message');
        messageEl.hidden = true;

        const { response, body } = await apiRequest('/households/notes', {
            method: 'POST',
            body: JSON.stringify({
                household_id: currentHouseholdId,
                body: form.body.value,
                visibility: form.public.checked ? 'public' : 'private',
            }),
        });

        if (response.ok) {
            form.reset();
            await loadNotes(currentHouseholdId);
            return;
        }

        messageEl.textContent = (body && body.message) || 'Could not add note.';
        messageEl.className = 'message message--error';
        messageEl.hidden = false;
    });

    document.getElementById('household-pet-form').addEventListener('submit', async (event) => {
        event.preventDefault();
        const form = event.target;
        const messageEl = document.getElementById('household-pet-message');
        messageEl.hidden = true;

        const { response, body } = await apiRequest('/households/pets', {
            method: 'POST',
            body: JSON.stringify({
                household_id: currentHouseholdId,
                name: form.name.value,
                species: form.species.value,
                breed: form.breed.value,
                birthday: form.birthday.value,
                notes: form.notes.value,
            }),
        });

        if (response.ok) {
            form.reset();
            await loadPets(currentHouseholdId);
            return;
        }

        messageEl.textContent = (body && body.message) || 'Could not add pet.';
        messageEl.className = 'message message--error';
        messageEl.hidden = false;
    });

    document.getElementById('close-household-detail-button').addEventListener('click', closeHouseholdDetail);

    await loadInvites();
    await loadHouseholds();
})();
