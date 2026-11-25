// Regressões de respostas atrasadas e paginação sem navegador ou servidor.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const root = path.resolve(__dirname, '..');
const source = fs.readFileSync(path.join(root, 'public/assets/js/script.js'), 'utf8');
const start = source.indexOf('async function backendRequest(');
const end = source.indexOf('async function initializeApi(', start);
assert.ok(start >= 0 && end > start);
let release;
let logoutCount = 0;
const context = vm.createContext({
    currentUser: { id: 1 },
    csrfToken: 'initial-token',
    refreshCsrf: async () => {},
    fetch: () => new Promise(resolve => { release = resolve; }),
    clearAuthentication: () => { logoutCount++; },
    navigateTo: () => {},
});
vm.runInContext(source.slice(start, end), context);

async function response(status, body, changeSession) {
    context.currentUser = { id: 1 };
    context.csrfToken = 'initial-token';
    const request = context.backendRequest('php/api/auth/me.php').then(
        value => ({ value }),
        error => ({ error }),
    );
    changeSession?.();
    release({ status, ok: status === 200, json: async () => body });
    return request;
}

async function main() {
    await response(401, { success: false, message: 'Autenticação necessária.' }, () => {
        context.currentUser = { id: 2 };
        context.csrfToken = 'new-account-token';
    });
    assert.equal(logoutCount, 0, 'Resposta de outra conta não encerra a sessão atual.');
    await response(401, { success: false }, () => { context.csrfToken = 'renewed-token'; });
    assert.equal(logoutCount, 0, 'Resposta anterior à rotação não encerra a sessão renovada.');
    await response(403, { success: false, message: 'Token de segurança inválido ou ausente.' }, () => {
        context.csrfToken = 'renewed-token';
    });
    assert.equal(context.csrfToken, 'renewed-token', 'CSRF antigo não apaga o token atual.');
    await response(200, { success: true, csrfToken: 'old-response-token' }, () => {
        context.csrfToken = 'renewed-token';
    });
    assert.equal(context.csrfToken, 'renewed-token', 'Resposta antiga não substitui token renovado.');
    await response(200, { success: true, csrfToken: 'valid-response-token' });
    assert.equal(context.csrfToken, 'valid-response-token', 'Resposta vigente pode renovar CSRF.');
    await response(401, { success: false });
    assert.equal(logoutCount, 1, 'Expiração da sessão vigente continua encerrando a interface.');

    const nodes = new Map();
    const node = id => {
        if (!nodes.has(id)) nodes.set(id, {
            disabled: false, textContent: 'Página anterior', value: '',
            replaceChildren() { this.cleared = true; },
            classList: { add() {} },
        });
        return nodes.get(id);
    };
    const taskContext = vm.createContext({
        document: { getElementById: node },
        setFeedback: (id, value) => { node(id).textContent = value; },
        currentUser: { id: 1 }, selectedHortaId: null, location: { hash: '#tarefas' },
    });
    const tasks = fs.readFileSync(path.join(root, 'public/assets/js/tarefas.js'), 'utf8');
    vm.runInContext(tasks.slice(tasks.indexOf('async function loadTasks('), tasks.indexOf('async function openTaskCreation(')), taskContext);
    vm.runInContext('let taskListVersion = 0;', taskContext);
    await taskContext.loadTasks();
    assert.equal(node('tasks-prev').disabled, true);
    assert.equal(node('tasks-next').disabled, true);
    assert.equal(node('tasks-page').textContent, '');
    console.log('9 verificações de integração JavaScript aprovadas.');
}
main().catch(error => { console.error(error); process.exitCode = 1; });
