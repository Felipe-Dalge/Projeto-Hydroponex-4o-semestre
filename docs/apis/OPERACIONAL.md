# Painel operacional do agricultor

A área **Área do agricultor** abre o painel operacional após o login. Ela reúne **Início**, **Minhas hortas**, **Plantas com problemas** e **Minhas tarefas**. O proprietário e o gestor podem abrir o mesmo painel pelo menu **Painel do agricultor** na área administrativa, ou alternar a área de trabalho quando disponível.

O painel considera somente vínculos atuais em hortas ativas. As tarefas exibidas e contadas pertencem à conta autenticada, inclusive quando ela tem papel administrativo. O leitor consulta plantas e medições, com escrita desabilitada e sem acesso a tarefas. A execução, verificação e auditoria usam o [módulo de tarefas](TAREFAS.md).

## Dados e critérios

- As plantas em **Alerta** precedem as de **Atenção**. Os filtros permitem escolher uma horta, estado e tipo. **Todos os estados** também mostra plantas em Normal. A lista e os detalhes usam os valores gravados no banco.
- Os indicadores numéricos do cadastro são identificados como cadastro manual/legado. A origem das medições distingue registro manual, dispositivo, importação e simulação. A última leitura da lista considera sensores diretamente vinculados à planta.
- Uma medição com mais de 24 horas recebe o aviso de desatualização. Data ausente ou futura recebe indicação própria. Essa faixa é um critério de atualização do painel. O estado Normal informado no cadastro continua acompanhado da disponibilidade dos dados.
- Os detalhes exibem os limites registrados em cada leitura e destacam os valores que os ultrapassaram. Os registros de observação e intervenção exibem a descrição e a autoria informadas. O painel apresenta os fatos registrados sem atribuir uma causa automática.
- **Concluídas nos últimos 7 dias** conta tarefas atualmente concluídas cuja data de conclusão está persistida. Reabrir uma tarefa limpa sua conclusão atual. Repetir a mesma atualização de estado preserva a data.
- **Histórico** lista as tarefas persistidas da própria conta em todos os estados. As demais opções filtram pendentes, em andamento, em verificação, concluídas e canceladas. Os detalhes mostram as ações permitidas, o histórico de eventos e as intervenções. O estado da planta continua sendo controlado pelo seu cadastro.

## Vínculos opcionais com plantas

A migração 007 adiciona `id_planta` às tarefas e aos registros de trabalho. A chave estrangeira composta exige que planta e registro pertençam à mesma horta. Tarefas e registros anteriores permanecem gerais da horta. Nos detalhes de uma planta aparecem os itens que têm vínculo explícito com ela.

O formulário administrativo de tarefas em **Trabalho na horta** permite escolher uma planta. O formulário de registros nessa tela também oferece esse vínculo. O agricultor pode salvar uma observação ou intervenção diretamente nos detalhes da planta. A autoria vem da sessão.

A exclusão lógica da planta mantém o histórico. Uma planta com tarefas ou registros vinculados exige tratamento administrativo do histórico antes de transferência entre hortas. As novas chaves usam `RESTRICT`. As datas de conclusão anteriores à migração ficam desconhecidas e não entram no indicador dos últimos sete dias.

## API

`GET php/api/operacional.php` exige sessão autenticada e usa as associações vigentes em `usuario_horta`. O parâmetro `recurso` escolhe a consulta.

| Recurso | Resposta e parâmetros |
| --- | --- |
| `visao` | Hortas acessíveis e resumo. `horta` opcional filtra o resumo e preserva as opções do seletor. A interface usa essa consulta para reutilizar a agregação. |
| `resumo` | Quantidades de hortas, plantas em Atenção e Alerta, tarefas pendentes, em andamento e concluídas recentemente. `horta` opcional. |
| `hortas` | Nome, localização, papel, plantas, Atenção, Alerta e tarefas próprias pendentes. `horta` opcional. |
| `plantas` | `horta`, `estado`, `tipo` e `pagina` opcionais. Estado omitido seleciona Atenção e Alerta. Aceita `todas`, `Normal`, `Atenção` ou `Alerta`. |
| `tarefas` | `horta`, `estado` e `pagina` opcionais. Estado aceita `pendente`, `em_andamento`, `em_verificacao`, `concluida`, `cancelada` ou `historico`. |
| `detalhes` | Exige `horta` e `planta`. Retorna planta, medições diretamente relacionadas, registros, tarefas próprias e permissão para registrar trabalho. |
| `registros` | Exige `horta` e `planta`. `pagina` opcional para consultar mais observações e intervenções. |

As listas paginadas devolvem `itens`, `pagina` e `mais`, com 20 itens por página. Os detalhes retornam até 20 leituras e um `proximo_cursor`. As leituras seguintes usam a API existente `monitoramentos.php?horta=ID&planta=ID&limite=20&cursor=...`. Tarefas e registros usam os endpoints existentes com sessão e CSRF. A criação aceita `id_planta` opcional, validado no servidor.

Visitante recebe `401`, horta ou planta fora do escopo recebe `404` e filtro inválido recebe `422`. Os indicadores do navegador são atualizados após confirmar a gravação.

## Atualizar e testar

Faça backup e aplique a [migração 008 MariaDB](../../database/mariadb/migrations/008_gestao_tarefas.sql) em uma base 1.6.0 antes de publicar esta versão. O esquema de instalação novo já inclui 1.7.0. [Guia de migração](../banco-de-dados/MIGRACAO_MARIADB.md)

Em uma base descartável sem usuários, execute `python tests/operacional.py --executar --php C:/xampp/php/php.exe`. [Preparação dos testes](../desenvolvimento/README.md)

[Relatório de testes e arquivos da etapa](../desenvolvimento/VALIDACAO-OPERACIONAL.md)
