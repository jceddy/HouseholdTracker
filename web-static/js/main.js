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
    const SKIP_ICON = '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" '
        + 'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'
        + '<polygon points="5 4 15 12 5 20 5 4"></polygon><line x1="19" y1="5" x2="19" y2="19"></line></svg>';

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

    // activateTab(...) - the household detail view's Members/Settings/Notes/
    // Pets/Tasks sections are tab panels rather than stacked vertically;
    // this shows the chosen one and hides the rest, keyed off each tab
    // button's own data-tab attribute matching a "tab-panel-<name>" panel id.
    function activateTab(tabName) {
        for (const button of document.querySelectorAll('.tab-button')) {
            button.setAttribute('aria-selected', String(button.dataset.tab === tabName));
        }
        for (const panel of document.querySelectorAll('.tab-panel')) {
            panel.hidden = panel.id !== `tab-panel-${tabName}`;
        }
    }

    for (const button of document.querySelectorAll('.tab-button')) {
        button.addEventListener('click', () => activateTab(button.dataset.tab));
    }

    // activateTopTab(...) - same idea as activateTab(), one level up: the
    // landing page's Households/My Tasks sections are top-level tab panels.
    // Kept as a separate function/class pair (top-tab-button/top-tab-panel)
    // rather than sharing activateTab()'s selectors, so switching one tab
    // group never touches the other's aria-selected/hidden state.
    function activateTopTab(tabName) {
        for (const button of document.querySelectorAll('.top-tab-button')) {
            button.setAttribute('aria-selected', String(button.dataset.topTab === tabName));
        }
        for (const panel of document.querySelectorAll('.top-tab-panel')) {
            panel.hidden = panel.id !== `top-tab-panel-${tabName}`;
        }
    }

    for (const button of document.querySelectorAll('.top-tab-button')) {
        button.addEventListener('click', () => activateTopTab(button.dataset.topTab));
    }

    // activateHiTab(...) - same idea as activateTab()/activateTopTab(), one
    // level deeper: the Home Improvement tab's own Projects/Maintenance
    // sections are sub-tab panels. Own class pair (hi-tab-button/
    // hi-tab-panel) for the same reason activateTopTab() has its own --
    // switching this sub-tab group never touches the outer household-detail
    // tabs' aria-selected/hidden state, or vice versa.
    function activateHiTab(tabName) {
        for (const button of document.querySelectorAll('.hi-tab-button')) {
            button.setAttribute('aria-selected', String(button.dataset.hiTab === tabName));
        }
        for (const panel of document.querySelectorAll('.hi-tab-panel')) {
            panel.hidden = panel.id !== `hi-tab-panel-${tabName}`;
        }
    }

    for (const button of document.querySelectorAll('.hi-tab-button')) {
        button.addEventListener('click', () => activateHiTab(button.dataset.hiTab));
    }

    // Re-fetch My Tasks every time it's navigated to, rather than only once
    // at page load -- a task added/completed elsewhere (or by someone else)
    // since the last load otherwise wouldn't show up here without a manual
    // page refresh.
    document.querySelector('.top-tab-button[data-top-tab="my-tasks"]').addEventListener('click', () => loadMyTasks());

    async function openHouseholdDetail(householdId, householdName) {
        currentHouseholdId = householdId;
        document.getElementById('household-detail-name').textContent = householdName;
        document.getElementById('household-settings-name').value = householdName;
        document.getElementById('household-detail-section').hidden = false;
        // Hidden while a household's detail is open -- shown underneath the
        // detail panel, it was easy to mistake for part of it and got in the
        // way of the invite form.
        document.getElementById('create-household-section').hidden = true;
        activateTab('dashboard');
        activateHiTab('projects');
        // Reset rather than carry over stale finished-today data (and its
        // "Hide finished today" label) from whichever household was open
        // before this one.
        document.getElementById('household-tasks-finished-list').hidden = true;
        document.getElementById('household-tasks-finished-toggle').textContent = 'Show finished today';
        // Same idea for a still-open project detail panel from whichever
        // household was open before this one.
        closeProjectDetail();
        await loadMembers(householdId);
        await loadNotes(householdId);
        await loadPets(householdId);
        await loadTasks(householdId);
        await loadProjects(householdId);
        await loadMaintenance(householdId);
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
        populateAssigneeCheckboxes(document.getElementById('household-task-assignees'));
        populateAssigneeCheckboxes(document.getElementById('hi-maintenance-assignees'));

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

    // populateAssigneeCheckboxes(...) - fills an "assign to" checkbox group
    // with the current household roster, one checkbox per member, so a task
    // can go to any number of them (see TaskService's multi-assignee
    // docblock) -- pre-checking whichever ones are in selectedUserIds (for
    // the edit form).
    function populateAssigneeCheckboxes(containerEl, selectedUserIds) {
        containerEl.innerHTML = '';
        for (const member of currentMembers) {
            const label = document.createElement('label');
            label.className = 'checkbox-label';
            const checkbox = document.createElement('input');
            checkbox.type = 'checkbox';
            checkbox.value = String(member.user_id);
            checkbox.checked = !!selectedUserIds && selectedUserIds.includes(member.user_id);
            label.appendChild(checkbox);
            label.appendChild(document.createTextNode(member.username));
            containerEl.appendChild(label);
        }
    }

    // getCheckedAssigneeIds(...) - reads back a checkbox group built by
    // populateAssigneeCheckboxes() as an array of user ids, for submitting.
    function getCheckedAssigneeIds(containerEl) {
        return Array.from(containerEl.querySelectorAll('input[type="checkbox"]:checked')).map((checkbox) => Number(checkbox.value));
    }

    const RECURRENCE_UNITS = { daily: 'day', weekly: 'week', monthly: 'month', annual: 'year' };

    function describeRecurrence(frequency, interval) {
        const unit = RECURRENCE_UNITS[frequency] || frequency;
        return interval === 1 ? `every ${unit}` : `every ${interval} ${unit}s`;
    }

    // todayIso() - the viewer's LOCAL calendar date as YYYY-MM-DD.
    // getFullYear()/getMonth()/getDate() are local-time accessors, unlike
    // toISOString() (used here previously), which reports the UTC date and
    // shifts "today" a day early for any timezone behind UTC.
    function todayIso() {
        const now = new Date();
        const year = now.getFullYear();
        const month = String(now.getMonth() + 1).padStart(2, '0');
        const day = String(now.getDate()).padStart(2, '0');
        return `${year}-${month}-${day}`;
    }

    // isTaskOverdue(...) - the list only ever contains *pending* instances
    // (see loadTasks()/loadMyTasks()'s own routes), so there's no status
    // check needed here anymore -- just whether its due date has passed.
    function isTaskOverdue(task) {
        return !!task.due_at && task.due_at < todayIso();
    }

    // isDueToday(...) - for highlighting a task row -- distinct from
    // isTaskOverdue() (strictly *before* today), so the two never both
    // apply to the same task.
    function isDueToday(task) {
        return task.due_at === todayIso();
    }

    // formatAssigneesBit(...) - shared by formatTaskLabel()/
    // formatMyTaskLabel(): names the assignee(s), and, only when there's
    // more than one (the only time it's not implied), whether completing
    // this instance counts for all of them ('anyone') or just its own
    // assignee ('everyone' -- task.assigned_to_username, if set, is *this
    // instance's own* assignee out of the full list).
    function formatAssigneesBit(task) {
        const assignees = task.assignees || [];
        if (assignees.length === 0) {
            return null;
        }

        const names = assignees.map((assignee) => assignee.username).join(', ');
        if (assignees.length === 1) {
            return `assigned to ${names}`;
        }

        const modeText = task.assignment_mode === 'everyone'
            ? `everyone completes their own${task.assigned_to_username ? ` — this one is ${task.assigned_to_username}'s` : ''}`
            : 'anyone completes it for all';
        return `assigned to ${names} (${modeText})`;
    }

    // isOpenEnded(...) - a one-off task with no due date at all (issue #12's
    // open-ended-task follow-up) -- distinct from a dated one-off, which
    // still has a due_at like any recurring occurrence does.
    function isOpenEnded(task) {
        return !task.recurrence_frequency && !task.due_at;
    }

    // formatDueAtBit(...) - shared by formatTaskLabel()/formatMyTaskLabel():
    // a due date (or OVERDUE marker) for a dated task, or its priority for
    // an open-ended one -- the two are mutually exclusive, since an
    // open-ended task is defined by having no due_at.
    function formatDueAtBit(task) {
        if (task.due_at) {
            return isTaskOverdue(task) ? `OVERDUE (was due ${task.due_at})` : `due ${task.due_at}`;
        }
        if (isOpenEnded(task) && task.priority) {
            return `${task.priority} priority, no deadline`;
        }
        return null;
    }

    function formatTaskLabel(task) {
        const bits = [task.title];
        const assigneesBit = formatAssigneesBit(task);
        if (assigneesBit) {
            bits.push(assigneesBit);
        }
        if (task.recurrence_frequency) {
            bits.push(describeRecurrence(task.recurrence_frequency, Number(task.recurrence_interval)));
        }
        const dueAtBit = formatDueAtBit(task);
        if (dueAtBit) {
            bits.push(dueAtBit);
        }
        if (task.notes) {
            bits.push(`note: ${task.notes}`);
        }
        if (Number(task.completion_count) > 0) {
            bits.push(`completed ${task.completion_count}x`);
        }
        return bits.join(' — ');
    }

    // renderSkipForm(...) - inline reason-required mini-form for skipping a
    // recurring task's occurrence (POST /households/tasks/skip), same
    // inline-replace pattern as renderTaskEditForm()/renderNoteEditForm()/
    // renderPetEditForm(). Distinct from Complete (it happened) and Delete
    // (no record left at all): skipping keeps a note on why this
    // occurrence isn't happening ("didn't walk the dog -- there was a
    // tornado"). Only ever wired up for a recurring task by its callers
    // below, but TaskService::skipInstance() enforces that server-side too.
    function renderSkipForm(li, task, reload) {
        li.innerHTML = '';

        const form = document.createElement('form');
        form.className = 'inline-edit-form';

        const notesLabel = document.createElement('label');
        notesLabel.textContent = 'Why was this skipped?';
        const notesInput = document.createElement('input');
        notesInput.type = 'text';
        notesInput.maxLength = 2000;
        notesInput.required = true;
        notesLabel.appendChild(notesInput);
        form.appendChild(notesLabel);

        const row = document.createElement('div');
        row.className = 'form-row';
        const skipButton = document.createElement('button');
        skipButton.type = 'submit';
        skipButton.className = 'button--compact';
        skipButton.textContent = 'Skip';
        const cancelButton = document.createElement('button');
        cancelButton.type = 'button';
        cancelButton.className = 'button--compact';
        cancelButton.textContent = 'Cancel';
        cancelButton.addEventListener('click', () => reload());
        row.appendChild(skipButton);
        row.appendChild(cancelButton);
        form.appendChild(row);

        const messageEl = document.createElement('p');
        messageEl.className = 'message';
        messageEl.hidden = true;
        form.appendChild(messageEl);

        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            const { response, body } = await apiRequest('/households/tasks/skip', {
                method: 'POST',
                body: JSON.stringify({ instance_id: task.id, notes: notesInput.value }),
            });

            if (response.ok) {
                await reload();
                return;
            }

            messageEl.textContent = (body && body.message) || 'Could not skip task.';
            messageEl.className = 'message message--error';
            messageEl.hidden = false;
        });

        li.appendChild(form);
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
            if (isDueToday(task)) {
                li.classList.add('task-due-today');
            }
            if (isTaskOverdue(task)) {
                li.classList.add('task-overdue');
            }
            actions.appendChild(buildIconButton(CHECK_ICON, 'Complete', async () => {
                await apiRequest('/households/tasks/complete', {
                    method: 'POST',
                    body: JSON.stringify({ instance_id: task.id }),
                });
                await loadTasks(householdId);
            }));
            if (task.recurrence_frequency) {
                actions.appendChild(buildIconButton(SKIP_ICON, 'Skip', () => renderSkipForm(li, task, () => loadTasks(householdId))));
            }
            actions.appendChild(buildIconButton(EDIT_ICON, 'Edit', () => renderTaskEditForm(li, task, householdId, () => loadTasks(householdId))));
            actions.appendChild(buildIconButton(DELETE_ICON, 'Delete', async () => {
                await apiRequest('/households/tasks/delete', {
                    method: 'POST',
                    body: JSON.stringify({ instance_id: task.id }),
                });
                await loadTasks(householdId);
            }));
            list.appendChild(li);
        }

        // Keep the "finished today" list in sync whenever it's visible --
        // loadTasks() is already what every complete/skip/delete/edit-cancel
        // action reloads after itself, so this is the one place that needs
        // to know about it.
        if (!document.getElementById('household-tasks-finished-list').hidden) {
            await loadFinishedTasksToday(householdId);
        }
        // Same idea for the Dashboard tab's due-today/overdue lists -- they
        // derive from this same pending-instance data, so every action that
        // already reloads the Tasks list keeps the dashboard current too,
        // regardless of which tab is actually visible right now.
        await loadDashboard(householdId);
    }

    // loadDashboard(...) - issue #20's "what's due today" view: the same
    // pending-instance data loadTasks() shows, split into "due today" and
    // "overdue" using the same isDueToday()/isTaskOverdue() checks that
    // already highlight/label rows on the Tasks tab, so nothing here can
    // disagree with what that tab already shows for the same task. An
    // open-ended task (no due_at) never matches either bucket, same as it
    // never gets a due-today highlight or an OVERDUE label there.
    //
    // Deliberately reuses GET /households/tasks rather than a new aggregate
    // endpoint: today this dashboard has exactly one tracker to pull from
    // (issue #12's tasks), so a dedicated aggregation layer would have
    // nothing to aggregate yet. Issue #20 also calls for a household
    // calendar (#13) and overdue maintenance (#11) here -- neither tracker
    // exists yet, so those sections are left for whichever of those lands
    // first to add.
    async function loadDashboard(householdId) {
        const { response, body } = await apiRequest('/households/tasks?household_id=' + householdId);
        const dueTodayList = document.getElementById('dashboard-due-today-list');
        const overdueList = document.getElementById('dashboard-overdue-list');
        dueTodayList.innerHTML = '';
        overdueList.innerHTML = '';

        if (!response.ok) {
            return;
        }

        const dueToday = body.tasks.filter((task) => isDueToday(task));
        const overdue = body.tasks.filter((task) => isTaskOverdue(task));

        renderDashboardTaskList(dueTodayList, dueToday, 'Nothing due today.', householdId);
        renderDashboardTaskList(overdueList, overdue, 'Nothing overdue.', householdId);
    }

    function renderDashboardTaskList(listEl, tasks, emptyMessage, householdId) {
        if (tasks.length === 0) {
            const li = document.createElement('li');
            li.textContent = emptyMessage;
            listEl.appendChild(li);
            return;
        }

        for (const task of tasks) {
            const { li, actions } = buildListItem(formatTaskLabel(task));
            if (isDueToday(task)) {
                li.classList.add('task-due-today');
            }
            if (isTaskOverdue(task)) {
                li.classList.add('task-overdue');
            }
            actions.appendChild(buildIconButton(CHECK_ICON, 'Complete', async () => {
                await apiRequest('/households/tasks/complete', {
                    method: 'POST',
                    body: JSON.stringify({ instance_id: task.id }),
                });
                // loadTasks() re-renders the Tasks tab's own list and, as
                // its last step, this dashboard too -- see its own comment.
                await loadTasks(householdId);
            }));
            listEl.appendChild(li);
        }
    }

    // Home improvement projects and maintenance (issue #11). currentProjectId
    // tracks which project's detail panel (if any) is currently open, the
    // same module-scope-state pattern as currentHouseholdId itself.
    let currentProjectId = null;

    const PROJECT_STATUS_LABELS = {
        idea: 'Idea',
        planned: 'Planned',
        in_progress: 'In progress',
        completed: 'Completed',
        abandoned: 'Abandoned',
    };

    function formatProjectLabel(project) {
        const bits = [project.title, PROJECT_STATUS_LABELS[project.status] || project.status];
        if (project.estimated_cost !== null) {
            bits.push(`est. $${project.estimated_cost}`);
        }
        if (project.actual_cost !== null) {
            bits.push(`actual $${project.actual_cost}`);
        }
        if (project.target_date) {
            bits.push(`target ${project.target_date}`);
        }
        return bits.join(' — ');
    }

    // renderProjectEditForm(...) - same inline-edit pattern as
    // renderTaskEditForm()/renderNoteEditForm()/renderPetEditForm().
    function renderProjectEditForm(li, project, householdId) {
        li.innerHTML = '';

        const form = document.createElement('form');
        form.className = 'inline-edit-form';

        const titleLabel = document.createElement('label');
        titleLabel.textContent = 'Title';
        const titleInput = document.createElement('input');
        titleInput.type = 'text';
        titleInput.value = project.title;
        titleInput.maxLength = 150;
        titleInput.required = true;
        titleLabel.appendChild(titleInput);
        form.appendChild(titleLabel);

        const descriptionLabel = document.createElement('label');
        descriptionLabel.textContent = 'Description';
        const descriptionTextarea = document.createElement('textarea');
        descriptionTextarea.value = project.description || '';
        descriptionTextarea.maxLength = 2000;
        descriptionLabel.appendChild(descriptionTextarea);
        form.appendChild(descriptionLabel);

        const statusLabel = document.createElement('label');
        statusLabel.textContent = 'Status';
        const statusSelect = document.createElement('select');
        for (const [value, text] of Object.entries(PROJECT_STATUS_LABELS)) {
            const option = document.createElement('option');
            option.value = value;
            option.textContent = text;
            statusSelect.appendChild(option);
        }
        statusSelect.value = project.status;
        statusLabel.appendChild(statusSelect);
        form.appendChild(statusLabel);

        const estimatedCostLabel = document.createElement('label');
        estimatedCostLabel.textContent = 'Estimated cost';
        const estimatedCostInput = document.createElement('input');
        estimatedCostInput.type = 'number';
        estimatedCostInput.min = '0';
        estimatedCostInput.step = '0.01';
        estimatedCostInput.value = project.estimated_cost !== null ? project.estimated_cost : '';
        estimatedCostLabel.appendChild(estimatedCostInput);
        form.appendChild(estimatedCostLabel);

        const actualCostLabel = document.createElement('label');
        actualCostLabel.textContent = 'Actual cost';
        const actualCostInput = document.createElement('input');
        actualCostInput.type = 'number';
        actualCostInput.min = '0';
        actualCostInput.step = '0.01';
        actualCostInput.value = project.actual_cost !== null ? project.actual_cost : '';
        actualCostLabel.appendChild(actualCostInput);
        form.appendChild(actualCostLabel);

        const targetDateLabel = document.createElement('label');
        targetDateLabel.textContent = 'Target date';
        const targetDateInput = document.createElement('input');
        targetDateInput.type = 'date';
        targetDateInput.value = project.target_date || '';
        targetDateLabel.appendChild(targetDateInput);
        form.appendChild(targetDateLabel);

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
        cancelButton.addEventListener('click', () => loadProjects(householdId));
        row.appendChild(saveButton);
        row.appendChild(cancelButton);
        form.appendChild(row);

        const messageEl = document.createElement('p');
        messageEl.className = 'message';
        messageEl.hidden = true;
        form.appendChild(messageEl);

        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            const { response, body } = await apiRequest('/households/projects/update', {
                method: 'POST',
                body: JSON.stringify({
                    project_id: project.id,
                    title: titleInput.value,
                    description: descriptionTextarea.value,
                    status: statusSelect.value,
                    estimated_cost: estimatedCostInput.value,
                    actual_cost: actualCostInput.value,
                    target_date: targetDateInput.value,
                }),
            });

            if (response.ok) {
                await loadProjects(householdId);
                return;
            }

            messageEl.textContent = (body && body.message) || 'Could not save project.';
            messageEl.className = 'message message--error';
            messageEl.hidden = false;
        });

        li.appendChild(form);
    }

    async function loadProjects(householdId) {
        const { response, body } = await apiRequest('/households/projects?household_id=' + householdId);
        const list = document.getElementById('home-improvement-projects-list');
        list.innerHTML = '';

        if (!response.ok) {
            return;
        }

        for (const project of body.projects) {
            const { li, actions } = buildListItem(formatProjectLabel(project));
            actions.appendChild(buildButton('View tasks', () => openProjectDetail(project.id, householdId)));
            actions.appendChild(buildIconButton(EDIT_ICON, 'Edit', () => renderProjectEditForm(li, project, householdId)));
            actions.appendChild(buildIconButton(DELETE_ICON, 'Delete', async () => {
                await apiRequest('/households/projects/delete', {
                    method: 'POST',
                    body: JSON.stringify({ project_id: project.id }),
                });
                if (currentProjectId === project.id) {
                    closeProjectDetail();
                }
                await loadProjects(householdId);
            }));
            list.appendChild(li);
        }

        // Keep an open project detail panel in sync with the list it came
        // from, the same "reload whatever's currently visible" idea as
        // loadTasks()'s own finished-list/dashboard refresh.
        if (currentProjectId !== null) {
            await openProjectDetail(currentProjectId, householdId);
        }
    }

    // openProjectDetail(...)/closeProjectDetail() - only one project's
    // detail panel is ever shown at a time (same singular-expand idea as
    // the Tasks tab's own "Show finished today" toggle), rather than an
    // inline expand per row -- simpler to keep in sync when a task is
    // added/completed/deleted from within it.
    async function openProjectDetail(projectId, householdId) {
        const { response, body } = await apiRequest('/households/projects/detail?project_id=' + projectId);
        if (!response.ok) {
            closeProjectDetail();
            return;
        }

        currentProjectId = projectId;
        const detail = document.getElementById('home-improvement-project-detail');
        detail.hidden = false;
        document.getElementById('hi-project-detail-title').textContent = body.project.title;
        document.getElementById('hi-project-detail-info').textContent = formatProjectLabel(body.project);
        renderProjectDetailTasks(body.tasks, householdId);
        populateAssigneeCheckboxes(document.getElementById('hi-project-task-assignees'));
        // The panel sits right below the project list, but on a long list it
        // can still open off-screen -- without this, clicking "View tasks"
        // can look like it did nothing.
        detail.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    function closeProjectDetail() {
        currentProjectId = null;
        document.getElementById('home-improvement-project-detail').hidden = true;
    }

    // renderProjectDetailTasks(...) - the project's own linked tasks
    // (household_tasks tagged source_type = 'home_improvement_project',
    // source_id = this project), reusing the exact same formatTaskLabel()/
    // Complete/Skip/Edit/Delete actions the household Tasks tab uses --
    // it's the same kind of row, just a different, project-scoped list.
    function renderProjectDetailTasks(tasks, householdId) {
        const list = document.getElementById('hi-project-detail-tasks');
        list.innerHTML = '';

        if (tasks.length === 0) {
            const li = document.createElement('li');
            li.textContent = 'No tasks on this project yet.';
            list.appendChild(li);
            return;
        }

        for (const task of tasks) {
            const { li, actions } = buildListItem(formatTaskLabel(task));
            if (isDueToday(task)) {
                li.classList.add('task-due-today');
            }
            if (isTaskOverdue(task)) {
                li.classList.add('task-overdue');
            }
            actions.appendChild(buildIconButton(CHECK_ICON, 'Complete', async () => {
                await apiRequest('/households/tasks/complete', {
                    method: 'POST',
                    body: JSON.stringify({ instance_id: task.id }),
                });
                await loadProjects(householdId);
                await loadTasks(householdId);
            }));
            actions.appendChild(buildIconButton(EDIT_ICON, 'Edit', () => renderTaskEditForm(li, task, householdId, async () => {
                await loadProjects(householdId);
                await loadTasks(householdId);
            })));
            actions.appendChild(buildIconButton(DELETE_ICON, 'Delete', async () => {
                await apiRequest('/households/tasks/delete', {
                    method: 'POST',
                    body: JSON.stringify({ instance_id: task.id }),
                });
                await loadProjects(householdId);
                await loadTasks(householdId);
            }));
            list.appendChild(li);
        }
    }

    // loadMaintenance(...)/renderMaintenanceList(...) - the Maintenance
    // schedule: recurring household_tasks tagged source_type =
    // 'maintenance' (see TaskService::validateSource()'s own docblock).
    // These same tasks also still show up in the ordinary Tasks tab and
    // dashboard -- this is a second, filtered view onto the same rows, so
    // it reuses the same row rendering (Complete/Skip/Edit/Delete, due-
    // today highlight) as loadTasks() does.
    async function loadMaintenance(householdId) {
        const { response, body } = await apiRequest('/households/maintenance?household_id=' + householdId);
        const list = document.getElementById('home-improvement-maintenance-list');
        list.innerHTML = '';

        if (!response.ok) {
            return;
        }

        if (body.tasks.length === 0) {
            const li = document.createElement('li');
            li.textContent = 'No maintenance items yet.';
            list.appendChild(li);
            return;
        }

        for (const task of body.tasks) {
            const { li, actions } = buildListItem(formatTaskLabel(task));
            if (isDueToday(task)) {
                li.classList.add('task-due-today');
            }
            if (isTaskOverdue(task)) {
                li.classList.add('task-overdue');
            }
            actions.appendChild(buildIconButton(CHECK_ICON, 'Complete', async () => {
                await apiRequest('/households/tasks/complete', {
                    method: 'POST',
                    body: JSON.stringify({ instance_id: task.id }),
                });
                await loadMaintenance(householdId);
                await loadTasks(householdId);
            }));
            actions.appendChild(buildIconButton(SKIP_ICON, 'Skip', () => renderSkipForm(li, task, async () => {
                await loadMaintenance(householdId);
                await loadTasks(householdId);
            })));
            actions.appendChild(buildIconButton(EDIT_ICON, 'Edit', () => renderTaskEditForm(li, task, householdId, async () => {
                await loadMaintenance(householdId);
                await loadTasks(householdId);
            })));
            actions.appendChild(buildIconButton(DELETE_ICON, 'Delete', async () => {
                await apiRequest('/households/tasks/delete', {
                    method: 'POST',
                    body: JSON.stringify({ instance_id: task.id }),
                });
                await loadMaintenance(householdId);
                await loadTasks(householdId);
            }));
            list.appendChild(li);
        }
    }

    // formatFinishedTaskLabel(...) - for the "Show finished today" list:
    // who resolved it and when, plus the note either way -- required for a
    // skip (the whole point of a skip over an outright delete), optional
    // (and possibly just whatever was already on the task before it was
    // completed) for a done one.
    function formatFinishedTaskLabel(task) {
        const bits = [task.title];
        const assigneesBit = formatAssigneesBit(task);
        if (assigneesBit) {
            bits.push(assigneesBit);
        }
        const actor = task.completed_by_username ? ` by ${task.completed_by_username}` : '';
        const at = task.completed_at ? ` at ${task.completed_at}` : '';
        if (task.status === 'skipped') {
            bits.push(`skipped${actor}${at}${task.notes ? `: ${task.notes}` : ''}`);
        } else {
            bits.push(`completed${actor}${at}${task.notes ? ` — note: ${task.notes}` : ''}`);
        }
        return bits.join(' — ');
    }

    async function loadFinishedTasksToday(householdId) {
        const { response, body } = await apiRequest('/households/tasks/finished?household_id=' + householdId);
        const list = document.getElementById('household-tasks-finished-list');
        list.innerHTML = '';

        if (!response.ok) {
            return;
        }

        if (body.tasks.length === 0) {
            const li = document.createElement('li');
            li.textContent = 'Nothing finished today yet.';
            list.appendChild(li);
            return;
        }

        for (const task of body.tasks) {
            const { li } = buildListItem(formatFinishedTaskLabel(task));
            list.appendChild(li);
        }
    }

    // formatMyTaskLabel(...) - like formatTaskLabel(), but for the cross-
    // household "My Tasks" view: leads with which household the task
    // belongs to. Still shows the assignee bit (unlike before the
    // multi-assignee follow-up) since "me" is no longer necessarily the
    // *only* assignee -- an 'anyone'-mode task shared with others is useful
    // context here too.
    function formatMyTaskLabel(task) {
        const bits = [`${task.household_name}: ${task.title}`];
        const assigneesBit = formatAssigneesBit(task);
        if (assigneesBit) {
            bits.push(assigneesBit);
        }
        if (task.recurrence_frequency) {
            bits.push(describeRecurrence(task.recurrence_frequency, Number(task.recurrence_interval)));
        }
        const dueAtBit = formatDueAtBit(task);
        if (dueAtBit) {
            bits.push(dueAtBit);
        }
        if (task.notes) {
            bits.push(`note: ${task.notes}`);
        }
        if (Number(task.completion_count) > 0) {
            bits.push(`completed ${task.completion_count}x`);
        }
        return bits.join(' — ');
    }

    // myTasksCache/renderMyTasks() - the "Show open-ended tasks" checkbox
    // just re-renders from the last-fetched list instead of re-fetching, so
    // toggling it is instant and never fights with a concurrent complete.
    // The server already does the actual sorting (open-ended tasks bubble
    // to the top, highest priority first -- see
    // HouseholdTaskInstanceRepository::listAssignedToUser()); this only
    // ever hides/shows rows, never reorders them.
    let myTasksCache = [];

    function renderMyTasks() {
        const list = document.getElementById('my-tasks-list');
        list.innerHTML = '';

        const showOpenEnded = document.getElementById('my-tasks-show-open-ended').checked;
        const tasks = showOpenEnded ? myTasksCache : myTasksCache.filter((task) => !isOpenEnded(task));

        if (tasks.length === 0) {
            const li = document.createElement('li');
            li.textContent = myTasksCache.length === 0
                ? 'Nothing assigned to you right now.'
                : 'No tasks match the current filter.';
            list.appendChild(li);
            return;
        }

        for (const task of tasks) {
            const { li, actions } = buildListItem(formatMyTaskLabel(task));
            if (isDueToday(task)) {
                li.classList.add('task-due-today');
            }
            if (isTaskOverdue(task)) {
                li.classList.add('task-overdue');
            }
            actions.appendChild(buildIconButton(CHECK_ICON, 'Complete', async () => {
                await apiRequest('/households/tasks/complete', {
                    method: 'POST',
                    body: JSON.stringify({ instance_id: task.id }),
                });
                await loadMyTasks();
            }));
            if (task.recurrence_frequency) {
                actions.appendChild(buildIconButton(SKIP_ICON, 'Skip', () => renderSkipForm(li, task, () => loadMyTasks())));
            }
            list.appendChild(li);
        }
    }

    async function loadMyTasks() {
        const { response, body } = await apiRequest('/tasks/mine');
        myTasksCache = response.ok ? body.tasks : [];
        renderMyTasks();
    }

    // renderTaskEditForm(...) - same inline-edit pattern as
    // renderNoteEditForm()/renderPetEditForm(); any household member may
    // edit any task (a shared resource, like pets). Editing updates both
    // the underlying recurring/one-off definition (title/description/
    // assignee/recurrence) and this specific instance's own due date --
    // see TaskService::updateTask()'s own docblock for why moving the due
    // date only affects the instance being edited, not the whole series.
    //
    // $reload (same idea as renderSkipForm()'s own $reload) - this form
    // gets embedded in several different lists now (the Tasks tab, a
    // project's own task list, the Maintenance schedule), each needing its
    // *own* list refreshed after a save/cancel, not always the Tasks tab's
    // -- a task edited from the Maintenance section, say, still needs
    // #home-improvement-maintenance-list re-rendered, or the edit form
    // just sits there looking like Save silently did nothing even though
    // the update went through.
    function renderTaskEditForm(li, task, householdId, reload) {
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

        const notesLabel = document.createElement('label');
        notesLabel.textContent = 'Notes';
        const notesTextarea = document.createElement('textarea');
        notesTextarea.value = task.notes || '';
        notesTextarea.maxLength = 2000;
        notesLabel.appendChild(notesTextarea);
        form.appendChild(notesLabel);

        const assigneeFieldset = document.createElement('fieldset');
        assigneeFieldset.className = 'checkbox-fieldset';
        const assigneeLegend = document.createElement('legend');
        assigneeLegend.textContent = 'Assign to';
        assigneeFieldset.appendChild(assigneeLegend);
        const assigneesContainer = document.createElement('div');
        assigneeFieldset.appendChild(assigneesContainer);
        form.appendChild(assigneeFieldset);
        populateAssigneeCheckboxes(assigneesContainer, (task.assignees || []).map((assignee) => assignee.id));

        const modeLabel = document.createElement('label');
        modeLabel.textContent = 'If assigned to more than one person';
        const modeSelect = document.createElement('select');
        for (const [value, text] of [['anyone', 'Anyone completes it for all'], ['everyone', 'Everyone completes their own']]) {
            const option = document.createElement('option');
            option.value = value;
            option.textContent = text;
            modeSelect.appendChild(option);
        }
        modeSelect.value = task.assignment_mode || 'anyone';
        modeLabel.appendChild(modeSelect);
        form.appendChild(modeLabel);

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
        dueAtLabel.textContent = 'Due date (leave blank for an open-ended task with no deadline)';
        const dueAtInput = document.createElement('input');
        dueAtInput.type = 'date';
        dueAtInput.value = task.due_at || '';
        dueAtLabel.appendChild(dueAtInput);
        form.appendChild(dueAtLabel);

        const priorityLabel = document.createElement('label');
        priorityLabel.textContent = 'Priority (used to sort open-ended tasks)';
        const prioritySelect = document.createElement('select');
        for (const [value, text] of [['', 'None'], ['low', 'Low'], ['medium', 'Medium'], ['high', 'High'], ['critical', 'Critical']]) {
            const option = document.createElement('option');
            option.value = value;
            option.textContent = text;
            prioritySelect.appendChild(option);
        }
        prioritySelect.value = task.priority || '';
        priorityLabel.appendChild(prioritySelect);
        form.appendChild(priorityLabel);

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
        cancelButton.addEventListener('click', () => reload());
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
                    instance_id: task.id,
                    title: titleInput.value,
                    description: descriptionTextarea.value,
                    notes: notesTextarea.value,
                    assigned_to_user_ids: getCheckedAssigneeIds(assigneesContainer),
                    assignment_mode: modeSelect.value,
                    recurrence_frequency: frequencySelect.value,
                    recurrence_interval: frequencySelect.value ? intervalInput.value : '',
                    due_at: dueAtInput.value,
                    priority: prioritySelect.value,
                }),
            });

            if (response.ok) {
                await reload();
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

    document.getElementById('hi-maintenance-frequency').addEventListener('change', (event) => {
        const frequency = event.target.value;
        document.getElementById('hi-maintenance-interval-unit').textContent = RECURRENCE_UNITS[frequency] ? `${RECURRENCE_UNITS[frequency]}(s)` : 'month(s)';
    });

    document.getElementById('home-improvement-project-form').addEventListener('submit', async (event) => {
        event.preventDefault();
        const form = event.target;
        const messageEl = document.getElementById('hi-project-message');
        messageEl.hidden = true;

        const { response, body } = await apiRequest('/households/projects', {
            method: 'POST',
            body: JSON.stringify({
                household_id: currentHouseholdId,
                title: form.title.value,
                description: form.description.value,
                status: form.status.value,
                estimated_cost: form.estimated_cost.value,
                target_date: form.target_date.value,
            }),
        });

        if (response.ok) {
            form.reset();
            await loadProjects(currentHouseholdId);
            return;
        }

        messageEl.textContent = (body && body.message) || 'Could not add project.';
        messageEl.className = 'message message--error';
        messageEl.hidden = false;
    });

    document.getElementById('hi-project-detail-close').addEventListener('click', closeProjectDetail);

    document.getElementById('hi-project-task-form').addEventListener('submit', async (event) => {
        event.preventDefault();
        const form = event.target;
        const messageEl = document.getElementById('hi-project-task-message');
        messageEl.hidden = true;

        const { response, body } = await apiRequest('/households/tasks', {
            method: 'POST',
            body: JSON.stringify({
                household_id: currentHouseholdId,
                title: form.title.value,
                assigned_to_user_ids: getCheckedAssigneeIds(document.getElementById('hi-project-task-assignees')),
                due_at: form.due_at.value,
                source_type: 'home_improvement_project',
                source_id: currentProjectId,
            }),
        });

        if (response.ok) {
            form.reset();
            await loadProjects(currentHouseholdId);
            await loadTasks(currentHouseholdId);
            return;
        }

        messageEl.textContent = (body && body.message) || 'Could not add task.';
        messageEl.className = 'message message--error';
        messageEl.hidden = false;
    });

    document.getElementById('home-improvement-maintenance-form').addEventListener('submit', async (event) => {
        event.preventDefault();
        const form = event.target;
        const messageEl = document.getElementById('hi-maintenance-message');
        messageEl.hidden = true;

        const { response, body } = await apiRequest('/households/tasks', {
            method: 'POST',
            body: JSON.stringify({
                household_id: currentHouseholdId,
                title: form.title.value,
                assigned_to_user_ids: getCheckedAssigneeIds(document.getElementById('hi-maintenance-assignees')),
                recurrence_frequency: form.recurrence_frequency.value,
                recurrence_interval: form.recurrence_interval.value,
                source_type: 'maintenance',
            }),
        });

        if (response.ok) {
            form.reset();
            await loadMaintenance(currentHouseholdId);
            await loadTasks(currentHouseholdId);
            return;
        }

        messageEl.textContent = (body && body.message) || 'Could not add maintenance item.';
        messageEl.className = 'message message--error';
        messageEl.hidden = false;
    });

    document.getElementById('my-tasks-show-open-ended').addEventListener('change', renderMyTasks);
    document.getElementById('my-tasks-refresh-button').addEventListener('click', () => loadMyTasks());

    document.getElementById('household-tasks-finished-toggle').addEventListener('click', async (event) => {
        const list = document.getElementById('household-tasks-finished-list');
        if (list.hidden) {
            await loadFinishedTasksToday(currentHouseholdId);
            list.hidden = false;
            event.target.textContent = 'Hide finished today';
        } else {
            list.hidden = true;
            event.target.textContent = 'Show finished today';
        }
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
                notes: form.notes.value,
                assigned_to_user_ids: getCheckedAssigneeIds(document.getElementById('household-task-assignees')),
                assignment_mode: form.assignment_mode.value,
                recurrence_frequency: form.recurrence_frequency.value,
                recurrence_interval: form.recurrence_frequency.value ? form.recurrence_interval.value : '',
                due_at: form.due_at.value,
                priority: form.priority.value,
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
    await loadMyTasks();
})();
