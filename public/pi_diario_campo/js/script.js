'use strict';

const CHAVE_STORAGE = 'hydroponex.diarioCampo.v1';
const CONDICOES = ['Normal', 'Atenção', 'Alerta'];
const CATEGORIAS = ['Água', 'Nutrientes', 'Temperatura', 'Saúde vegetal', 'Outros'];
const CAMPOS = ['horta', 'local', 'data', 'condicao', 'categoria', 'observacao'];
const formulario = document.getElementById('inspecao-form');
const filtro = document.getElementById('filtro-condicao');
let inspecoes = [];
let idEdicao = null;
let conteudoArmazenado = null;
let armazenamentoBloqueado = false;

function mostrarMensagem(texto, erro = false) {
    const mensagem = document.getElementById('mensagem');
    mensagem.textContent = texto;
    mensagem.classList.toggle('erro', erro);
    mensagem.setAttribute('role', erro ? 'alert' : 'status');
    mensagem.hidden = false;
}

function bloquearArmazenamento(mensagem) {
    armazenamentoBloqueado = true;
    document.getElementById('salvar').disabled = true;
    mostrarMensagem(mensagem, true);
}

function dataValida(valor) {
    if (typeof valor !== 'string' || !/^\d{4}-\d{2}-\d{2}$/.test(valor) || valor.startsWith('0000')) return false;
    const data = new Date(valor + 'T00:00:00Z');
    // A comparação rejeita datas normalizadas pelo Date, como 30 de fevereiro.
    return !Number.isNaN(data.getTime()) && data.toISOString().slice(0, 10) === valor;
}

function validarFormulario(dados) {
    const erros = [];
    const textos = [
        ['horta', 100, 'Informe o nome da horta.'],
        ['local', 160, 'Informe a planta, bancada ou local da inspeção.'],
        ['observacao', 2000, 'Descreva a observação realizada.']
    ];
    for (const [campo, limite, mensagem] of textos) {
        const valor = dados[campo];
        if (typeof valor !== 'string' || !valor.trim()) erros.push({ campo, mensagem });
        else if (valor.length > limite) erros.push({ campo, mensagem: 'Use até ' + limite + ' caracteres neste campo.' });
        else if (/[\u0000-\u0008\u000b\u000c\u000e-\u001f\u007f]/u.test(valor)) {
            erros.push({ campo, mensagem: 'Remova caracteres de controle deste campo.' });
        }
    }
    if (!dataValida(dados.data)) erros.push({ campo: 'data', mensagem: 'Informe uma data válida para a inspeção.' });
    if (!CONDICOES.includes(dados.condicao)) erros.push({ campo: 'condicao', mensagem: 'Selecione uma condição válida.' });
    if (!CATEGORIAS.includes(dados.categoria)) erros.push({ campo: 'categoria', mensagem: 'Selecione uma categoria válida.' });
    return erros;
}

function estruturaValida(dados) {
    if (!Array.isArray(dados)) return false;
    const ids = new Set();
    return dados.every((registro) => {
        if (!registro || typeof registro !== 'object' || Array.isArray(registro)) return false;
        const chaves = Object.keys(registro);
        if (chaves.length !== CAMPOS.length + 1 || !chaves.every((chave) => chave === 'id' || CAMPOS.includes(chave))) return false;
        if (typeof registro.id !== 'string' || !/^[a-zA-Z0-9_-]{1,100}$/.test(registro.id) || ids.has(registro.id)) return false;
        ids.add(registro.id);
        return validarFormulario(registro).length === 0;
    });
}

function carregarDados() {
    try {
        const texto = localStorage.getItem(CHAVE_STORAGE);
        const dados = texto === null ? [] : JSON.parse(texto);
        if (!estruturaValida(dados)) {
            bloquearArmazenamento('Os dados locais possuem estrutura incompatível. Nada foi sobrescrito. Preserve o conteúdo da chave ' + CHAVE_STORAGE + ' e procure orientação antes de corrigir.');
            return;
        }
        conteudoArmazenado = texto;
        inspecoes = dados;
    } catch (erro) {
        const detalhe = erro instanceof SyntaxError
            ? 'O JSON do diário está inválido. Nada foi sobrescrito. Preserve o conteúdo da chave ' + CHAVE_STORAGE + ' antes de corrigir.'
            : 'O armazenamento local está indisponível. Permita o armazenamento neste navegador e recarregue a página. Nenhum registro foi confirmado.';
        bloquearArmazenamento(detalhe);
    }
}

function salvarDados(novosDados) {
    if (armazenamentoBloqueado) {
        mostrarMensagem('A gravação está bloqueada para proteger seus dados. Resolva o aviso de armazenamento e recarregue a página.', true);
        return false;
    }
    try {
        if (!estruturaValida(novosDados)) throw new Error('estrutura');
        if (localStorage.getItem(CHAVE_STORAGE) !== conteudoArmazenado) {
            bloquearArmazenamento('O diário foi alterado em outra aba. Seu formulário foi preservado; recarregue antes de continuar para evitar sobrescrever dados.');
            renderizarLista();
            return false;
        }
        const texto = JSON.stringify(novosDados);
        localStorage.setItem(CHAVE_STORAGE, texto);
        conteudoArmazenado = texto;
        return true;
    } catch (erro) {
        const mensagem = erro.name === 'QuotaExceededError' || erro.name === 'NS_ERROR_DOM_QUOTA_REACHED'
            ? 'O espaço de armazenamento do navegador acabou. Nenhuma alteração foi salva; o formulário e os registros anteriores foram preservados.'
            : 'Não foi possível salvar no navegador. Nenhuma alteração foi confirmada; o formulário e os registros anteriores foram preservados.';
        mostrarMensagem(mensagem, true);
        return false;
    }
}

function obterDadosFormulario() {
    const dados = {};
    for (const campo of CAMPOS) dados[campo] = document.getElementById(campo).value.trim();
    return dados;
}

function limparErros() {
    for (const campo of CAMPOS) {
        document.getElementById(campo).removeAttribute('aria-invalid');
        document.getElementById(campo + '-erro').textContent = '';
        document.getElementById(campo + '-erro').hidden = true;
    }
}

function apresentarErros(erros) {
    limparErros();
    for (const erro of erros) {
        document.getElementById(erro.campo).setAttribute('aria-invalid', 'true');
        const aviso = document.getElementById(erro.campo + '-erro');
        aviso.textContent = erro.mensagem;
        aviso.hidden = false;
    }
    mostrarMensagem('Confira os campos indicados. A inspeção não foi salva.', true);
    document.getElementById(erros[0].campo).focus();
}

function criarInspecao(dados) {
    let id;
    do {
        id = 'inspecao-' + (globalThis.crypto?.randomUUID
            ? globalThis.crypto.randomUUID()
            : Date.now().toString(36) + '-' + Math.random().toString(36).slice(2));
    } while (inspecoes.some((registro) => registro.id === id));
    return { id, ...dados };
}

function limparFormulario() {
    formulario.reset();
    idEdicao = null;
    limparErros();
    document.getElementById('titulo-formulario').textContent = 'Nova inspeção';
    document.getElementById('salvar').textContent = 'Cadastrar inspeção';
    document.getElementById('cancelar-edicao').hidden = true;
    document.getElementById('modo-edicao').hidden = true;
}

function editarInspecao(id) {
    const registro = inspecoes.find((item) => item.id === id);
    if (!registro) {
        mostrarMensagem('A inspeção não foi encontrada. Atualize a página antes de editar.', true);
        return;
    }
    if (armazenamentoBloqueado) return;
    limparErros();
    idEdicao = id;
    for (const campo of CAMPOS) document.getElementById(campo).value = registro[campo];
    document.getElementById('titulo-formulario').textContent = 'Editar inspeção';
    document.getElementById('salvar').textContent = 'Salvar alterações';
    document.getElementById('cancelar-edicao').hidden = false;
    document.getElementById('modo-edicao').hidden = false;
    mostrarMensagem('Editando a inspeção. Salve as alterações ou cancele a edição.');
    document.getElementById('horta').focus();
}

function excluirInspecao(id) {
    const registro = inspecoes.find((item) => item.id === id);
    if (!registro) {
        mostrarMensagem('A inspeção não foi encontrada. Atualize a página antes de excluir.', true);
        return;
    }
    if (armazenamentoBloqueado) return;
    if (!window.confirm('Excluir a inspeção de ' + registro.horta + ' em ' + registro.data + '? Esta ação remove este rascunho local.')) {
        mostrarMensagem('Exclusão cancelada. A inspeção foi mantida.');
        return;
    }
    const novosDados = inspecoes.filter((item) => item.id !== id);
    if (!salvarDados(novosDados)) return;
    // O estado só muda depois de setItem ter concluído sem exceções.
    inspecoes = novosDados;
    if (idEdicao === id) limparFormulario();
    renderizarLista();
    atualizarIndicadores();
    mostrarMensagem('Inspeção excluída deste navegador.');
    filtro.focus();
}

function filtrarInspecoes() {
    const condicao = filtro.value;
    return inspecoes.filter((registro) => !condicao || registro.condicao === condicao);
}

function criarTexto(tag, texto, classe = '') {
    const elemento = document.createElement(tag);
    elemento.textContent = texto; // Nunca interpretar observações como HTML.
    if (classe) elemento.className = classe;
    return elemento;
}

function criarCard(registro) {
    const card = document.createElement('article');
    card.className = 'inspecao';
    card.dataset.id = registro.id;
    const cabecalho = document.createElement('div');
    cabecalho.className = 'cabecalho-card';
    cabecalho.appendChild(criarTexto('h3', registro.horta));
    const classes = { Normal: 'normal', 'Atenção': 'atencao', Alerta: 'alerta' };
    cabecalho.appendChild(criarTexto('span', registro.condicao, 'selo ' + classes[registro.condicao]));
    card.appendChild(cabecalho);
    card.appendChild(criarTexto('p', registro.local));
    const data = document.createElement('time');
    data.dateTime = registro.data;
    data.textContent = new Intl.DateTimeFormat('pt-BR', { timeZone: 'UTC' }).format(new Date(registro.data + 'T00:00:00Z'));
    card.appendChild(data);
    card.appendChild(criarTexto('p', 'Categoria: ' + registro.categoria, 'meta'));
    card.appendChild(criarTexto('p', registro.observacao, 'observacao'));
    card.appendChild(criarTexto('small', 'ID: ' + registro.id, 'meta identificador'));
    const acoes = document.createElement('div');
    acoes.className = 'acoes';
    for (const [titulo, classe, acao] of [['Editar', 'secundario', editarInspecao], ['Excluir', 'excluir', excluirInspecao]]) {
        const botao = criarTexto('button', titulo, 'botao ' + classe);
        botao.type = 'button';
        botao.disabled = armazenamentoBloqueado;
        botao.setAttribute('aria-label', titulo + ' inspeção: ' + registro.horta + ', ' + registro.local);
        botao.addEventListener('click', () => acao(registro.id));
        acoes.appendChild(botao);
    }
    card.appendChild(acoes);
    return card;
}

function renderizarLista() {
    const lista = document.getElementById('lista-inspecoes');
    lista.replaceChildren();
    const filtradas = filtrarInspecoes();
    // Uma cópia ordenada preserva o array original e mantém datas recentes no topo.
    const ordenadas = [...filtradas].sort((a, b) => b.data.localeCompare(a.data));
    for (const registro of ordenadas) lista.appendChild(criarCard(registro));
    document.getElementById('quantidade-visivel').textContent = filtradas.length + ' de ' + inspecoes.length;
    const vazio = document.getElementById('estado-vazio');
    vazio.hidden = filtradas.length > 0;
    vazio.textContent = armazenamentoBloqueado && inspecoes.length === 0
        ? 'O diário não pôde ser carregado com segurança. Consulte o aviso acima; os dados locais não foram apagados.'
        : (inspecoes.length === 0 ? 'Nenhuma inspeção cadastrada. Use o formulário para começar.' : 'Nenhuma inspeção corresponde à condição selecionada.');
}

function atualizarIndicadores() {
    document.getElementById('total-inspecoes').textContent = String(inspecoes.length);
    document.getElementById('total-atencao').textContent = String(inspecoes.filter((item) => item.condicao === 'Atenção').length);
    document.getElementById('total-alerta').textContent = String(inspecoes.filter((item) => item.condicao === 'Alerta').length);
}

formulario.addEventListener('submit', (evento) => {
    evento.preventDefault();
    const dados = obterDadosFormulario();
    const erros = validarFormulario(dados);
    if (erros.length) {
        apresentarErros(erros);
        return;
    }
    limparErros();
    if (idEdicao !== null && !inspecoes.some((item) => item.id === idEdicao)) {
        mostrarMensagem('A inspeção em edição não existe mais. Cancele a edição e atualize a página.', true);
        return;
    }
    const editando = idEdicao !== null;
    const registro = editando ? { id: idEdicao, ...dados } : criarInspecao(dados);
    const novosDados = editando
        ? inspecoes.map((item) => item.id === idEdicao ? registro : item)
        : [...inspecoes, registro];
    if (!salvarDados(novosDados)) return;
    inspecoes = novosDados;
    renderizarLista();
    atualizarIndicadores();
    limparFormulario();
    mostrarMensagem(editando ? 'Inspeção atualizada neste navegador.' : 'Inspeção cadastrada neste navegador.');
});

document.getElementById('cancelar-edicao').addEventListener('click', () => {
    limparFormulario();
    mostrarMensagem('Edição cancelada. Os dados anteriores foram mantidos.');
    document.getElementById('horta').focus();
});
filtro.addEventListener('change', renderizarLista);
window.addEventListener('storage', (evento) => {
    if (evento.key === CHAVE_STORAGE || evento.key === null) {
        bloquearArmazenamento('O armazenamento do diário mudou em outra aba. Recarregue a página antes de continuar; nenhum dado foi sobrescrito por esta aba.');
        renderizarLista();
    }
});

carregarDados();
renderizarLista();
atualizarIndicadores();
