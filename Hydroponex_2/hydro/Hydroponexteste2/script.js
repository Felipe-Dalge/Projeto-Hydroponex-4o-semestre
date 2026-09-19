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

        // --- PERSISTÊNCIA VIA API PHP (sem banco de dados) ---
        let currentUser = JSON.parse(localStorage.getItem('hydro_user')) || {
            name: 'Ana Silva',
            email: 'ana.silva@hydroponex.io',
            avatar: 'https://images.unsplash.com/photo-1534528741775-53994a69daeb?auto=format&fit=crop&q=80&w=256'
        };

        // Os dados das plantas são carregados do PHP; localStorage não é mais usado para o CRUD.
        let plantas = [];
        let csrfToken = '';
        let editingPlantaId = null;

        async function initializeApi() {
            try {
                const sessionResponse = await fetch('php/api/session.php', {
                    method: 'GET',
                    credentials: 'same-origin',
                    cache: 'no-store'
                });
                const sessionData = await sessionResponse.json();
                if (!sessionResponse.ok || !sessionData.success || !sessionData.csrfToken) {
                    throw new Error(sessionData.message || 'Não foi possível iniciar a sessão de segurança.');
                }
                csrfToken = sessionData.csrfToken;
                await loadPlantas();
            } catch (error) {
                console.error(error);
                showApiError('Não foi possível carregar os dados do sistema. Verifique se o projeto está sendo executado por um servidor PHP.');
            }
        }

        async function apiRequest(method, body = null) {
            const options = {
                method,
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json' },
                cache: 'no-store'
            };
            if (body !== null) {
                options.headers['Content-Type'] = 'application/json';
                options.headers['X-CSRF-Token'] = csrfToken;
                options.body = JSON.stringify(body);
            }

            const response = await fetch('php/api/plantas.php', options);
            let data = null;
            try { data = await response.json(); } catch (_) {
                throw new Error('O servidor retornou uma resposta inválida.');
            }
            if (!response.ok || !data.success) {
                const validation = data.errors ? Object.values(data.errors).join(' ') : '';
                throw new Error(data.message + (validation ? ` ${validation}` : ''));
            }
            return data;
        }

        async function loadPlantas() {
            const data = await apiRequest('GET');
            plantas = Array.isArray(data.data) ? data.data : [];
            const activeHash = window.location.hash.replace('#', '');
            if (activeHash === 'dashboard') renderDashboard();
            if (activeHash === 'plantas') renderTablePlantas();
            if (activeHash === 'monitoramento') renderMonitoramentoCards();
            if (activeHash === 'consumo') renderConsumoPage();
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
            await initializeApi();
            
            const hash = window.location.hash.replace('#', '') || 'landing';
            navigateTo(hash);

            startSensorSimulator();

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
            window.location.hash = pageId;

            document.querySelectorAll('.view-page').forEach(el => el.classList.add('hidden'));

            const targetView = document.getElementById(`view-${pageId}`);
            if (targetView) {
                targetView.classList.remove('hidden');
            } else {
                document.getElementById('view-landing').classList.remove('hidden');
                pageId = 'landing';
            }

            const isPublicPage = ['landing', 'login', 'cadastro', 'recuperar-senha'].includes(pageId);
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

            if (pageId === 'dashboard') {
                renderDashboard();
            } else if (pageId === 'plantas') {
                renderTablePlantas();
            } else if (pageId === 'monitoramento') {
                renderMonitoramentoCards();
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
        function updateUserUI() {
            document.getElementById('header-user-name').innerText = currentUser.name;
            document.getElementById('dash-user-name').innerText = currentUser.name;
            document.getElementById('header-avatar').src = currentUser.avatar;
        }

        function handleLogin(e) {
            e.preventDefault();
            navigateTo('dashboard');
        }

        function handleRegister(e) {
            e.preventDefault();
            currentUser.name = document.getElementById('register-name').value || currentUser.name;
            currentUser.email = document.getElementById('register-email').value || currentUser.email;
            localStorage.setItem('hydro_user', JSON.stringify(currentUser));
            updateUserUI();
            navigateTo('dashboard');
        }

        function handleRecovery(e) {
            e.preventDefault();
            alert("E-mail de recuperação enviado com sucesso!");
            navigateTo('login');
        }

        function logout() {
            navigateTo('landing');
        }

        function loadProfileForm() {
            document.getElementById('profile-name').value = currentUser.name;
            document.getElementById('profile-email').value = currentUser.email;
            document.getElementById('profile-avatar-url').value = currentUser.avatar;
            document.getElementById('profile-avatar-preview').src = currentUser.avatar;
        }

        function updateProfileAvatarPreview(url) {
            if(url) document.getElementById('profile-avatar-preview').src = url;
        }

        function handleSaveProfile(e) {
            e.preventDefault();
            currentUser.name = document.getElementById('profile-name').value;
            currentUser.email = document.getElementById('profile-email').value;
            currentUser.avatar = document.getElementById('profile-avatar-url').value || currentUser.avatar;
            localStorage.setItem('hydro_user', JSON.stringify(currentUser));
            updateUserUI();
            alert("Perfil atualizado com sucesso!");
        }

        // --- DADOS & MONITORAMENTO ---
        function renderDashboard() {
            const total = plantas.length;
            const normal = plantas.filter(p => p.status === 'Normal').length;
            const atencao = plantas.filter(p => p.status === 'Atenção').length;
            const alerta = plantas.filter(p => p.status === 'Alerta').length;

            document.getElementById('stat-total').innerText = total;
            document.getElementById('stat-normal').innerText = normal;
            document.getElementById('stat-atencao').innerText = atencao;
            document.getElementById('stat-alerta').innerText = alerta;

            const estadoGeralEl = document.getElementById('stat-estado-geral');
            if (alerta > 0) {
                estadoGeralEl.innerText = "Atenção Requerida";
                estadoGeralEl.className = "inline-block px-3 py-1 rounded-full text-xs font-bold bg-rose-100 text-rose-800 dark:bg-rose-950 dark:text-rose-300";
            } else if (atencao > 0) {
                estadoGeralEl.innerText = "Acompanhamento";
                estadoGeralEl.className = "inline-block px-3 py-1 rounded-full text-xs font-bold bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300";
            } else {
                estadoGeralEl.innerText = "Ótimo";
                estadoGeralEl.className = "inline-block px-3 py-1 rounded-full text-xs font-bold bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300";
            }

            initDashboardCharts(normal, atencao, alerta);
        }

        function renderMonitoramentoCards() {
            const container = document.getElementById('grid-monitoramento');
            container.innerHTML = '';

            const list = currentFilterStatus === 'todos' 
                ? plantas 
                : plantas.filter(p => p.status === currentFilterStatus);

            list.forEach(planta => {
                let statusBadge = '';
                let borderClass = '';

                if (planta.status === 'Normal') {
                    statusBadge = `<span class="px-3 py-1 rounded-full text-xs font-bold bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300 flex items-center gap-1.5"><span class="w-2 h-2 rounded-full bg-emerald-500"></span> 🟢 Verde — Normal</span>`;
                    borderClass = 'border-l-4 border-l-emerald-500';
                } else if (planta.status === 'Atenção') {
                    statusBadge = `<span class="px-3 py-1 rounded-full text-xs font-bold bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300 flex items-center gap-1.5"><span class="w-2 h-2 rounded-full bg-amber-500"></span> 🟡 Amarelo — Atenção</span>`;
                    borderClass = 'border-l-4 border-l-amber-500';
                } else {
                    statusBadge = `<span class="px-3 py-1 rounded-full text-xs font-bold bg-rose-100 text-rose-800 dark:bg-rose-950 dark:text-rose-300 flex items-center gap-1.5"><span class="w-2 h-2 rounded-full bg-rose-500"></span> 🔴 Vermelho — Alerta</span>`;
                    borderClass = 'border-l-4 border-l-rose-500';
                }

                const cardHtml = `
                    <div class="bg-white dark:bg-darkbg-800 rounded-3xl p-6 border border-slate-200/80 dark:border-slate-700/80 shadow-sm hover:shadow-md transition-all ${borderClass}">
                        <div class="flex justify-between items-start mb-4">
                            <div>
                                <h3 class="font-bold text-slate-800 dark:text-white text-lg">${escapeHtml(planta.nome)}</h3>
                                <p class="text-xs text-slate-400">${escapeHtml(planta.bancada)}</p>
                            </div>
                            ${statusBadge}
                        </div>

                        <div class="grid grid-cols-2 gap-3 mt-4 text-xs">
                            <div class="bg-slate-50 dark:bg-darkbg-900 p-3 rounded-2xl border border-slate-100 dark:border-slate-700/50">
                                <span class="text-slate-400 block mb-0.5">Temperatura</span>
                                <span class="font-bold text-slate-800 dark:text-white text-sm">${planta.temp.toFixed(1)} °C</span>
                            </div>
                            <div class="bg-slate-50 dark:bg-darkbg-900 p-3 rounded-2xl border border-slate-100 dark:border-slate-700/50">
                                <span class="text-slate-400 block mb-0.5">Umidade Ar</span>
                                <span class="font-bold text-slate-800 dark:text-white text-sm">${planta.umidade}%</span>
                            </div>
                            <div class="bg-slate-50 dark:bg-darkbg-900 p-3 rounded-2xl border border-slate-100 dark:border-slate-700/50">
                                <span class="text-slate-400 block mb-0.5">Nutrientes (EC)</span>
                                <span class="font-bold text-slate-800 dark:text-white text-sm">${planta.nutrientes} ppm</span>
                            </div>
                            <div class="bg-slate-50 dark:bg-darkbg-900 p-3 rounded-2xl border border-slate-100 dark:border-slate-700/50">
                                <span class="text-slate-400 block mb-0.5">Consumo Água</span>
                                <span class="font-bold text-slate-800 dark:text-white text-sm">${planta.agua.toFixed(1)} L/dia</span>
                            </div>
                        </div>

                        <div class="mt-4 pt-3 border-t border-slate-100 dark:border-slate-700/80 flex justify-between items-center text-[11px] text-slate-400">
                            <span>Última leitura: ${escapeHtml(planta.lastUpdate)}</span>
                            <i data-lucide="wifi" class="w-3.5 h-3.5 text-hydro-500"></i>
                        </div>
                    </div>
                `;
                container.innerHTML += cardHtml;
            });
            lucide.createIcons();
        }

        function filterMonitor(status) {
            currentFilterStatus = status;
            renderMonitoramentoCards();
        }

        // --- TABELA E CRUD DE PLANTAS ---
        function renderTablePlantas() {
            const tbody = document.getElementById('table-plantas-body');
            if (!tbody) return;
            tbody.innerHTML = '';

            if (plantas.length === 0) {
                tbody.innerHTML = '<tr><td colspan="6" class="p-6 text-center text-slate-500">Nenhuma planta cadastrada.</td></tr>';
                return;
            }

            plantas.forEach(p => {
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
                            <button onclick="openEditPlanta(${Number(p.id)})" aria-label="Editar planta" title="Editar" class="p-2 text-hydro-600 hover:bg-hydro-50 dark:hover:bg-hydro-950/40 rounded-lg transition-all"><i data-lucide="pencil" class="w-4 h-4"></i></button>
                            <button onclick="removePlanta(${Number(p.id)})" aria-label="Excluir planta" title="Excluir" class="p-2 text-rose-500 hover:bg-rose-50 dark:hover:bg-rose-950/40 rounded-lg transition-all"><i data-lucide="trash-2" class="w-4 h-4"></i></button>
                        </td>
                    </tr>
                `;
            });
            lucide.createIcons();
        }

        function openModalNovaPlanta() {
            editingPlantaId = null;
            document.querySelector('#modal-planta h3').textContent = 'Cadastrar Nova Planta';
            document.querySelector('#modal-planta button[type="submit"]').textContent = 'Salvar Planta';
            document.getElementById('new-plant-name').value = '';
            document.getElementById('new-plant-location').value = '';
            document.getElementById('new-plant-temp').value = '22';
            document.getElementById('new-plant-nutri').value = '800';
            document.getElementById('modal-planta').classList.remove('hidden');
        }

        function openEditPlanta(id) {
            const planta = plantas.find(p => Number(p.id) === Number(id));
            if (!planta) {
                alert('Planta não encontrada.');
                return;
            }
            editingPlantaId = Number(id);
            document.querySelector('#modal-planta h3').textContent = 'Editar Planta';
            document.querySelector('#modal-planta button[type="submit"]').textContent = 'Salvar Alterações';
            document.getElementById('new-plant-name').value = planta.nome || '';
            document.getElementById('new-plant-location').value = planta.bancada || '';
            document.getElementById('new-plant-temp').value = planta.temp ?? 22;
            document.getElementById('new-plant-nutri').value = planta.nutrientes ?? 800;
            document.getElementById('modal-planta').classList.remove('hidden');
        }

        function closeModalNovaPlanta() {
            document.getElementById('modal-planta').classList.add('hidden');
            editingPlantaId = null;
        }

        async function handleAddPlanta(e) {
            e.preventDefault();
            const payload = {
                nome: document.getElementById('new-plant-name').value.trim(),
                bancada: document.getElementById('new-plant-location').value.trim(),
                temp: Number(document.getElementById('new-plant-temp').value),
                nutrientes: Number(document.getElementById('new-plant-nutri').value)
            };

            try {
                if (editingPlantaId === null) {
                    await apiRequest('POST', payload);
                } else {
                    await apiRequest('PUT', { id: editingPlantaId, ...payload });
                }
                closeModalNovaPlanta();
                await loadPlantas();
            } catch (error) {
                alert(error.message);
            }
        }

        async function removePlanta(id) {
            const planta = plantas.find(p => Number(p.id) === Number(id));
            if (!planta) {
                alert('Planta não encontrada.');
                return;
            }
            if (!confirm(`Deseja realmente excluir a planta "${planta.nome}"?`)) return;

            try {
                await apiRequest('DELETE', { id: Number(id) });
                await loadPlantas();
            } catch (error) {
                alert(error.message);
            }
        }

        // --- SIMULADOR DE TELEMETRIA IoT ---
        function startSensorSimulator() {
            simulatorInterval = setInterval(() => {
                plantas.forEach(p => {
                    const tempDiff = (Math.random() - 0.5) * 0.8;
                    p.temp = Math.max(15, Math.min(35, p.temp + tempDiff));

                    if (p.temp > 27 || p.temp < 18) {
                        p.status = 'Alerta';
                    } else if (p.temp > 24 || p.temp < 20) {
                        p.status = 'Atenção';
                    } else {
                        p.status = 'Normal';
                    }
                    p.lastUpdate = new Date().toLocaleTimeString();
                });

                const activeHash = window.location.hash.replace('#', '');
                if (activeHash === 'monitoramento') renderMonitoramentoCards();
                if (activeHash === 'dashboard') renderDashboard();
            }, 4000);
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

        function initDashboardCharts(normal, atencao, alerta) {
            const textColor = getChartTextColor();
            const gridColor = getChartGridColor();

            // Chart 1: Consumo x Economia
            const ctx1 = document.getElementById('chart-dash-consumo');
            if (ctx1) {
                if (chartConsumoDash) chartConsumoDash.destroy();
                chartConsumoDash = new Chart(ctx1, {
                    type: 'line',
                    data: {
                        labels: ['Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb', 'Dom'],
                        datasets: [
                            {
                                label: 'Consumo Hidropônico (L)',
                                data: [12, 11, 13, 10, 12, 14, 12],
                                borderColor: '#0ea5e9',
                                backgroundColor: 'rgba(14, 165, 233, 0.15)',
                                fill: true,
                                tension: 0.4
                            },
                            {
                                label: 'Economia Estimada (L)',
                                data: [110, 115, 108, 120, 118, 112, 115],
                                borderColor: '#22c55e',
                                backgroundColor: 'rgba(34, 197, 94, 0.15)',
                                fill: true,
                                tension: 0.4
                            }
                        ]
                    },
                    options: { 
                        responsive: true, 
                        maintainAspectRatio: false,
                        scales: {
                            x: { ticks: { color: textColor }, grid: { color: gridColor } },
                            y: { ticks: { color: textColor }, grid: { color: gridColor } }
                        },
                        plugins: {
                            legend: { labels: { color: textColor } }
                        }
                    }
                });
            }

            // Chart 2: Rosca Status
            const ctx2 = document.getElementById('chart-dash-status');
            if (ctx2) {
                if (chartStatusDash) chartStatusDash.destroy();
                chartStatusDash = new Chart(ctx2, {
                    type: 'doughnut',
                    data: {
                        labels: ['🟢 Normal', '🟡 Atenção', '🔴 Alerta'],
                        datasets: [{
                            data: [normal, atencao, alerta],
                            backgroundColor: ['#22c55e', '#f59e0b', '#f43f5e'],
                            borderWidth: 0
                        }]
                    },
                    options: { 
                        responsive: true, 
                        maintainAspectRatio: false,
                        plugins: {
                            legend: { labels: { color: textColor } }
                        }
                    }
                });
            }
        }

        function renderConsumoPage() {
            const textColor = getChartTextColor();
            const gridColor = getChartGridColor();

            const ctx = document.getElementById('chart-consumo-comparativo');
            if (ctx) {
                if (chartConsumoComp) chartConsumoComp.destroy();
                chartConsumoComp = new Chart(ctx, {
                    type: 'bar',
                    data: {
                        labels: ['Jan', 'Fev', 'Mar', 'Abr', 'Mai', 'Jun'],
                        datasets: [
                            {
                                label: 'Horta Tradicional (Solo)',
                                data: [1200, 1350, 1250, 1400, 1300, 1280],
                                backgroundColor: currentTheme === 'dark' ? '#475569' : '#cbd5e1'
                            },
                            {
                                label: 'Hydroponex (Hidroponia)',
                                data: [95, 105, 98, 110, 102, 99],
                                backgroundColor: '#22c55e'
                            }
                        ]
                    },
                    options: { 
                        responsive: true, 
                        maintainAspectRatio: false,
                        scales: {
                            x: { ticks: { color: textColor }, grid: { color: gridColor } },
                            y: { ticks: { color: textColor }, grid: { color: gridColor } }
                        },
                        plugins: {
                            legend: { labels: { color: textColor } }
                        }
                    }
                });
            }
        }
