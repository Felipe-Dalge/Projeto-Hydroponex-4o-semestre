# Relatório e roteiro de defesa — Diário de Campo

## Entrega e integração

O módulo implementa um CRUD de inspeções preliminares em página própria com HTML5, CSS3 e JavaScript nativo. O array é persistido em localStorage. A criação, edição e exclusão só são confirmadas quando a gravação termina com sucesso. Não há PHP, banco, API ou biblioteca externa no módulo.

O Hydroponex já possui observações/intervenções oficiais e tarefas compartilhadas. O Diário não substitui esse fluxo: a horta/local são referências textuais de rascunhos pessoais, sem autorização, vínculo SQL ou sincronização. O aviso aparece antes do formulário. Não há promessa de privacidade entre contas do mesmo navegador.

As únicas alterações no sistema principal são três links em public/index.html: navegação desktop, móvel e painel operacional. Os links usam pi_diario_campo/index.html explicitamente porque o router PHP existente não resolve automaticamente índices de subdiretórios. Não houve alteração de handlers, autenticação, autorização, tabelas ou endpoints existentes.

## Arquivos

| Arquivo relativo à raiz do repositório | Finalidade |
|---|---|
| public/pi_diario_campo/index.html | Regiões semânticas, indicadores, formulário, filtro, lista e avisos. |
| public/pi_diario_campo/css/styles.css | Reset, variáveis, identidade Hydroponex, Flexbox/Grid, foco e media queries. |
| public/pi_diario_campo/js/script.js | Validação, CRUD, arrays, DOM, eventos e persistência com tratamento de falhas. |
| public/pi_diario_campo/README.md | Execução, regras, contexto acadêmico e limites. |
| public/pi_diario_campo/docs/ai-usage-log.md | Prompt integral e histórico real da interação com IA. |
| public/pi_diario_campo/docs/defesa.md | Este relatório, mapa de conceitos, testes e oito slides. |
| public/pi_diario_campo/docs/print-prototipo.png | Captura real em 1440px com dados fictícios identificados. |
| public/index.html | Três links para o módulo, preservando a aplicação existente. |
| tests/diario_campo.cjs | Testes automatizados no Edge, com servidor e contextos descartáveis. |

## Mapa dos dez conceitos obrigatórios

As referências são nomes de elementos/funções ou linhas verificadas no código desta entrega. Os caminhos de implementação abaixo são relativos a public/pi_diario_campo.

| Conceito da disciplina | Arquivo | Linha, função ou elemento | Justificativa técnica |
|---|---|---|---|
| 1. HTML5 semântico | index.html | header (13), nav (16), main (23), section (24), form (50), label (52), footer (116) | Separa regiões, organiza títulos e associa campos às suas instruções. |
| 2. Box Model | css/styles.css | Reset, linhas 1–6 | border-box inclui borda e padding na largura: controles com 100% cabem no painel. |
| 3. Flexbox e Grid | css/styles.css | .barra/.acoes (45), .indicadores/.layout-principal (56) | Flex para uma direção com quebra; Grid para a distribuição coordenada em colunas. |
| 4. Mobile-first e media queries | css/styles.css | @media min-width: 768px (96) e 1024px (102) | Começa com uma coluna; só expande indicadores e painéis quando o conteúdo cabe. |
| 5. Formulários e validação | index.html; js/script.js | form#inspecao-form; validarFormulario; apresentarErros | required/maxlength/min/max documentam regras; erros específicos e foco ajudam a correção. |
| 6. let, const, funções e arrays de objetos | js/script.js | CHAVE_STORAGE, CAMPOS, inspecoes, criarInspecao | Constantes definem opções/regras; let acompanha estado mutável; cada objeto representa uma inspeção. |
| 7. DOM e eventos | js/script.js | criarTexto, criarCard, renderizarLista; submit (263) | createElement/textContent evitam interpretar HTML; addEventListener controla todas as ações. |
| 8. localStorage e try/catch | js/script.js | carregarDados e salvarDados; chave na linha 3 | JSON é validado; falhas não confirmam operações nem sobrescrevem conteúdo incompatível. |
| 9. Prompt com cinco pilares e V1 → V2 → V3 | docs/ai-usage-log.md | Prompt exato: Papel, Contexto, Tarefa, Restrições e Formato da entrega; tabela de sequência | Os cinco pilares estão no prompt recebido. V1 registrada; V2/V3 aguardam interações reais do grupo, sem histórico inventado. |
| 10. Organização e versionamento | README.md; histórico Git | Estrutura do módulo; branch atividade/sif072-diario-campo | Commits locais separados para plano, implementação e validação. Publicação no GitHub depende de autorização. |

O conceito 9 não está academicamente completo enquanto V2 e V3 reais não ocorrerem. A publicação remota do conceito 10 também está pendente por instrução expressa; o comando está no README.

## Decisões técnicas para explicar oralmente

**HTML semântico.** Header apresenta identidade e navegação, main contém a atividade principal, section delimita formulário/resumo/histórico, article representa cada inspeção e footer identifica a atividade. Labels apontam para IDs únicos. O link de salto e tabindex do main permitem chegar ao conteúdo por teclado.

**Box Model.** Sem border-box, uma largura de 100% somada a padding e borda pode exceder a área disponível. O reset inclui esses componentes na medida declarada, facilitando campos de formulário e cards responsivos.

**Flexbox.** Barras de navegação, cabeçalhos e ações têm organização predominante em uma direção. display:flex com flex-wrap permite quebrar os elementos quando não cabem, mantendo alinhamento e distância.

**Grid.** Indicadores e relação entre formulário/lista precisam coordenar colunas. minmax(0,1fr) e min-width:0 permitem encolher as células; overflow-wrap:anywhere trata palavras longas. O layout principal usa proporção 2:3 em telas amplas.

**Mobile-first e breakpoints.** A regra inicial é uma coluna. Em 768px os indicadores já cabem em três colunas, mas dividir o restante nessa largura apertaria labels e controles. Em 1024px o formulário tem aproximadamente 360px e a lista 500px. A implementação foi medida em cinco larguras, não apenas escolhida por convenção. Botões/campos têm pelo menos 44px de altura.

**Eventos e atualização.** submit chama preventDefault, lê valores e valida. Depois prepara um novo array usando spread, map ou filter. O array em memória só é substituído após salvarDados retornar true. Renderizar os cards e recalcular os indicadores mantém a interface derivada do mesmo estado. Cancelar edição não grava.

**Validação.** Texto precisa ser string não vazia após trim, respeitar limite e não conter controles inadequados. Condição/categoria pertencem a listas fechadas. A data tem formato ISO e precisa corresponder a um dia real após a conversão, evitando que Date normalize 30 de fevereiro. Edição/exclusão procuram o ID antes de agir.

**Arrays de objetos.** Cada inspeção tem sete campos, incluindo o ID. find localiza uma inspeção, map prepara a edição, filter prepara a exclusão/filtro e sort ordena uma cópia para exibição. A lista original não é reordenada pela interface.

**Persistência.** getItem recupera texto; JSON.parse recupera o array. estruturaValida rejeita formatos inesperados, campos desconhecidos e IDs duplicados. JSON.stringify prepara o próximo estado e setItem tenta gravá-lo. Os registros pertencem ao navegador/origem, sem vínculo com as contas.

**Exceções.** try/catch distingue JSON inválido, acesso indisponível e quota. Na leitura incompatível, a gravação fica bloqueada até correção orientada/recarga. Na falha de escrita, formulário e estado anterior permanecem. A comparação do texto anterior e o evento storage reduzem sobrescritas entre abas; não equivalem a uma transação distribuída.

**Segurança e escopo.** textContent e campos value tratam as entradas como texto. Nenhum dado oficial é obtido por API. Não há armazenamento de credenciais. A ausência de autenticação própria é uma limitação explícita do rascunho acadêmico, não uma alteração das permissões do Hydroponex.

## Testes executados

Executados em **27/11/2026**, com Node, Playwright e Microsoft Edge em modo headless no Windows. O teste não adiciona dependências de runtime à página. Os contextos de navegador e o servidor HTTP são descartáveis.

| Teste realizado | Resultado esperado | Resultado obtido | Situação |
|---|---|---|---|
| Cadastro válido e múltiplos registros | Criar objetos/IDs únicos e listar todos | Três registros e IDs distintos | Aprovado |
| Obrigatórios, espaços, limites, opções e calendário | Rejeitar antes de gravar; apontar campo | Rejeições verificadas, incluindo ano bissexto e datas impossíveis | Aprovado |
| Edição | Atualizar dados mantendo ID/quantidade | ID mantido e indicadores ajustados | Aprovado |
| Cancelamento da edição | Não gravar valores digitados | Armazenamento idêntico e formulário limpo | Aprovado |
| Exclusão confirmada e cancelada | Remover somente com confirmação | Registros mantidos no cancelamento e removidos na confirmação | Aprovado |
| ID inexistente | Não editar/excluir outro registro | Mensagem específica sem alteração | Aprovado |
| Filtro e estados vazios | Filtrar sem mudar totais globais | Lista, totais e mensagens coerentes | Aprovado |
| Recarga | Recuperar registros do localStorage | Quantidade e cards preservados | Aprovado |
| Quota no cadastro, edição e exclusão | Não mudar array/registro nem declarar sucesso | Operações recusadas; formulário preservado | Aprovado |
| JSON inválido, objeto/nulo, duplicação de ID e categoria inválida | Não sobrescrever conteúdo original | Conteúdo mantido e botão de gravação bloqueado | Aprovado |
| Armazenamento indisponível na leitura | Erro tratado sem exceção não capturada | Aviso e gravação bloqueada | Aprovado |
| Alteração por outra aba | Avisar, bloquear e permitir recuperar após recarga | Estado obsoleto bloqueado; novos dados recuperados | Aprovado |
| Entrada com marcação e evento malicioso | Exibir como texto, sem executar código | Nenhum img criado ou código executado | Aprovado |
| 320, 375, 768, 1024 e 1440px | Sem rolagem horizontal; controles de 44px e colunas adequadas | Medidas verificadas nas cinco larguras | Aprovado |
| Textos longos | Quebrar linhas sem ultrapassar a página | Sem overflow horizontal em 320px | Aprovado |
| Teclado e foco | Tab alcança link de salto e formulário; foco visível | Ordem e outline verificados | Aprovado |
| Semântica, labels, eventos/estilos inline e bibliotecas | Semântica válida por inspeção do DOM; nenhum inline/dependência | Verificado no documento e nas requisições | Aprovado |
| Console | Sem erros inesperados | Zero erros ao final da suíte | Aprovado |
| Suíte tests/diario_campo.cjs | Todos os casos acima concluídos | **52 verificações aprovadas** | Aprovado |
| Suíte preexistente tests/frontend_integracao.cjs | Preservar os casos de integração JS | **9 verificações aprovadas** | Aprovado |
| Router PHP existente | HTML, CSS e JS servidos; sessão disponível e config bloqueada | Quatro respostas 200 e bloqueio 404 de php/config/database.php | Aprovado |
| Inspeção visual | Conferir os elementos em captura real | Imagens de 1440 e 320px revisadas; captura desktop incluída | Aprovado |
| Sintaxe JS e whitespace Git | Sem erros | node --check e git diff --check sem erros | Aprovado |
| CRUD completo/autorização PHP/MariaDB do sistema principal | Preservar o comportamento do sistema | Não reexecutado nesta atividade; somente links HTML alterados | Pendente de conferência no ambiente do grupo |
| Leitor de tela, celular físico e outros navegadores | Conferir uso real com tecnologias do grupo | Não executado | Pendente |
| PDF/PPTX oficiais e sequência V2/V3 | Conferir arquivos e registrar interações reais | Arquivos ausentes; interações ainda não fornecidas | Pendente |

Os testes estruturais de HTML não substituem um validador normativo completo. O contraste utiliza cores escuras sobre fundos claros, com condição também escrita em texto; uma avaliação humana de acessibilidade continua recomendada.

## Apresentação: exatamente oito slides

O template oficial não estava disponível. A distribuição abaixo segue os oito títulos e tempos exigidos no prompt; transfira o conteúdo para o arquivo oficial quando o grupo o obtiver, sem acrescentar slides.

| Slide | Tempo | Conteúdo e fala sugerida |
|---|---|---|
| 1. Identificação do grupo e do projeto | Parte de 0:00–1:30 | Hydroponex — Diário de Campo; SIF072; preencher integrantes e RAs. “Este módulo registra observações preliminares de cultivo no navegador.” |
| 2. Problema e solução | Restante de 0:00–1:30 | Uma percepção de campo nem sempre é uma tarefa formal. Mostrar o aviso de rascunho local e explicar que os registros oficiais do sistema continuam separados. |
| 3. Demonstração funcional | 1:30–3:30 | Executar o roteiro CRUD abaixo, incluindo recarga. Mostrar filtro/indicadores se houver tempo. |
| 4. Mapa de conceitos | 3:30–5:30 | Mostrar HTML semântico, border-box, Flex/Grid, media queries, formulário, array e eventos/persistência. Referenciar a tabela e reconhecer V2/V3 pendentes até realização real. |
| 5. Decisões técnicas | 5:30–8:00 | Explicar uma coluna inicial, limites de texto/data, textContent, novo array antes da gravação e try/catch. Mostrar as funções no código, sem ler todas as linhas. |
| 6. IA como copiloto | 8:00–9:15 | OpenAI Codex; prompt integral com cinco pilares; plano, código, testes e documentação gerados. Mostrar o log e apenas refinamentos reais registrados. |
| 7. O que a IA não fez | 9:15–9:45 | Não executou uma inspeção agronômica real; não confirmou dados oficiais; não tomou decisões acadêmicas pelo grupo; não inventou nomes/RA/V2/V3; não publicou a branch. Relatar aprendizados humanos somente depois de o grupo realmente demonstrá-los. |
| 8. Entregáveis | 9:45–10:00 | Branch, arquivos do módulo, README, log de IA, captura e testes. Inserir link da branch após publicação autorizada. Encerrar no tempo. |

### Roteiro prático para o slide 3

1. Abrir o Diário pelo painel. Explicar em uma frase que é local. Usar perfil de navegador de demonstração, sem dados pessoais.
2. Cadastrar Horta de demonstração, Bancada 02, data real da apresentação, Atenção, Saúde vegetal e “Exemplo fictício: folhas com amarelamento”.
3. Apontar o novo card e os indicadores. Recarregar e mostrar que o card permanece.
4. Editar o mesmo card para Normal e alterar a observação para um texto fictício de revisão. Salvar e mostrar o mesmo ID.
5. Cadastrar uma segunda inspeção curta. Demonstrar o filtro e voltar a todas as condições.
6. Clicar em Excluir na segunda inspeção, cancelar e mostrar que permanece. Repetir e confirmar. A primeira deve continuar.
7. Se sobrar tempo, iniciar uma edição e cancelar; mostrar o limite local novamente.

Não apagar dados reais para preparar a demonstração. Para simular quota ou JSON corrompido, usar a suíte de testes ou um perfil descartável, não o navegador com dados do grupo.

## Versionamento e pendências finais

- Branch criada a partir da main limpa e atualizada por pull --ff-only: atividade/sif072-diario-campo.
- Commit inicial: a48d287 — docs(diario): registra prompt e plano da atividade SIF072.
- Implementação: 03f5af0 — feat(diario): implementa inspeções locais e navegação do agricultor.
- Os testes e esta documentação são registrados em commit próprio; consultar git log da branch para o identificador final.
- Nenhum merge, descarte de alterações ou push foi realizado.
- Comando para publicar **após autorização**: git push -u origin atividade/sif072-diario-campo.

O grupo ainda precisa preencher identificação acadêmica, obter/conferir os arquivos oficiais, revisar a implementação, realizar V2/V3 reais e registrar alterações manuais e aprendizados efetivos. Até isso ocorrer, o log diferencia decisões de implementação da IA de decisões humanas, que não foram presumidas.

Estado do repositório ao finalizar: arquivos da atividade registrados em commits locais. Dois PDFs alheios à atividade foram observados como não rastreados durante o trabalho e permaneceram intocados, fora dos commits.
