// Dados em memória são descartados ao mudar de horta/usuário. Nenhuma medição é gerada.
let monitoringVersion = 0, dashboardVersion = 0, monitoringHistoryVersion = 0;
let monitoringSensors = [], monitoringTypes = {}, monitoringOptions = {}, monitoringCursor = null;
let monitoringOperation = null, monitoringChart = null, monitoringHistory = [];
const monitoringOrigins = { manual: 'Manual (informada)', sensor: 'Dispositivo (origem registrada)', simulacao: 'SIMULAÇÃO', importacao: 'Importação (origem não verificada)' };
const monitoringStates = { normal: 'Dentro dos limites', alerta: 'FORA DOS LIMITES', sem_limites: 'Sem limites definidos' };

function monitoringUrl(resource, extra = {}) {
    return `php/api/${resource}.php?` + new URLSearchParams({ horta: selectedHortaId, ...extra });
}

function resetMonitoring() {
    monitoringVersion++; dashboardVersion++; monitoringHistoryVersion++; monitoringOperation = null;
    monitoringSensors = []; monitoringOptions = {}; monitoringCursor = null; monitoringHistory = [];
    monitoringChart?.destroy(); monitoringChart = null;
    document.getElementById('monitor-dialog').close();
    for (const id of ['monitor-sensors','monitor-history','dashboard-sensors','grid-monitoramento']) document.getElementById(id).replaceChildren();
    for (const id of ['monitor-filter-sensor','monitor-filter-plant']) document.getElementById(id).replaceChildren(new Option('Todos',''));
    document.getElementById('monitor-filter-form').reset();
    document.getElementById('monitor-next').disabled = true;
    document.getElementById('monitor-create').disabled = true; document.getElementById('monitor-reading').disabled = true;
    setFeedback('monitor-feedback', 'Selecione uma horta.'); setFeedback('dashboard-feedback', 'Selecione uma horta.');
    for (const id of ['stat-total','stat-normal','stat-atencao','stat-alerta']) setFeedback(id, '—');
    setFeedback('stat-consumo','Sem registro');setFeedback('stat-estado-geral','Sem dados');
    [chartConsumoDash,chartStatusDash,chartConsumoComp].forEach(c=>c?.destroy());chartConsumoDash=chartStatusDash=chartConsumoComp=null;
    ['consumo-mes','consumo-economia','consumo-indice'].forEach(id=>setFeedback(id,'Sem registro'));
}

function monitoringSelect(id, rows, pk, label = 'nome', empty = 'Todos') {
    const select = document.getElementById(id), old = select.value;
    select.replaceChildren(new Option(empty,''));
    for (const row of rows) select.add(new Option(row[label], String(row[pk])));
    if ([...select.options].some(o=>o.value===old)) select.value=old;
}

async function loadMonitoring() {
    if (!currentUser || !selectedHortaId || !selectedHorta) return;
    const version=++monitoringVersion, garden=selectedHortaId;
    setFeedback('monitor-feedback','Carregando sensores…');
    try {
        const [sensors,reservoirs,structures,nutrients] = await Promise.all(['sensores','reservatorios','estruturas','nutrientes'].map(r=>backendRequest(monitoringUrl(r))));
        const plants=(await backendRequest(monitoringUrl('plantas'))).data;
        if(version!==monitoringVersion||garden!==selectedHortaId||!currentUser)return;
        monitoringSensors=sensors.data;monitoringTypes=sensors.tipos;
        monitoringOptions={reservatorios:reservoirs.data,estruturas:structures.data,nutrientes:nutrients.data,plantas:plants};
        document.getElementById('monitor-create').disabled=!selectedHorta.pode_editar;
        document.getElementById('monitor-reading').disabled=!selectedHorta.pode_editar||!monitoringSensors.some(s=>s.status==='ativo'&&monitoringTypes[s.tipo]?.unidades.includes(s.unidade));
        monitoringSelect('monitor-filter-sensor',monitoringSensors,'id_sensor');monitoringSelect('monitor-filter-plant',plants,'id');
        renderSensorList();await loadMonitoringHistory();
    } catch(error) { if(version===monitoringVersion)setFeedback('monitor-feedback',error.message); }
}

function renderSensorList() {
    const box=document.getElementById('monitor-sensors');box.replaceChildren();
    if(!monitoringSensors.length)box.textContent='Nenhum sensor cadastrado nesta horta.';
    for(const s of monitoringSensors) {
        const card=document.createElement('article');card.className='p-5 rounded-3xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-darkbg-800 space-y-3 min-w-0';
        const title=document.createElement('h2');title.className='font-bold break-words';title.textContent=s.nome;card.append(title);
        const details=document.createElement('p');details.className='text-sm break-words';details.textContent=`#${s.id_sensor} · ${s.tipo} (${s.unidade}) · ${s.status} · limites ${s.limite_min??'—'} / ${s.limite_max??'—'}`;card.append(details);
        const target=document.createElement('p');target.className='text-xs break-words';target.textContent=s.id_planta?`Planta #${s.id_planta}`:s.id_reservatorio?`Reservatório #${s.id_reservatorio}`:s.id_estrutura?`Estrutura #${s.id_estrutura}`:'Horta (sem alvo específico)';card.append(target);
        if(selectedHorta?.pode_editar)for(const [label,fn]of [['Editar',()=>openSensorForm(s)],['Excluir',()=>deleteSensor(s)]]){const b=document.createElement('button');b.className='text-sm text-hydro-600 font-bold mr-4';b.textContent=label;b.onclick=fn;card.append(b);}
        box.append(card);
    }
}

function monitoringField(name,label,type,value='',options=[],required=false) {
    const wrap=document.createElement('label');wrap.className='block text-sm';wrap.textContent=label;
    const input=document.createElement(type==='select'?'select':'input');input.id='monitor-field-'+name;input.name=name;
    input.className='mt-1 w-full min-w-0 p-2.5 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-darkbg-900';
    if(type==='select')for(const [v,text]of options)input.add(new Option(text,String(v)));else input.type=type;
    if(type==='number')input.step='0.000001';if(type==='text')input.maxLength=100;
    input.value=value??'';input.required=required;wrap.append(input);document.getElementById('monitor-fields').append(wrap);return input;
}

function monitoringOpen(title,operation) {
    if(!selectedHorta?.pode_editar)return false;
    monitoringOperation={...operation,garden:selectedHortaId,user:currentUser.id};
    document.getElementById('monitor-fields').replaceChildren();setFeedback('monitor-dialog-title',title);setFeedback('monitor-dialog-feedback','');return true;
}

function openSensorForm(sensor=null) {
    if(!monitoringOpen(sensor?'Editar sensor':'Cadastrar sensor',{mode:'sensor',id:sensor?.id_sensor}))return;
    monitoringField('nome','Nome','text',sensor?.nome??'',[],true);
    const type=monitoringField('tipo','Parâmetro','select',sensor?.tipo??'temperatura',Object.keys(monitoringTypes).map(k=>[k,k]),true);
    const unit=monitoringField('unidade','Unidade','select');
    const units=()=>{unit.replaceChildren(...(monitoringTypes[type.value]?.unidades??[]).map(u=>new Option(u,u)));};units();if(sensor)unit.value=sensor.unidade;type.onchange=units;
    const legacy=sensor&&(!monitoringTypes[sensor.tipo]||!monitoringTypes[sensor.tipo].unidades.includes(sensor.unidade));
    if(legacy){type.add(new Option(sensor.tipo+' (legado)',sensor.tipo));type.value=sensor.tipo;unit.replaceChildren(new Option(sensor.unidade,sensor.unidade));type.disabled=true;unit.disabled=true;}
    monitoringField('status','Status','select',sensor?.status??'ativo',['ativo','inativo','manutencao'].map(k=>[k,k]),true);
    const targets=[['','Horta (sem alvo específico)']];let target='';
    for(const [kind,rows,pk,fk]of [['reservatorio',monitoringOptions.reservatorios,'id','id_reservatorio'],['estrutura',monitoringOptions.estruturas,'id','id_estrutura'],['planta',monitoringOptions.plantas,'id','id_planta']]){
        for(const r of rows)targets.push([`${kind}:${r[pk]}`,`${kind}: ${r.nome}`]);if(sensor?.[fk])target=`${kind}:${sensor[fk]}`;
    }
    if(target&&!targets.some(([v])=>v===target))targets.push([target,`${target} (histórico)`]);
    monitoringField('alvo','Local do sensor','select',target,targets);
    monitoringField('id_nutriente','Nutriente específico (sensor nutrientes)','select',sensor?.id_nutriente??'',[['','Nenhum'],...monitoringOptions.nutrientes.map(n=>[n.id,n.nome])]);
    monitoringField('limite_min','Limite inferior (opcional)','number',sensor?.limite_min);
    monitoringField('limite_max','Limite superior (opcional)','number',sensor?.limite_max);
    if(legacy){document.getElementById('monitor-field-limite_min').disabled=true;document.getElementById('monitor-field-limite_max').disabled=true;}
    if(Number(sensor?.possui_leituras)) {type.disabled=true;unit.disabled=true;document.getElementById('monitor-field-alvo').disabled=true;document.getElementById('monitor-field-id_nutriente').disabled=true;}
    document.getElementById('monitor-dialog').showModal();
}

function openReadingForm() {
    if(!monitoringOpen('Registrar medição',{mode:'reading'}))return;
    monitoringField('id_sensor','Sensor','select','', [['','Selecione'],...monitoringSensors.filter(s=>s.status==='ativo'&&monitoringTypes[s.tipo]?.unidades.includes(s.unidade)).map(s=>[s.id_sensor,`${s.nome} (${s.unidade})`])],true);
    monitoringField('valor','Valor medido','number','',[],true);
    monitoringField('origem','Origem declarada','select','manual',[['manual','Manual — valor informado'],['simulacao','SIMULAÇÃO — dado de teste']],true);
    const local=new Date(Date.now()-new Date().getTimezoneOffset()*60000).toISOString().slice(0,16);
    monitoringField('data_hora','Data e hora local da medição','datetime-local',local,[],true);
    monitoringField('descricao','Observação (opcional)','text');document.getElementById('monitor-dialog').showModal();
}

async function saveMonitoringForm(event) {
    await authForm(event,'monitor-dialog-feedback',async()=>{
        const op=monitoringOperation;if(!op||op.garden!==selectedHortaId||op.user!==currentUser?.id)throw Error('O contexto mudou. Abra o formulário novamente.');
        const get=n=>document.getElementById('monitor-field-'+n).value;let data;
        if(op.mode==='sensor') {
            data={nome:get('nome'),tipo:get('tipo'),unidade:get('unidade'),status:get('status'),limite_min:get('limite_min')||null,limite_max:get('limite_max')||null,id_nutriente:get('id_nutriente')?Number(get('id_nutriente')):null,id_reservatorio:null,id_estrutura:null,id_planta:null};
            if(document.getElementById('monitor-field-limite_min').disabled){delete data.limite_min;delete data.limite_max;}
            if(get('alvo')){const[kind,id]=get('alvo').split(':');data['id_'+kind]=Number(id);}
            await backendRequest(monitoringUrl('sensores',op.id?{id:op.id}:{}),op.id?'PATCH':'POST',data);
        } else {
            const sensor=monitoringSensors.find(s=>Number(s.id_sensor)===Number(get('id_sensor')));if(!sensor)throw Error('Selecione um sensor.');
            data={origem:get('origem'),data_hora:new Date(get('data_hora')).toISOString(),descricao:get('descricao'),leituras:[{id_sensor:Number(sensor.id_sensor),valor:get('valor'),tipo_medicao:sensor.tipo,unidade:sensor.unidade}]};
            await backendRequest(monitoringUrl('monitoramentos'),'POST',data);
        }
        document.getElementById('monitor-dialog').close();await loadMonitoring();await renderMonitoringDashboard();
    });
}

async function deleteSensor(sensor) {
    if(!confirm(`Excluir ${sensor.nome}? Sensores com histórico devem ser inativados.`))return;
    try{await backendRequest(monitoringUrl('sensores',{id:sensor.id_sensor}),'DELETE',{confirmacao:'EXCLUIR'});await loadMonitoring();await renderMonitoringDashboard();}catch(e){setFeedback('monitor-feedback',e.message);}
}

async function loadMonitoringHistory(next=false) {
    if(!currentUser||!selectedHortaId)return;const version=monitoringVersion,garden=selectedHortaId,historyVersion=++monitoringHistoryVersion;
    const query={limite:50};for(const k of ['sensor','planta','origem','inicio','fim']){const v=document.getElementById('monitor-filter-'+(k==='planta'?'plant':k)).value;if(v)query[k]=['inicio','fim'].includes(k)?new Date(v).toISOString():v;}
    if(next&&monitoringCursor)query.cursor=monitoringCursor;
    setFeedback('monitor-feedback','Consultando histórico…');document.getElementById('monitor-next').disabled=true;
    try{
        const response=await backendRequest(monitoringUrl('monitoramentos',query));if(version!==monitoringVersion||garden!==selectedHortaId||historyVersion!==monitoringHistoryVersion)return;
        monitoringCursor=response.proximo_cursor;monitoringHistory=response.data;
        const body=document.getElementById('monitor-history');body.replaceChildren();
        for(const r of response.data){const tr=document.createElement('tr');for(const text of [plantDate(r.data_hora)?.toLocaleString('pt-BR')??r.data_hora,r.sensor,`${r.valor} ${r.unidade}`,monitoringOrigins[r.origem],monitoringStates[r.estado]+(r.antiga?' · mais de 24h':''),r.descricao??'—']){const td=document.createElement('td');td.className='p-3 break-words';td.textContent=text;tr.append(td);}body.append(tr);}
        document.getElementById('monitor-next').disabled=!monitoringCursor;
        setFeedback('monitor-feedback',response.data.length?`${response.data.length} leitura(s) nesta página. Horários locais; simulações identificadas.`:'Nenhuma leitura encontrada.');
        renderMonitoringChart();
    }catch(e){if(version===monitoringVersion&&historyVersion===monitoringHistoryVersion){document.getElementById('monitor-history').replaceChildren();monitoringChart?.destroy();monitoringChart=null;setFeedback('monitor-feedback',e.message);}}
}

function renderMonitoringChart() {
    monitoringChart?.destroy();monitoringChart=null;
    const sensor=document.getElementById('monitor-filter-sensor').value;
    setFeedback('monitor-chart-note',sensor?'Valores desta página, por origem. Lacunas não são preenchidas.':'Selecione um sensor para visualizar a série sem misturar unidades.');
    if(!sensor||!monitoringHistory.length||typeof Chart==='undefined')return;
    const rows=[...monitoringHistory].reverse();
    monitoringChart=new Chart(document.getElementById('chart-monitor-history'),{type:'line',data:{labels:rows.map(r=>plantDate(r.data_hora)?.toLocaleString('pt-BR')),datasets:Object.entries(monitoringOrigins).map(([origin,label],i)=>({label,data:rows.map(r=>r.origem===origin?Number(r.valor):null),borderColor:['#0ea5e9','#22c55e','#f59e0b','#64748b'][i],borderDash:origin==='simulacao'?[5,5]:[],spanGaps:false,pointRadius:4}))},options:{responsive:true,maintainAspectRatio:false,animation:false}});
}

function monitoringPlot(canvas,labels,datasets,type='line') {
    if(typeof Chart==='undefined')return null;
    return new Chart(document.getElementById(canvas), {
        type, data: {labels,datasets},
        options: {
            responsive:true, maintainAspectRatio:false, animation:false,
            plugins:{legend:{labels:{color:getChartTextColor()}}},
            scales:type==='doughnut'?{}:{x:{ticks:{color:getChartTextColor()}},y:{ticks:{color:getChartTextColor()}}}
        }
    });
}

async function renderMonitoringDashboard() {
    if(!currentUser||!selectedHortaId)return;
    const version=++dashboardVersion,garden=selectedHortaId;
    try{
        const {data:d}=await backendRequest(monitoringUrl('dashboard'));if(version!==dashboardVersion||garden!==selectedHortaId)return;
        const values=[d.total_plantas,d.plantas.Normal,d.plantas['Atenção'],d.plantas.Alerta];['stat-total','stat-normal','stat-atencao','stat-alerta'].forEach((id,i)=>setFeedback(id,values[i]));
        setFeedback('stat-consumo',d.consumo_hoje_l===null?'Sem registro':Number(d.consumo_hoje_l).toLocaleString('pt-BR')+' L');
        const measured=d.sensores.filter(s=>s.status==='ativo'&&s.ultima_medicao);
        setFeedback('stat-estado-geral',d.alertas_medicoes?`${d.alertas_medicoes} alerta(s)`:!measured.length?'Sem medições':measured.some(s=>s.ultima_medicao.antiga)?'Dados antigos':measured.some(s=>s.ultima_medicao.estado==='sem_limites')?'Limites pendentes':'Dentro dos limites');
        setFeedback('dashboard-feedback',`${d.sensores.length} sensor(es) cadastrado(s). Sem conexão IoT automática. Estado das plantas informado no cadastro; alertas usam limites registrados na medição. Consumo legado/importado tem origem não verificada; simulações de consumo excluídas.`);
        const box=document.getElementById('dashboard-sensors');box.replaceChildren();if(!d.sensores.length)box.textContent='Nenhum sensor ou medição disponível.';
        for(const s of d.sensores){const p=document.createElement('p');p.className='p-3 rounded-xl border border-slate-200 dark:border-slate-700 text-sm break-words';let texts=[`${s.nome} · ${s.status}`];for(const [key,label]of [['ultima_medicao','Última medição'],['ultima_simulacao','Última SIMULAÇÃO']]){const r=s[key];texts.push(r?`${label}: ${r.valor} ${r.unidade} · ${monitoringOrigins[r.origem]} · ${plantDate(r.data_hora)?.toLocaleString('pt-BR')} · ${monitoringStates[r.estado]}${r.antiga?' · mais de 24h':''}`:`${label}: não disponível`);}p.textContent=texts.join(' | ');box.append(p);}
        [chartConsumoDash,chartStatusDash,chartConsumoComp].forEach(c=>c?.destroy());
        const days=Array.from({length:7},(_,i)=>{const date=new Date(d.data_consumo_hoje+'T12:00:00Z');date.setUTCDate(date.getUTCDate()-6+i);const key=date.toISOString().slice(0,10);return d.consumo_diario.find(r=>r.data_consumo===key)??{data_consumo:key,volume_l:null,referencia_solo_l:null};});
        chartConsumoDash=monitoringPlot('chart-dash-consumo',days.map(r=>r.data_consumo),[{label:'Consumo registrado (L)',data:days.map(r=>r.volume_l===null?null:Number(r.volume_l)),borderColor:'#0ea5e9',spanGaps:false},{label:'Economia estimada, quando há referência (L)',data:days.map(r=>r.referencia_solo_l===null?null:Number(r.referencia_solo_l)-Number(r.volume_l)),borderColor:'#22c55e'}]);
        chartStatusDash=monitoringPlot('chart-dash-status',['Normal (cadastro)','Atenção (cadastro)','Alerta (cadastro)'],[{data:values.slice(1),backgroundColor:['#22c55e','#f59e0b','#f43f5e']}],'doughnut');
        const months=d.consumo_mensal;chartConsumoComp=monitoringPlot('chart-consumo-comparativo',months.map(r=>r.mes),[{label:'Consumo registrado (L)',data:months.map(r=>Number(r.volume_l)),backgroundColor:'#0ea5e9'},{label:'Economia estimada nos dias com referência (L)',data:months.map(r=>r.economia_l===null?null:Number(r.economia_l)),backgroundColor:'#22c55e'}],'bar');
        const current=months.find(r=>r.mes===new Intl.DateTimeFormat('sv-SE',{timeZone:'America/Sao_Paulo',year:'numeric',month:'2-digit'}).format(new Date()));
        setFeedback('consumo-mes',current?`${current.volume_l} L (${current.dias_registrados} dias registrados)`:'Sem registro');setFeedback('consumo-economia',current?.economia_l!=null?`${current.economia_l} L (estimativa parcial)`:'Sem referência');setFeedback('consumo-indice','Não disponível');
    }catch(e){if(version===dashboardVersion){
        document.getElementById('dashboard-sensors').replaceChildren();
        ['stat-total','stat-normal','stat-atencao','stat-alerta','stat-consumo','stat-estado-geral','consumo-mes','consumo-economia','consumo-indice'].forEach(id=>setFeedback(id,'Não disponível'));
        [chartConsumoDash,chartStatusDash,chartConsumoComp].forEach(c=>c?.destroy());chartConsumoDash=chartStatusDash=chartConsumoComp=null;
        setFeedback('dashboard-feedback',e.message);
    }}
}
