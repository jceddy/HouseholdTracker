(async function () {
    let currentHouseholdId = null;
    let currentMembers = [];

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

    // Feather-style icons (inline so there's no icon font/library dependency).
    // aria-hidden since the button itself carries the real label via
    // aria-label -- screen readers read that, not the SVG.
    const EDIT_ICON = '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" '
        + 'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'
        + '<path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path>'
        + '<path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>';
    const DELETE_ICON = '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" '
        + 'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'
        + '<polyline points="3 6 5 6 21 6"></polyline>'
        + '<path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path>'
        + '<line x1="10" y1="11" x2="10" y2="17"></line><line x1="14" y1="11" x2="14" y2="17"></line></svg>';
    const CHECK_ICON = '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" '
        + 'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'
        + '<polyline points="20 6 9 17 4 12"></polyline></svg>';

    // buildIconButton(...) - same idea as buildButton(), but shows an icon
    // instead of text; the text is still there for screen readers via
    // aria-label (and as a hover tooltip via title).
    function buildIconButton(icon, label, onClick) {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'icon-button';
        button.setAttribute('aria-label', label);
        button.title = label;
        button.innerHTML = icon;
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
        await loadTasks(householdId);
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
        currentMembers = body.members;
        populateAssigneeSelect(document.getElementById('household-task-assignee'));

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
                actions.appendChild(buildIconButton(EDIT_ICON, 'Edit', () => renderNoteEditForm(li, note, householdId)));
                actions.appendChild(buildIconButton(DELETE_ICON, 'Delete', async () => {
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

    // renderNoteEditForm(...) - swaps a note's list item for an inline edit
    // form in place, rather than a separate modal/page. Cancel just
    // re-renders the list (discarding the in-progress edit); Save posts the
    // update and re-renders on success.
    function renderNoteEditForm(li, note, householdId) {
        li.innerHTML = '';

        const form = document.createElement('form');
        form.className = 'inline-edit-form';

        const textarea = document.createElement('textarea');
        textarea.value = note.body;
        textarea.maxLength = 20000;
        textarea.required = true;

        const checkboxLabel = document.createElement('label');
        checkboxLabel.className = 'checkbox-label';
        const checkbox = document.createElement('input');
        checkbox.type = 'checkbox';
        checkbox.checked = note.visibility === 'private';
        checkboxLabel.appendChild(checkbox);
        checkboxLabel.appendChild(document.createTextNode('Private'));

        const row = document.createElement('div');
        row.className = 'form-row';
        const saveButton = document.createElement('button');
        saveButton.type = 'submit';
        saveButton.className = 'button--compact';
        saveButton.textContent = 'Save';
        const cancelButton = document.createElement('button');
        cancelButton.type = 'button';
        cancelButton.className = 'button--compact';
        cancelButton.textContent = 'Cancel';
        cancelButton.addEventListener('click', () => loadNotes(householdId));
        row.appendChild(saveButton);
        row.appendChild(cancelButton);
        row.appendChild(checkboxLabel);

        const messageEl = document.createElement('p');
        messageEl.className = 'message';
        messageEl.hidden = true;

        form.appendChild(textarea);
        form.appendChild(row);
        form.appendChild(messageEl);

        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            const { response, body } = await apiRequest('/households/notes/update', {
                method: 'POST',
                body: JSON.stringify({
                    note_id: note.id,
                    body: textarea.value,
                    visibility: checkbox.checked ? 'private' : 'public',
                }),
            });

            if (response.ok) {
                await loadNotes(householdId);
                return;
            }

            messageEl.textContent = (body && body.message) || 'Could not save note.';
            messageEl.className = 'message message--error';
            messageEl.hidden = false;
        });

        li.appendChild(form);
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
            actions.appendChild(buildIconButton(EDIT_ICON, 'Edit', () => renderPetEditForm(li, pet, householdId)));
            actions.appendChild(buildIconButton(DELETE_ICON, 'Delete', async () => {
                await apiRequest('/households/pets/delete', {
                    method: 'POST',
                    body: JSON.stringify({ pet_id: pet.id }),
                });
                await loadPets(householdId);
            }));
            list.appendChild(li);
        }
    }

    // renderPetEditForm(...) - same inline-edit pattern as
    // renderNoteEditForm(); any household member may edit a pet (a shared
    // resource, not a per-user one), unlike notes.
    function renderPetEditForm(li, pet, householdId) {
        li.innerHTML = '';

        const form = document.createElement('form');
        form.className = 'inline-edit-form';

        function textField(labelText, value, maxLength) {
            const label = document.createElement('label');
            label.textContent = labelText;
            const input = document.createElement('input');
            input.type = 'text';
            input.value = value || '';
            input.maxLength = maxLength;
            label.appendChild(input);
            form.appendChild(label);
            return input;
        }

        const nameInput = textField('Name', pet.name, 100);
        nameInput.required = true;
        const speciesInput = textField('Species', pet.species, 100);
        const breedInput = textField('Breed', pet.breed, 100);

        const birthdayLabel = document.createElement('label');
        birthdayLabel.textContent = 'Birthday';
        const birthdayInput = document.createElement('input');
        birthdayInput.type = 'date';
        birthdayInput.value = pet.birthday || '';
        birthdayLabel.appendChild(birthdayInput);
        form.appendChild(birthdayLabel);

        const notesLabel = document.createElement('label');
        notesLabel.textContent = 'Notes';
        const notesTextarea = document.createElement('textarea');
        notesTextarea.value = pet.notes || '';
        notesTextarea.maxLength = 2000;
        notesLabel.appendChild(notesTextarea);
        form.appendChild(notesLabel);

        const row = document.createElement('div');
        row.className = 'form-row';
        const saveButton = document.createElement('button');
        saveButton.type = 'submit';
        saveButton.className = 'button--compact';
        saveButton.textContent = 'Save';
        const cancelButton = document.createElement('button');
        cancelButton.type = 'button';
        cancelButton.className = 'button--compact';
        cancelButton.textContent = 'Cancel';
        cancelButton.addEventListener('click', () => loadPets(householdId));
        row.appendChild(saveButton);
        row.appendChild(cancelButton);
        form.appendChild(row);

        const messageEl = document.createElement('p');
        messageEl.className = 'message';
        messageEl.hidden = true;
        form.appendChild(messageEl);

        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            const { response, body } = await apiRequest('/households/pets/update', {
                method: 'POST',
                body: JSON.stringify({
                    pet_id: pet.id,
                    name: nameInput.value,
                    species: speciesInput.value,
                    breed: breedInput.value,
                    birthday: birthdayInput.value,
                    notes: notesTextarea.value,
                }),
            });

            if (response.ok) {
                await loadPets(householdId);
                return;
            }

            messageEl.textContent = (body && body.message) || 'Could not save pet.';
            messageEl.className = 'message message--error';
            messageEl.hidden = false;
        });

        li.appendChild(form);
    }

    // populateAssigneeSelect(...) - fills an "assign to" <select> with the
    // current household roster, preserving an "Unassigned" first option and
    // optionally pre-selecting a given user id (for the edit form).
    function populateAssigneeSelect(selectEl, selectedUserId) {
        const unassignedOption = selectEl.options[0];
        selectEl.innerHTML = '';
        selectEl.appendChild(unassignedOption);
        for (const member of currentMembers) {
            const option = document.createElement('option');
            option.value = String(member.user_id);
            option.textContent = member.username;
            if (selectedUserId != null && member.user_id === selectedUserId) {
                option.selected = true;
            }
            selectEl.appendChild(option);
        }
    }

    const RECURRENCE_UNITS = { daily: 'day', weekly: 'week', monthly: 'month', annual: 'year' };

    function describeRecurrence(frequency, interval) {
        const unit = RECURRENCE_UNITS[frequency] || frequency;
        return interval === 1 ? `every ${unit}` : `every ${interval} ${unit}s`;
    }

    const todayIso = new Date().toISOString().slice(0, 10);

    function isTaskOverdue(task) {
        return task.status !== 'done' && !!task.next_due_at && task.next_due_at < todayIso;
    }

    function formatTaskLabel(task) {
        const bits = [task.title];
        if (task.assigned_to_username) {
            bits.push(`assigned to ${task.assigned_to_username}`);
        }
        if (task.recurrence_frequency) {
            bits.push(describeRecurrence(task.recurrence_frequency, Number(task.recurrence_interval)));
        }
        if (task.next_due_at) {
            bits.push(isTaskOverdue(task) ? `OVERDUE (was due ${task.next_due_at})` : `due ${task.next_due_at}`);
        }
        if (task.status === 'done') {
            bits.push('done');
        }
        if (Number(task.completion_count) > 0) {
            bits.push(`completed ${task.completion_count}x`);
        }
        return bits.join(' — ');
    }

    async function loadTasks(householdId) {
        const { response, body } = await apiRequest('/households/tasks?household_id=' + householdId);
        const list = document.getElementById('household-tasks-list');
        list.innerHTML = '';

        if (!response.ok) {
            return;
        }

        for (const task of body.tasks) {
            const { li, actions } = buildListItem(formatTaskLabel(task));
            if (task.status !== 'done') {
                actions.appendChild(buildIconButton(CHECK_ICON, 'Complete', async () => {
                    await apiRequest('/households/tasks/complete', {
                        method: 'POST',
                        body: JSON.stringify({ task_id: task.id }),
                    });
                    await loadTasks(householdId);
                }));
            }
            actions.appendChild(buildIconButton(EDIT_ICON, 'Edit', () => renderTaskEditForm(li, task, householdId)));
            actions.appendChild(buildIconButton(DELETE_ICON, 'Delete', async () => {
                await apiRequest('/households/tasks/delete', {
                    method: 'POST',
                    body: JSON.stringify({ task_id: task.id }),
                });
                await loadTasks(householdId);
            }));
            list.appendChild(li);
        }
    }

    // renderTaskEditForm(...) - same inline-edit pattern as
    // renderNoteEditForm()/renderPetEditForm(); any household member may
    // edit any task (a shared resource, like pets). Status isn't editable
    // here -- 'done' is only ever reached via the Complete button, and
    // there's no UI for the separate 'in_progress' state yet, so saving
    // always resubmits 'open' (see TaskService::updateTask()).
    function renderTaskEditForm(li, task, householdId) {
        li.innerHTML = '';

        const form = document.createElement('form');
        form.className = 'inline-edit-form';

        const titleLabel = document.createElement('label');
        titleLabel.textContent = 'Title';
        const titleInput = document.createElement('input');
        titleInput.type = 'text';
        titleInput.value = task.title;
        titleInput.maxLength = 150;
        titleInput.required = true;
        titleLabel.appendChild(titleInput);
        form.appendChild(titleLabel);

        const descriptionLabel = document.createElement('label');
        descriptionLabel.textContent = 'Description';
        const descriptionTextarea = document.createElement('textarea');
        descriptionTextarea.value = task.description || '';
        descriptionTextarea.maxLength = 2000;
        descriptionLabel.appendChild(descriptionTextarea);
        form.appendChild(descriptionLabel);

        const assigneeLabel = document.createElement('label');
        assigneeLabel.textContent = 'Assign to';
        const assigneeSelect = document.createElement('select');
        const unassignedOption = document.createElement('option');
        unassignedOption.value = '';
        unassignedOption.textContent = 'Unassigned';
        assigneeSelect.appendChild(unassignedOption);
        assigneeLabel.appendChild(assigneeSelect);
        form.appendChild(assigneeLabel);
        populateAssigneeSelect(assigneeSelect, task.assigned_to_user_id != null ? Number(task.assigned_to_user_id) : null);

        const frequencyLabel = document.createElement('label');
        frequencyLabel.textContent = 'Repeats';
        const frequencySelect = document.createElement('select');
        for (const [value, text] of [['', 'One-off (no repeat)'], ['daily', 'Daily'], ['weekly', 'Weekly'], ['monthly', 'Monthly'], ['annual', 'Annual']]) {
            const option = document.createElement('option');
            option.value = value;
            option.textContent = text;
            frequencySelect.appendChild(option);
        }
        frequencySelect.value = task.recurrence_frequency || '';
        frequencyLabel.appendChild(frequencySelect);
        form.appendChild(frequencyLabel);

        const intervalLabel = document.createElement('label');
        intervalLabel.textContent = 'Every';
        const intervalInput = document.createElement('input');
        intervalInput.type = 'number';
        intervalInput.min = '1';
        intervalInput.max = '1000';
        intervalInput.value = String(task.recurrence_interval || 1);
        intervalLabel.appendChild(intervalInput);
        const intervalUnit = document.createElement('span');
        intervalUnit.textContent = RECURRENCE_UNITS[frequencySelect.value] ? `${RECURRENCE_UNITS[frequencySelect.value]}(s)` : '';
        intervalLabel.appendChild(intervalUnit);
        intervalLabel.hidden = !frequencySelect.value;
        form.appendChild(intervalLabel);

        const dueAtLabel = document.createElement('label');
        dueAtLabel.textContent = 'Due date';
        const dueAtInput = document.createElement('input');
        dueAtInput.type = 'date';
        dueAtInput.value = task.next_due_at || '';
        dueAtLabel.appendChild(dueAtInput);
        form.appendChild(dueAtLabel);

        frequencySelect.addEventListener('change', () => {
            intervalLabel.hidden = !frequencySelect.value;
            intervalUnit.textContent = RECURRENCE_UNITS[frequencySelect.value] ? `${RECURRENCE_UNITS[frequencySelect.value]}(s)` : '';
        });

        const row = document.createElement('div');
        row.className = 'form-row';
        const saveButton = document.createElement('button');
        saveButton.type = 'submit';
        saveButton.className = 'button--compact';
        saveButton.textContent = 'Save';
        const cancelButton = document.createElement('button');
        cancelButton.type = 'button';
        cancelButton.className = 'button--compact';
        cancelButton.textContent = 'Cancel';
        cancelButton.addEventListener('click', () => loadTasks(householdId));
        row.appendChild(saveButton);
        row.appendChild(cancelButton);
        form.appendChild(row);

        const messageEl = document.createElement('p');
        messageEl.className = 'message';
        messageEl.hidden = true;
        form.appendChild(messageEl);

        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            const { response, body } = await apiRequest('/households/tasks/update', {
                method: 'POST',
                body: JSON.stringify({
                    task_id: task.id,
                    title: titleInput.value,
                    description: descriptionTextarea.value,
                    assigned_to_user_id: assigneeSelect.value,
                    status: 'open',
                    recurrence_frequency: frequencySelect.value,
                    recurrence_interval: frequencySelect.value ? intervalInput.value : '',
                    due_at: dueAtInput.value,
                }),
            });

            if (response.ok) {
                await loadTasks(householdId);
                return;
            }

            messageEl.textContent = (body && body.message) || 'Could not save task.';
            messageEl.className = 'message message--error';
            messageEl.hidden = false;
        });

        li.appendChild(form);
    }

    document.getElementById('household-task-frequency').addEventListener('change', (event) => {
        const frequency = event.target.value;
        document.getElementById('household-task-interval-row').hidden = !frequency;
        document.getElementById('household-task-interval-unit').textContent = RECURRENCE_UNITS[frequency] ? `${RECURRENCE_UNITS[frequency]}(s)` : 'day(s)';
    });

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
                visibility: form.private.checked ? 'private' : 'public',
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

    document.getElementById('household-task-form').addEventListener('submit', async (event) => {
        event.preventDefault();
        const form = event.target;
        const messageEl = document.getElementById('household-task-message');
        messageEl.hidden = true;

        const { response, body } = await apiRequest('/households/tasks', {
            method: 'POST',
            body: JSON.stringify({
                household_id: currentHouseholdId,
                title: form.title.value,
                description: form.description.value,
                assigned_to_user_id: form.assigned_to_user_id.value,
                recurrence_frequency: form.recurrence_frequency.value,
                recurrence_interval: form.recurrence_frequency.value ? form.recurrence_interval.value : '',
                due_at: form.due_at.value,
            }),
        });

        if (response.ok) {
            form.reset();
            document.getElementById('household-task-interval-row').hidden = true;
            await loadTasks(currentHouseholdId);
            return;
        }

        messageEl.textContent = (body && body.message) || 'Could not add task.';
        messageEl.className = 'message message--error';
        messageEl.hidden = false;
    });

    document.getElementById('close-household-detail-button').addEventListener('click', closeHouseholdDetail);

    await loadInvites();
    await loadHouseholds();
})();
