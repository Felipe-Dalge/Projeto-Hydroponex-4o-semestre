# Hydroponex — Diário de Campo

**Disciplina:** SIF072 — Programação para Web I. Atividade “Do Conceito ao Código: Construção e Defesa”.

**Grupo:** [preencher]. **Integrantes e RAs:** preencher abaixo, sem presumir os responsáveis pela implementação.

| Nome completo | RA |
|---|---|
| [preencher] | [preencher] |

**Branch:** atividade/sif072-diario-campo. A atividade foi desenvolvida separadamente da main; não publicar nem mesclar sem autorização.

## Objetivo e problema

Uma observação de campo pode anteceder uma tarefa ou intervenção formal. Este módulo permite anotar essas percepções rapidamente, com condição e categoria, para consulta pessoal no mesmo navegador. Complementa as observações oficiais do Hydroponex sem duplicar o fluxo compartilhado: não envia dados a tarefas, hortas, plantas ou APIs.

A implementação acadêmica usa somente HTML5, CSS3 e JavaScript nativo. Não importa Tailwind, Lucide, Chart.js, fontes, imagens ou bibliotecas externas. Os componentes reproduzem o verde #15803d, fundos claros, bordas e espaçamentos do projeto principal com CSS convencional.

## Funcionalidades

- Cadastro, listagem, edição e exclusão de inspeções com confirmação nativa.
- Cancelamento de edição/exclusão; identificador único automático e estável na edição.
- Filtro por Normal, Atenção ou Alerta; indicadores globais de total, atenção e alerta.
- Mensagens específicas, estados vazios, formulário com labels, foco de erro e navegação por teclado.
- Persistência local após recarga; gravação antes de confirmar a operação.
- Proteção contra sobrescrita automática de JSON inválido ou estrutura incompatível.
- Tratamento de indisponibilidade e quota; aviso/bloqueio ao detectar mudanças em outra aba.
- Textos inseridos via textContent; observações nunca são interpretadas como HTML.
- Layout mobile-first validado de 320 a 1440px.

## Estrutura e finalidade

~~~text
public/pi_diario_campo/
├── README.md                   Contexto, execução, regras e limitações
├── index.html                  Estrutura semântica e formulário
├── css/
│   └── styles.css              Identidade, Box Model, Flexbox, Grid e responsividade
├── js/
│   └── script.js               Estado, validação, DOM, eventos e localStorage
└── docs/
    ├── ai-usage-log.md          Prompt exato e acompanhamento real do uso de IA
    ├── defesa.md                Relatório, conceitos, testes e roteiro dos oito slides
    └── print-prototipo.png      Captura real com dados fictícios de demonstração

tests/diario_campo.cjs           Suíte de navegador, fora do código do módulo
public/index.html               Único arquivo principal alterado: três links de acesso
~~~

## Executar no XAMPP

Se o projeto já está instalado, mantenha banco/configuração e use o atualizador do Hydroponex com os arquivos desta branch. O módulo não precisa de migração SQL. Antes de distribuir, siga o processo de revisão da branch.

Para publicação manual do módulo, copie a pasta pi_diario_campo para C:/xampp/htdocs/hydroponex/pi_diario_campo. A integração completa dos menus exige também o public/index.html desta mesma versão; prefira o atualizador para sincronizar a publicação sem misturar versões.

Inicie Apache e abra:

~~~text
http://localhost/hydroponex/pi_diario_campo/index.html
~~~

No sistema principal, use **Diário de Campo · local**, disponível no menu desktop, menu móvel e navegação do painel do agricultor. O retorno aponta para index.html#operacao; o sistema principal mantém seu próprio login.

Para demonstrar o módulo sem XAMPP, a partir da raiz do repositório:

~~~powershell
C:/xampp/php/php.exe -S 127.0.0.1:8080 -t public public/server/router.php
~~~

Abra http://127.0.0.1:8080/pi_diario_campo/index.html. PHP serve os arquivos estáticos; não persiste o diário. Esse router protege arquivos internos do projeto. Não use um servidor estático genérico sobre todo o projeto PHP.

O arquivo explícito index.html nos links evita o 404 do router em caminhos de diretório. Prefira HTTP em localhost a file://, cujo comportamento de armazenamento varia por navegador.

## Persistência e limites

Chave única: **hydroponex.diarioCampo.v1**. O valor é um array JSON de objetos com id, horta, local, data, condicao, categoria e observacao.

A origem do navegador (protocolo, host e porta), o perfil e as configurações de privacidade determinam o armazenamento. localhost e 127.0.0.1, portas diferentes e perfis diferentes não compartilham o mesmo diário. Limpar os dados do site/perfil ou encerrar certas sessões privadas pode remover os registros.

**Não há sincronização, autenticação própria, separação por conta nem vínculo com a autorização das hortas.** A horta é texto livre. Outras pessoas no mesmo navegador/origem podem ler o diário, mesmo após logout do sistema principal. Não inclua credenciais ou informações sensíveis. Estes são rascunhos preliminares, não registros oficiais compartilhados.

A página não apaga nem substitui automaticamente conteúdo inválido. Se surgir um aviso, preserve o valor original da chave nas ferramentas do navegador antes de qualquer correção orientada. Em caso de quota ou bloqueio de gravação, a interface mantém array, indicadores e formulário anteriores, sem declarar sucesso.

Mudanças entre abas geram bloqueio e pedido de recarga. A comparação antes da gravação reduz conflitos, mas localStorage não oferece uma transação entre leitura e escrita em várias abas. Evite editar simultaneamente. Não há exportação/importação ou recuperação de dados apagados nesta atividade.

## Regras de validação

| Campo | Regra e justificativa |
|---|---|
| ID | String automática, não editável, única no array. UUID quando disponível; alternativa de tempo/aleatoriedade com verificação de colisão. Não é token de segurança. |
| Horta | Texto livre obrigatório, até 100 caracteres; comporta um nome legível sem impor um cadastro do banco. |
| Local | Texto obrigatório, até 160 caracteres, para planta, bancada ou outra referência. |
| Data | AAAA-MM-DD, ano 0001 a 9999 e dia real do calendário; conversão UTC evita deslocar o dia na exibição. Datas futuras não foram proibidas pelo enunciado. |
| Condição | Somente Normal, Atenção ou Alerta. |
| Categoria | Somente Água, Nutrientes, Temperatura, Saúde vegetal ou Outros. |
| Observação | Texto obrigatório, até 2.000 caracteres; permite parágrafos e rejeita caracteres de controle inadequados. |
| Dados recuperados | Array, conjunto esperado de campos, tipos corretos, regras de formulário e IDs não repetidos. Campos desconhecidos bloqueiam a leitura para não descartar dados silenciosamente. |

Os campos textuais recebem trim antes de salvar, rejeitando conteúdo apenas com espaços. Os limites seguem unidades de string do JavaScript e dos atributos maxlength do HTML. Os atributos required, maxlength e min/max expressam as restrições; novalidate permite apresentar mensagens próprias consistentes e acessíveis, sem depender do idioma do navegador.

## Fluxo de eventos

1. submit é tratado com addEventListener e preventDefault.
2. obterDadosFormulario lê os valores; validarFormulario devolve erros específicos.
3. Em erro, apresentarErros associa mensagens, marca aria-invalid e foca o primeiro campo.
4. Sem erros, um novo objeto/array é preparado, sem mudar inspecoes.
5. salvarDados valida a estrutura, verifica a versão local conhecida, serializa e tenta setItem dentro de try/catch.
6. Somente no sucesso, inspecoes recebe o novo array; lista e indicadores são renderizados; formulário é limpo e o sucesso é anunciado.

Editar carrega os dados pelo ID. Cancelar limpa o formulário sem gravar. Excluir procura o registro, pede confirmação e repete a estratégia de persistir antes de mudar o array. O filtro é um estado da interface, não modifica os registros. Os totais sempre representam todas as inspeções, independentemente do filtro.

## Conceitos e defesa

HTML semântico oferece regiões claras e títulos navegáveis. border-box inclui borda/padding no tamanho declarado. Flexbox organiza barras e ações em uma direção com quebra; Grid organiza indicadores e painéis em colunas. Mobile-first mantém o formulário utilizável em telas estreitas.

Em 768px os indicadores passam a três colunas, mas os painéis continuam empilhados. Só em 1024px formulário e listagem ficam lado a lado: há espaço para os textos e controles de 44px sem compressão. A escolha foi verificada em 320, 375, 768, 1024 e 1440px, incluindo textos longos. :focus-visible, labels associados e mensagens ao lado dos campos ajudam no uso por teclado.

Os dez conceitos exigidos, explicações para a defesa e os oito slides com tempos estão em [docs/defesa.md](docs/defesa.md). O requisito de evolução real V1 → V2 → V3 depende de novas interações do grupo; não foi simulado.

## Testes

Em 27/11/2026 foram aprovadas **52 verificações de navegador** no Edge, além de **9 verificações preexistentes** de integração JavaScript. A tabela detalhada diferencia testes executados e pendências em docs/defesa.md.

Para reproduzir, instale Playwright em uma pasta de ferramentas de desenvolvimento, sem adicionar dependências ao módulo, e disponibilize o pacote ao Node por PLAYWRIGHT_MODULE:

~~~powershell
$env:PLAYWRIGHT_MODULE = "C:/Ferramentas/testes/node_modules/playwright"
node tests/diario_campo.cjs
node tests/frontend_integracao.cjs
node --check public/pi_diario_campo/js/script.js
~~~

A suíte usa Edge instalado, inicia seu próprio servidor em loopback/porta livre e fecha tudo ao terminar. Dados ficam em contextos descartáveis, sem acessar contas, banco ou localStorage pessoal. Pode-se escolher um canal compatível por DIARIO_BROWSER.

Para gerar novamente a captura opcional:

~~~powershell
$env:DIARIO_SCREENSHOT = "C:/caminho/do/projeto/public/pi_diario_campo/docs/print-prototipo.png"
node tests/diario_campo.cjs
~~~

Antes da defesa, testar manualmente com teclado e leitor de tela do grupo, revisar a apresentação e conferir os fluxos do sistema principal em uma cópia de teste com banco configurado.

## Pendências acadêmicas e limites da validação

- O PDF e o PPTX oficiais não foram encontrados nas pastas consultadas; os conteúdos e tempos foram seguidos conforme o prompt. Conferir com os arquivos oficiais quando disponíveis.
- Preencher grupo, integrantes e RAs; revisar o código e registrar decisões humanas reais.
- Realizar e documentar prompts V2 e V3, refinamentos e alterações manuais efetivamente ocorridas.
- A captura é de demonstração fictícia. Não representa uma inspeção real.
- Nesta atividade não foram reexecutadas as suítes completas PHP/MariaDB, nem testes em dispositivos físicos, Safari ou Firefox. As APIs, o banco e as regras de autenticação não foram alterados.

## Publicar a branch

Somente com autorização:

~~~bash
git push -u origin atividade/sif072-diario-campo
~~~

Não houve merge ou push nesta entrega.

Estado do repositório ao finalizar: arquivos da atividade registrados em commits locais. Dois PDFs alheios à atividade foram observados como não rastreados durante o trabalho e permaneceram intocados, fora dos commits.
