# Perfis, papéis e colaboração

O perfil de entrada é gravado em `usuario.perfil_entrada` na criação da conta. O perfil **administrador** pode criar hortas próprias e recebe o papel de proprietário nessas hortas. O perfil **agricultor** começa sem hortas e entra em uma horta compartilhada ao aceitar um convite. Contas migradas mantêm a capacidade anterior de criar hortas próprias. O campo legado `usuario.tipo` permanece informativo.

## Área e autorização

O servidor oferece a área administrativa às contas que podem criar hortas ou têm vínculo de proprietário ou gestor em uma horta ativa. A área do agricultor está disponível ao perfil agricultor e a contas com pelo menos um vínculo ativo. Na área operacional, a interface prioriza o trabalho na horta e as tarefas próprias.

A área é gravada na sessão e pode ser alterada sem novo login quando ambas estão disponíveis. O backend usa o papel vigente em `usuario_horta` e o escopo da horta para cada ação. Ao perder o último vínculo administrativo, uma conta que entrou como agricultor volta à área operacional na próxima consulta ao perfil.

## Matriz de permissões por horta

| Ação | Proprietário | Gestor | Agricultor | Leitor |
| --- | --- | --- | --- | --- |
| Consultar horta, plantas, culturas, infraestrutura, leituras, alertas e registros | Sim | Sim | Sim | Sim |
| Editar horta e gerenciar plantas, vínculos de culturas, equipamentos e sensores | Sim | Sim | — | — |
| Registrar leituras, vincular nutrientes a sensores e registrar aplicações | Sim | Sim | — | — |
| Excluir horta quando os vínculos permitirem | Sim | — | — | — |
| Emitir e revogar convites, alterar papéis e revogar acessos | Sim | — | — | — |
| Atribuir tarefas e atualizar tarefas da horta | Sim | Sim | — | — |
| Consultar e atualizar tarefas atribuídas à própria conta | Sim | Sim | Sim | — |
| Registrar observações e intervenções | Sim | Sim | Sim | — |

O proprietário pode gerenciar os papéis gestor, agricultor e leitor. A propriedade fica protegida nas operações de convite e gestão de membros. A transferência de plantas exige autorização administrativa nas duas hortas. Catálogos pessoais sem horta no endereço exigem acesso à área administrativa e preservam as regras de autoria. Com horta indicada, operações de escrita exigem permissão administrativa nessa horta.

## Fluxo na interface

1. No cadastro, selecione o perfil de entrada. No login, escolha a área ou mantenha a opção que usa a área disponível da conta.
2. Como proprietário, abra **Equipe** na área administrativa, informe o e-mail e selecione as hortas. O sistema envia o convite por SMTP. [Fluxo e rotas da Equipe](EQUIPE.md)
3. Na conta convidada, abra o link do e-mail e confira as hortas antes de aceitar. Quem recebeu um código manual em versão anterior pode aceitá-lo em **Trabalho na horta** enquanto estiver válido.
4. Proprietários e gestores podem atribuir tarefas a membros operacionais, inclusive a si mesmos. O responsável inicia o trabalho e solicita verificação. A gestão pode aprovar, pedir correções ou cancelar com justificativa. Agricultores veem apenas as tarefas que receberam. [Gestão de tarefas](TAREFAS.md)
5. Use **Observações e intervenções** para registrar o trabalho realizado. Esses registros ficam no histórico da horta e mantêm a autoria. A exclusão de uma conta continua preservando o histórico por meio do registro anonimizado do autor.

## APIs JSON

As rotas abaixo são relativas a `php/api/`. Escritas usam sessão e `X-CSRF-Token`, conforme o [contrato de autenticação](AUTENTICACAO.md). Cadastro e login também validam CSRF.

| Rota e método | Corpo ou escopo |
| --- | --- |
| `POST auth/cadastro.php` | Campos existentes e `perfil_entrada` com `administrador` ou `agricultor`. Clientes antigos que omitem o campo recebem o perfil administrador. |
| `POST auth/login.php` | E-mail, senha e `area` opcional com `administrativa` ou `agricultor`. Uma área indisponível recebe `403`. |
| `GET auth/me.php` | Inclui `perfil_entrada`, `area`, `areas`, `pode_criar_horta` e vínculos vigentes. |
| `PATCH auth/area.php` | `area`. Atualiza a área da sessão após verificar a disponibilidade. |
| `GET convites.php?horta=ID` | Lista códigos legados para o proprietário. |
| `PATCH convites.php` | `codigo` legado. A horta e o papel vêm do convite validado. |
| `DELETE convites.php?horta=ID` | `id_convite` para revogar um código legado. |
| `GET membros.php?horta=ID` | Lista membros para o proprietário. |
| `PATCH membros.php?horta=ID` | `id_usuario` do membro e `papel` escolhido pelo proprietário. |
| `DELETE membros.php?horta=ID` | `id_usuario` do membro cujo acesso será revogado. |
| `GET tarefas.php?horta=ID` | Lista tarefas conforme o papel. Proprietários e gestores também recebem a lista de responsáveis elegíveis. |
| `POST tarefas.php?horta=ID` | `titulo`, `id_responsavel` e `descricao` opcional. |
| `PATCH tarefas.php?horta=ID` | `id_tarefa` e `estado` com `pendente`, `em_andamento` ou `concluida`. |
| `GET registros.php?horta=ID` | Lista o histórico de observações e intervenções na horta. |
| `POST registros.php?horta=ID` | `tipo` com `observacao` ou `intervencao`, e `descricao`. A autoria vem da sessão. |

As rotas de horta retornam `404` para quem não tem vínculo e `403` para quem tem vínculo, mas não possui a permissão solicitada. A atualização de tarefas de agricultor usa a horta, o identificador da tarefa e a conta responsável no filtro da consulta. Convites inválidos, expirados, revogados, consumidos ou destinados a outra conta recebem `404`.

## Atualizar uma instalação

A atualização exige o schema 1.7.0. Em uma base 1.6.0, aplique a migração 008 antes de publicar esta versão. [Guia de migração](../banco-de-dados/MIGRACAO_MARIADB.md)

Para verificar os fluxos em uma base descartável, execute `python tests/colaboracao.py --executar --php C:/xampp/php/php.exe`. [Configuração dos testes](../desenvolvimento/README.md)
