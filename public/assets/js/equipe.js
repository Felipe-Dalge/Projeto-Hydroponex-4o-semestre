const teamStates = { pendente: 'Pendente', aceito: 'Aceito', recusado: 'Recusado', cancelado: 'Cancelado', expirado: 'Expirado' };
const teamDelivery = { enviando: 'Enviando', enviado: 'Aceito pelo SMTP', falhou: 'Falha no envio', inconclusivo: 'Envio sem confirmação' };
let teamLoadVersion = 0;

function clearTeam() {
    teamLoadVersion++;
    const form = document.getElementById('team-invite-form');
    form.reset();
    form.classList.add('hidden');
    for (const id of ['team-gardens', 'team-members', 'team-invites']) document.getElementById(id).replaceChildren();
    for (const id of ['team-feedback', 'team-load-feedback']) setFeedback(id, '');
}

function teamPageCurrent(userId) {
    return currentUser?.id === userId && administrativeWorkspace() && location.hash === '#equipe';
}

async function loadTeam() {
    const userId = currentUser?.id;
    const version = ++teamLoadVersion;
    const form = document.getElementById('team-invite-form');
    form.classList.add('hidden');
    setFeedback('team-load-feedback', 'Carregando equipe…');
    if (!currentUser?.hortas.some(garden => garden.papel === 'proprietario')) {
        for (const id of ['team-gardens', 'team-members', 'team-invites']) document.getElementById(id).replaceChildren();
        setFeedback('team-load-feedback', 'A Equipe fica disponível quando você é proprietário de pelo menos uma horta.');
        return;
    }
    try {
        const { data } = await backendRequest('php/api/equipe.php');
        if (!teamPageCurrent(userId) || version !== teamLoadVersion) return;
        const selected = new Set(new FormData(form).getAll('hortas'));
        document.getElementById('team-gardens').replaceChildren(...data.hortas.map(garden => {
            const label = document.createElement('label');
            label.className = 'flex items-center gap-2 rounded-lg border border-slate-200 dark:border-slate-700 p-2';
            const input = document.createElement('input');
            input.type = 'checkbox';
            input.name = 'hortas';
            input.value = garden.id_horta;
            input.checked = selected.has(input.value);
            label.append(input, document.createTextNode(garden.nome));
            return label;
        }));
        form.classList.remove('hidden');
        setFeedback('team-load-feedback', '');
        renderCollaborationList('team-members', data.agricultores, 'Nenhum agricultor vinculado.', (row, member) => {
            const title = document.createElement('strong');
            title.className = 'w-full';
            title.textContent = `${member.nome} · ${member.email}`;
            row.append(title);
            for (const garden of member.hortas) {
                const line = document.createElement('div');
                line.className = 'w-full flex flex-wrap justify-between gap-2';
                line.textContent = `${garden.nome} · Ativo`;
                const button = collaborationButton('Remover vínculo', async () => {
                    if (!confirm(`Remover o acesso de ${member.nome} à horta ${garden.nome}?`)) return;
                    await teamMutation(button, collaborationUrl('membros', garden.id_horta), 'DELETE',
                        { id_usuario: member.id_usuario }, 'Vínculo removido.');
                });
                line.append(button);
                row.append(line);
            }
        });
        renderCollaborationList('team-invites', data.convites, 'Nenhum convite enviado pela sua conta.', (row, invite) => {
            const label = document.createElement('span');
            label.className = 'w-full';
            const gardens = invite.hortas.map(garden => garden.nome).join(', ');
            label.textContent = `${invite.email} · ${gardens} · ${teamStates[invite.estado]} · ${teamDelivery[invite.entrega]}`;
            row.append(label);
            if (!invite.disponivel) {
                row.append(document.createTextNode('Uma ou mais hortas ficaram indisponíveis.'));
                return;
            }
            const resend = ['pendente', 'expirado'].includes(invite.estado);
            if (resend) {
                const button = collaborationButton('Reenviar', () => teamMutation(button, 'php/api/equipe.php', 'PATCH',
                    { id_convite: invite.id_convite }, 'Novo link aceito pelo SMTP. O anterior foi invalidado.'));
                row.append(button);
            }
            if (invite.estado === 'pendente') {
                const button = collaborationButton('Cancelar', () => teamMutation(button, 'php/api/equipe.php', 'DELETE',
                    { id_convite: invite.id_convite }, 'Convite cancelado.'));
                row.append(button);
            }
        });
    } catch (error) {
        if (!teamPageCurrent(userId) || version !== teamLoadVersion) return;
        document.getElementById('team-members').replaceChildren();
        document.getElementById('team-invites').replaceChildren();
        setFeedback('team-load-feedback', error.message);
    }
}

async function teamMutation(button, url, method, data, message, form = null) {
    if (button.disabled) return;
    const userId = currentUser?.id;
    button.disabled = true;
    setFeedback('team-feedback', method === 'DELETE' ? 'Atualizando…' : 'Enviando convite…');
    try {
        await backendRequest(url, method, data);
        if (!teamPageCurrent(userId)) return;
        form?.reset();
        setFeedback('team-feedback', message);
    } catch (error) {
        if (teamPageCurrent(userId)) setFeedback('team-feedback', error.message);
    } finally {
        button.disabled = false;
        if (teamPageCurrent(userId)) await loadTeam();
    }
}

async function sendTeamInvite(event) {
    event.preventDefault();
    const form = event.currentTarget;
    const fields = new FormData(form);
    const gardens = fields.getAll('hortas');
    if (!gardens.length) {
        setFeedback('team-feedback', 'Selecione pelo menos uma horta.');
        return;
    }
    await teamMutation(form.querySelector('button[type=submit]'), 'php/api/equipe.php', 'POST',
        { email: fields.get('email'), hortas: gardens }, 'Convite aceito pelo SMTP.', form);
}
