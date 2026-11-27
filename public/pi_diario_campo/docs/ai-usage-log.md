# Registro de uso de IA — Diário de Campo

- Ferramenta: OpenAI Codex.
- Data da interação: 27/11/2026 (America/Sao_Paulo).
- Branch: atividade/sif072-diario-campo.
- Registro criado antes dos arquivos de implementação.
- Objetivo: implementar um CRUD acadêmico independente com HTML, CSS e JavaScript puros e persistência local, preservando o Hydroponex.

## Sequência real dos prompts

Esta é a primeira interação documentada para este módulo, identificada como **V1 neste registro**. O grupo não forneceu um histórico V1/V2/V3 anterior. Não se presume que esta seja a primeira utilização de IA pelo grupo.

| Etapa | Situação real |
|---|---|
| V1 — implementação | Prompt abaixo recebido nesta interação. |
| V2 — refinamento solicitado pelo grupo | Pendente. Inserir o prompt exato e a resposta após uma nova interação real. |
| V3 — auditoria solicitada pelo grupo | Pendente. Inserir o prompt exato e a resposta após uma nova interação real. |

Testes e autorrevisões durante V1 não serão apresentados como novos prompts do grupo.

## Análise inicial e plano (antes da geração)

Repositório limpo em main, remoto origin GitHub de Felipe-Dalge. Frontend em public/index.html e assets; APIs PHP/PDO e banco MariaDB. O painel operacional oferece observações/intervenções oficiais. O Diário será um rascunho local separado, sem copiar ou alterar esses registros. Identidade: verde hydro-700 #15803d, fundos slate, componentes arredondados e espaçamento consistente. Bibliotecas do sistema principal permanecem; nenhuma será importada no módulo.

Plano adotado pela implementação da IA: registrar este log; criar página semântica e CSS próprio mobile-first; implementar CRUD com array e gravação antes da mudança de estado; falhar sem sobrescrever armazenamento corrompido; integrar links; testar navegador e responsividade; documentar conceitos e apresentação. O PDF e o PPTX indicados não foram encontrados no repositório, Desktop ou anexos consultados. Seguir o prompt é uma decisão operacional da IA, ainda sem validação dos integrantes.

## Prompt exato recebido

~~~~text
# Hydroponex — Implementação do módulo Diário de Campo

## 1. Papel

Atue como um engenheiro de software especializado em desenvolvimento web, arquitetura de sistemas, HTML5, CSS3, JavaScript puro e versionamento com Git.

Você deverá trabalhar diretamente no repositório existente do Hydroponex, respeitando sua arquitetura, identidade visual, organização e funcionalidades.

Considere que este é um projeto acadêmico. A clareza, a simplicidade e a capacidade de explicar cada decisão técnica são tão importantes quanto o funcionamento do código.

## 2. Contexto

O Hydroponex é um sistema de gerenciamento de hortas hidropônicas desenvolvido como Projeto Interdisciplinar.

Precisamos implementar um novo módulo denominado **Diário de Campo**, para atender à atividade da disciplina SIF072 — Programação para Web I, intitulada “Do Conceito ao Código: Construção e Defesa”.

Os documentos de referência da atividade são:

- `Atividade_Grupo_Do_Conceito_ao_Codigo_SIF072.pdf`
- `Template_Apresentacao.pptx`

Caso estejam disponíveis no ambiente, leia integralmente esses documentos antes de iniciar a implementação. Caso não estejam disponíveis, utilize as especificações detalhadas neste prompt e informe essa limitação.

O objetivo da atividade é demonstrar domínio dos fundamentos de desenvolvimento web, não desenvolver uma solução desnecessariamente complexa.

O novo módulo deverá complementar o Hydroponex, permitindo que agricultores registrem observações preliminares sobre as condições das hortas, plantas e estruturas de cultivo, independentemente da criação de tarefas ou intervenções formais.

## 3. Tarefa

### Etapa 1 — Análise do projeto existente

Antes de modificar qualquer arquivo:

1. Analise a estrutura de diretórios do Hydroponex.
2. Identifique a arquitetura do frontend e do backend.
3. Identifique os padrões de nomenclatura utilizados.
4. Analise a identidade visual, incluindo cores, tipografia, espaçamentos, componentes e navegação.
5. Identifique o painel do agricultor e o local mais adequado para disponibilizar o Diário de Campo.
6. Verifique se existem funcionalidades semelhantes e evite duplicações desnecessárias.
7. Identifique possíveis incompatibilidades entre as tecnologias existentes e as restrições da atividade acadêmica.
8. Apresente um breve plano de implementação antes de executar as alterações.

### Etapa 2 — Versionamento com Git

Crie uma branch exclusiva para o desenvolvimento da atividade.

Nome da branch:

`atividade/sif072-diario-campo`

Antes de criar a branch:

- Execute `git status`.
- Identifique a branch atual.
- Verifique a existência de alterações não commitadas.
- Identifique a branch principal e o repositório remoto.
- Preserve todas as alterações preexistentes do usuário.

Se existirem alterações pendentes que possam comprometer a criação da branch, interrompa essa etapa e solicite orientação. Não descarte, sobrescreva ou esconda alterações automaticamente.

Se o repositório estiver limpo e a branch principal for `main`, utilize:

```bash
git switch main
git pull --ff-only origin main
git switch -c atividade/sif072-diario-campo
```

Caso a branch já exista, verifique seu estado e reutilize-a quando apropriado.

Realize toda a implementação na branch criada.

Não realize merge com a branch principal, não utilize comandos destrutivos e não execute force push.

Organize o desenvolvimento em commits pequenos, com mensagens descritivas, preferencialmente seguindo Conventional Commits.

Não publique a branch no repositório remoto sem autorização. Ao finalizar, informe o comando necessário para publicá-la.

### Etapa 3 — Estrutura de arquivos

Crie um módulo independente dentro do Hydroponex, preferencialmente na pasta `public/pi_diario_campo/`, desde que essa localização seja compatível com a arquitetura identificada.

A estrutura obrigatória do módulo será:

```text
pi_diario_campo/
├── README.md
├── index.html
├── css/
│   └── styles.css
├── js/
│   └── script.js
└── docs/
    ├── ai-usage-log.md
    └── print-prototipo.png (opcional)
```

Não reorganize desnecessariamente os arquivos existentes do Hydroponex.

O módulo deverá possuir seus próprios arquivos HTML, CSS e JavaScript, sem depender dos frameworks e bibliotecas utilizados pelo restante do sistema.

### Etapa 4 — Funcionalidades do Diário de Campo

Implemente um CRUD completo de registros de inspeção.

Cada registro deverá possuir:

| Campo | Tipo | Regra |
|---|---|---|
| Identificador | String | Único e gerado automaticamente |
| Horta | Texto | Obrigatório |
| Planta, bancada ou local | Texto | Obrigatório |
| Data da inspeção | Data | Obrigatório |
| Condição observada | Seleção | Normal, Atenção ou Alerta |
| Categoria | Seleção | Água, Nutrientes, Temperatura, Saúde vegetal ou Outros |
| Observação | Texto longo | Obrigatório |

As funcionalidades deverão incluir:

- **Create:** cadastrar uma nova inspeção.
- **Read:** listar as inspeções cadastradas.
- **Update:** editar uma inspeção existente.
- **Delete:** excluir uma inspeção mediante confirmação.
- Persistência dos registros após recarregar a página.
- Mensagens claras de sucesso e erro.
- Estado vazio quando não houver registros.
- Cancelamento da operação de edição.
- Filtro por condição observada.
- Indicadores de quantidade total de inspeções, registros de atenção e registros de alerta.

Os indicadores deverão ser atualizados dinamicamente após alterações nos registros.

Priorize o funcionamento correto do CRUD antes de implementar os recursos complementares.

### Etapa 5 — HTML5 semântico

Implemente o `index.html` utilizando obrigatoriamente:

- `header`
- `nav`
- `main`
- `section`
- `footer`
- `form`
- `label`

Utilize outros elementos semânticos, como `article`, quando apropriado.

Requisitos:

- Cada campo deverá possuir um `label` corretamente associado por meio dos atributos `for` e `id`.
- Utilize tipos de campos adequados, como `text`, `date`, `select` e `textarea`.
- Utilize atributos de validação HTML quando apropriado.
- Diferencie botões de envio e botões de ações comuns.
- Mantenha uma hierarquia coerente de títulos.
- Garanta a navegação por teclado.
- Disponibilize mensagens de validação acessíveis.
- Utilize arquivos externos para CSS e JavaScript.
- Não utilize atributos `style`, `onclick`, `onsubmit` ou outros eventos inline.

A estrutura deverá ser simples e facilmente explicável durante a defesa oral.

### Etapa 6 — CSS e identidade visual

Implemente o `css/styles.css` utilizando exclusivamente CSS puro.

Preserve a identidade visual do Hydroponex, reproduzindo seus padrões visuais por meio de CSS convencional, sem importar Tailwind ou outras bibliotecas.

#### Box Model

Implemente um reset global contendo:

```css
*,
*::before,
*::after {
    box-sizing: border-box;
}
```

Utilize `margin`, `padding`, `border` e dimensões de maneira consciente.

Inclua comentários explicando por que `border-box` foi escolhido e como ele facilita o controle das dimensões dos componentes.

#### Variáveis CSS

Utilize `:root` e propriedades personalizadas para centralizar:

- Cores principais.
- Cores de fundo.
- Cores de texto.
- Cores de condições e estados.
- Espaçamentos.
- Bordas.
- Raios de arredondamento.
- Tipografia, quando necessário.

#### Flexbox

Utilize Flexbox nos componentes cuja organização seja predominantemente unidimensional, como:

- Navbar.
- Grupos de botões.
- Alinhamento interno dos cards.
- Ações de edição e exclusão.

Comente as razões técnicas para essa escolha.

#### CSS Grid

Utilize Grid nos componentes cuja organização dependa de linhas e colunas, como:

- Layout principal.
- Distribuição do formulário e da listagem.
- Organização dos indicadores.

Comente as razões técnicas para essa escolha.

#### Responsividade mobile-first

Desenvolva inicialmente a interface para dispositivos com telas pequenas.

O layout deverá apresentar:

- Em telas pequenas: formulário e listagem organizados verticalmente.
- Em telas maiores: formulário e listagem organizados lado a lado, quando houver espaço suficiente.

Utilize pelo menos uma media query com `min-width`.

Considere inicialmente um breakpoint de 768px, mas valide essa escolha com base no conteúdo real da interface. Não utilize esse valor apenas por ser uma largura convencional.

Teste a responsividade entre 320px e 1440px.

Garanta:

- Ausência de rolagem horizontal indevida.
- Campos de formulário legíveis.
- Botões acessíveis.
- Cards corretamente dimensionados.
- Contraste adequado.
- Indicadores de foco visíveis.
- Tratamento de textos longos.

### Etapa 7 — JavaScript puro

Implemente o `js/script.js` exclusivamente com JavaScript nativo.

Utilize obrigatoriamente:

- `let`
- `const`
- Funções.
- Arrays de objetos.
- Manipulação do DOM.
- `addEventListener`.
- Validação de formulários.
- `localStorage`.
- `try/catch`.

Não utilize `var`, frameworks ou bibliotecas externas.

Cada inspeção deverá ser representada por um objeto JavaScript, seguindo uma estrutura semelhante a:

```javascript
const inspecao = {
    id: "identificador-unico",
    horta: "Horta principal",
    local: "Bancada 02",
    data: "2026-11-27",
    condicao: "Atenção",
    categoria: "Saúde vegetal",
    observacao: "Folhas com sinais de amarelamento."
};
```

Armazene o conjunto de inspeções em um array de objetos.

Organize o código em funções com responsabilidades bem definidas, preferencialmente:

- `carregarDados()`
- `salvarDados()`
- `obterDadosFormulario()`
- `validarFormulario()`
- `criarInspecao()`
- `renderizarLista()`
- `editarInspecao()`
- `excluirInspecao()`
- `limparFormulario()`
- `atualizarIndicadores()`
- `filtrarInspecoes()`

Os nomes podem ser ajustados para respeitar os padrões do projeto, desde que permaneçam claros e consistentes.

A atividade exige pelo menos três funções, mas a separação de responsabilidades deverá orientar a organização final.

Evite abstrações excessivas, padrões de projeto desnecessários e estruturas que dificultem a compreensão por estudantes.

### Etapa 8 — DOM e eventos

Todos os eventos deverão ser associados utilizando `addEventListener`.

O fluxo de cadastro deverá funcionar da seguinte maneira:

1. O usuário preenche o formulário.
2. O evento `submit` é capturado.
3. O JavaScript utiliza `preventDefault()` para impedir o envio convencional.
4. Os valores são recuperados dos campos.
5. Os dados são validados.
6. Um objeto de inspeção é preparado.
7. Um novo estado do array é preparado.
8. Os dados são persistidos.
9. Se a persistência for bem-sucedida, o estado da aplicação é atualizado.
10. A listagem e os indicadores são renderizados novamente.
11. O formulário é limpo.
12. Uma mensagem de sucesso é apresentada.

Caso a validação ou a persistência falhe, informe o erro ao usuário e não confirme a operação.

Utilize métodos nativos de manipulação do DOM, como:

- `getElementById`
- `querySelector`
- `createElement`
- `appendChild`
- `textContent`

Não insira dados fornecidos pelo usuário diretamente em `innerHTML`, evitando vulnerabilidades de injeção de código.

Documente o fluxo de eventos e atualização da interface de maneira didática.

### Etapa 9 — Validação

Implemente validação antes de qualquer gravação.

Verifique:

- Campos obrigatórios.
- Campos preenchidos apenas com espaços.
- Validade da data informada.
- Condições pertencentes às opções permitidas.
- Categorias pertencentes às opções permitidas.
- Limites razoáveis de comprimento dos campos.
- Existência do registro antes de editar ou excluir.

Apresente mensagens específicas e compreensíveis, por exemplo:

- “Informe o nome da horta.”
- “Informe a planta, bancada ou local da inspeção.”
- “Selecione uma condição válida.”
- “Descreva a observação realizada.”

Não utilize exclusivamente mensagens genéricas.

Documente as regras adotadas e suas justificativas.

### Etapa 10 — Persistência com localStorage

Utilize exclusivamente `localStorage` para a persistência dos dados do módulo.

Utilize uma chave específica:

```javascript
const CHAVE_STORAGE = "hydroponex.diarioCampo.v1";
```

Implemente:

- `localStorage.getItem()`
- `localStorage.setItem()`
- `JSON.stringify()`
- `JSON.parse()`
- `try/catch` nas operações de leitura e gravação.

Verifique se os dados recuperados possuem a estrutura esperada.

Trate possíveis situações de erro:

- Dados JSON inválidos.
- Estrutura de dados incompatível.
- Armazenamento indisponível.
- Falha na gravação.
- Limite de armazenamento excedido.

Não sobrescreva automaticamente dados corrompidos.

Caso uma operação de gravação falhe, preserve o estado anterior da aplicação e informe o usuário.

Não utilize PHP, MariaDB ou APIs externas para persistir os registros deste módulo.

Não modifique o banco de dados existente do Hydroponex.

Deixe explícito na interface e na documentação que os registros são armazenados localmente no navegador, não são sincronizados entre dispositivos e não constituem registros oficiais compartilhados da horta.

Não armazene senhas, tokens ou informações sensíveis no localStorage.

### Etapa 11 — Integração com o Hydroponex

Integre o Diário de Campo ao painel do agricultor por meio de um link ou item de navegação, respeitando os padrões existentes.

O módulo deverá:

- Possuir identidade visual compatível com o Hydroponex.
- Permitir retornar ao sistema principal.
- Funcionar independentemente das bibliotecas utilizadas pelo sistema principal.
- Ser executável e demonstrável no navegador.
- Preservar as funcionalidades existentes.
- Não alterar as regras de autenticação e autorização do sistema principal.

Realize apenas as alterações estritamente necessárias para disponibilizar o acesso ao módulo.

Não remova Tailwind, Chart.js, Lucide ou outras dependências do restante do Hydroponex. A proibição dessas tecnologias aplica-se à implementação acadêmica do novo módulo.

Caso a integração exija mudanças significativas na arquitetura ou nas regras de segurança, informe o problema antes de prosseguir.

## 4. Restrições e requisitos acadêmicos

A implementação deverá atender integralmente aos seguintes conceitos obrigatórios da disciplina:

1. HTML5 semântico.
2. Box Model e `box-sizing: border-box`.
3. Flexbox ou Grid com justificativa técnica.
4. Responsividade mobile-first e media queries.
5. Formulários HTML com validação e mensagens claras.
6. JavaScript com `let`, `const`, funções e arrays de objetos.
7. DOM e eventos com `addEventListener`.
8. Persistência com `localStorage` e `try/catch`.
9. Prompt estruturado com cinco pilares e evolução V1 → V2 → V3.
10. Organização de arquivos e versionamento no GitHub.

É expressamente proibido no novo módulo:

- Bootstrap.
- Tailwind CSS.
- React.
- Vue.
- Angular.
- jQuery.
- Chart.js.
- Lucide.
- Outras bibliotecas ou frameworks externos.
- Estilos inline.
- Eventos inline.
- Dependências externas desnecessárias.
- Código excessivamente complexo.
- Alterações destrutivas no repositório.
- Modificações em funcionalidades não relacionadas à atividade.

Todo código produzido deverá ser compreensível e explicável pelos integrantes do grupo.

Inclua comentários explicativos nos pontos relevantes, principalmente onde os conceitos da disciplina forem aplicados.

Não comente indiscriminadamente cada linha. Priorize comentários que expliquem decisões técnicas.

### Documentação — README.md

Crie um README específico para o módulo contendo:

- Nome do projeto.
- Nome da disciplina.
- Objetivo do Diário de Campo.
- Problema que o módulo pretende resolver.
- Funcionalidades implementadas.
- Tecnologias utilizadas.
- Estrutura de arquivos.
- Instruções de execução.
- Explicação da persistência local.
- Limitações conhecidas.
- Conceitos da disciplina aplicados.
- Procedimentos de teste.
- Nome da branch.
- Campos para nome do grupo, integrantes e respectivos RAs.

Não invente nomes, RAs ou informações acadêmicas ausentes.

### Documentação — AI Usage Log

Crie o arquivo `docs/ai-usage-log.md` antes de iniciar a geração dos arquivos de implementação.

Registre o uso da IA durante o desenvolvimento, e não somente ao final.

O documento deverá conter:

1. Ferramenta utilizada: OpenAI Codex.
2. Prompt exato recebido nesta interação.
3. Objetivo do prompt.
4. Arquivos e trechos gerados ou modificados.
5. Decisões técnicas sugeridas pela IA.
6. Problemas efetivamente identificados.
7. Refinamentos efetivamente solicitados.
8. Modificações realizadas manualmente pelo grupo, quando ocorrerem.
9. Explicação conceitual da solução.

A atividade exige uma evolução real de prompts:

- V1: construção inicial.
- V2: refinamento com restrições e melhorias.
- V3: auditoria e correção após a avaliação do código.

Registre este prompt como uma interação real de implementação, indicando sua posição na sequência conforme o histórico fornecido pelo grupo.

Não invente prompts anteriores, respostas, erros, intervenções humanas ou modificações manuais.

Deixe espaços claramente identificados para que o grupo registre as próximas interações e alterações manuais.

Diferencie sugestões da IA de decisões efetivamente tomadas pelos integrantes.

### Testes e critérios de aceitação

Antes de considerar o módulo concluído, verifique:

- Cadastro de registros válidos.
- Rejeição de registros inválidos.
- Listagem de múltiplos registros.
- Edição de registros.
- Cancelamento de edição.
- Exclusão de registros.
- Cancelamento da exclusão.
- Funcionamento dos filtros.
- Atualização dos indicadores.
- Persistência após recarregar a página.
- Comportamento diante de falhas no localStorage.
- Estado vazio da listagem.
- Ausência de erros inesperados no console.
- Responsividade em 320px, 375px, 768px, 1024px e 1440px.
- Ausência de estilos e eventos inline.
- Ausência de bibliotecas externas no módulo.
- Associação correta entre labels e campos.
- Navegação por teclado.
- Tratamento seguro de textos inseridos pelo usuário.
- Preservação das funcionalidades existentes do Hydroponex.

Utilize as ferramentas de teste disponíveis no ambiente.

Não afirme que um teste foi executado caso tenha realizado apenas uma inspeção estática do código. Identifique claramente os testes que ainda precisam ser realizados manualmente.

## 5. Formato da entrega

Ao finalizar a implementação, apresente um relatório contendo:

### 5.1. Resumo da implementação

Explique o que foi desenvolvido, como o módulo funciona e como ele foi integrado ao Hydroponex.

### 5.2. Versionamento

Informe:

- Branch criada ou reutilizada.
- Commits realizados.
- Estado final do repositório.
- Comando para publicar a branch no GitHub.

### 5.3. Arquivos

Apresente a estrutura final de diretórios e uma relação de todos os arquivos criados ou modificados, explicando a finalidade de cada um.

### 5.4. Mapa de conceitos

Apresente uma tabela com as seguintes colunas:

| Conceito da disciplina | Arquivo | Linha, função ou elemento | Justificativa técnica |
|---|---|---|---|

Inclua os dez conceitos obrigatórios da atividade.

Utilize referências reais ao código final, sem inventar números de linhas.

### 5.5. Decisões técnicas

Explique detalhadamente:

- Por que utilizar HTML semântico.
- Por que utilizar `border-box`.
- Por que utilizar Flexbox em determinados componentes.
- Por que utilizar Grid em outros componentes.
- Por que utilizar mobile-first.
- Como o breakpoint foi definido.
- Como os eventos atualizam a interface.
- Como os dados são validados.
- Como o array de objetos é organizado.
- Como funciona a persistência com localStorage.
- Como as exceções são tratadas.

Essas explicações serão utilizadas pelos integrantes durante a defesa oral.

### 5.6. Testes

Apresente uma tabela indicando:

- Teste realizado.
- Resultado esperado.
- Resultado obtido.
- Situação: aprovado, reprovado ou pendente.

### 5.7. Orientações para a apresentação

Forneça informações para preencher os oito slides do template oficial, sem adicionar slides extras:

1. Identificação do grupo e do projeto.
2. Problema e solução.
3. Demonstração funcional.
4. Mapa de conceitos.
5. Decisões técnicas.
6. IA como copiloto.
7. O que a IA não fez.
8. Entregáveis.

Respeite os tempos do template:

- Slides 1 e 2: 0:00–1:30.
- Slide 3: 1:30–3:30.
- Slide 4: 3:30–5:30.
- Slide 5: 5:30–8:00.
- Slide 6: 8:00–9:15.
- Slide 7: 9:15–9:45.
- Slide 8: 9:45–10:00.

Prepare um roteiro de demonstração que inclua cadastro, listagem, edição, exclusão e persistência após recarregar a página.

Não invente aprendizados, decisões humanas ou modificações manuais que ainda não tenham ocorrido.

### 5.8. Pendências

Informe quaisquer limitações, incompatibilidades, decisões tomadas sem orientação explícita e atividades que ainda dependam dos integrantes do grupo.

---

Inicie agora pela análise do repositório, pela verificação do estado do Git e pela apresentação do plano de implementação.

Se não houver impedimentos, prossiga com a criação da branch e a implementação completa do módulo.

Não se limite a apresentar exemplos de código ou instruções: crie e modifique efetivamente os arquivos necessários no repositório.

Priorize, nesta ordem:

1. Integridade do Hydroponex existente.
2. Atendimento aos requisitos acadêmicos.
3. Funcionamento correto do módulo.
4. Clareza e facilidade de compreensão do código.
5. Consistência visual.
6. Recursos complementares.
~~~~

## Acompanhamento da implementação V1

- Início: somente este log foi criado; implementação ainda não gerada.
- Alterações manuais do grupo: nenhuma observada nesta interação. Espaço para registrar autor, data, arquivo, trecho e justificativa: **[preencher após ocorrer]**.
- Decisões do grupo: **[preencher após revisão humana]**. Sugestões e decisões de implementação da IA não representam aprovação dos integrantes.

### Geração inicial V1

- Criados index.html, css/styles.css e js/script.js independentes, sem dependências externas.
- Modificado somente public/index.html no sistema principal, acrescentando três links: menu desktop, menu móvel e navegação operacional. Nenhuma API ou tabela modificada.
- Decisões de implementação da IA: texto livre para horta; aviso de armazenamento compartilhado pelo navegador; limite de 100/160/2000 caracteres; validação de datas reais; IDs únicos; textContent; gravação antes de trocar o array; recusa de estrutura incompatível e IDs repetidos; bloqueio de conflitos entre abas.
- Flex para barras/ações, Grid para indicadores/painéis, indicadores em três colunas em 768px e painéis lado a lado em 1024px. Essas medidas ainda serão verificadas no navegador.
- Ainda não há resultados de execução nesta entrada; testes vêm a seguir.

### Autorrevisão e testes durante V1 (não são novos prompts)

- A suíte de navegador chegou a 52 verificações aprovadas no Edge, incluindo CRUD, falhas de armazenamento, XSS textual, conflitos de abas, teclado e cinco larguras.
- Identificado na inspeção: o router PHP existente não resolve diretórios internos como índice. Os três links passaram a apontar explicitamente para pi_diario_campo/index.html, compatível com Apache e com o router, sem alterar o servidor.
- Corrigidos dois problemas no próprio teste: URLs locais resolvidas pelo DOM não são bibliotecas externas; a preparação de localStorage deve aguardar um documento HTTP em vez de about:blank. Não foram defeitos de persistência do módulo.
- Captura de protótipo gerada de uma execução real com exemplo fictício identificado como demonstração.
- Testes JavaScript preexistentes: 9 verificações aprovadas. O CRUD PHP/MySQL completo não foi reexecutado nesta atividade; não houve modificação nele.
