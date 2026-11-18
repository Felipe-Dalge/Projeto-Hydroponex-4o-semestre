let operationalMode = 'inicio';
let operationalVersion = 0;
let operationalDetailVersion = 0;
let operationalDetail = null;
const operationalTitles = { inicio: 'Painel do agricultor', hortas: 'Minhas hortas', problemas: 'Plantas com problemas', tarefas: 'Minhas tarefas' };
const operationalOrigins = { manual: 'Registro manual', sensor: 'Dispositivo', importacao: 'Importação', simulacao: 'SIMULAÇÃO' };
const operationalFreshness = { ausente: 'Data da medição indisponível', recente: 'Medição recente', desatualizada: 'Medição há mais de 24 horas', futura: 'Data futura, conferir cadastro' };

function renderOperationalNavigation() {
    for (const nav of document.querySelectorAll('#app-sidebar nav, #mobile-sidebar nav')) {
        for (const button of nav.querySelectorAll('button')) {
            const adminPage = /navigateTo\('(dashboard|infraestrutura|hortas|plantas|monitoramento|consumo)'\)/.test(button.getAttribute('onclick') || '');
            if (adminPage) {
                button.classList.toggle('hidden', !administrativeWorkspace());
                button.classList.toggle('flex', administrativeWorkspace());
            }
        }
    }
    for (const button of document.querySelectorAll('[data-operation-only]')) {
        button.classList.toggle('hidden', administrativeWorkspace());
        button.classList.toggle('flex', !administrativeWorkspace());
    }
    for (const button of document.querySelectorAll('[data-task-admin-nav]')) {
        button.classList.toggle('hidden', !administrativeWorkspace());
        button.classList.toggle('flex', administrativeWorkspace());
    }
}

function openOperational(mode = 'inicio', garden = null) {
    operationalMode = operationalTitles[mode] ? mode : 'inicio';
    if (garden !== null) document.getElementById('op-garden').value = String(garden);
    if (mode === 'problemas') document.getElementById('op-status').value = '';
    navigateTo('operacao');
}

function clearOperational() {
    operationalVersion++;
    closeOperationalDetail();
    for (const id of ['op-stats', 'op-gardens', 'op-plants', 'op-tasks']) document.getElementById(id).replaceChildren();
    setFeedback('op-feedback', '');
}

function operationalCurrent(user, version) {
    return currentUser?.id === user && version === operationalVersion && location.hash === '#operacao';
}

function operationalUrl(resource, values = {}) {
    return 'php/api/operacional.php?' + new URLSearchParams({ recurso: resource, ...values });
}

function operationalText(tag, text, className = '') {
    const node = document.createElement(tag);
    node.textContent = text;
    node.className = className;
    return node;
}

function operationalButton(text, action) {
    const button = collaborationButton(text, action);
    button.classList.add('op-button');
    return button;
}

function operationalDate(value) {
    if (!value) return 'Data indisponível';
    const date = new Date(value.replace(' ', 'T') + 'Z');
    return Number.isNaN(date.getTime()) ? 'Data inválida' : date.toLocaleString('pt-BR');
}

async function loadOperational(page = 1) {
    if (!currentUser || location.hash !== '#operacao') return;
    const user = currentUser.id;
    const version = ++operationalVersion;
    const mode = operationalMode;
    const garden = document.getElementById('op-garden').value;
    const type = document.getElementById('op-type').value;
    document.getElementById('op-title').textContent = operationalTitles[mode];
    for (const id of ['op-stats', 'op-gardens', 'op-plants', 'op-tasks']) document.getElementById(id).replaceChildren();
    document.getElementById('op-pages').hidden = true;
    document.getElementById('op-status-label').hidden = mode === 'tarefas' || mode === 'hortas';
    document.getElementById('op-type-label').hidden = mode === 'tarefas' || mode === 'hortas';
    document.getElementById('op-task-label').classList.toggle('hidden', mode !== 'tarefas');
    setFeedback('op-feedback', 'Carregando dados das suas hortas…');
    try {
        const values = { horta: garden, pagina: page };
        const resource = mode === 'tarefas' ? 'tarefas' : 'plantas';
        values.estado = document.getElementById(mode === 'tarefas' ? 'op-task-state' : 'op-status').value;
        if (mode !== 'tarefas') values.tipo = type;
        const [overview, listing] = await Promise.all([
            backendRequest(operationalUrl('visao', { horta: garden })),
            mode === 'hortas' ? null : backendRequest(operationalUrl(resource, values))
        ]);
        if (!operationalCurrent(user, version)) return;
        const gardens = { data: overview.data.hortas };
        const summary = { data: overview.data.resumo };
        const select = document.getElementById('op-garden');
        select.replaceChildren(new Option('Todas as minhas hortas', ''), ...gardens.data.map(row => new Option(row.nome, row.id_horta)));
        select.value = garden;
        renderOperationalSummary(summary.data);
        if (!gardens.data.length) {
            setFeedback('op-feedback', 'Você ainda não tem hortas atribuídas. Peça um convite ao proprietário e abra o link recebido por e-mail.');
            return;
        }
        setFeedback('op-feedback', '');
        if (mode === 'hortas') {
            renderOperationalGardens(gardens.data.filter(row => !garden || String(row.id_horta) === garden));
        } else {
            if (mode === 'tarefas') renderOperationalTasks(document.getElementById('op-tasks'), listing.data.itens);
            else {
                const types = document.getElementById('op-type');
                types.replaceChildren(new Option('Todos os tipos', ''), ...listing.data.tipos.map(value => new Option(value, value)));
                types.value = type;
                renderOperationalPlants(listing.data.itens);
            }
            document.getElementById('op-pages').hidden = false;
            document.getElementById('op-prev').disabled = page === 1;
            document.getElementById('op-next').disabled = !listing.data.mais;
            document.getElementById('op-page').textContent = 'Página ' + page;
            document.getElementById('op-prev').onclick = () => loadOperational(page - 1);
            document.getElementById('op-next').onclick = () => loadOperational(page + 1);
        }
    } catch (error) {
        if (operationalCurrent(user, version)) setFeedback('op-feedback', error.message);
    }
}

function renderOperationalSummary(summary) {
    const labels = { hortas: 'Hortas com acesso', alerta: 'Plantas em Alerta', atencao: 'Plantas em Atenção',
        pendentes: 'Minhas tarefas pendentes', em_andamento: 'Minhas tarefas em andamento', concluidas_recentes: 'Concluídas nos últimos 7 dias' };
    document.getElementById('op-stats').replaceChildren(...Object.entries(labels).map(([key, label]) => {
        const card = operationalText('div', '', 'op-card');
        card.append(operationalText('p', label, 'text-sm'), operationalText('strong', summary[key], 'text-2xl'));
        return card;
    }));
}

function renderOperationalGardens(gardens) {
    const list = document.getElementById('op-gardens');
    for (const garden of gardens) {
        const card = operationalText('article', '', 'op-card');
        card.append(operationalText('h2', garden.nome, 'text-lg font-bold'),
            operationalText('p', garden.localizacao || 'Localização não informada'),
            operationalText('p', `${garden.plantas} plantas · ${garden.alerta} em Alerta · ${garden.atencao} em Atenção`),
            operationalText('p', `${garden.pendentes} tarefas pendentes para você`),
            operationalButton('Consultar plantas', () => {
                openOperational('problemas', garden.id_horta);
                document.getElementById('op-status').value = 'todas';
                loadOperational();
            }));
        list.append(card);
    }
}

function operationalIndicators(plant) {
    return [['pH', plant.ph_atual, 'pH'], ['Água', plant.temperatura_agua_c, '°C'],
        ['Umidade', plant.umidade_percentual, '%'], ['Nutrientes', plant.nivel_nutrientes_ppm, 'ppm'],
        ['Água cadastrada', plant.agua_l, 'L (legado)'], ['Irrigação', plant.nivel_irrigacao, '(unidade não definida)']]
        .filter(([, value]) => value !== null)
        .map(([label, value, unit]) => `${label} ${value} ${unit}`).join(' · ') || 'Sem indicadores no cadastro';
}

function renderOperationalPlants(plants) {
    const list = document.getElementById('op-plants');
    if (!plants.length) list.append(operationalText('p', 'Nenhuma planta corresponde aos filtros selecionados.'));
    for (const plant of plants) {
        const card = operationalText('article', '', 'op-card');
        card.append(operationalText('strong', plant.status_planta, plant.status_planta === 'Alerta' ? 'text-red-700 dark:text-red-300' : 'text-amber-800 dark:text-amber-300'),
            operationalText('h2', plant.nome, 'text-lg font-bold'),
            operationalText('p', `${plant.horta} · ${plant.localizacao}${plant.estrutura ? ' · ' + plant.estrutura : ''}`),
            operationalText('p', operationalIndicators(plant)),
            operationalText('p', `Cadastro manual/legado · ${operationalFreshness[plant.medicao_cadastro]}${plant.ultima_medicao_em ? ' · ' + operationalDate(plant.ultima_medicao_em) : ''}`));
        if (plant.ultima_leitura) card.append(operationalText('p', operationalReadingText(plant.ultima_leitura)));
        else card.append(operationalText('p', 'Sem leituras de sensores diretamente vinculados à planta.'));
        card.append(operationalText('p', `${plant.tarefas_proprias} tarefas relacionadas para você · Atualizada em ${operationalDate(plant.atualizado_em)}`, 'text-sm'),
            operationalButton('Ver problemas e detalhes', () => showOperationalPlant(plant.id_horta, plant.id_planta)));
        list.append(card);
    }
}

function operationalReadingText(reading) {
    const freshness = reading.atualidade || (reading.antiga ? 'desatualizada' : 'recente');
    const limits = reading.limite_min === null && reading.limite_max === null ? 'Sem limites definidos nesta medição'
        : `Limites registrados: mínimo ${reading.limite_min ?? 'não definido'}, máximo ${reading.limite_max ?? 'não definido'} ${reading.unidade}`;
    return `${reading.tipo_medicao} ${reading.valor} ${reading.unidade} · ${operationalOrigins[reading.origem] || reading.origem} · ${operationalDate(reading.data_hora)} · ${operationalFreshness[freshness]} · ${limits}${reading.estado === 'alerta' ? ' · Limite excedido nesta medição' : ''}`;
}

function renderOperationalTasks(list, tasks) {
    list.replaceChildren(...tasks.map(taskCard));
    if (!tasks.length) list.append(operationalText('li', 'Nenhuma tarefa sua corresponde a esta consulta.'));
}

function closeOperationalDetail() {
    operationalDetailVersion++;
    operationalDetail = null;
    document.getElementById('op-detail').close();
    document.getElementById('op-detail-body').replaceChildren();
    document.getElementById('op-record-form').reset();
    document.getElementById('op-record-form').classList.add('hidden');
}

async function showOperationalPlant(garden, plant, reset = true) {
    const version = ++operationalDetailVersion;
    const user = currentUser?.id;
    operationalDetail = { garden, plant };
    const current = () => version === operationalDetailVersion && currentUser?.id === user && location.hash === '#operacao';
    const dialog = document.getElementById('op-detail');
    if (reset) document.getElementById('op-record-form').reset();
    document.getElementById('op-record-form').classList.add('hidden');
    document.getElementById('op-detail-body').replaceChildren();
    setFeedback('op-detail-feedback', 'Carregando detalhes…');
    if (!dialog.open) dialog.showModal();
    try {
        const { data } = await backendRequest(operationalUrl('detalhes', { horta: garden, planta: plant }));
        if (!current()) return;
        const p = data.planta;
        document.getElementById('op-detail-title').textContent = p.nome;
        const body = document.getElementById('op-detail-body');
        body.append(operationalText('p', `Planta #${p.id_planta} · ${p.tipo || 'Tipo não informado'}`),
            operationalText('p', `${p.horta} · ${p.localizacao}${p.estrutura ? ' · ' + p.estrutura : ''} · ${p.status_planta}`),
            operationalText('p', `Estado informado no cadastro. Atualização em ${operationalDate(p.atualizado_em)}.`),
            operationalText('h3', 'Indicadores do cadastro manual/legado', 'font-bold'),
            operationalText('p', operationalIndicators(p)),
            operationalText('p', operationalFreshness[p.medicao_cadastro] + (p.ultima_medicao_em ? ' · ' + operationalDate(p.ultima_medicao_em) : '')),
            operationalText('h3', 'Medições e limites registrados', 'font-bold'));
        const readings = operationalText('ul', '', 'space-y-2');
        if (!data.leituras.length) readings.append(operationalText('li', 'Sem medições diretamente associadas. A ausência de dados não confirma a condição da planta.'));
        for (const reading of data.leituras) readings.append(operationalText('li', operationalReadingText(reading)));
        body.append(readings);
        if (administrativeWorkspace() && data.pode_criar_tarefa) {
            if (['Atenção', 'Alerta'].includes(p.status_planta)) body.append(operationalButton('Criar tarefa para esta planta', () => openTaskCreation(garden, plant)));
            for (const reading of data.leituras.filter(row => row.estado === 'alerta')) {
                body.append(operationalButton('Criar tarefa para leitura #' + reading.id_leitura,
                    () => openTaskCreation(garden, plant, reading.id_leitura)));
            }
        }
        let cursor = data.proximo_cursor;
        const more = operationalButton('Mais medições', async () => {
            more.disabled = true;
            try {
                const next = await backendRequest(collaborationUrl('monitoramentos', garden) + '&planta=' + plant + '&limite=20&cursor=' + encodeURIComponent(cursor));
                if (!current()) return;
                for (const reading of next.data) readings.append(operationalText('li', operationalReadingText(reading)));
                cursor = next.proximo_cursor;
                more.hidden = !cursor;
            } catch (error) { if (current()) setFeedback('op-detail-feedback', error.message); }
            finally { more.disabled = false; }
        });
        more.hidden = !cursor;
        body.append(more, operationalText('h3', 'Observações e intervenções registradas', 'font-bold'));
        const records = operationalText('ul', '', 'space-y-2');
        const appendRecords = rows => {
            for (const record of rows) records.append(operationalText('li', `${record.tipo === 'intervencao' ? 'Intervenção' : 'Observação'} · ${record.autor} · ${operationalDate(record.criado_em)} · ${record.descricao}`));
        };
        if (!data.registros.itens.length) records.append(operationalText('li', 'Nenhum registro vinculado a esta planta.'));
        appendRecords(data.registros.itens);
        body.append(records);
        let recordPage = 1;
        const nextRecords = operationalButton('Mais registros', async () => {
            nextRecords.disabled = true;
            try {
                const next = await backendRequest(operationalUrl('registros', { horta: garden, planta: plant, pagina: recordPage + 1 }));
                if (!current()) return;
                appendRecords(next.data.itens);
                recordPage++;
                nextRecords.hidden = !next.data.mais;
            } catch (error) { if (current()) setFeedback('op-detail-feedback', error.message); }
            finally { nextRecords.disabled = false; }
        });
        nextRecords.hidden = !data.registros.mais;
        body.append(nextRecords, operationalText('h3', 'Minhas tarefas relacionadas', 'font-bold'));
        const tasks = operationalText('ul', '', 'space-y-2');
        renderOperationalTasks(tasks, data.tarefas.itens);
        body.append(tasks);
        if (data.tarefas.mais) body.append(operationalButton('Ver todas as minhas tarefas', () => {
            closeOperationalDetail();
            openOperational('tarefas', garden);
        }));
        document.getElementById('op-record-form').classList.toggle('hidden', !data.pode_registrar);
        setFeedback('op-detail-feedback', '');
    } catch (error) {
        if (current()) setFeedback('op-detail-feedback', error.message);
    }
}

async function saveOperationalRecord(event) {
    const form = event.currentTarget;
    const context = operationalDetail;
    const user = currentUser?.id;
    await authForm(event, 'op-detail-feedback', async () => {
        if (!context) return;
        const data = Object.fromEntries(new FormData(form));
        await backendRequest(collaborationUrl('registros', context.garden), 'POST', { ...data, id_planta: context.plant });
        if (currentUser?.id !== user || operationalDetail !== context) return;
        await showOperationalPlant(context.garden, context.plant);
        setFeedback('op-detail-feedback', 'Registro salvo para esta planta.');
    });
}

document.getElementById('op-detail').addEventListener('cancel', event => {
    event.preventDefault();
    closeOperationalDetail();
});
