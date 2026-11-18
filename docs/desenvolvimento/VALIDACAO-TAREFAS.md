# Validação da Etapa 4

Executada em Windows com PHP 8.2.12 e MariaDB 10.4.32 do XAMPP, em bases descartáveis de uma instância isolada em loopback.

## Resultados

| Suíte | Verificações aprovadas |
| --- | --- |
| Gestão de tarefas | 50 |
| Painel operacional | 46 |
| Colaboração | 50 |
| Equipe | 40 |
| Plantas e migração JSON | 72 |
| Hortas | 62 |
| Autenticação | 64 |
| Infraestrutura | 83 |
| Monitoramento | 92 |
| Integridade do banco | 24 |

O diagnóstico PDO confirmou schema 1.7.0 e 28 tabelas InnoDB com utf8mb4. O teste da migração 008 comparou as colunas anteriores de sete tabelas, confirmou a preservação dos dados, as 48 chaves estrangeiras e o evento de importação de uma tarefa antiga com autor desconhecido.

A sintaxe foi verificada em 55 arquivos PHP e 11 JavaScript. Também foram verificados IDs repetidos no HTML, links locais da documentação e espaços em branco nas alterações.

## Cenários do módulo

A suíte específica cobre criação sem responsável, atribuição a agricultor, execução pelo próprio administrador, prioridades e prazos, transições válidas, intervenções, verificação, aprovação, correção, reabertura e cancelamento. Verifica justificativas obrigatórias, autoria da sessão, registros preservados, atraso, filtros combinados e paginação.

Os testes verificam rejeição de plantas e responsáveis fora da horta, impedimento de execução por outra pessoa, remoção de vínculo na sessão atual e reatribuição ao novo responsável. Histórico e intervenções recusam edição e exclusão pelas APIs.

Dois processos PHP recebem requisições simultâneas para a mesma tarefa. Uma atualização com a versão consultada é confirmada, e a outra recebe conflito. Duas criações com a mesma chave retornam a mesma tarefa. Uma repetição da intervenção com versão anterior também recebe conflito.

A suíte verifica criação manual a partir de estado de planta ou leitura anormal, prevenção de tarefas abertas duplicadas para a mesma referência e preservação do estado da planta após concluir o serviço. Os campos de texto suportam quebras de linha e UTF-8 nos limites documentados. Os endpoints de tarefa têm limite de payload de 48 KiB para comportar texto Unicode escapado em JSON e mantêm validação individual dos campos.

## Navegador

No Edge, a conta administrativa criou uma tarefa a partir da planta em Alerta e atribuiu à agricultora. No layout móvel de 390 pixels, a agricultora iniciou, registrou intervenção e solicitou verificação. A gestão aprovou, reabriu e cancelou com justificativas. O histórico refletiu as ações e a planta continuou em Alerta.

Também foram verificadas edição de prioridade e prazo, atribuição ao próprio administrador, execução individual e conclusão. Um teste específico editou somente o título de uma tarefa com segundos no prazo e confirmou a preservação integral desse horário.

A validação encontrou uma corrida entre carregamentos da tela e o preenchimento da planta ao criar a partir de um alerta. O formulário passou a preencher as opções autorizadas antes de selecionar a planta, com revalidação do contexto após a espera.

## Arquivos principais

- `public/php/services/tasks.php` concentra estados, permissões, atribuição, auditoria, intervenções, versões e prevenção de duplicação.
- `public/php/api/tarefas.php`, `tarefa_historico.php` e `tarefa_intervencoes.php` expõem as operações.
- `public/assets/js/tarefas.js` implementa a gestão e os detalhes compartilhados.
- `public/index.html`, os scripts de navegação, colaboração e operação, e os estilos integram as telas.
- `database/mariadb/migrations/008_gestao_tarefas.sql` e sua variante MySQL atualizam a base. Os esquemas completos já incluem 1.7.0.
- `tests/tarefas.py` cobre o novo módulo. As suítes de colaboração e operação foram adaptadas ao fluxo com verificação e versão.

## Reproduzir

Faça backup, suspenda as escritas e aplique a migração 008 em uma base 1.6.0 antes de publicar o código. Na área administrativa, selecione uma horta e abra **Tarefas**. Para criar a partir de uma planta ou leitura, abra os detalhes no **Painel do agricultor** usando uma conta com permissão de gestão.

Em uma base descartável 1.7.0 sem usuários, execute `python tests/tarefas.py --executar --php C:/xampp/php/php.exe`. [Preparação do ambiente](README.md) · [Rotas e regras](../apis/TAREFAS.md)

A execução no navegador usou o servidor de desenvolvimento do PHP do XAMPP. A publicação Apache da instalação do usuário e a variante MySQL 8 dependem de validação nesses ambientes.
