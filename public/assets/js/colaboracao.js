const workspaceLabels = { administrativa: 'Área administrativa', agricultor: 'Área do agricultor' };
const memberRoles = { proprietario: 'Proprietário', gestor: 'Gestor', agricultor: 'Agricultor', leitor: 'Leitor' };
const taskStates = { pendente: 'Pendente', em_andamento: 'Em andamento', em_verificacao: 'Em verificação', concluida: 'Concluída', cancelada: 'Cancelada' };
let collaborationVersion = 0;
let collaborationFormScope = '';
let workspaceSwitching = false;

function administrativeWorkspace() {
    return currentUser?.area === 'administrativa';
}

function renderWorkspace() {
    const select = document.getElementById('area-selector');
    const areas = currentUser?.areas || [];
    select.replaceChildren(...areas.map(area => new Option(workspaceLabels[area], area)));
    select.value = currentUser?.area || '';
    select.disabled = workspaceSwitching || areas.length < 2;
    for (const button of document.querySelectorAll('[data-team-nav]')) {
        button.classList.toggle('hidden', !administrativeWorkspace());
        button.classList.toggle('flex', administrativeWorkspace());
    }
    renderOperationalNavigation();
}

async function switchWorkspace(area) {
    if (!currentUser || workspaceSwitching) return;
    const userId = currentUser.id;
    workspaceSwitching = true;
    renderWorkspace();
    setFeedback('area-feedback', '');
    try {
        const result = await backendRequest('php/api/auth/area.php', 'PATCH', { area });
        if (currentUser?.id !== userId) return;
        currentUser = result.data;
        updateUserUI();
        await Promise.all([selectHorta(selectedHortaId, false), loadHortas()]);
        navigateTo(administrativeWorkspace() ? 'dashboard' : 'operacao');
    } catch (error) {
        setFeedback('area-feedback', error.message);
    } finally {
        workspaceSwitching = false;
        renderWorkspace();
    }
}

function clearCollaboration() {
    collaborationVersion++;
    for (const id of ['member-list', 'invite-list', 'task-list', 'record-list']) {
        document.getElementById(id).replaceChildren();
    }
    for (const id of ['collaboration-members', 'collaboration-tasks', 'collaboration-records']) {
        document.getElementById(id).classList.add('hidden');
    }
    document.getElementById('invite-code').value = '';
    setFeedback('collaboration-feedback', '');
    setFeedback('accept-invite-feedback', '');
}

function collaborationUrl(resource, gardenId = selectedHortaId) {
    return `php/api/${resource}.php?horta=${encodeURIComponent(gardenId)}`;
}

function collaborationContext() {
    return { user: currentUser?.id, garden: selectedHortaId, area: currentUser?.area, version: gardensContextVersion };
}

function collaborationCurrent(context) {
    return currentUser?.id === context.user && selectedHortaId === context.garden
        && currentUser?.area === context.area && gardensContextVersion === context.version
        && window.location.hash === '#colaboracao';
}

async function loadCollaboration() {
    clearCollaboration();
    const request = collaborationVersion;
    const context = collaborationContext();
    const scope = JSON.stringify([context.user, context.garden, context.area]);
    if (scope !== collaborationFormScope) {
        for (const id of ['new-task-form', 'new-record-form']) {
            document.getElementById(id).reset();
        }
        collaborationFormScope = scope;
    }
    if (!currentUser || !selectedHorta) {
        setFeedback('collaboration-feedback', 'Selecione uma horta para consultar tarefas e registros.');
        return;
    }
    const garden = selectedHorta;
    const manageMembers = administrativeWorkspace() && garden.pode_gerenciar_membros;
    const manageTasks = administrativeWorkspace() && garden.pode_gerenciar_tarefas;
    try {
        const [records, tasks, members, invites] = await Promise.all([
            backendRequest(collaborationUrl('registros', context.garden)),
            garden.pode_consultar_tarefas ? backendRequest(collaborationUrl('tarefas', context.garden)) : null,
            manageMembers ? backendRequest(collaborationUrl('membros', context.garden)) : null,
            manageMembers ? backendRequest(collaborationUrl('convites', context.garden)) : null
        ]);
        if (request !== collaborationVersion || !collaborationCurrent(context)) return;
        document.getElementById('collaboration-members').classList.toggle('hidden', !manageMembers);
        document.getElementById('collaboration-tasks').classList.toggle('hidden', !tasks);
        document.getElementById('collaboration-records').classList.remove('hidden');
        document.getElementById('new-task-form').classList.toggle('hidden', !manageTasks);
        document.getElementById('new-record-form').classList.toggle('hidden', !garden.pode_registrar);
        for (const id of ['new-task-form', 'new-record-form']) {
            const select = document.getElementById(id).elements.id_planta;
            const selected = select.value;
            select.replaceChildren(new Option('Geral da horta', ''), ...plantas.map(plant => new Option(plant.nome, plant.id_planta)));
            select.value = selected;
        }
        renderCollaborationList('record-list', records.data, 'Nenhum registro nesta horta.', (row, record) => {
            row.textContent = `${record.tipo === 'intervencao' ? 'Intervenção' : 'Observação'} · ${record.autor} · ${record.criado_em} UTC — ${record.descricao}`;
        });
        if (tasks) renderGardenTasks(tasks, manageTasks, context);
        if (members) renderGardenMembers(members.data, invites.data);
    } catch (error) {
        if (request === collaborationVersion && collaborationCurrent(context)) setFeedback('collaboration-feedback', error.message);
    }
}

function renderCollaborationList(id, data, empty, render) {
    const list = document.getElementById(id);
    list.replaceChildren();
    if (!data.length) {
        const row = document.createElement('li');
        row.textContent = empty;
        list.append(row);
    }
    for (const item of data) {
        const row = document.createElement('li');
        row.className = 'flex flex-wrap items-center gap-2 border-b border-slate-200 dark:border-slate-700 py-2 break-words';
        render(row, item);
        list.append(row);
    }
}

function collaborationSelect(options, value, label) {
    const select = document.createElement('select');
    select.className = 'rounded-lg border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-darkbg-900 p-2';
    select.setAttribute('aria-label', label);
    select.append(...Object.entries(options).map(([key, text]) => new Option(text, key)));
    select.value = value;
    return select;
}

function collaborationButton(label, action) {
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'text-hydro-600 dark:text-hydro-400 font-semibold';
    button.textContent = label;
    button.addEventListener('click', action);
    return button;
}

async function collaborationMutation(resource, method, data, form = null, message = '') {
    const context = collaborationContext();
    try {
        await backendRequest(collaborationUrl(resource, context.garden), method, data);
        if (!collaborationCurrent(context)) return;
        form?.reset();
        await loadCollaboration();
        if (message && collaborationCurrent(context)) setFeedback('collaboration-feedback', message);
    } catch (error) {
        if (collaborationCurrent(context)) setFeedback('collaboration-feedback', error.message);
    }
}

function renderGardenTasks(tasks, manage, context) {
    const select = document.getElementById('new-task-form').elements.id_responsavel;
    select.replaceChildren(new Option('Sem responsável', ''), ...tasks.responsaveis.map(person => new Option(person.nome, person.id_usuario)));
    const visible = manage ? tasks.data : tasks.data.filter(task => Number(task.id_responsavel) === Number(context.user));
    document.getElementById('task-list').replaceChildren(...visible.map(taskCard));
    if (!visible.length) document.getElementById('task-list').append(operationalText('li', 'Nenhuma tarefa para este acesso.'));
}

function renderGardenMembers(members, invites) {
    renderCollaborationList('invite-list', invites, 'Nenhum convite nesta horta.', (row, invite) => {
        row.textContent = `${invite.email} · ${memberRoles[invite.papel]} · ${invite.estado}`;
        if (invite.estado === 'pendente') row.append(collaborationButton('Revogar convite', () =>
            collaborationMutation('convites', 'DELETE', { id_convite: invite.id_convite })));
    });
    renderCollaborationList('member-list', members, 'Nenhum membro encontrado.', (row, member) => {
        row.textContent = `${member.nome} · ${member.email} · ${memberRoles[member.papel]}`;
        if (member.papel === 'proprietario') return;
        const role = collaborationSelect({ gestor: 'Gestor', agricultor: 'Agricultor', leitor: 'Leitor' }, member.papel, `Papel de ${member.nome}`);
        row.append(role, collaborationButton('Salvar papel', () =>
            collaborationMutation('membros', 'PATCH', { id_usuario: member.id_usuario, papel: role.value })));
        row.append(collaborationButton('Revogar acesso', () => {
            if (window.confirm(`Revogar o acesso de ${member.nome} a esta horta?`)) {
                collaborationMutation('membros', 'DELETE', { id_usuario: member.id_usuario });
            }
        }));
    });
}

async function acceptInvite(event) {
    await authForm(event, 'accept-invite-feedback', async () => {
        const userId = currentUser?.id;
        const input = document.getElementById('invite-code');
        let result;
        try {
            result = await backendRequest('php/api/convites.php', 'PATCH', { codigo: input.value.trim() });
        } finally {
            input.value = '';
        }
        if (currentUser?.id !== userId) return;
        await refreshGardenAccess(result.data.id_horta);
        setFeedback('accept-invite-feedback', 'Convite aceito. A horta está disponível no seletor.');
    });
}

async function createGardenTask(event) {
    const form = event.currentTarget;
    await authForm(event, 'collaboration-feedback', async () => {
        const data = Object.fromEntries(new FormData(form));
        form.dataset.key ||= crypto.randomUUID();
        data.chave_criacao = form.dataset.key;
        await collaborationMutation('tarefas', 'POST', data, form, 'Tarefa cadastrada.');
        if (!form.elements.titulo.value) delete form.dataset.key;
    });
}

async function createGardenRecord(event) {
    const form = event.currentTarget;
    await authForm(event, 'collaboration-feedback', async () => {
        const data = Object.fromEntries(new FormData(form));
        await collaborationMutation('registros', 'POST', data, form, 'Registro salvo.');
    });
}
