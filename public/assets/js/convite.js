'use strict';

let inviteToken = location.hash.slice(1);
history.replaceState(null, '', location.pathname);
let inviteCsrf = '';
let inviteBusy = false;
const byId = id => document.getElementById(id);
const statusText = message => { byId('invite-status').textContent = message; };

async function inviteRequest(path, data) {
    const options = { credentials: 'same-origin', headers: { Accept: 'application/json' } };
    if (data) {
        options.method = 'POST';
        options.headers['Content-Type'] = 'application/json';
        options.headers['X-CSRF-Token'] = inviteCsrf;
        options.body = JSON.stringify(data);
    }
    const response = await fetch('php/api/' + path, options);
    let result;
    try { result = await response.json(); }
    catch { throw new Error('Serviço de convites temporariamente indisponível.'); }
    if (!response.ok || !result.success) {
        const error = new Error(result.message || 'Não foi possível concluir.');
        error.status = response.status;
        throw error;
    }
    if (result.csrfToken) inviteCsrf = result.csrfToken;
    return result.data;
}

function showInviteForm(mode) {
    byId('invite-login').hidden = mode !== 'login';
    byId('invite-register').hidden = mode !== 'register';
}

async function lookupOffer() {
    let user;
    try {
        user = await inviteRequest('auth/me.php');
    } catch (error) {
        if (error.status !== 401) throw error;
        await inviteRequest('session.php');
        showInviteForm('login');
        statusText('Entre ou crie uma conta com o e-mail que recebeu o convite.');
        return;
    }
    showInviteForm(null);
    byId('invite-switch-account').hidden = false;
    const offer = await inviteRequest('convite_email.php', { token: inviteToken, acao: 'consultar' });
    byId('invite-offer-gardens').replaceChildren(...offer.hortas.map(garden => {
        const item = document.createElement('li');
        item.textContent = garden.nome;
        return item;
    }));
    byId('invite-offer').hidden = false;
    statusText(`Conectado como ${user.email}.`);
}

async function inviteOperation(operation) {
    if (inviteBusy) return;
    inviteBusy = true;
    const buttons = Array.from(document.querySelectorAll('button'));
    buttons.forEach(button => { button.disabled = true; });
    statusText('Aguarde…');
    try { await operation(); }
    catch (error) { statusText(error.message); }
    finally {
        inviteBusy = false;
        buttons.forEach(button => { button.disabled = false; });
    }
}

byId('show-login').onclick = () => showInviteForm('login');
byId('show-register').onclick = () => {
    byId('invite-register').elements.email.value = byId('invite-login').elements.email.value;
    showInviteForm('register');
};
for (const [mode, route] of [['login', 'login'], ['register', 'cadastro']]) {
    const form = byId('invite-' + mode);
    form.onsubmit = event => {
        event.preventDefault();
        const data = Object.fromEntries(new FormData(form));
        form.elements.senha.value = '';
        inviteOperation(async () => {
            if (mode === 'register') data.perfil_entrada = 'agricultor';
            await inviteRequest(`auth/${route}.php`, data);
            if (mode === 'login') {
                await lookupOffer();
            } else {
                form.reset();
                byId('invite-login').elements.email.value = data.email;
                showInviteForm('login');
                statusText('Agora entre com o e-mail e a senha informados para conferir o convite.');
            }
        });
    };
}

byId('invite-switch-account').onclick = () => inviteOperation(async () => {
    await inviteRequest('auth/logout.php', {});
    byId('invite-switch-account').hidden = true;
    byId('invite-offer').hidden = true;
    byId('invite-login').reset();
    await lookupOffer();
});
for (const [button, action] of [['invite-accept', 'aceitar'], ['invite-decline', 'recusar']]) {
    byId(button).onclick = () => inviteOperation(async () => {
        await inviteRequest('convite_email.php', { token: inviteToken, acao: action });
        inviteToken = '';
        byId('invite-offer').hidden = true;
        byId('invite-switch-account').hidden = true;
        byId('invite-done').hidden = false;
        statusText(action === 'aceitar' ? 'Convite aceito. As hortas já estão disponíveis na sua conta.' : 'Convite recusado.');
    });
}

if (/^[0-9a-f]{64}$/.test(inviteToken)) {
    inviteOperation(lookupOffer);
} else {
    inviteToken = '';
    statusText('Abra o link completo na mensagem de convite.');
}
