const infrastructureModules = {
    culturas: { title: 'Culturas', catalog: true, fields: [
        ['nome','Nome','text',true],['tipo','Categoria','text'],['tempo_crescimento_dias','Crescimento (dias)','number',false,0,65535,1],['temperatura_ideal_c','Temperatura ideal (°C)','number',false,-273.15,999.99,.01],['ph_ideal','pH ideal','number',false,0,14,.01]] },
    reservatorios: { title: 'Reservatórios', fields: [
        ['nome','Nome','text',true],['capacidade_l','Capacidade (L)','number',true,.001,999999999.999,.001],['nivel_atual_l','Nível atual (L)','number',false,0,999999999.999,.001],['temperatura_c','Temperatura da água (°C)','number',false,-273.15,999.99,.01],['ph_atual','pH atual','number',false,0,14,.01]] },
    estruturas: { title: 'Estruturas', fields: [['nome','Nome','text',true],['tipo','Tipo','text'],['qnt_unidade','Unidades','number',true,1,65535,1],['id_reservatorio','Reservatório da horta','reservoir']] },
    nutrientes: { title: 'Nutrientes', catalog: true, fields: [['nome','Nome','text'],['classificacao','Classificação','text'],['tipo','Tipo','text'],['descricao','Descrição','textarea']] }
};
let infrastructureTab = 'culturas';
let infrastructureData = { culturas: [], reservatorios: [], estruturas: [], nutrientes: [] };
let infrastructureLinks = { sensores: [], aplicacoes: [], leituras: [] };
let infrastructureRequest = 0;
let infrastructureEditing = null;

function resetInfrastructure() {
    infrastructureRequest++; infrastructureEditing = null;
    infrastructureData = { culturas: [], reservatorios: [], estruturas: [], nutrientes: [] };
    infrastructureLinks = { sensores: [], aplicacoes: [], leituras: [] };
    document.getElementById('infra-list').replaceChildren();
    document.getElementById('infra-dialog').close();
    document.getElementById('infra-applications').replaceChildren();
    document.getElementById('infra-readings').replaceChildren();
}

function infrastructureUrl(resource, id = null, garden = selectedHortaId) {
    const query = new URLSearchParams();
    if (garden) query.set('horta', garden);
    if (id) query.set('id', id);
    return `php/api/${resource}.php` + (query.size ? '?' + query : '');
}

function infrastructureCanWrite() { return !!selectedHorta?.pode_editar; }

async function loadInfrastructure() {
    if (!currentUser) return;
    const request = ++infrastructureRequest, garden = selectedHortaId, user = currentUser.id;
    document.getElementById('infra-list').setAttribute('aria-busy', 'true');
    setFeedback('infra-feedback', 'Carregando catálogos e infraestrutura…');
    try {
        const names = garden ? Object.keys(infrastructureModules) : ['culturas', 'nutrientes'];
        const responses = await Promise.all(names.map(name => backendRequest(infrastructureUrl(name, null, garden))));
        if (request !== infrastructureRequest || garden !== selectedHortaId || currentUser?.id !== user) return;
        const data = { culturas: [], reservatorios: [], estruturas: [], nutrientes: [] };
        names.forEach((name, i) => { data[name] = responses[i].data; });
        const links = garden ? (await backendRequest(infrastructureUrl('nutrientes_vinculos', null, garden))).data : { sensores: [], aplicacoes: [], leituras: [] };
        if (request !== infrastructureRequest || garden !== selectedHortaId || currentUser?.id !== user) return;
        infrastructureData = data; infrastructureLinks = links;
        renderInfrastructure();
        setFeedback('infra-feedback', infrastructureData[infrastructureTab].length ? 'Dados atualizados.' : 'Nenhum registro neste módulo.');
    } catch (error) {
        if (request === infrastructureRequest) { document.getElementById('infra-list').replaceChildren(); setFeedback('infra-feedback', error.message); }
    } finally { if (request === infrastructureRequest) document.getElementById('infra-list').setAttribute('aria-busy', 'false'); }
}

function showInfrastructure(tab) {
    if (!infrastructureModules[tab]) return;
    infrastructureTab = tab; renderInfrastructure();
    setFeedback('infra-feedback', infrastructureData[tab].length ? '' : 'Nenhum registro neste módulo.');
}

function renderInfrastructure() {
    const meta = infrastructureModules[infrastructureTab];
    document.querySelectorAll('[data-infra-tab]').forEach(button => button.setAttribute('aria-pressed', String(button.dataset.infraTab === infrastructureTab)));
    document.getElementById('infra-new').disabled = !meta.catalog && !infrastructureCanWrite();
    setFeedback('infra-policy', meta.catalog ? 'Globais: consulta. Pessoais: você pode editar enquanto não estiverem em uso. Copie um item para criar sua própria versão.' : (selectedHorta ? `Recursos de ${selectedHorta.nome}. Escrita para gestores e proprietários.` : 'Selecione uma horta para gerenciar instalações.'));
    const list = document.getElementById('infra-list'); list.replaceChildren();
    for (const row of infrastructureData[infrastructureTab]) {
        const card = document.createElement('article'); card.className = 'p-5 rounded-3xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-darkbg-800 shadow-sm space-y-3 min-w-0';
        const title = document.createElement('h2'); title.className = 'text-lg font-bold text-slate-900 dark:text-white break-words'; title.textContent = row.nome; card.append(title);
        const scope = document.createElement('p'); scope.className = 'text-xs text-slate-500'; scope.textContent = row.escopo ? `${row.escopo}${row.em_uso ? ' · em uso' : ''}` : `Horta #${row.id_horta}`; card.append(scope);
        for (const [field, label] of meta.fields.filter(([field]) => field !== 'nome')) {
            const line = document.createElement('p'); line.className = 'text-sm text-slate-600 dark:text-slate-300 break-words';
            if(field==='descricao')line.classList.add('whitespace-pre-wrap');
            let value = row[field] ?? 'Não informado';
            if (field === 'id_reservatorio' && row[field]) value = infrastructureData.reservatorios.find(r => Number(r.id) === Number(row[field]))?.nome ?? value;
            line.textContent = `${label}: ${value}`; card.append(line);
        }
        const actions = document.createElement('div'); actions.className = 'flex flex-wrap gap-3 text-sm font-semibold';
        const action = (name, handler, color = 'text-hydro-600') => { const button = document.createElement('button'); button.textContent = name; button.className = color; button.addEventListener('click', handler); actions.append(button); };
        action('Detalhes', () => showInfrastructureDetails(row.id));
        if (row.pode_editar) action('Editar', () => openInfrastructureForm(row.id));
        if (meta.catalog) action('Copiar', () => openInfrastructureForm(null, row));
        if (row.pode_excluir) action('Excluir', () => deleteInfrastructure(row), 'text-rose-600');
        if (infrastructureTab === 'culturas' && selectedHorta && infrastructureCanWrite()) action(row.vinculada ? 'Desvincular da horta' : 'Habilitar nesta horta', () => toggleInfrastructureCulture(row));
        card.append(actions); list.append(card);
    }
    const nutrientView = infrastructureTab === 'nutrientes' && !!selectedHortaId;
    document.getElementById('infra-nutrient-links').classList.toggle('hidden', !nutrientView);
    document.getElementById('infra-apply').disabled = !infrastructureCanWrite() || !infrastructureData.reservatorios.length || !infrastructureData.nutrientes.length;
    document.getElementById('infra-sensor').disabled = !infrastructureCanWrite() || !infrastructureLinks.sensores.length;
    const lines = (id, rows, format, empty) => { const block = document.getElementById(id); block.replaceChildren(); if (!rows.length) block.textContent = empty; for (const row of rows) { const line = document.createElement('p'); line.className = 'break-words'; line.textContent = format(row); block.append(line); } };
    lines('infra-applications', infrastructureLinks.aplicacoes, a => `${a.aplicado_em} UTC · ${a.reservatorio}: ${a.nutriente}, ${a.quantidade} ${a.unidade}`, 'Nenhuma aplicação registrada nesta horta.');
    lines('infra-readings', infrastructureLinks.leituras, r => `${r.data_hora} UTC · ${r.origem === 'simulacao' ? 'SIMULAÇÃO' : r.origem === 'manual' ? 'Manual (informada)' : r.origem === 'sensor' ? 'Dispositivo (origem registrada)' : 'Origem não verificada'} · ${r.sensor} / ${r.nutriente}: ${r.valor} ${r.unidade}`, 'Nenhuma leitura de sensor vinculada a nutriente nesta horta.');
}

function infrastructureField(spec, value = null, options = []) {
    const [name, text, type, required, min, max, step] = spec;
    const label = document.createElement('label'); label.className = 'block text-sm'; label.append(document.createTextNode(text));
    const control = document.createElement(type === 'textarea' ? 'textarea' : ['reservoir', 'select'].includes(type) ? 'select' : 'input');
    control.id = 'infra-field-' + name; control.name = name;
    control.className = 'mt-1 w-full min-w-0 p-2.5 rounded-xl border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-darkbg-900';
    if (control.tagName === 'SELECT') {
        control.add(new Option('Selecione', ''));
        for (const item of type === 'reservoir' ? infrastructureData.reservatorios : options) control.add(new Option(item.nome ?? item.label, String(item.id ?? item.value)));
    } else if (type !== 'textarea') control.type = type;
    if (type === 'number') { control.min = min; control.max = max; control.step = step; }
    if (type === 'text' || type === 'textarea') control.maxLength = type === 'textarea' ? 1000 : 100;
    control.required = !!required; control.value = value ?? ''; label.append(control); return label;
}

async function openInfrastructureForm(id = null, copy = null) {
    const resource = infrastructureTab, garden = selectedHortaId, userId = currentUser?.id;
    if (!infrastructureModules[resource].catalog && !infrastructureCanWrite()) return;
    try {
        const row = id ? (await backendRequest(infrastructureUrl(resource, id, garden))).data : copy;
        if (currentUser?.id !== userId || garden !== selectedHortaId) return;
        if (id && !row.pode_editar) throw new Error('Item global, compartilhado ou em uso: crie uma cópia pessoal.');
        infrastructureEditing = { mode: 'resource', resource, id, garden, userId };
        const fields = document.getElementById('infra-fields'); fields.replaceChildren();
        for (const spec of infrastructureModules[resource].fields) fields.append(infrastructureField(spec, row?.[spec[0]] ?? (spec[0] === 'qnt_unidade' ? 1 : null)));
        setFeedback('infra-dialog-title', `${id ? 'Editar' : copy ? 'Copiar' : 'Cadastrar'} — ${infrastructureModules[resource].title}`);
        setFeedback('infra-dialog-feedback', ''); document.getElementById('infra-dialog').showModal();
    } catch (error) { setFeedback('infra-feedback', error.message); }
}

async function refreshInfrastructureContext(garden) {
    if (garden && garden === selectedHortaId) await refreshGardenAccess(garden);
    else await loadInfrastructure();
}

async function saveInfrastructureForm(event) {
    await authForm(event, 'infra-dialog-feedback', async () => {
        const operation = infrastructureEditing;
        if (!operation || operation.garden !== selectedHortaId || operation.userId !== currentUser?.id) throw new Error('O contexto mudou. Abra o formulário novamente.');
        const data = {};
        for (const control of document.querySelectorAll('#infra-fields [name]')) data[control.name] = control.value === '' ? null : control.value;
        if (operation.mode === 'resource') {
            if (operation.resource === 'estruturas' && data.id_reservatorio !== null) data.id_reservatorio = Number(data.id_reservatorio);
            await backendRequest(infrastructureUrl(operation.resource, operation.id, operation.garden), operation.id ? 'PATCH' : 'POST', data);
        } else if (operation.mode === 'application') {
            data.id_reservatorio = Number(data.id_reservatorio); data.id_nutriente = Number(data.id_nutriente);
            await backendRequest(infrastructureUrl('nutrientes_vinculos', null, operation.garden), 'POST', data);
        } else {
            data.id_sensor = Number(data.id_sensor); data.id_nutriente = data.id_nutriente === null ? null : Number(data.id_nutriente);
            await backendRequest(infrastructureUrl('nutrientes_vinculos', null, operation.garden), 'PATCH', data);
        }
        document.getElementById('infra-dialog').close(); await refreshInfrastructureContext(operation.garden);
        setFeedback('infra-feedback', 'Dados salvos.');
    });
}

async function deleteInfrastructure(row) {
    if (!row.pode_excluir || !confirm(`Excluir “${row.nome}”? Registros com vínculos não podem ser removidos.`)) return;
    const garden = selectedHortaId, resource = infrastructureTab;
    try { await backendRequest(infrastructureUrl(resource, row.id, garden), 'DELETE', { confirmacao: 'EXCLUIR' }); await refreshInfrastructureContext(garden); setFeedback('infra-feedback', 'Registro excluído.'); }
    catch (error) { setFeedback('infra-feedback', error.message); }
}

async function toggleInfrastructureCulture(row) {
    const garden = selectedHortaId;
    if (!infrastructureCanWrite()) return;
    if (row.vinculada && !confirm(`Desvincular “${row.nome}” desta horta? Plantas associadas impedem a operação.`)) return;
    try { await backendRequest(infrastructureUrl('culturas_horta', null, garden), row.vinculada ? 'DELETE' : 'POST', { id_cultura: Number(row.id), ...(row.vinculada ? { confirmacao: 'DESVINCULAR' } : {}) }); await refreshInfrastructureContext(garden); setFeedback('infra-feedback', row.vinculada ? 'Cultura desvinculada.' : 'Cultura habilitada.'); }
    catch (error) { setFeedback('infra-feedback', error.message); }
}

async function showInfrastructureDetails(id) {
    const resource = infrastructureTab, garden = selectedHortaId;
    try {
        const row = (await backendRequest(infrastructureUrl(resource, id, garden))).data;
        if (garden !== selectedHortaId) return;
        const list = document.getElementById('plant-details'); list.replaceChildren();
        for (const [field, label] of [['id','Identificador'],...infrastructureModules[resource].fields,['escopo','Escopo']]) {
            const block = document.createElement('div'), term = document.createElement('dt'), value = document.createElement('dd');
            term.className = 'font-semibold'; term.textContent = label; value.className = 'break-words'; value.textContent = row[field] ?? 'Não informado'; block.append(term,value); list.append(block);
        }
        document.getElementById('modal-detalhes-planta').querySelector('h2').textContent = 'Detalhes — ' + infrastructureModules[resource].title;
        document.getElementById('modal-detalhes-planta').showModal();
    } catch (error) { setFeedback('infra-feedback', error.message); }
}

function openInfraApplication() {
    if (!infrastructureCanWrite()) return;
    infrastructureEditing = { mode: 'application', garden: selectedHortaId, userId: currentUser.id };
    const fields = document.getElementById('infra-fields'); fields.replaceChildren();
    fields.append(infrastructureField(['id_reservatorio','Reservatório','reservoir',true]), infrastructureField(['id_nutriente','Nutriente','select',true],null,infrastructureData.nutrientes),
        infrastructureField(['quantidade','Quantidade','number',true,.001,999999999.999,.001]), infrastructureField(['unidade','Unidade','select',true],null,['g','mg','mL','L'].map(value=>({value,label:value}))));
    setFeedback('infra-dialog-title','Registrar aplicação de nutriente'); setFeedback('infra-dialog-feedback',''); document.getElementById('infra-dialog').showModal();
}

function openInfraSensor() {
    if (!infrastructureCanWrite() || !infrastructureLinks.sensores.length) return;
    infrastructureEditing = { mode: 'sensor', garden: selectedHortaId, userId: currentUser.id };
    const fields = document.getElementById('infra-fields'); fields.replaceChildren();
    fields.append(infrastructureField(['id_sensor','Sensor da horta','select',true],null,infrastructureLinks.sensores.map(s=>({id:s.id_sensor,nome:`${s.nome}${Number(s.possui_leituras)>0 ? ' (com histórico)' : ''}`}))), infrastructureField(['id_nutriente','Nutriente (vazio para desvincular)','select'],null,infrastructureData.nutrientes));
    setFeedback('infra-dialog-title','Vincular nutriente ao sensor');setFeedback('infra-dialog-feedback','Sensores com leituras não permitem trocar ou remover o nutriente.');document.getElementById('infra-dialog').showModal();
}
