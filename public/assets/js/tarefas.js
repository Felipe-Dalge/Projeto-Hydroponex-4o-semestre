const taskPriorities = { baixa: 'Baixa', media: 'Média', alta: 'Alta', critica: 'Crítica' };
const taskActionLabels = { iniciar: 'Iniciar tarefa', verificar: 'Solicitar verificação', concluir: 'Aprovar e concluir',
    corrigir: 'Solicitar correções', reabrir: 'Reabrir tarefa', cancelar: 'Cancelar tarefa' };
const taskEventLabels = { criacao: 'Criação', atribuicao: 'Atribuição', reatribuicao: 'Mudança de responsável',
    edicao: 'Edição', inicio: 'Início', verificacao: 'Solicitação de verificação', aprovacao: 'Aprovação',
    correcao: 'Correções solicitadas', reabertura: 'Reabertura', cancelamento: 'Cancelamento',
    intervencao: 'Intervenção', observacao: 'Observação', migracao: 'Importação do estado anterior' };
let taskListVersion = 0;
let taskDetailVersion = 0;
let taskDetail = null;
let taskMutationBusy = false;
let taskCreateScope = null;

function taskLocalInput(value) {
    if (!value) return '';
    const date = new Date(value.replace(' ', 'T') + 'Z');
    date.setMinutes(date.getMinutes() - date.getTimezoneOffset());
    return date.toISOString().slice(0, 16);
}

function taskInputDate(value) {
    return value ? new Date(value).toISOString() : null;
}

function resetTaskCreation() {
    const form = document.getElementById('task-create-form');
    form.reset();
    form.dataset.key = crypto.randomUUID();
    form.dataset.source = 'manual';
    delete form.dataset.reading;
    setFeedback('task-create-source', '');
}

function clearManagedTasks() {
    taskListVersion++;
    document.getElementById('tasks-list').replaceChildren();
    document.getElementById('task-create-form').classList.add('hidden');
    document.getElementById('tasks-prev').disabled = true;
    document.getElementById('tasks-next').disabled = true;
    setFeedback('tasks-page', '');
    closeTaskDetail();
}

function taskCard(task) {
    const item = operationalText('li', '', 'op-card');
    item.append(operationalText('h3', task.titulo, 'font-bold'),
        operationalText('p', `${task.horta || ''}${task.planta ? ' · ' + task.planta : ' · Tarefa geral da horta'}`),
        operationalText('p', `${taskStates[task.estado]} · Prioridade ${taskPriorities[task.prioridade]}`),
        operationalText('p', task.responsavel ? 'Responsável: ' + task.responsavel : 'Sem responsável'));
    if (task.atrasada) item.append(operationalText('strong', 'Atrasada', 'text-red-700 dark:text-red-300'));
    if (task.responsavel_sem_acesso) item.append(operationalText('p', 'Responsável sem acesso operacional. A gestão precisa reatribuir esta tarefa.'));
    if (task.prazo_em) item.append(operationalText('p', 'Prazo: ' + operationalDate(task.prazo_em)));
    item.append(operationalButton('Detalhes e execução', () => showTaskDetail(task.id_horta, task.id_tarefa)));
    return item;
}

async function loadTasks(page = 1) {
    const version = ++taskListVersion;
    const user = currentUser?.id;
    const garden = selectedHortaId;
    const form = document.getElementById('task-create-form');
    document.getElementById('tasks-list').replaceChildren();
    form.classList.add('hidden');
    document.getElementById('tasks-prev').disabled = true;
    document.getElementById('tasks-next').disabled = true;
    setFeedback('tasks-page', '');
    setFeedback('tasks-feedback', 'Carregando tarefas…');
    const current = () => version === taskListVersion && currentUser?.id === user && garden === selectedHortaId && location.hash === '#tarefas';
    if (!garden) { setFeedback('tasks-feedback', 'Selecione uma horta para gerenciar tarefas.'); return; }
    if (taskCreateScope !== garden) {
        taskCreateScope = garden;
        resetTaskCreation();
    }
    const filter = Object.fromEntries(new FormData(document.getElementById('task-filters')));
    try {
        const result = await backendRequest(collaborationUrl('tarefas', garden) + '&' + new URLSearchParams({ ...filter, pagina: page }));
        if (!current()) return;
        const selected = form.elements.id_responsavel.value;
        const selectedPlant = form.elements.id_planta.value;
        form.elements.id_responsavel.replaceChildren(new Option('Sem responsável', ''),
            ...result.responsaveis.map(person => new Option(person.nome, person.id_usuario)));
        form.elements.id_responsavel.value = selected;
        form.elements.id_planta.replaceChildren(new Option('Tarefa geral da horta', ''),
            ...plantas.map(plant => new Option(plant.nome, plant.id_planta)));
        form.elements.id_planta.value = selectedPlant;
        form.classList.toggle('hidden', !result.pode_gerenciar);
        const select = document.getElementById('task-filters').elements.responsavel;
        const value = select.value;
        select.replaceChildren(new Option('Todos', ''), new Option('Sem responsável', 'sem_responsavel'),
            ...result.responsaveis_filtro.map(person => new Option(person.nome, person.id_usuario)));
        select.value = value;
        document.getElementById('tasks-list').replaceChildren(...result.data.map(taskCard));
        setFeedback('tasks-feedback', result.data.length ? '' : 'Nenhuma tarefa corresponde aos filtros.');
        document.getElementById('tasks-prev').disabled = page === 1;
        document.getElementById('tasks-next').disabled = !result.mais;
        document.getElementById('tasks-page').textContent = 'Página ' + page;
        document.getElementById('tasks-prev').onclick = () => loadTasks(page - 1);
        document.getElementById('tasks-next').onclick = () => loadTasks(page + 1);
    } catch (error) { if (current()) setFeedback('tasks-feedback', error.message); }
}

async function openTaskCreation(garden, plant = null, reading = null) {
    const user = currentUser?.id;
    closeOperationalDetail();
    await selectHorta(garden, false);
    if (currentUser?.id !== user || !selectedHorta?.pode_gerenciar_tarefas) return;
    navigateTo('tarefas');
    await loadTasks();
    if (currentUser?.id !== user || Number(selectedHortaId) !== Number(garden) || location.hash !== '#tarefas') return;
    resetTaskCreation();
    const form = document.getElementById('task-create-form');
    form.elements.id_planta.replaceChildren(new Option('Tarefa geral da horta', ''),
        ...plantas.map(row => new Option(row.nome, row.id_planta)));
    form.elements.id_planta.value = plant || '';
    form.dataset.source = reading ? 'leitura' : (plant ? 'estado_planta' : 'manual');
    if (reading) form.dataset.reading = reading;
    setFeedback('task-create-source', reading ? 'Origem: leitura fora dos limites selecionada.' : (plant ? 'Origem: estado atual da planta.' : ''));
    form.scrollIntoView({ block: 'start' });
    form.elements.titulo.focus();
}

async function createManagedTask(event) {
    const form = event.currentTarget;
    const garden = selectedHortaId;
    const user = currentUser?.id;
    await authForm(event, 'tasks-feedback', async () => {
        if (!form.dataset.key) form.dataset.key = crypto.randomUUID();
        const data = Object.fromEntries(new FormData(form));
        data.prazo_em = taskInputDate(data.prazo_em);
        data.chave_criacao = form.dataset.key;
        data.origem_problema = form.dataset.source || 'manual';
        if (form.dataset.reading) data.id_leitura = form.dataset.reading;
        await backendRequest(collaborationUrl('tarefas', garden), 'POST', data);
        if (currentUser?.id !== user || garden !== selectedHortaId) return;
        resetTaskCreation();
        await loadTasks();
        setFeedback('tasks-feedback', 'Tarefa criada.');
    });
}

function closeTaskDetail() {
    taskDetailVersion++;
    taskDetail = null;
    document.getElementById('task-dialog').close();
    for (const id of ['task-description', 'task-actions', 'task-work-list', 'task-history-list']) document.getElementById(id).replaceChildren();
    for (const id of ['task-edit-form', 'task-work-form']) {
        document.getElementById(id).reset();
        document.getElementById(id).classList.add('hidden');
    }
}

function appendTaskFeed(target, resource, rows) {
    for (const row of rows) {
        const item = operationalText('li', '', 'op-card');
        if (resource === 'tarefa_historico') {
            item.append(operationalText('p', `${taskEventLabels[row.evento] || row.evento} · ${row.autor || 'Migração do sistema'} · ${operationalDate(row.criado_em)}`));
            const details = row.detalhes;
            if (details.estado) item.append(operationalText('p', typeof details.estado === 'string' ? taskStates[details.estado]
                : `${taskStates[details.estado.antes]} → ${taskStates[details.estado.depois]}`));
            if (details.justificativa) item.append(operationalText('p', details.justificativa));
            for (const field of ['titulo', 'descricao', 'prioridade', 'prazo_em', 'id_responsavel']) {
                const change = details[field];
                if (change && typeof change === 'object') item.append(operationalText('p', `${field}: ${change.antes ?? 'não informado'} → ${change.depois ?? 'não informado'}`));
            }
        } else {
            item.append(operationalText('p', `${row.tipo === 'intervencao' ? 'Intervenção' : 'Observação'} · ${row.autor} · Realizada em ${operationalDate(row.realizada_em)}`),
                operationalText('p', row.descricao));
            if (row.observacoes) item.append(operationalText('p', row.observacoes));
        }
        target.append(item);
    }
}

async function showTaskDetail(garden, id) {
    const user = currentUser?.id;
    const version = ++taskDetailVersion;
    const current = () => user === currentUser?.id && version === taskDetailVersion;
    taskDetail = null;
    const dialog = document.getElementById('task-dialog');
    document.getElementById('task-dialog-title').textContent = 'Tarefa';
    document.getElementById('task-more-work').hidden = true;
    document.getElementById('task-more-history').hidden = true;
    for (const section of ['task-description', 'task-actions', 'task-work-list', 'task-history-list']) document.getElementById(section).replaceChildren();
    document.getElementById('task-edit-form').classList.add('hidden');
    document.getElementById('task-work-form').classList.add('hidden');
    if (!dialog.open) dialog.showModal();
    setFeedback('task-detail-feedback', 'Carregando tarefa…');
    try {
        const response = await backendRequest(collaborationUrl('tarefas', garden) + '&id=' + id);
        if (!current()) return;
        taskDetail = { garden, id, task: response.data, user };
        const context = taskDetail;
        const task = response.data;
        document.getElementById('task-dialog-title').textContent = task.titulo;
        const description = document.getElementById('task-description');
        description.append(operationalText('p', `${task.horta} · ${task.planta || 'Tarefa geral'} · ${taskStates[task.estado]} · Prioridade ${taskPriorities[task.prioridade]}`),
            operationalText('p', task.descricao || 'Sem descrição complementar'),
            operationalText('p', task.problema || 'Sem descrição adicional do problema'),
            operationalText('p', task.responsavel ? 'Responsável: ' + task.responsavel : 'Sem responsável'));
        if (task.id_leitura) description.append(operationalText('p', 'Leitura de origem #' + task.id_leitura));
        if (task.prazo_em) description.append(operationalText('p', 'Prazo: ' + operationalDate(task.prazo_em)));
        if (task.atrasada) description.append(operationalText('strong', 'Atrasada'));
        if (task.responsavel_sem_acesso) description.append(operationalText('p', 'Responsável sem acesso operacional. Reatribua a tarefa para retomar a execução.'));
        if (task.concluida_em) description.append(operationalText('p', 'Concluída em ' + operationalDate(task.concluida_em)));
        const actions = document.getElementById('task-actions');
        actions.append(operationalButton('Recarregar detalhes', () => showTaskDetail(garden, id)));
        for (const action of task.acoes) {
            actions.append(operationalButton(taskActionLabels[action], () => {
                let reason = '';
                if (['corrigir', 'reabrir', 'cancelar'].includes(action)) {
                    reason = prompt('Informe a justificativa desta ação.');
                    if (reason === null) return;
                }
                mutateTask(context, 'tarefas', { acao: action, justificativa: reason });
            }));
        }
        const edit = document.getElementById('task-edit-form');
        edit.reset();
        edit.elements.id_responsavel.replaceChildren(new Option('Sem responsável', ''),
            ...response.responsaveis.map(person => new Option(person.nome, person.id_usuario)));
        if (task.responsavel_sem_acesso) edit.elements.id_responsavel.append(new Option(task.responsavel + ' (sem acesso)', task.id_responsavel));
        for (const field of ['titulo', 'descricao', 'prioridade', 'id_responsavel']) edit.elements[field].value = task[field] ?? '';
        edit.elements.prazo_em.value = taskLocalInput(task.prazo_em);
        edit.classList.toggle('hidden', !task.pode_editar);
        const work = document.getElementById('task-work-form');
        work.reset();
        work.elements.realizada_em.value = taskLocalInput(new Date().toISOString().replace('T', ' ').replace('Z', ''));
        work.classList.toggle('hidden', !task.pode_intervir);
        for (const [resource, feed, listId, buttonId] of [
            ['tarefa_historico', response.historico, 'task-history-list', 'task-more-history'],
            ['tarefa_intervencoes', response.intervencoes, 'task-work-list', 'task-more-work']
        ]) {
            const list = document.getElementById(listId);
            appendTaskFeed(list, resource, feed.itens);
            if (!feed.itens.length) list.append(operationalText('li', 'Nenhum registro.'));
            let page = 1;
            const button = document.getElementById(buttonId);
            button.hidden = !feed.mais;
            button.onclick = async () => {
                button.disabled = true;
                try {
                    const next = await backendRequest(collaborationUrl(resource, garden) + '&' + new URLSearchParams({ id_tarefa: id, pagina: page + 1 }));
                    if (!current()) return;
                    appendTaskFeed(list, resource, next.data.itens);
                    page++;
                    button.hidden = !next.data.mais;
                } catch (error) { if (current()) setFeedback('task-detail-feedback', error.message); }
                finally { button.disabled = false; }
            };
        }
        setFeedback('task-detail-feedback', '');
    } catch (error) { if (current()) setFeedback('task-detail-feedback', error.message); }
}

async function mutateTask(context, resource, data) {
    if (taskMutationBusy || taskDetail !== context) return;
    taskMutationBusy = true;
    setFeedback('task-detail-feedback', 'Salvando…');
    try {
        await backendRequest(collaborationUrl(resource, context.garden), resource === 'tarefas' ? 'PATCH' : 'POST',
            { ...data, id_tarefa: context.id, versao: context.task.versao });
        if (currentUser?.id !== context.user || taskDetail !== context) return;
        await showTaskDetail(context.garden, context.id);
        if (location.hash === '#tarefas') await loadTasks();
        else if (location.hash === '#operacao') await loadOperational();
        else if (location.hash === '#colaboracao') await loadCollaboration();
        setFeedback('task-detail-feedback', 'Alteração registrada.');
    } catch (error) {
        if (taskDetail === context) setFeedback('task-detail-feedback', error.message);
    } finally { taskMutationBusy = false; }
}

async function editManagedTask(event) {
    event.preventDefault();
    const context = taskDetail;
    if (!context) return;
    const data = Object.fromEntries(new FormData(event.currentTarget));
    if (data.prazo_em === taskLocalInput(context.task.prazo_em)) delete data.prazo_em;
    else data.prazo_em = taskInputDate(data.prazo_em);
    for (const field of ['titulo', 'descricao', 'prioridade']) {
        if (data[field] === (context.task[field] ?? '')) delete data[field];
    }
    // Reenviar um responsável já revogado não deve bloquear alterações de título ou prazo.
    if (String(data.id_responsavel) === String(context.task.id_responsavel ?? '')) delete data.id_responsavel;
    await mutateTask(context, 'tarefas', { ...data, acao: 'editar' });
}

async function saveTaskWork(event) {
    event.preventDefault();
    const context = taskDetail;
    if (!context) return;
    const data = Object.fromEntries(new FormData(event.currentTarget));
    data.realizada_em = taskInputDate(data.realizada_em);
    await mutateTask(context, 'tarefa_intervencoes', data);
}

document.getElementById('task-dialog').addEventListener('cancel', event => {
    event.preventDefault();
    closeTaskDetail();
});
