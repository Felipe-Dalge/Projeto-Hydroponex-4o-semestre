/* Teste de navegador separado do módulo: npm/Playwright são ferramentas de teste, não dependências da página. */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const http = require('node:http');
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const root = path.resolve(__dirname, '..');
const moduleRoot = path.join(root, 'public/pi_diario_campo');
const key = 'hydroponex.diarioCampo.v1';
let checks = 0;
function ok(value, label) { assert.ok(value, label); checks++; console.log('OK: ' + label); }
const server = http.createServer((req, res) => {
    const routes = {
        '/pi_diario_campo/': ['index.html', 'text/html; charset=utf-8'],
        '/pi_diario_campo/index.html': ['index.html', 'text/html; charset=utf-8'],
        '/pi_diario_campo/css/styles.css': ['css/styles.css', 'text/css; charset=utf-8'],
        '/pi_diario_campo/js/script.js': ['js/script.js', 'text/javascript; charset=utf-8']
    };
    if (req.url === '/favicon.ico') { res.writeHead(204); res.end(); return; }
    const route = routes[req.url];
    if (!route) { res.writeHead(404); res.end(); return; }
    res.writeHead(200, { 'Content-Type': route[1] });
    res.end(fs.readFileSync(path.join(moduleRoot, route[0])));
});

(async () => {
    await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
    const url = 'http://127.0.0.1:' + server.address().port + '/pi_diario_campo/';
    const browser = await chromium.launch({ channel: process.env.DIARIO_BROWSER || 'msedge', headless: true });
    const errors = [];
    const external = [];
    browser.on('context', (context) => context.on('page', (page) => {
        page.on('pageerror', (error) => errors.push(error.message));
        page.on('console', (message) => { if (message.type() === 'error') errors.push(message.text()); });
        page.on('request', (request) => { if (new URL(request.url()).origin !== new URL(url).origin) external.push(request.url()); });
    }));
    async function open(initial) {
        const context = await browser.newContext();
        if (initial !== undefined) await context.addInitScript(({ key, initial }) => {
            if (location.protocol === 'http:') localStorage.setItem(key, initial);
        }, { key, initial });
        const page = await context.newPage();
        await page.goto(url);
        return { context, page };
    }
    async function fill(page, data = {}) {
        const values = {
            horta: 'Horta acadêmica', local: 'Bancada 02', data: '2026-11-27',
            condicao: 'Normal', categoria: 'Água', observacao: 'Observação de teste.',
            ...data
        };
        for (const [name, value] of Object.entries(values)) {
            const locator = page.locator('#' + name);
            if (['condicao', 'categoria'].includes(name)) await locator.selectOption(value);
            else await locator.fill(value);
        }
    }
    async function submit(page) { await page.locator('#salvar').click(); }
    async function records(page) { return page.evaluate((key) => JSON.parse(localStorage.getItem(key) || '[]'), key); }
    try {
        const { context, page } = await open();
        ok(await page.locator('#estado-vazio').isVisible(), 'estado vazio inicial');
        ok(await page.locator('#total-inspecoes').textContent() === '0', 'indicador inicial zero');
        const audit = await page.evaluate(() => ({
            semantics: ['header', 'nav', 'main', 'section', 'footer', 'form', 'label'].every((tag) => document.querySelector(tag)),
            labels: [...document.querySelectorAll('input, select, textarea')].every((field) => [...field.labels].some((label) => label.htmlFor === field.id)),
            inline: [...document.querySelectorAll('*')].some((node) => [...node.attributes].some((attr) => attr.name === 'style' || attr.name.startsWith('on'))),
            external: [...document.querySelectorAll('script[src], link[href]')].some((node) => /^(https?:)?\/\//.test(node.getAttribute('src') || node.getAttribute('href'))),
            back: document.querySelector('nav a[href="../index.html#operacao"]') !== null
        }));
        ok(audit.semantics && audit.labels, 'HTML semântico e todos os labels associados');
        ok(!audit.inline && !audit.external, 'ausência de atributos inline e dependências externas');
        ok(audit.back, 'link de retorno ao painel operacional');
        await fill(page, { horta: '   ', observacao: '   ' });
        await submit(page);
        ok((await records(page)).length === 0 && await page.locator('#horta').getAttribute('aria-invalid') === 'true', 'espaços obrigatórios rejeitados sem gravação');
        ok(await page.locator('#horta-erro').textContent() === 'Informe o nome da horta.', 'erro específico associado ao campo');
        ok(await page.locator('#horta').evaluate((field) => document.activeElement === field), 'foco no primeiro erro');
        const rules = await page.evaluate(() => {
            const valid = { horta: 'H', local: 'L', data: '2026-11-27', condicao: 'Normal', categoria: 'Água', observacao: 'O' };
            return {
                leap: dataValida('2024-02-29') && !dataValida('2026-02-29') && !dataValida('2026-02-30') && !dataValida('0000-01-01'),
                invalid: ['horta', 'local', 'observacao'].every((field) => validarFormulario({ ...valid, [field]: ' ' }).some((error) => error.campo === field)),
                lengths: [['horta', 101], ['local', 161], ['observacao', 2001]].every(([field, length]) => validarFormulario({ ...valid, [field]: 'a'.repeat(length) }).some((error) => error.campo === field)),
                enums: validarFormulario({ ...valid, condicao: 'Inventada', categoria: 'Inválida' }).length === 2
            };
        });
        for (const [name, value] of Object.entries(rules)) ok(value, 'validação: ' + name);
        const payload = '<img src=x onerror="window.__diarioXss=1">';
        await fill(page, { condicao: 'Atenção', observacao: payload });
        await submit(page);
        const firstId = (await records(page))[0].id;
        ok(await page.locator('.observacao').textContent() === payload && await page.locator('.observacao img').count() === 0, 'entrada HTML exibida como texto');
        ok(await page.evaluate(() => !window.__diarioXss), 'código inserido não executado');
        await fill(page, { horta: 'Horta B', data: '2026-11-26' }); await submit(page);
        await fill(page, { horta: 'Horta C', condicao: 'Alerta', categoria: 'Temperatura' }); await submit(page);
        let saved = await records(page);
        ok(saved.length === 3 && new Set(saved.map((row) => row.id)).size === 3, 'múltiplos registros com IDs únicos');
        ok(await page.locator('.inspecao').count() === 3, 'listagem completa de três registros');
        ok(await page.locator('#total-atencao').textContent() === '1' && await page.locator('#total-alerta').textContent() === '1', 'indicadores por condição');
        await page.locator('#filtro-condicao').selectOption('Atenção');
        ok(await page.locator('.inspecao').count() === 1 && await page.locator('#total-inspecoes').textContent() === '3', 'filtro sem alterar os totais globais');
        await page.locator('.inspecao button').first().click();
        await page.locator('#local').fill('Bancada editada');
        await page.locator('#condicao').selectOption('Alerta');
        await submit(page);
        saved = await records(page);
        ok(saved.find((row) => row.id === firstId).local === 'Bancada editada' && saved.length === 3, 'edição preserva ID e quantidade');
        ok(await page.locator('#total-atencao').textContent() === '0' && await page.locator('#total-alerta').textContent() === '2', 'indicadores atualizados após edição');
        ok(await page.locator('#estado-vazio').textContent() === 'Nenhuma inspeção corresponde à condição selecionada.', 'estado vazio do filtro');
        await page.locator('#filtro-condicao').selectOption('');
        const storedBeforeCancel = await page.evaluate((key) => localStorage.getItem(key), key);
        await page.locator('.inspecao button').first().click();
        await page.locator('#horta').fill('Não salvar');
        await page.locator('#cancelar-edicao').click();
        ok(await page.locator('#horta').inputValue() === '' && await page.evaluate((key) => localStorage.getItem(key), key) === storedBeforeCancel, 'cancelamento de edição sem alterar armazenamento');
        page.once('dialog', (dialog) => dialog.dismiss());
        await page.getByRole('button', { name: /^Excluir inspeção:/ }).first().click();
        ok((await records(page)).length === 3, 'cancelamento da exclusão');
        page.once('dialog', (dialog) => dialog.accept());
        await page.getByRole('button', { name: /^Excluir inspeção:/ }).first().click();
        ok((await records(page)).length === 2 && await page.locator('#total-inspecoes').textContent() === '2', 'exclusão confirmada e indicador atualizado');
        await page.reload();
        ok((await records(page)).length === 2 && await page.locator('.inspecao').count() === 2, 'persistência após recarregar');
        await page.evaluate(() => editarInspecao('inexistente'));
        ok((await page.locator('#mensagem').textContent()).includes('não foi encontrada'), 'edição inexistente rejeitada');
        await page.evaluate(() => excluirInspecao('inexistente'));
        ok((await page.locator('#mensagem').textContent()).includes('não foi encontrada'), 'exclusão inexistente rejeitada');
        await page.evaluate(() => {
            window.originalSetItem = Storage.prototype.setItem;
            Storage.prototype.setItem = function () { throw new DOMException('Teste', 'QuotaExceededError'); };
        });
        await fill(page, { horta: 'Não deve persistir' });
        await submit(page);
        ok((await records(page)).length === 2 && await page.locator('#horta').inputValue() === 'Não deve persistir', 'quota preserva array e formulário no cadastro');
        ok((await page.locator('#mensagem').textContent()).includes('espaço'), 'mensagem específica de quota');
        await page.locator('.inspecao button').first().click();
        const originalGarden = (await records(page))[0].horta;
        await page.locator('#horta').fill('Edição falha');
        await submit(page);
        ok((await records(page))[0].horta === originalGarden && await page.locator('#horta').inputValue() === 'Edição falha', 'falha na edição preserva estado');
        page.once('dialog', (dialog) => dialog.accept());
        await page.getByRole('button', { name: /^Excluir inspeção:/ }).first().click();
        ok((await records(page)).length === 2, 'falha na exclusão preserva registros');
        await page.evaluate(() => { Storage.prototype.setItem = window.originalSetItem; });
        await page.locator('#cancelar-edicao').click();
        const other = await context.newPage(); await other.goto(url);
        await fill(other, { horta: 'Criada em outra aba' }); await submit(other);
        await page.waitForFunction(() => document.getElementById('salvar').disabled);
        ok(await page.locator('#salvar').isDisabled(), 'mudança entre abas bloqueia gravação obsoleta');
        await page.reload();
        ok(await page.locator('.inspecao').count() === 3 && !await page.locator('#salvar').isDisabled(), 'recarga incorpora alteração externa');
        await other.close();

        for (const width of [320, 375, 768, 1024, 1440]) {
            await page.setViewportSize({ width, height: 900 });
            const layout = await page.evaluate(() => ({
                fits: document.documentElement.scrollWidth <= innerWidth,
                columns: getComputedStyle(document.querySelector('.layout-principal')).gridTemplateColumns.split(' ').length,
                targets: [...document.querySelectorAll('input,select,textarea,button:not([hidden])')].filter((node) => node.getClientRects().length).every((node) => node.getBoundingClientRect().height >= 44)
            }));
            ok(layout.fits && layout.targets && layout.columns === (width >= 1024 ? 2 : 1), 'responsividade e alvos de toque em ' + width + 'px');
        }
        await fill(page, { horta: 'X'.repeat(100), local: 'L'.repeat(160), observacao: 'Z'.repeat(2000) });
        await submit(page);
        await page.setViewportSize({ width: 320, height: 900 });
        ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), 'textos longos não geram rolagem horizontal');
        await page.reload(); await page.keyboard.press('Tab');
        ok(await page.locator('.pular-conteudo').evaluate((node) => document.activeElement === node), 'link de salto alcançável por teclado');
        await page.keyboard.press('Enter'); await page.keyboard.press('Tab');
        ok(await page.locator('#horta').evaluate((node) => document.activeElement === node), 'teclado alcança formulário pelo link de salto');
        ok(await page.locator('#horta').evaluate((node) => getComputedStyle(node).outlineStyle !== 'none'), 'foco visível');
        await context.close();

        const duplicate = { id: 'igual', horta: 'H', local: 'L', data: '2026-11-27', condicao: 'Normal', categoria: 'Água', observacao: 'O' };
        for (const initial of ['{invalido', '{}', '[null]', JSON.stringify([duplicate, duplicate]), JSON.stringify([{ ...duplicate, categoria: 'Inválida' }])]) {
            const test = await open(initial);
            ok(await test.page.locator('#salvar').isDisabled() && await test.page.evaluate((key) => localStorage.getItem(key), key) === initial, 'dados inválidos preservados sem sobrescrita: ' + initial.slice(0, 22));
            await test.context.close();
        }
        const unavailable = await browser.newContext();
        await unavailable.addInitScript(() => { Storage.prototype.getItem = function () { throw new DOMException('Teste', 'SecurityError'); }; });
        const unavailablePage = await unavailable.newPage(); await unavailablePage.goto(url);
        ok(await unavailablePage.locator('#salvar').isDisabled() && (await unavailablePage.locator('#mensagem').textContent()).includes('indisponível'), 'leitura indisponível tratada sem erro não capturado');
        await unavailable.close();

        const demo = await open();
        await fill(demo.page, { horta: 'Horta de demonstração', condicao: 'Atenção', categoria: 'Saúde vegetal', observacao: 'Exemplo fictício para apresentação: folhas com amarelamento na bancada 02.' });
        await submit(demo.page);
        await demo.page.setViewportSize({ width: 1440, height: 1000 });
        if (process.env.DIARIO_SCREENSHOT) await demo.page.screenshot({ path: process.env.DIARIO_SCREENSHOT, fullPage: true });
        if (process.env.DIARIO_SCREENSHOT_MOBILE) {
            await demo.page.setViewportSize({ width: 320, height: 900 });
            await demo.page.screenshot({ path: process.env.DIARIO_SCREENSHOT_MOBILE, fullPage: true });
        }
        await demo.context.close();
        const main = fs.readFileSync(path.join(root, 'public/index.html'), 'utf8');
        ok((main.match(/href="pi_diario_campo\/index.html"/g) || []).length === 3, 'três links integrados sem alterar handlers existentes');
        const script = fs.readFileSync(path.join(moduleRoot, 'js/script.js'), 'utf8');
        ok(!/\bvar\s|\.innerHTML\s*=|\bfetch\s*\(/.test(script), 'JavaScript sem var, innerHTML ou API de persistência');
        ok(errors.length === 0, 'nenhum erro inesperado de console: ' + errors.join('; '));
        ok(external.length === 0, 'nenhuma requisição a biblioteca/API externa');
        console.log(checks + ' verificações do Diário de Campo aprovadas.');
    } finally {
        await browser.close();
        await new Promise((resolve) => server.close(resolve));
    }
})().catch((error) => { console.error(error); server.close(); process.exitCode = 1; });
