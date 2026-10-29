# Validação de autenticação — 28/10/2026

Ambiente: PHP 8.2.12, PDO MySQL, MySQL Community 8.0.44 em instância temporária própria, conta com SELECT/INSERT/UPDATE/DELETE sem DDL e schema 1.1.0. As APIs foram exercitadas em cópias temporárias do aplicativo; os testes não gravaram usuários ou plantas no ambiente real do projeto. O MariaDB local não foi alterado.

## Resultados

| Verificação executada | Resultado |
| --- | --- |
| Instalação do schema novo | 21 tabelas; diagnóstico de conexão/schema 1.1.0 aprovado |
| Migração 1.0.0 → 1.1.0 com seed | Aprovada; IDs, nomes, e-mails, hashes e estado dos usuários preservados; colunas novas inicializadas corretamente |
| `tests/autenticacao.py` | **64 verificações HTTP/SQL aprovadas** |
| Visitantes e contas de terceiros | APIs privadas recusam visitantes; campos/IDs de terceiros não concedem edição ou exclusão |
| Cadastro | Validação, senha curta, proibição de campos de privilégio, índice de e-mail único e resposta idêntica para duplicata/novo usuário |
| Login | Hash bcrypt custo 12, credenciais reais, mensagem idêntica para conta ausente/inativa ou senha errada, ID de sessão regenerado e ID antigo sem acesso |
| Sessões | Recarga de identidade, revogação em outros dispositivos, expiração por 30 minutos sem atividade e limite absoluto de 12 horas |
| Perfil e senha | Nome persistido, e-mail duplicado recusado, senha atual obrigatória para e-mail/senha, confirmação da nova senha, token pendente invalidado e senha antiga deixa de funcionar |
| CSRF | Obrigatório no cadastro, alterações privadas e logout; visitantes não obtêm mutação autenticada |
| Exclusão | Confirmação textual e senha, bloqueio do último proprietário ativo, anonimização, revogação de outras sessões, preservação de horta compartilhada e FK de histórico |
| Novo cadastro após exclusão | E-mail liberado, outro ID de usuário, sem herdar identidade ou recursos anteriores |
| Papéis de horta | Proprietário gerencia; gestor escreve sem gerir membros; leitor somente consulta; não membro recebe 403 |
| Limitação | Cinco tentativas por e-mail persistem mesmo com cookies novos; janela expirada libera; limite por IP bloqueia distribuição entre e-mails |
| JSON | Arquivos isolados por conta; novo usuário não recebe arquivo legado sem proprietário; terceiros não alteram plantas alheias |
| Arquivos privados | Router bloqueia storage/config e caminhos com segmentos/codificação; regras Apache adicionadas |
| Logs | Sem senhas de teste e sem credenciais da conexão |
| Testes anteriores de configuração | **18 casos aprovados** |
| Testes anteriores de integridade | **24 verificações aprovadas** |
| Sintaxe | Todos os arquivos PHP do projeto aprovados em `php -l`; JavaScript aprovado em `node --check` |

As regras de papéis foram chamadas por endpoint de fixture existente **apenas na cópia de teste**. O fixture de expiração também existe só nessa cópia. Não foram publicados endpoints para testar papéis/expiração ou alterar artificialmente o estado de uma sessão.

## Teste no navegador

Executado com Microsoft Edge em contexto novo e isolado, por automação de navegador, sem adicionar dependência à aplicação:

- Acesso direto a `#dashboard` sem sessão redireciona para login.
- Campos de login começam vazios; um perfil falso inserido previamente em `localStorage.hydro_user` é descartado.
- Formulários reais de cadastro e login funcionam com usuário novo.
- Recarregar a página restaura identidade pela sessão do servidor.
- Editar nome pelo formulário atualiza cabeçalho e banco.
- `localStorage` mantém apenas o tema, sem identidade/token/senha.
- Perfil em viewport móvel de 390 × 844 sem overflow horizontal.
- Formulário de exclusão exige senha e confirmação, encerra acesso e bloqueia nova navegação privada.
- Nenhum erro JavaScript de aplicação durante o fluxo.

## Preservação

CSS, configuração Tailwind, imagem e `php/storage/plantas.json` originais continuam idênticos byte a byte aos membros do ZIP de referência. Senhas demonstrativas e perfil fictício foram removidos do HTML/JavaScript. O estilo visual existente foi mantido e os novos campos reutilizam suas classes.

O schema foi ampliado por migração aditiva; nenhuma tabela original foi removida. Fixtures foram removidas do banco de testes e servidores PHP temporários foram encerrados. A instância MySQL temporária foi encerrada ao concluir.

## Arquivos novos

- `Hydroponex_2/hydro/Hydroponexteste2/php/config/auth.php`
- `Hydroponex_2/hydro/Hydroponexteste2/php/services/users.php`
- `Hydroponex_2/hydro/Hydroponexteste2/php/api/auth/cadastro.php`
- `Hydroponex_2/hydro/Hydroponexteste2/php/api/auth/login.php`
- `Hydroponex_2/hydro/Hydroponexteste2/php/api/auth/logout.php`
- `Hydroponex_2/hydro/Hydroponexteste2/php/api/auth/me.php`
- `Hydroponex_2/hydro/Hydroponexteste2/php/api/auth/senha.php`
- `Hydroponex_2/hydro/Hydroponexteste2/php/api/perfil.php`
- `Hydroponex_2/hydro/Hydroponexteste2/.htaccess`
- `Hydroponex_2/hydro/Hydroponexteste2/php/storage/.htaccess`
- `Hydroponex_2/hydro/Hydroponexteste2/php/config/.htaccess`
- `Hydroponex_2/hydro/Hydroponexteste2/php/services/.htaccess`
- `database/migrations/002_autenticacao.sql`
- `database/tests/autenticacao.py`
- `database/AUTENTICACAO.md`
- `database/VALIDACAO-AUTH.md`

## Arquivos atualizados

- `Hydroponex_2/hydro/Hydroponexteste2/php/config/config.php`
- `Hydroponex_2/hydro/Hydroponexteste2/php/api/session.php`
- `Hydroponex_2/hydro/Hydroponexteste2/php/api/plantas.php`
- `Hydroponex_2/hydro/Hydroponexteste2/server/router.php`
- `Hydroponex_2/hydro/Hydroponexteste2/index.html`
- `Hydroponex_2/hydro/Hydroponexteste2/script.js`
- `Hydroponex_2/hydro/Hydroponexteste2/README-CRUD.md`
- `database/hydroponex.sql`
- `database/diagrama.md`
- `database/test_connection.php`
- `database/verificar.sql`
- `database/README.md`
- `.env.example`
- `.gitignore`
- `README.md`

Todos os caminhos são relativos à raiz do projeto.

## Limites e decisões

Não há administrador global na modelagem; não foi criada API de gerenciamento de outras contas nem promoção pelo campo descritivo `tipo`. Também não há envio de recuperação/verificação por e-mail: a tela deixa a indisponibilidade explícita.

O CRUD JSON continua pessoal e separado por usuário; compartilhamento de plantas por horta depende da migração planejada desse CRUD para MySQL. O helper de papéis está disponível e foi testado, mas uma interface de gestão de membros/hortas não foi acrescentada a esta etapa de contas.

Exclusão é anonimização definitiva de perfil e revogação de acesso, com tombstone para preservar FKs. Não é eliminação física de todos os dados agronômicos ou backups. A política está documentada e aparece na tela de confirmação.

Não foram testadas implantação Apache/Nginx/IIS, carga distribuída, entrega de e-mail ou restauração em ambiente produtivo. Antes de executar localmente com o banco existente, aplique a migração 002 uma vez e configure a chave estável conforme [AUTENTICACAO.md](AUTENTICACAO.md).
