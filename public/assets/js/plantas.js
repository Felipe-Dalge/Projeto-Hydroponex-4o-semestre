let listedPlants = [];
let plantSummary = { total: 0, normal: 0, atencao: 0, alerta: 0 };
let plantConsumption = null;
let plantFilterVersion = 0;
let plantLoadVersion = 0;
let plantFilterTimer = null;
let transferCandidate = null;
let plantMeasurementOriginal = null;
let plantMeasurementDisplayed = '';

function resetPlantView() {
    plantFilterVersion++; plantLoadVersion++; clearTimeout(plantFilterTimer);
    listedPlants = []; plantSummary = { total: 0, normal: 0, atencao: 0, alerta: 0 }; plantConsumption = null;
    document.getElementById('plant-filter-name').value = '';
    document.getElementById('plant-filter-status').value = '';
    document.getElementById('modal-planta').querySelector('form').reset();
    document.getElementById('modal-detalhes-planta').close();
    document.getElementById('modal-transferir-planta').close();
    transferCandidate = null;
}

function schedulePlantFilters() {
    clearTimeout(plantFilterTimer);
    plantFilterVersion++;
    plantFilterTimer = setTimeout(() => applyPlantFilters(), 250);
}

async function applyPlantFilters() {
    const garden = selectedHortaId, context = gardensContextVersion, request = ++plantFilterVersion;
    if (!garden || !currentUser) { listedPlants = []; renderTablePlantas(); return; }
    const name = document.getElementById('plant-filter-name').value.trim();
    const status = document.getElementById('plant-filter-status').value;
    if (!name && !status) { listedPlants = plantas.slice(); renderTablePlantas(); return; }
    setFeedback('plantas-feedback', 'Filtrando plantas…');
    try {
        const query = new URLSearchParams({ horta: String(garden), nome: name, status });
        const response = await backendRequest('php/api/plantas.php?' + query);
        if (garden !== selectedHortaId || context !== gardensContextVersion || request !== plantFilterVersion) return;
        listedPlants = response.data; plantSummary = response.resumo; plantConsumption = response.indicadores.consumo_hoje_l;
        renderTablePlantas();
        setFeedback('plantas-feedback', `${listedPlants.length} resultado(s); ${plantSummary.total} planta(s) na horta.`);
    } catch (error) {
        if (request === plantFilterVersion) { listedPlants = []; renderTablePlantas(); setFeedback('plantas-feedback', error.message); }
    }
}

const plantFormFields = {
    nome: 'new-plant-name', tipo: 'new-plant-type', localizacao: 'new-plant-location', dias_crescimento: 'new-plant-days',
    ph_atual: 'new-plant-ph', temperatura_agua_c: 'new-plant-temp', nivel_nutrientes_ppm: 'new-plant-nutri',
    umidade_percentual: 'new-plant-humidity', nivel_irrigacao: 'new-plant-irrigation', agua_l: 'new-plant-water', status_planta: 'new-plant-status'
};

function plantDate(value) {
    if (!value) return null;
    return new Date(value.replace(' ', 'T').replace(/(\.\d{3})\d+/, '$1') + 'Z');
}

async function openPlantForm(id = null) {
    if (!selectedHorta?.pode_editar) { setFeedback('plantas-feedback', 'Selecione uma horta com permissão de edição.'); return; }
    const garden = selectedHortaId, context = gardensContextVersion;
    try {
        const plant = id ? (await backendRequest(`php/api/plantas.php?horta=${garden}&id=${Number(id)}`)).data : { status_planta: 'Normal' };
        if (garden !== selectedHortaId || context !== gardensContextVersion) return;
        editingPlantaId = id ? Number(id) : null;
        for (const [field, element] of Object.entries(plantFormFields)) document.getElementById(element).value = plant[field] ?? '';
        const date = plantDate(plant.ultima_medicao_em);
        document.getElementById('new-plant-measurement').value = date ? new Date(date.getTime() - date.getTimezoneOffset() * 60000).toISOString().slice(0, 19) : '';
        plantMeasurementOriginal = plant.ultima_medicao_em ?? null;
        plantMeasurementDisplayed = document.getElementById('new-plant-measurement').value;
        const structure = document.getElementById('new-plant-structure');
        structure.replaceChildren(new Option('Sem estrutura', ''));
        for (const item of selectedHorta.estruturas) structure.add(new Option(item.nome, String(item.id_estrutura)));
        structure.value = plant.id_estrutura ?? '';
        const culture=document.getElementById('new-plant-culture');culture.replaceChildren(new Option('Sem cultura',''));
        for(const item of selectedHorta.culturas || [])culture.add(new Option(item.nome,String(item.id_cultura)));
        culture.value=plant.id_cultura ?? '';
        document.querySelector('#modal-planta h3').textContent = id ? 'Editar Planta' : 'Cadastrar Nova Planta';
        setFeedback('plant-form-garden', `Horta: ${selectedHorta.nome} (#${garden})`);
        setFeedback('plant-form-feedback', '');
        document.getElementById('modal-planta').classList.remove('hidden');
    } catch (error) { setFeedback('plantas-feedback', error.message); }
}

async function savePlantForm(event) {
    await authForm(event, 'plant-form-feedback', async () => {
        if (!selectedHortaId || !selectedHorta?.pode_editar) throw new Error('Selecione uma horta com permissão de edição.');
        const garden = selectedHortaId, context = gardensContextVersion, id = editingPlantaId;
        const payload = {};
        for (const [field, element] of Object.entries(plantFormFields)) {
            const value = document.getElementById(element).value;
            payload[field] = value === '' ? null : value;
        }
        const date = document.getElementById('new-plant-measurement').value;
        payload.ultima_medicao_em = date === plantMeasurementDisplayed ? plantMeasurementOriginal : (date ? new Date(date).toISOString() : null);
        payload.id_estrutura = document.getElementById('new-plant-structure').value ? Number(document.getElementById('new-plant-structure').value) : null;
        payload.id_cultura = document.getElementById('new-plant-culture').value ? Number(document.getElementById('new-plant-culture').value) : null;
        if (id) payload.id = id;
        await apiRequest(id ? 'PATCH' : 'POST', payload);
        if (garden !== selectedHortaId || context !== gardensContextVersion) return;
        closeModalNovaPlanta(); await loadPlantas();
        setFeedback('plantas-feedback', 'Planta salva. Indicadores atualizados.');
    });
}

async function showPlantDetails(id) {
    const garden = selectedHortaId, context = gardensContextVersion;
    if (!garden) return;
    try {
        const plant = (await backendRequest(`php/api/plantas.php?horta=${garden}&id=${Number(id)}`)).data;
        if (garden !== selectedHortaId || context !== gardensContextVersion) return;
        const labels = { id: 'Identificador', id_horta: 'Horta', nome: 'Nome', tipo: 'Tipo', localizacao: 'Localização', dias_crescimento: 'Dias de crescimento',
            ph_atual: 'pH atual', temperatura_agua_c: 'Temperatura da água (°C)', umidade_percentual: 'Umidade (%)', nivel_nutrientes_ppm: 'Nutrientes (ppm)',
            nivel_irrigacao: 'Nível de irrigação', agua_l: 'Água informada (L)', status_planta: 'Status', id_estrutura: 'Estrutura', id_cultura: 'Cultura',
            ultima_medicao_em: 'Última medição (UTC)', criado_em: 'Cadastro (UTC)', atualizado_em: 'Atualização do cadastro (UTC)' };
        const list = document.getElementById('plant-details'); list.replaceChildren();
        document.getElementById('modal-detalhes-planta').querySelector('h2').textContent='Detalhes da planta';
        for (const [field, label] of Object.entries(labels)) {
            const block = document.createElement('div'), term = document.createElement('dt'), value = document.createElement('dd');
            term.className = 'font-semibold'; value.className = 'break-words'; term.textContent = label; value.textContent = plant[field] ?? 'Não informado';
            block.append(term, value); list.append(block);
        }
        document.getElementById('modal-detalhes-planta').showModal();
    } catch (error) { setFeedback('plantas-feedback', error.message); }
}

async function openPlantTransfer(id) {
    const garden = selectedHortaId, context = gardensContextVersion;
    if (!selectedHorta?.pode_editar) return;
    const targets = currentUser.hortas.filter(h => Number(h.id_horta) !== garden && ['gestor', 'proprietario'].includes(h.papel));
    if (!targets.length) { setFeedback('plantas-feedback', 'Cadastre ou obtenha permissão de edição em outra horta para transferir.'); return; }
    try {
        const plant = (await backendRequest(`php/api/plantas.php?horta=${garden}&id=${Number(id)}`)).data;
        if (garden !== selectedHortaId || context !== gardensContextVersion) return;
        transferCandidate = plant;
        const select = document.getElementById('plant-transfer-target'); select.replaceChildren(new Option('Selecione o destino', ''));
        for (const target of targets) select.add(new Option(target.nome, String(target.id_horta)));
        setFeedback('plant-transfer-description', `${plant.nome} — origem: ${selectedHorta.nome}`);
        setFeedback('plant-transfer-feedback', '');
        document.getElementById('plant-transfer-unlink').checked = false;
        document.getElementById('plant-transfer-unlink-label').hidden = !plant.id_estrutura && !plant.id_cultura;
        document.getElementById('plant-transfer-confirmation').value = '';
        document.getElementById('modal-transferir-planta').showModal();
    } catch (error) { setFeedback('plantas-feedback', error.message); }
}

async function transferPlant(event) {
    await authForm(event, 'plant-transfer-feedback', async () => {
        if (!transferCandidate || transferCandidate.id_horta !== selectedHortaId) throw new Error('Selecione novamente a planta.');
        const linked = transferCandidate.id_estrutura || transferCandidate.id_cultura;
        if (linked && !document.getElementById('plant-transfer-unlink').checked) throw new Error('Confirme o tratamento dos vínculos de estrutura/cultura.');
        const target = Number(document.getElementById('plant-transfer-target').value);
        const payload = { id: transferCandidate.id, horta_destino: target, confirmacao: document.getElementById('plant-transfer-confirmation').value };
        if (linked) { payload.id_estrutura = null; payload.id_cultura = null; }
        await backendRequest(`php/api/plantas_transferir.php?horta=${selectedHortaId}`, 'POST', payload);
        document.getElementById('modal-transferir-planta').close(); await refreshGardenAccess(target);
        navigateTo('plantas'); setFeedback('plantas-feedback', 'Planta transferida para a horta selecionada.');
    });
}
