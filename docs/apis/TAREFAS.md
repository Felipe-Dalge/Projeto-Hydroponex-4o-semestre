# Gestão de tarefas agrícolas

A área administrativa ganhou o menu **Tarefas**, ligado à horta selecionada. Proprietários e gestores podem criar tarefas, definir prioridade e prazo, atribuir um responsável operacional ou manter a tarefa sem responsável. O administrador pode atribuir a si mesmo e executar o ciclo completo.

No **Painel do agricultor**, **Minhas tarefas** mostra as tarefas da conta autenticada. Os detalhes são compartilhados com a área administrativa e exibem dados, ações disponíveis, intervenções e histórico. O papel é revalidado no servidor em cada requisição.

## Estados e ações

| Estado atual | Ação | Papel necessário | Resultado |
| --- | --- | --- | --- |
| Pendente | Iniciar | Responsável com vínculo operacional | Em andamento |
| Em andamento | Solicitar verificação | Responsável com vínculo operacional | Em verificação |
| Em verificação | Aprovar e concluir | Proprietário ou gestor | Concluída com data persistida |
| Em verificação | Solicitar correções | Proprietário ou gestor, com justificativa | Em andamento |
| Concluída | Reabrir | Proprietário ou gestor, com justificativa | Pendente, com conclusão atual limpa |
| Pendente, em andamento ou em verificação | Cancelar | Proprietário ou gestor, com justificativa | Cancelada |

A edição administrativa permite alterar título, descrição, prioridade, prazo e responsável enquanto a tarefa está aberta. Alterar o responsável exige justificativa e reinicia a execução como pendente. As intervenções anteriores permanecem no histórico. A tarefa cancelada fica encerrada.

Somente o responsável pode registrar observações e intervenções na tarefa em andamento ou em verificação. O registro contém procedimento ou observação, data de realização, autoria da sessão e observações complementares. A data pode representar trabalho anterior, mas o servidor recusa horários mais de cinco minutos no futuro.

A conclusão registra a verificação administrativa do serviço. A aplicação mantém o estado da planta conforme o cadastro. A recuperação da planta exige inspeção, medição ou uma atualização autorizada.

## Prioridade, prazo e problemas

As prioridades disponíveis são baixa, média, alta e crítica, apresentadas por texto. A criação usa média quando a prioridade é omitida. Atraso corresponde a prazo vencido em tarefa que ainda não foi concluída ou cancelada.

O painel operacional permite que a gestão crie uma tarefa a partir do estado Atenção ou Alerta de uma planta, ou de uma leitura diretamente associada que excedeu seus limites registrados. A criação continua sendo uma decisão manual.

- Origem `estado_planta` exige uma planta ativa em Atenção ou Alerta. O sistema registra como referência o identificador e o estado atual.
- Origem `leitura` exige o identificador de uma leitura da mesma horta fora dos limites gravados naquela coleta. A planta é derivada do sensor quando houver esse vínculo direto.
- Origem `manual` permite informar o problema observado em texto e vincular opcionalmente uma planta.

Uma referência de problema ou leitura com tarefa aberta recebe conflito ao tentar criar outra tarefa. O texto do problema manual é normalizado para detectar repetição. A interface também gera uma chave por formulário para que um reenvio da mesma criação devolva a tarefa já criada. Reutilizar essa chave com conteúdo diferente recebe conflito. A criação de tarefas gerais sem descrição de problema conserva sua utilização para rotinas agrícolas.

## Persistência e integridade

A migração 008 amplia `horta_tarefa` e cria `tarefa_historico` e `tarefa_intervencao`. As duas novas tabelas referenciam a tarefa junto com a horta por chave composta. A leitura de origem também precisa pertencer à horta. O vínculo opcional da planta mantém a chave composta existente.

Responsáveis e autores referenciam `usuario`. A atribuição exige vínculo operacional vigente na horta. O responsável continua identificado após a remoção de seu vínculo para preservar a rastreabilidade. A lista e os detalhes sinalizam **Responsável sem acesso operacional**, e a gestão pode reatribuir a tarefa.

O servidor grava tarefa, incremento da versão e auditoria na mesma transação. Atualizações exigem a versão consultada, e duas alterações concorrentes com a mesma versão resultam em uma gravação e um conflito. O registro de uma intervenção também avança a versão, impedindo aprovação baseada em uma versão anterior ao novo trabalho.

Tarefas anteriores à migração preservam seus campos e recebem prioridade média e versão 1. A migração grava um evento específico com autor desconhecido e o estado conhecido, sem fabricar ações de criação, execução ou aprovação anteriores.

## APIs

Todas as rotas ficam em `php/api/`. Exigem sessão autenticada. Escritas exigem `X-CSRF-Token`. [Autenticação](AUTENTICACAO.md)

| Rota | Método | Campos ou filtros |
| --- | --- | --- |
| `tarefas.php?horta=ID` | GET | `estado`, `prioridade`, `responsavel`, `atrasadas=1`, `pagina`. Responsável aceita um ID ou `sem_responsavel`. |
| `tarefas.php?horta=ID&id=ID` | GET | Dados, versão, ações permitidas, primeira página de intervenções e histórico. |
| `tarefas.php?horta=ID` | POST | `titulo`, `descricao`, `id_planta`, `id_responsavel`, `prioridade`, `prazo_em`, `problema`, `origem_problema`, `id_leitura`, `chave_criacao`. Título obrigatório. |
| `tarefas.php?horta=ID` | PATCH | `id_tarefa`, `versao`, `acao` e `justificativa` quando exigida. |
| `tarefas.php?horta=ID` | PATCH | Ação `editar`, `id_tarefa`, `versao` e os campos administrativos alterados. |
| `tarefa_historico.php?horta=ID&id_tarefa=ID` | GET | Histórico, com `pagina` opcional. |
| `tarefa_intervencoes.php?horta=ID&id_tarefa=ID` | GET | Intervenções e observações, com `pagina` opcional. |
| `tarefa_intervencoes.php?horta=ID` | POST | `id_tarefa`, `versao`, `tipo`, `descricao`, `realizada_em`, `observacoes` opcional. |

As ações aceitas são `iniciar`, `verificar`, `concluir`, `corrigir`, `reabrir`, `cancelar` e `editar`. Datas de entrada usam ISO 8601 com fuso horário. `id_responsavel=null` mantém ou remove uma atribuição. Na edição, omita os campos que deseja conservar.

O campo legado `estado` ainda pode indicar o destino no PATCH, acompanhado de `versao`. Ele passa pelas mesmas transições e permissões. Clientes antigos que enviavam somente um estado precisam passar a consultar a versão e seguir o ciclo de verificação.

As listas usam páginas de 20 itens. A resposta da lista de tarefas contém `data`, `pagina`, `mais`, os responsáveis elegíveis e as opções do filtro de responsável. As opções de filtro incluem responsáveis anteriormente atribuídos que perderam o vínculo.

Sessão ausente recebe `401`, papel ou ação não permitida recebe `403`, tarefa fora do escopo recebe `404`, versão desatualizada ou problema duplicado recebe `409`, e entradas inválidas recebem `422`. As APIs de histórico e intervenções preservam os registros e oferecem leitura paginada.

## Atualização e teste

Faça backup, suspenda as escritas e aplique a [migração 008 MariaDB](../../database/mariadb/migrations/008_gestao_tarefas.sql) em uma base 1.6.0 antes de publicar esta versão. A instalação nova já contém o schema 1.7.0. [Guia de migração](../banco-de-dados/MIGRACAO_MARIADB.md)

Para testar, prepare uma base descartável sem usuários com nome terminado em `_test` ou `_tests` e execute `python tests/tarefas.py --executar --php C:/xampp/php/php.exe`. [Preparação do ambiente](../desenvolvimento/README.md)

[Relatório de testes e arquivos da etapa](../desenvolvimento/VALIDACAO-TAREFAS.md)

Na revisão final, a validação de textos foi compartilhada com os registros operacionais, mantendo quebras de linha e as restrições de tamanho e caracteres. Os controles de paginação da gestão são limpos quando muda o contexto ou a consulta falha. O schema permanece 1.7.0. [Validação integrada](../desenvolvimento/VALIDACAO-FINAL.md)
