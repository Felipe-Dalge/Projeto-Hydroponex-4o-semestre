// Contexto de horta fica somente em memória; autorização é verificada em cada API.
let hortas = [];
let hortasPage = 1;
let hortasTotal = 0;
let selectedHortaId = null;
let selectedHorta = null;
let gardensContextVersion = 0;
let gardenListVersion = 0;
let editingGarden = null;
let deletingGardenId = null;

function clearGardens() {
    gardensContextVersion++; gardenListVersion++;
    resetPlantView();
    if (typeof resetInfrastructure === 'function') resetInfrastructure();
    if (typeof resetMonitoring === 'function') resetMonitoring();
    hortas = []; hortasPage = 1; hortasTotal = 0; selectedHortaId = null; selectedHorta = null;
    editingGarden = null; deletingGardenId = null;
    document.getElementById('hortas-list').replaceChildren();
    document.getElementById('modal-horta').close();
    document.getElementById('modal-excluir-horta').close();
    renderGardenContext();
}

function renderGardenContext() {
    const selector = document.getElementById('horta-selector');
    selector.replaceChildren(new Option('Selecione uma horta', ''));
    for (const h of currentUser?.hortas || []) selector.add(new Option(h.nome, String(h.id_horta)));
    selector.value = selectedHortaId ? String(selectedHortaId) : '';
    document.getElementById('new-plant-button').disabled = !selectedHorta?.pode_editar;
    document.getElementById('new-plant-button').classList.toggle('opacity-50', !selectedHorta?.pode_editar);
    setFeedback('horta-context-feedback', selectedHorta ? `${selectedHorta.nome} · ${selectedHorta.papel} · ${selectedHorta.total_plantas} planta(s)` : 'Selecione ou cadastre uma horta para visualizar suas plantas.');
}

async function initializeGardens() {
    renderGardenContext();
    await loadHortas(1);
    const id = (currentUser?.hortas || []).some(h => Number(h.id_horta) === selectedHortaId)
        ? selectedHortaId : (currentUser?.hortas?.[0]?.id_horta || null);
    await selectHorta(id, false);
}

async function refreshGardenAccess(preferredId = selectedHortaId) {
    const userId = currentUser?.id;
    try {
        const response = await backendRequest('php/api/auth/me.php');
        if (!currentUser || currentUser.id !== userId) return;
        currentUser = response.data;
        updateUserUI();
        await loadHortas(hortasPage);
        const id = currentUser.hortas.some(h => Number(h.id_horta) === Number(preferredId))
            ? Number(preferredId) : (currentUser.hortas[0]?.id_horta || null);
        await selectHorta(id, false);
    } catch (error) { setFeedback('hortas-feedback', error.message); }
}

async function loadHortas(page = 1) {
    if (!currentUser || page < 1) return;
    const request = ++gardenListVersion;
    const userId = currentUser.id;
    const list = document.getElementById('hortas-list');
    list.setAttribute('aria-busy', 'true');
    setFeedback('hortas-feedback', 'Carregando hortas…');
    try {
        const response = await backendRequest(`php/api/hortas.php?pagina=${page}`);
        if (request !== gardenListVersion || currentUser?.id !== userId) return;
        hortas = response.data; hortasPage = response.meta.pagina; hortasTotal = response.meta.total;
        if (!hortas.length && hortasTotal && page > 1) { await loadHortas(page - 1); return; }
        renderHortas();
        setFeedback('hortas-feedback', hortasTotal ? `${hortasTotal} horta(s) com acesso autorizado.` : 'Você ainda não possui hortas. Cadastre sua primeira horta para começar.');
    } catch (error) {
        if (request === gardenListVersion) { list.replaceChildren(); setFeedback('hortas-feedback', error.message); }
    } finally { if (request === gardenListVersion) list.setAttribute('aria-busy', 'false'); }
}

function renderHortas() {
    const list = document.getElementById('hortas-list');
    list.innerHTML = hortas.map(h => `
        <article class="bg-white dark:bg-darkbg-800 rounded-3xl border border-slate-200 dark:border-slate-700 p-6 space-y-4 shadow-sm">
            <div class="flex items-start justify-between gap-3"><h2 class="font-bold text-lg text-slate-900 dark:text-white break-words min-w-0">${escapeHtml(h.nome)}</h2><span class="text-xs text-hydro-600 shrink-0">#${Number(h.id_horta)}</span></div>
            <dl class="text-sm space-y-2 text-slate-600 dark:text-slate-300">
                <div><dt class="font-semibold">Localização</dt><dd class="break-words">${escapeHtml(h.localizacao || 'Não informada')}</dd></div>
                <div><dt class="font-semibold">Área</dt><dd>${h.tamanho_m2 === null ? 'Não informada' : escapeHtml(h.tamanho_m2) + ' m²'}</dd></div>
                <div><dt class="font-semibold">CEP</dt><dd>${escapeHtml(h.cep || 'Não informado')}${h.cidade ? ' · ' + escapeHtml(h.cidade) + '/' + escapeHtml(h.estado) : ''}</dd></div>
                <div><dt class="font-semibold">Responsável(is)</dt><dd class="break-words">${escapeHtml(h.responsaveis.map(u => u.nome).join(', ') || 'Não informado')}</dd></div>
                <div><dt class="font-semibold">Estruturas</dt><dd class="break-words">${escapeHtml(h.estruturas.map(e => e.nome).join(', ') || 'Nenhuma')}</dd></div>
            </dl>
            <p class="text-xs text-slate-500">${Number(h.total_plantas)} planta(s) · Seu acesso: ${escapeHtml(h.papel)}</p>
            <div class="flex flex-wrap gap-3"><button onclick="selectHorta(${Number(h.id_horta)})" class="text-sm font-bold text-hydro-600">Ver plantas</button>${h.pode_editar ? `<button onclick="openHortaForm(${Number(h.id_horta)})" class="text-sm font-semibold text-slate-600 dark:text-slate-300">Editar</button>` : ''}${h.pode_excluir ? `<button onclick="openDeleteHorta(${Number(h.id_horta)})" class="text-sm font-semibold text-rose-600">Excluir</button>` : ''}</div>
        </article>`).join('');
    const pages = Math.max(1, Math.ceil(hortasTotal / 12));
    document.getElementById('hortas-page').textContent = `Página ${hortasPage} de ${pages}`;
    document.getElementById('hortas-prev').disabled = hortasPage <= 1;
    document.getElementById('hortas-next').disabled = hortasPage >= pages;
}

async function selectHorta(value, goToPlants = true) {
    const id = value ? Number(value) : null;
    const version = ++gardensContextVersion;
    selectedHortaId = id; selectedHorta = null; plantas = [];
    resetPlantView();
    if (typeof resetInfrastructure === 'function') resetInfrastructure();
    if (typeof resetMonitoring === 'function') resetMonitoring();
    closeModalNovaPlanta();
    renderGardenContext(); renderTablePlantas(); renderMonitoramentoCards();
    if (window.location.hash === '#dashboard') renderDashboard();
    if (!id) { if (goToPlants) navigateTo('hortas'); if (window.location.hash === '#infraestrutura') loadInfrastructure(); return; }
    setFeedback('horta-context-feedback', 'Carregando a horta selecionada…');
    try {
        const response = await backendRequest(`php/api/hortas.php?id=${id}`);
        if (version !== gardensContextVersion || !currentUser) return;
        selectedHorta = response.data;
        renderGardenContext();
        await loadPlantas();
        if (version !== gardensContextVersion) return;
        if (goToPlants) navigateTo('plantas');
        else if (window.location.hash === '#infraestrutura') await loadInfrastructure();
        else if (window.location.hash === '#monitoramento') await loadMonitoring();
        else if (['#dashboard','#consumo'].includes(window.location.hash)) await renderMonitoringDashboard();
    } catch (error) {
        if (version === gardensContextVersion) {
            selectedHortaId = null; selectedHorta = null; plantas = [];
            renderGardenContext(); setFeedback('horta-context-feedback', error.message); renderTablePlantas();
        }
    }
}

async function openHortaForm(id = null) {
    if (!currentUser) return;
    const userId = currentUser.id;
    document.getElementById('horta-form').reset();
    editingGarden = null;
    setFeedback('horta-form-feedback', '');
    document.getElementById('horta-estrutura-id').replaceChildren(new Option('Adicionar se preencher o nome', ''));
    document.getElementById('horta-form-title').textContent = id ? 'Editar Horta' : 'Cadastrar Horta';
    document.getElementById('horta-responsavel').textContent = `Responsável: ${currentUser.nome}`;
    if (id) {
        try {
            const response = await backendRequest(`php/api/hortas.php?id=${Number(id)}`);
            if (currentUser?.id !== userId) return;
            if (!response.data.pode_editar) throw new Error('Acesso somente para consulta.');
            editingGarden = response.data;
            document.getElementById('horta-nome').value = editingGarden.nome;
            document.getElementById('horta-tamanho').value = editingGarden.tamanho_m2 ?? '';
            document.getElementById('horta-localizacao').value = editingGarden.localizacao ?? '';
            document.getElementById('horta-cep').value = editingGarden.cep ?? '';
            document.getElementById('horta-responsavel').textContent = 'Responsável(is): ' + editingGarden.responsaveis.map(u => u.nome).join(', ');
            for (const structure of editingGarden.estruturas) document.getElementById('horta-estrutura-id').add(new Option(structure.nome, String(structure.id_estrutura)));
        } catch (error) { setFeedback('hortas-feedback', error.message); return; }
    }
    document.getElementById('modal-horta').showModal();
}

function fillHortaStructure(value) {
    const structure = editingGarden?.estruturas.find(e => Number(e.id_estrutura) === Number(value));
    document.getElementById('horta-estrutura-nome').value = structure?.nome ?? '';
    document.getElementById('horta-estrutura-tipo').value = structure?.tipo ?? '';
    document.getElementById('horta-estrutura-unidades').value = structure?.qnt_unidade ?? 1;
}

async function saveHorta(event) {
    await authForm(event, 'horta-form-feedback', async () => {
        const id = editingGarden?.id_horta;
        const payload = { nome: document.getElementById('horta-nome').value,
            tamanho_m2: document.getElementById('horta-tamanho').value || null,
            localizacao: document.getElementById('horta-localizacao').value,
            cep: document.getElementById('horta-cep').value || null };
        const structureName = document.getElementById('horta-estrutura-nome').value.trim();
        if (structureName) {
            payload.estrutura = { nome: structureName, tipo: document.getElementById('horta-estrutura-tipo').value, qnt_unidade: Number(document.getElementById('horta-estrutura-unidades').value) };
            const structureId = document.getElementById('horta-estrutura-id').value;
            if (structureId) payload.estrutura.id_estrutura = Number(structureId);
        }
        const response = await backendRequest('php/api/hortas.php' + (id ? `?id=${id}` : ''), id ? 'PATCH' : 'POST', payload);
        document.getElementById('modal-horta').close();
        hortasPage = 1;
        await refreshGardenAccess(response.data.id_horta);
        setFeedback('hortas-feedback', response.message);
    });
}

function openDeleteHorta(id) {
    const garden = hortas.find(h => Number(h.id_horta) === Number(id));
    if (!garden?.pode_excluir) return;
    deletingGardenId = Number(id);
    setFeedback('horta-delete-description', `Você está excluindo “${garden.nome}” (#${deletingGardenId}).`);
    setFeedback('horta-delete-feedback', '');
    document.getElementById('horta-delete-confirmation').value = '';
    document.getElementById('modal-excluir-horta').showModal();
}

async function deleteHorta(event) {
    await authForm(event, 'horta-delete-feedback', async () => {
        const id = deletingGardenId;
        const response = await backendRequest(`php/api/hortas.php?id=${id}`, 'DELETE', { confirmacao: document.getElementById('horta-delete-confirmation').value });
        document.getElementById('modal-excluir-horta').close();
        if (selectedHortaId === id) { selectedHortaId = null; selectedHorta = null; }
        await refreshGardenAccess();
        setFeedback('hortas-feedback', response.message);
    });
}
