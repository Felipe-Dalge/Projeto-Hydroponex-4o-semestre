        // --- GERENCIAMENTO DE TEMA (MODO ESCURO / CLARO) ---
        let currentTheme = localStorage.getItem('hydro_theme') || 'light';

        function initTheme() {
            setTheme(currentTheme);
        }

        function toggleTheme() {
            const newTheme = currentTheme === 'dark' ? 'light' : 'dark';
            setTheme(newTheme);
        }

        function setTheme(theme) {
            currentTheme = theme;
            localStorage.setItem('hydro_theme', theme);

            const html = document.documentElement;

            // Desliga as transições da página inteira por um instante: evita que o
            // navegador anime centenas de elementos com dark: ao mesmo tempo.
            html.classList.add('theme-switching');

            // Aplica a classe 'dark' já com as transições desligadas -> troca instantânea.
            html.classList.toggle('dark', theme === 'dark');

            // Força o navegador a processar o novo estilo agora (sem isso, o
            // "remove" logo abaixo seria agrupado junto com a troca e as
            // transições nunca chegariam a ser desligadas de fato).
            void html.offsetHeight;

            // Religa as transições logo depois que o novo tema já foi pintado na
            // tela, para que hovers e outras interações continuem suaves normalmente.
            requestAnimationFrame(() => {
                requestAnimationFrame(() => html.classList.remove('theme-switching'));
            });

            const btnLight = document.getElementById('theme-btn-light');
            const btnDark = document.getElementById('theme-btn-dark');
            if (btnLight && btnDark) {
                const activeCls = "px-3 py-1.5 rounded-lg text-xs font-bold transition-all flex items-center gap-1.5 bg-hydro-500 text-white shadow-sm";
                const inactiveCls = "px-3 py-1.5 rounded-lg text-xs font-bold transition-all flex items-center gap-1.5 text-slate-500 dark:text-slate-400 hover:text-slate-800 dark:hover:text-white";
                btnLight.className = theme === 'dark' ? inactiveCls : activeCls;
                btnDark.className = theme === 'dark' ? activeCls : inactiveCls;
            }

            // Atualiza as cores dos gráficos já existentes (sem destruir/recriar).
            requestAnimationFrame(updateChartsTheme);
        }

        // Atualiza apenas as cores dos gráficos Chart.js já instanciados, in-place.
        // Muito mais rápido do que destruir e recriar os gráficos a cada troca de tema.
        function updateChartsTheme() {
            const textColor = getChartTextColor();
            const gridColor = getChartGridColor();

            [chartConsumoDash, chartStatusDash, chartConsumoComp].forEach(chart => {
                if (!chart) return;
                if (chart.options.scales) {
                    if (chart.options.scales.x) {
                        chart.options.scales.x.ticks.color = textColor;
                        chart.options.scales.x.grid.color = gridColor;
                    }
                    if (chart.options.scales.y) {
                        chart.options.scales.y.ticks.color = textColor;
                        chart.options.scales.y.grid.color = gridColor;
                    }
                }
                if (chart.options.plugins && chart.options.plugins.legend) {
                    chart.options.plugins.legend.labels.color = textColor;
                }
            });

            // O gráfico comparativo de consumo tem uma cor de barra que também
            // depende do tema.
            if (chartConsumoComp) {
                chartConsumoComp.data.datasets[0].backgroundColor = currentTheme === 'dark' ? '#475569' : '#cbd5e1';
            }

            // 'none' desativa a animação de atualização, tornando a troca instantânea.
            [chartConsumoDash, chartStatusDash, chartConsumoComp].forEach(chart => {
                if (chart) chart.update('none');
            });
        }

        // Identidade e CSRF existem somente em memória; sessão autenticada fica no servidor.
        localStorage.removeItem('hydro_user'); // Remove o perfil demonstrativo de versões anteriores.
        let currentUser = null;
        let plantas = [];
        let csrfToken = '';
        let editingPlantaId = null;
        let authReady = false;
        const defaultAvatar = 'img/ImagemLogoSite.png';

        async function refreshCsrf() {
            const response = await fetch('php/api/session.php', { credentials: 'same-origin', cache: 'no-store' });
            const data = await response.json();
            if (!response.ok || !data.csrfToken) throw new Error('Não foi possível iniciar a sessão de segurança.');
            csrfToken = data.csrfToken;
        }

        async function backendRequest(path, method = 'GET', body = null) {
            if (method !== 'GET' && !csrfToken) await refreshCsrf();
            const options = { method, credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' } };
            if (method !== 'GET') {
                options.headers['Content-Type'] = 'application/json';
                options.headers['X-CSRF-Token'] = csrfToken;
                options.body = JSON.stringify(body ?? {});
            }
            const response = await fetch(path, options);
            let data;
            try { data = await response.json(); }
            catch (_) { throw new Error('O servidor retornou uma resposta inválida.'); }
            if (!response.ok || !data.success) {
                const error = new Error(data.message || 'Não foi possível concluir a operação.');
                error.status = response.status;
                if (response.status === 403 && data.message === 'Token de segurança inválido ou ausente.') {
                    csrfToken = '';
                    error.message = 'A sessão de segurança expirou. Envie o formulário novamente.';
                }
                if (response.status === 401 && currentUser && !path.endsWith('/auth/login.php')) {
                    clearAuthentication();
                    navigateTo('login');
                }
                throw error;
            }
            if (data.csrfToken) csrfToken = data.csrfToken;
            return data;
        }

        async function initializeApi() {
            await refreshCsrf();
            try {
                const response = await backendRequest('php/api/auth/me.php');
                currentUser = response.data;
                updateUserUI();
                await initializeGardens();
            } catch (error) {
                if (error.status !== 401) throw error;
            }
        }

        async function apiRequest(method, body = null) {
            if (!selectedHortaId) throw new Error('Selecione uma horta primeiro.');
            return backendRequest(`php/api/plantas.php?horta=${selectedHortaId}`, method, body);
        }

        async function loadPlantas() {
            const garden = selectedHortaId;
            const version = gardensContextVersion;
            const request = ++plantLoadVersion;
            if (!garden) { plantas = []; renderTablePlantas(); return; }
            setFeedback('plantas-feedback', 'Carregando plantas…');
            try {
                const data = await apiRequest('GET');
                if (garden !== selectedHortaId || version !== gardensContextVersion || request !== plantLoadVersion || !currentUser) return;
                plantas = Array.isArray(data.data) ? data.data : [];
                plantSummary = data.resumo;
                plantConsumption = data.indicadores.consumo_hoje_l;
                if (selectedHorta) selectedHorta.total_plantas = data.resumo.total;
                const summary = hortas.find(h => Number(h.id_horta) === garden);
                if (summary) summary.total_plantas = data.resumo.total;
                renderHortas();
                renderGardenContext(); renderMonitoramentoCards();
                await applyPlantFilters();
                setFeedback('plantas-feedback', plantas.length ? `${plantas.length} planta(s) nesta horta.` : 'Nenhuma planta cadastrada nesta horta.');
                if (window.location.hash === '#dashboard') renderDashboard();
            } catch (error) {
                if (garden === selectedHortaId && version === gardensContextVersion) {
                    plantas = []; renderTablePlantas(); setFeedback('plantas-feedback', error.message);
                }
                throw error;
            }
        }

        function showApiError(message) {
            const tbody = document.getElementById('table-plantas-body');
            if (tbody) tbody.innerHTML = `<tr><td colspan="6" class="p-6 text-center text-rose-600">${escapeHtml(message)}</td></tr>`;
        }

        function escapeHtml(value) {
            return String(value ?? '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        let currentFilterStatus = 'todos';
        let simulatorInterval = null;
        let chartConsumoDash = null;
        let chartStatusDash = null;
        let chartConsumoComp = null;

        // --- INICIALIZAÇÃO DA APLICAÇÃO ---
        document.addEventListener('DOMContentLoaded', async () => {
            initTheme();
            lucide.createIcons();
            updateUserUI();
            try { await initializeApi(); }
            catch (_) { setFeedback('login-feedback', 'Não foi possível conectar ao servidor. Confira a configuração local.'); }
            authReady = true;
            const hash = window.location.hash.replace('#', '') || 'landing';
            navigateTo(hash);
            if (currentUser) startSensorSimulator();
            window.addEventListener('hashchange', () => {
                if (authReady) navigateTo(window.location.hash.replace('#', '') || 'landing');
            });
            document.getElementById('mobile-menu-btn').addEventListener('click', () => {
                document.getElementById('mobile-sidebar').classList.remove('hidden');
            });
            document.getElementById('close-mobile-menu').addEventListener('click', closeMobileMenu);
        });

        function closeMobileMenu() {
            document.getElementById('mobile-sidebar').classList.add('hidden');
        }

        // --- ROTEAMENTO (SPA) ---
        function navigateTo(pageId) {
            const publicPages = ['landing', 'login', 'cadastro', 'recuperar-senha'];
            if (!currentUser && !publicPages.includes(pageId)) pageId = 'login';
            if (window.location.hash !== '#' + pageId) window.location.hash = pageId;

            document.querySelectorAll('.view-page').forEach(el => el.classList.add('hidden'));

            const targetView = document.getElementById(`view-${pageId}`);
            if (targetView) {
                targetView.classList.remove('hidden');
            } else {
                document.getElementById('view-landing').classList.remove('hidden');
                pageId = 'landing';
            }

            const isPublicPage = ['landing', 'login', 'cadastro', 'recuperar-senha'].includes(pageId);
            document.getElementById('horta-context').classList.toggle('hidden', isPublicPage || ['perfil','configuracoes'].includes(pageId));
            const appSidebar = document.getElementById('app-sidebar');
            if (isPublicPage) {
                document.getElementById('app-header').classList.add('hidden');
                appSidebar.classList.add('hidden');
                // O <aside> usa as classes "hidden md:flex": em telas >=768px a regra
                // "md:flex" tem prioridade sobre ".hidden" no CSS gerado pelo Tailwind,
                // então só a classe "hidden" não é suficiente para escondê-lo no
                // desktop (era exatamente por isso que o menu continuava aparecendo
                // na tela de login). O estilo inline abaixo tem prioridade sobre
                // qualquer classe e garante que ele fique realmente oculto.
                appSidebar.style.display = 'none';
            } else {
                document.getElementById('app-header').classList.remove('hidden');
                appSidebar.classList.remove('hidden');
                // Remove o override inline para que o Tailwind volte a controlar a
                // exibição do menu normalmente (flex no desktop, oculto no mobile).
                appSidebar.style.display = '';
                updateActiveNavLinks(pageId);
            }

            if (pageId === 'infraestrutura') {
                loadInfrastructure();
            } else if (pageId === 'hortas') {
                renderHortas();
            } else if (pageId === 'dashboard') {
                renderDashboard();
            } else if (pageId === 'plantas') {
                renderTablePlantas();
            } else if (pageId === 'monitoramento') {
                loadMonitoring();
            } else if (pageId === 'consumo') {
                renderConsumoPage();
            } else if (pageId === 'perfil') {
                loadProfileForm();
            }
        }

        function updateActiveNavLinks(pageId) {
            document.querySelectorAll('.nav-link').forEach(btn => {
                if (btn.dataset.page === pageId) {
                    btn.classList.add('bg-hydro-50', 'dark:bg-hydro-950/60', 'text-hydro-700', 'dark:text-hydro-400', 'font-bold');
                    btn.classList.remove('text-slate-600', 'dark:text-slate-300');
                } else {
                    btn.classList.remove('bg-hydro-50', 'dark:bg-hydro-950/60', 'text-hydro-700', 'dark:text-hydro-400', 'font-bold');
                    btn.classList.add('text-slate-600', 'dark:text-slate-300');
                }
            });
        }

        // --- PERFIL E AUTENTICAÇÃO ---
        function setFeedback(id, message) {
            const element = document.getElementById(id);
            if (element) element.textContent = message;
        }

        function updateUserUI() {
            document.getElementById('header-user-name').textContent = currentUser?.nome || 'Visitante';
            document.getElementById('dash-user-name').textContent = currentUser?.nome || 'usuário';
            document.getElementById('header-avatar').src = currentUser?.avatar_url || defaultAvatar;
        }

        function clearAuthentication() {
            currentUser = null; plantas = []; csrfToken = '';
            clearGardens();
            clearInterval(simulatorInterval);
            [chartConsumoDash, chartStatusDash, chartConsumoComp].forEach(chart => chart?.destroy());
            chartConsumoDash = chartStatusDash = chartConsumoComp = null;
            document.getElementById('table-plantas-body').replaceChildren();
            document.getElementById('grid-monitoramento').replaceChildren();
            document.querySelectorAll('#view-perfil input').forEach(input => { input.value = ''; });
            document.getElementById('profile-avatar-preview').src = defaultAvatar;
            closeModalNovaPlanta(); closeMobileMenu(); updateUserUI();
        }

        async function authForm(event, feedback, action) {
            event.preventDefault();
            const button = event.target.querySelector('button[type="submit"]');
            if (button.disabled) return;
            button.disabled = true;
            setFeedback(feedback, '');
            try { await action(); }
            catch (error) { setFeedback(feedback, error.message || 'Não foi possível concluir a operação.'); }
            finally { button.disabled = false; }
        }

        async function handleLogin(event) {
            await authForm(event, 'login-feedback', async () => {
                const password = document.getElementById('login-password');
                let response;
                try {
                    response = await backendRequest('php/api/auth/login.php', 'POST', {
                        email: document.getElementById('login-email').value,
                        senha: password.value
                    });
                } finally { password.value = ''; }
                currentUser = response.data;
                updateUserUI();
                navigateTo('dashboard');
                try { await initializeGardens(); }
                catch (error) { showApiError(error.message); }
                if (!currentUser?.hortas.length) navigateTo('hortas');
                if (document.getElementById('toggle-simulador').checked) startSensorSimulator();
            });
        }

        async function handleRegister(event) {
            await authForm(event, 'register-feedback', async () => {
                const password = document.getElementById('register-password');
                let response;
                try {
                    response = await backendRequest('php/api/auth/cadastro.php', 'POST', {
                        nome: document.getElementById('register-name').value,
                        email: document.getElementById('register-email').value,
                        senha: password.value
                    });
                } finally { password.value = ''; }
                document.getElementById('login-email').value = document.getElementById('register-email').value;
                setFeedback('login-feedback', response.message);
                navigateTo('login');
            });
        }

        function handleRecovery(event) {
            event.preventDefault();
            setFeedback('recovery-feedback', 'A recuperação por e-mail ainda não está disponível neste ambiente.');
        }

        async function logout() {
            try { await backendRequest('php/api/auth/logout.php', 'POST'); }
            catch (error) {
                if (error.status !== 401) { alert(error.message); return; }
            }
            clearAuthentication();
            navigateTo('login');
            try { await refreshCsrf(); } catch (_) { /* A próxima tentativa mostrará o erro. */ }
        }

        function loadProfileForm() {
            if (!currentUser) return;
            document.getElementById('profile-name').value = currentUser.nome;
            document.getElementById('profile-email').value = currentUser.email;
            document.getElementById('profile-avatar-url').value = currentUser.avatar_url || '';
            document.getElementById('profile-avatar-preview').src = currentUser.avatar_url || defaultAvatar;
            document.querySelectorAll('#view-perfil input[type="password"]').forEach(input => { input.value = ''; });
        }

        function updateProfileAvatarPreview(value) {
            try {
                const url = new URL(value);
                if (url.protocol === 'https:' && !url.username && !url.password) document.getElementById('profile-avatar-preview').src = url.href;
            } catch (_) {
                if (!value) document.getElementById('profile-avatar-preview').src = defaultAvatar;
            }
        }

        async function handleSaveProfile(event) {
            await authForm(event, 'profile-feedback', async () => {
                const payload = {
                    nome: document.getElementById('profile-name').value,
                    email: document.getElementById('profile-email').value,
                    avatar_url: document.getElementById('profile-avatar-url').value
                };
                const currentPassword = document.getElementById('profile-current-password');
                const newPassword = document.getElementById('profile-new-password');
                const confirmation = document.getElementById('profile-confirm-password');
                if (currentPassword.value) payload.senha_atual = currentPassword.value;
                if (newPassword.value || confirmation.value) {
                    payload.nova_senha = newPassword.value;
                    payload.confirmacao_senha = confirmation.value;
                }
                try {
                    const response = await backendRequest('php/api/perfil.php', 'PATCH', payload);
                    currentUser = response.data;
                    updateUserUI();
                    setFeedback('profile-feedback', response.message);
                } finally {
                    currentPassword.value = newPassword.value = confirmation.value = '';
                }
            });
        }

        async function handleDeleteAccount(event) {
            await authForm(event, 'delete-feedback', async () => {
                const password = document.getElementById('delete-password');
                try {
                    await backendRequest('php/api/perfil.php', 'DELETE', {
                        senha_atual: password.value,
                        confirmacao: document.getElementById('delete-confirmation').value
                    });
                } finally { password.value = ''; }
                clearAuthentication();
                navigateTo('login');
                setFeedback('login-feedback', 'Sua conta foi excluída.');
                try { await refreshCsrf(); } catch (_) { /* Refeito antes de enviar outro formulário. */ }
            });
        }

        // --- DADOS & MONITORAMENTO ---
        function renderDashboard() { renderMonitoringDashboard(); }
        function renderMonitoramentoCards() { /* Sensores/histórico são carregados por loadMonitoring(). */ }

        // --- TABELA E CRUD DE PLANTAS ---
        function renderTablePlantas() {
            const tbody = document.getElementById('table-plantas-body');
            if (!tbody) return;
            tbody.innerHTML = '';

            if (listedPlants.length === 0) {
                const filtered = document.getElementById('plant-filter-name').value || document.getElementById('plant-filter-status').value;
                tbody.innerHTML = `<tr><td colspan="6" class="p-6 text-center text-slate-500">${selectedHortaId ? (filtered ? 'Nenhuma planta corresponde aos filtros.' : 'Nenhuma planta cadastrada nesta horta.') : 'Selecione ou cadastre uma horta para visualizar suas plantas.'}</td></tr>`;
                return;
            }

            listedPlants.forEach(p => {
                tbody.innerHTML += `
                    <tr class="hover:bg-slate-50/80 dark:hover:bg-slate-700/30 transition-all">
                        <td class="p-4 pl-6 font-bold text-slate-800 dark:text-white">${escapeHtml(p.nome)}</td>
                        <td class="p-4 text-slate-600 dark:text-slate-300">${escapeHtml(p.bancada)}</td>
                        <td class="p-4 text-slate-600 dark:text-slate-300">${escapeHtml(p.temp)} °C</td>
                        <td class="p-4 text-slate-600 dark:text-slate-300">${escapeHtml(p.nutrientes)} ppm</td>
                        <td class="p-4">
                            <span class="px-2.5 py-1 rounded-full text-xs font-bold ${p.status === 'Normal' ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300' : p.status === 'Atenção' ? 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300' : 'bg-rose-100 text-rose-800 dark:bg-rose-950 dark:text-rose-300'}">
                                ${escapeHtml(p.status)}
                            </span>
                        </td>
                        <td class="p-4 text-right pr-6">
                            <button onclick="showPlantDetails(${Number(p.id)})" aria-label="Detalhes da planta" class="p-2 text-hydro-600"><i data-lucide="info" class="w-4 h-4"></i></button>
                            <button ${selectedHorta?.pode_editar ? '' : 'hidden'} onclick="openPlantTransfer(${Number(p.id)})" aria-label="Transferir planta" class="p-2 text-hydro-600"><i data-lucide="arrow-right-left" class="w-4 h-4"></i></button>
                            <button ${selectedHorta?.pode_editar ? '' : 'hidden'} onclick="openEditPlanta(${Number(p.id)})" aria-label="Editar planta" title="Editar" class="p-2 text-hydro-600 hover:bg-hydro-50 dark:hover:bg-hydro-950/40 rounded-lg transition-all"><i data-lucide="pencil" class="w-4 h-4"></i></button>
                            <button ${selectedHorta?.pode_editar ? '' : 'hidden'} onclick="removePlanta(${Number(p.id)})" aria-label="Excluir planta" title="Excluir" class="p-2 text-rose-500 hover:bg-rose-50 dark:hover:bg-rose-950/40 rounded-lg transition-all"><i data-lucide="trash-2" class="w-4 h-4"></i></button>
                        </td>
                    </tr>
                `;
            });
            lucide.createIcons();
        }

        function openModalNovaPlanta() { openPlantForm(); }
        async function openEditPlanta(id) { await openPlantForm(id); }
        function closeModalNovaPlanta() {
            document.getElementById('modal-planta').classList.add('hidden');
            editingPlantaId = null;
        }
        async function handleAddPlanta(event) { await savePlantForm(event); }

        async function removePlanta(id) {
            const planta = plantas.find(p => Number(p.id) === Number(id));
            if (!planta) {
                alert('Planta não encontrada.');
                return;
            }
            if (!confirm(`Excluir "${planta.nome}" da listagem desta horta? O histórico será preservado.`)) return;

            try {
                await apiRequest('DELETE', { id: Number(id), confirmacao: 'EXCLUIR' });
                await loadPlantas();
            } catch (error) {
                alert(error.message);
            }
        }

        // --- CONSULTA PERIÓDICA, SEM GERAR MEDIÇÕES ---
        function startSensorSimulator() {
            clearInterval(simulatorInterval);
            if (!currentUser || !document.getElementById('toggle-simulador').checked) return;
            simulatorInterval = setInterval(() => {
                if (selectedHortaId) { renderMonitoringDashboard(); if(window.location.hash==='#monitoramento') loadMonitoring(); }
            }, 15000);
        }

        function toggleSimulador(enabled) {
            if (enabled) {
                startSensorSimulator();
            } else {
                clearInterval(simulatorInterval);
            }
        }

        // --- RENDERIZAÇÃO ADAPTATIVA DE GRÁFICOS (CHART.JS) PARA MODO ESCURO ---
        function getChartTextColor() {
            return currentTheme === 'dark' ? '#94a3b8' : '#64748b';
        }

        function getChartGridColor() {
            return currentTheme === 'dark' ? 'rgba(51, 65, 85, 0.4)' : 'rgba(226, 232, 240, 0.8)';
        }

        function renderConsumoPage() { renderMonitoringDashboard(); }
