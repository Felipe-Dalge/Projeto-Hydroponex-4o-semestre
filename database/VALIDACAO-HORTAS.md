# Validação de hortas — 28/10/2026

Ambiente: PHP 8.2.12, PDO MySQL, MySQL Community 8.0.44 em instância temporária isolada, schema 1.1.0 e conta com SELECT/INSERT/UPDATE/DELETE, sem DDL. Testes HTTP em cópias temporárias da aplicação. Nenhum banco do MariaDB local foi alterado.

## Resultados executados

| Grupo | Resultado |
| --- | --- |
| `tests/hortas.py` | **62 verificações HTTP/SQL aprovadas**, com três usuários e várias hortas |
| Visitantes e terceiros | Visitante não lista; terceiros não consultam, editam ou excluem horta; identificador de responsável recebido do cliente é recusado |
| Criação | IDs únicos, múltiplas hortas por usuário, proprietário da sessão e estrutura opcional na mesma transação |
| Validação/rollback | Nome, tipos de tamanho, precisão/range, CEP, estrutura inválida e CSRF; falha de estrutura não deixa horta órfã |
| Endereço | CEP normalizado com hífen, zeros iniciais e associação ao catálogo existente |
| Estruturas | Criação/edição na própria horta; tentativa de mover/usar estrutura de outra horta recusada |
| Associação N:N | Horta compartilhada aparece apenas para membros; leitor consulta, gestor escreve, proprietário pode excluir horta vazia |
| Plantas | Várias plantas na horta; consultas e alterações restritas ao par horta + planta; nenhuma umidade/água fictícia inserida |
| Integridade de exclusão | Bloqueio separado para planta, estrutura, reservatório, sensor, monitoramento, consumo e cultura; confirmação e CSRF obrigatórios |
| Horta vazia | Exclusão remove explicitamente associações de acesso e preserva todas as contas de usuário |
| Paginação | 12 cartões por página, sem duplicação entre páginas; seletor recebe todas as associações autorizadas |
| Revogação | Remover participação impede a próxima consulta de horta e de plantas, sem novo login |
| Legado | JSON pessoal não recebe plantas SQL por engano; arquivo legado original preservado |
| Regressão de autenticação | **64 verificações aprovadas** |
| Regressão de configuração | **18 casos aprovados** |
| Regressão de integridade | **24 verificações aprovadas** |
| Conexão/schema | Diagnóstico PDO e schema 1.1.0, 21 tabelas, aprovados |
| Sintaxe | PHP do projeto aprovado em `php -l`; ambos os arquivos JavaScript aprovados em `node --check` |

## Validação no navegador

Microsoft Edge em contextos novos e isolados, sem adicionar dependência à aplicação:

- Conta nova abre **Minhas Hortas** com mensagem de ausência de resultados.
- Cadastro de duas hortas pelos formulários; cartões e responsável obtidos das APIs reais.
- Seletor muda o conjunto de plantas; cadastro pelo modal existente persiste no MySQL da horta selecionada.
- Edição de horta atualiza cartões e opções; exclusão vazia exige confirmação; horta com plantas mostra erro de integridade no próprio diálogo.
- Cartões e formulário em viewport de 390 × 844 sem overflow horizontal.
- Conta com papel leitor vê consultas e não recebe controles de edição/exclusão; botão de cadastro de plantas fica desabilitado.
- Nome de horta contendo marcação HTML é exibido como texto, sem criar imagens ou executar código.
- Snapshots de umidade/água nulos renderizam sem erro JavaScript.
- Resposta de plantas artificialmente atrasada é descartada quando a seleção já mudou para outra horta.
- Nenhum erro JavaScript de aplicação nos fluxos testados.

Os testes de navegador usaram fixtures removidas ao final, APIs reais e cópias temporárias do frontend. Não foi instalado pacote/framework no projeto para realizar a automação.

## Preservação e schema

Não houve alteração de DDL nem nova migração SQL: o schema corrigido já continha `horta`, `usuario_horta`, `estrutura` e `planta` com os relacionamentos necessários. A instalação continua em 1.1.0.

CSS, configuração Tailwind, imagem e JSON legado original foram preservados. A interface principal passou a consumir plantas SQL por horta; o endpoint sem `?horta` conserva o JSON pessoal anterior. Nenhum dado legado foi apagado ou atribuído automaticamente a um usuário/horta.

Servidores PHP temporários foram encerrados, fixtures removidas e a instância MySQL temporária foi encerrada ao concluir a validação. Credenciais de teste não fazem parte dos arquivos entregues.

## Arquivos novos

- `Hydroponex_2/hydro/Hydroponexteste2/php/api/hortas.php`
- `Hydroponex_2/hydro/Hydroponexteste2/php/services/gardens.php`
- `Hydroponex_2/hydro/Hydroponexteste2/php/services/garden_plants.php`
- `Hydroponex_2/hydro/Hydroponexteste2/hortas.js`
- `database/tests/hortas.py`
- `database/HORTAS.md`
- `database/VALIDACAO-HORTAS.md`

## Arquivos modificados

- `Hydroponex_2/hydro/Hydroponexteste2/index.html`
- `Hydroponex_2/hydro/Hydroponexteste2/script.js`
- `Hydroponex_2/hydro/Hydroponexteste2/php/api/plantas.php`
- `Hydroponex_2/hydro/Hydroponexteste2/README-CRUD.md`
- `database/AUTENTICACAO.md`
- `database/README.md`
- `README.md`

Os caminhos acima são relativos à raiz do projeto.

## Limites deliberados

Esta etapa não cria administrador global, convites/gestão de membros, transferência de proprietário, CRUD independente de equipamentos, integração postal externa ou arquivamento de hortas. Responsáveis vêm das associações existentes e o criador é proprietário. Estruturas com dependências não são apagadas por efeito colateral de excluir uma horta.

CEP é opcional e deve existir no catálogo. Exclusão física exige horta vazia. JSON legado exige importação administrativa explícita, com backup e corte de escrita; os dois modos não se sincronizam. Sensores e gráficos demonstrativos anteriores não se tornam medições reais por esta entrega.

Não foram executados testes de carga distribuída ou implantação em produção. Rotas, payloads, permissões, regras de exclusão e procedimentos de uso/teste estão em [HORTAS.md](HORTAS.md).
