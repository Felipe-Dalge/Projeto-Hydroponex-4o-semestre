# Cadastro, sessão e perfil — Hydroponex

> Versão atual: schema 1.3.0. Em banco 1.2.0, aplique 004 após backup; versões anteriores aplicam antes 002/003 conforme necessário. Instalação nova já incorpora as mudanças. Consulte [monitoramento](MONITORAMENTO.md) e [infraestrutura](INFRAESTRUTURA.md).

**Atualização de plantas:** a API é exclusivamente MySQL e exige horta selecionada. O JSON pessoal das etapas anteriores está preservado para migração, sem fallback. Veja [PLANTAS.md](PLANTAS.md) e [HORTAS.md](HORTAS.md).

## Preparar e executar

Requisitos: PHP >= 8.1 com PDO MySQL/session/json, MySQL >= 8.0.16 e schema **1.1.0**. O frontend existente foi integrado às APIs reais; não há framework novo. O arquivo de conexão e as variáveis de ambiente permanecem os da etapa PDO.

1. Faça backup do banco em uso e teste a restauração. Se já instalou o schema 1.0.0, suspenda escritas e importe **uma única vez** `database/migrations/002_autenticacao.sql` no phpMyAdmin. Ele adiciona `usuario.auth_versao`, `usuario.excluido_em`, `auth_limite` e registra a versão 1.1.0, sem apagar registros ou reescrever hashes. DDL MySQL faz commit implícito: em caso de erro, interrompa, inspecione e retome somente a parte faltante. Não reaplique o instalador sobre dados existentes.
2. Se a instalação for nova, importe `database/hydroponex.sql` em banco vazio; ele já inclui a versão 1.1.0 e **21 tabelas**. Não aplique a migração 002 depois. `seed.sql` continua opcional: seus usuários estão inativos e não têm senha utilizável. Eles não são contas de login.
3. Configure as variáveis da conexão no ambiente do processo PHP conforme o [README principal](../README.md). Crie uma conta MySQL restrita ao banco Hydroponex, com SELECT/INSERT/UPDATE/DELETE; instalação/migração de schema é feita separadamente por administrador.
4. Gere um segredo **estável**, exclusivo da instalação, com 64 caracteres hexadecimais aleatórios. Guarde-o no ambiente privado como `HYDROPONEX_AUTH_KEY`, fora do código. O valor de exemplo é um placeholder e será recusado. Não gere uma chave diferente a cada requisição.

```powershell
# Execute junto das variáveis de conexão, antes de iniciar o PHP.
# A chave é gerada diretamente no ambiente, sem aparecer na saída.
$env:HYDROPONEX_AUTH_KEY = (& C:/xampp/php/php.exe -r 'echo bin2hex(random_bytes(32));').Trim()
& C:/xampp/php/php.exe database/test_connection.php --schema
Set-Location Hydroponex_2/hydro/Hydroponexteste2
& C:/xampp/php/php.exe -S localhost:8000 server/router.php
```

O comando de geração acima serve para preparar um ambiente local novo. Para reinícios e produção, recupere **o mesmo segredo** do armazenamento privado de configurações; substituí-lo reinicia os identificadores HMAC dos limites. `.env.example` é referência, não um arquivo carregado automaticamente. Variáveis do terminal não afetam um Apache já iniciado: configure o ambiente do serviço e reinicie-o.

Abra [http://localhost:8000/](http://localhost:8000/), escolha **Criar Conta**, informe nome, e-mail e senha de 12 a 72 bytes, conclua e faça login. Não há login automático após cadastro. Em **Perfil**, altere nome/foto; para mudar e-mail ou senha, informe também a senha atual. Para excluir, informe senha atual e digite `EXCLUIR` no formulário específico.

O cadastro responde de forma igual para e-mail novo e duplicado. Isso não significa que a senha de uma conta existente foi substituída. Se o e-mail já estiver registrado, somente a senha anterior autentica. Não há confirmação de posse por e-mail nesta etapa; `email_verificado_em` continua nulo. A recuperação de senha por e-mail permanece indisponível, explicitamente indicada na tela; nenhum envio fictício é anunciado.

## Contrato das rotas

Respostas JSON: `success`, `message` quando aplicável, `data` para usuário/plantas e `csrfToken` após estabelecimento/renovação da sessão. Enviar cookies com `credentials: 'same-origin'`, `Content-Type: application/json` nas mutações e `X-CSRF-Token` obtido de `/php/api/session.php`. O token CSRF fica em memória no JavaScript; o identificador de sessão fica somente em cookie HttpOnly.

| Método e rota | Acesso e corpo |
| --- | --- |
| `GET /php/api/session.php` | Público. Inicia sessão anônima e fornece CSRF; não autentica nem expõe dados pessoais. |
| `POST /php/api/auth/cadastro.php` | Público + CSRF. `{nome,email,senha}`. Retorna 202 tanto para novo cadastro quanto para duplicata, sem enumerar usuários. |
| `POST /php/api/auth/login.php` | Público + CSRF. `{email,senha}`. Sucesso 200 com dados seguros e novo CSRF; ID da sessão regenerado. |
| `POST /php/api/auth/logout.php` | Autenticado + CSRF. `{}`. Destrói dados da sessão e expira seu cookie; encerra esta sessão, não todas as sessões. |
| `GET /php/api/auth/me.php` | Autenticado. Recupera exclusivamente a própria identidade e papéis nas hortas ativas. |
| `GET /php/api/perfil.php` | Autenticado. Mesmo conjunto seguro de dados próprios. Parâmetro `id` na URL não seleciona terceiros. |
| `PATCH /php/api/perfil.php` | Autenticado + CSRF. Campos permitidos: `nome`, `email`, `avatar_url`, `id_cep`, `numero_endereco`, `complemento`, `senha_atual`, `nova_senha`, `confirmacao_senha`. Alterações são atômicas. |
| `POST /php/api/auth/senha.php` | Autenticado + CSRF. `{senha_atual,nova_senha,confirmacao_senha}`. Revoga as outras sessões e tokens pendentes. |
| `DELETE /php/api/perfil.php` | Autenticado + CSRF. `{senha_atual,confirmacao:"EXCLUIR"}`. Exclui logicamente e anonimiza exclusivamente a conta da sessão. |
| `GET/POST/PUT/PATCH/DELETE /php/api/plantas.php` | Agora autenticado; mutações também exigem CSRF. Exige `?horta=ID`, usa somente MySQL, valida participação/papel e mantém aliases do contrato anterior. |

Exemplo de cadastro no navegador, sem armazenar segredos:

```javascript
const session = await fetch('php/api/session.php', {
    credentials: 'same-origin', cache: 'no-store'
}).then(response => response.json());
// Valores coletados dos campos do formulário, sem credenciais literais no código.
const response = await fetch('php/api/auth/cadastro.php', {
    method: 'POST', credentials: 'same-origin',
    headers: {'Content-Type': 'application/json', 'X-CSRF-Token': session.csrfToken},
    body: JSON.stringify({nome, email, senha})
});
```

Campos desconhecidos são recusados (422), incluindo `id_usuario`, `tipo`, `ativo`, `auth_versao`, `senha_hash` e atribuições de papéis. Nenhum ID recebido no corpo é usado para escolher a conta a editar/excluir. Perfil permite nome entre 1 e 100 caracteres, e-mail validado/normalizado, foto HTTPS sem credenciais embutidas, CEP existente ou nulo, número até 20 e complemento até 100 caracteres. CEP do perfil usa o ID do catálogo; não é o código postal digitado diretamente. A interface existente expõe nome/e-mail/avatar e senhas; os demais campos estão disponíveis via API.

Senhas não são aparadas ou normalizadas; limite máximo em bytes evita truncamento silencioso do bcrypt. Hashes usam `password_hash(PASSWORD_BCRYPT, cost=12)` e credenciais são verificadas com `password_verify`; hashes legados reconhecidos podem sofrer rehash após login. Tokens de senha/verificação anteriores são invalidados quando a senha ou e-mail muda. Nunca há senha/hash/versionamento interno em respostas de usuário.

Status: 400 JSON inválido, 401 sessão ausente/expirada ou credenciais inválidas, 403 CSRF/permissão/reauth incorretos, 405 método, 409 conflito de dados/último proprietário, 413 corpo grande, 415 tipo de conteúdo, 422 validação, 429 limite de tentativas, 503 conexão/configuração de autenticação indisponível e 500 erro interno genérico. Nenhuma resposta imprime SQL, DSN, senha ou stack trace. Em falha CSRF o frontend pede reenvio e renova o token na próxima tentativa, sem repetir mutações automaticamente.

## Sessões e proteção contra tentativas

- Sessão PHP estrita, cookie próprio `HYDROPONEXSESSID`, somente cookies, HttpOnly, SameSite=Lax, Secure quando servido por HTTPS, sem sessão na URL e cookie sem persistência entre sessões do navegador.
- Regeneração do ID e do CSRF após login e reautenticação sensível. Logout destrói a sessão. Inatividade máxima de 30 minutos e duração absoluta máxima de 12 horas; a coleta de sessões do PHP pode encerrar antes conforme configuração do servidor.
- Cada API privada confirma no banco que o usuário continua ativo, não excluído e com a mesma `auth_versao`. Mudança de senha/e-mail aumenta essa versão, revoga outros dispositivos e mantém apenas a sessão reautenticada atual. Exclusão revoga todos.
- Não há confiança no antigo `localStorage.hydro_user`: ele é removido. Somente a preferência de tema continua em `localStorage`; identidade, senha e CSRF não são persistidos ali. Recarregar a página consulta `auth/me.php`.
- `auth_limite` faz reserva transacional e bloqueio da linha para contar tentativas antes de verificar credenciais, inclusive em processos/sessões diferentes. São **5 tentativas por e-mail / 15 minutos**, **30 por IP / 15 minutos**, **20 cadastros por IP / hora** e **5 reautenticações sensíveis por usuário / 15 minutos**. Contam sucessos e falhas; não existe reset condicionado à existência da conta. Resposta 429 inclui `Retry-After`.
- Os identificadores de limitação são HMAC-SHA-256 de escopo + identidade com segredo estável; não são guardados e-mails/IPs brutos nessa tabela. Usa-se `REMOTE_ADDR`, sem confiar em `X-Forwarded-For` fornecido pelo cliente. Em proxy reverso, a normalização por proxies confiáveis deve ser configurada na infraestrutura antes de ampliar a implantação.
- Mensagem e status de login são iguais para conta ausente, inativa, excluída ou senha incorreta. Conta sem hash também verifica um hash fictício. O cadastro faz hashing e trata a unicidade no banco com resposta pública idêntica para duplicatas.

Em manutenção administrativa, remover limites antigos é seguro, pois já expiraram; por exemplo `DELETE FROM auth_limite WHERE janela_em < UTC_TIMESTAMP() - INTERVAL 2 DAY`. Isso deve ser executado com acesso administrativo de manutenção, sem endpoint público. Não limpar limites vigentes para desbloquear tentativas automaticamente a cada login.

## Autorização e usuários administradores

A modelagem deliberadamente **não possui administrador global**. `usuario.tipo` é descrição legada e não concede autoridade. Portanto, não foi criada API para listar/editar/excluir todas as contas, nem promoção automática de quem se cadastrar primeiro. Todo usuário pode gerenciar somente o próprio perfil.

`usuario_horta.papel` continua sendo a fonte de permissões de domínio: leitor consulta, gestor consulta/escreve recursos, proprietário também gerencia membros. `require_horta_permission()` em `php/config/auth.php` aplica essa política para as próximas APIs MySQL. `auth/me.php` informa apenas as próprias associações. O helper foi testado com os três papéis e não membros; não implica que uma tela de administração de membros tenha sido adicionada nesta etapa.

## Exclusão e dados relacionados

A exclusão faz transação e bloqueia as hortas envolvidas em ordem de ID. Bloqueia a saída do último proprietário **ativo** de horta não arquivada (409). Antes de excluir, transfira a propriedade a outro membro ativo ou arquive a horta por operação administrativa autorizada; a gestão de hortas não foi adicionada à interface de perfil.

Quando permitido, remove associações `usuario_horta` e tokens, limpa senha, nome, endereço, avatar e e-mail reais, define `ativo=0`, `excluido_em` e aumenta `auth_versao`. O e-mail de tombstone é aleatório em `deleted.invalid`; o e-mail real fica disponível para novo cadastro, que recebe outro ID e não herda recursos antigos. Não há fluxo público de restaurar essa conta.

Mantém um registro técnico anonimizado do usuário para as FKs de autoria em monitoramentos/aplicações de nutrientes. Não apaga hortas, plantas, sensores, reservatórios, medições ou outro histórico de colaboradores. Não se usa cascata para apagar cultivo. Dados de cultivo pessoais em JSON permanecem inacessíveis após exclusão, associados ao antigo ID para futura migração/retenção; a exclusão de perfil não é um processo geral de eliminação de backups ou de todo o conteúdo agronômico. Essa política está informada na tela. Retenção física definitiva exige procedimento administrativo próprio.

## JSON preservado e CRUD atual

O arquivo global e eventuais arquivos pessoais estão preservados e protegidos por HTTP. A API não os lê ou grava: é obrigatório selecionar a horta e a persistência usa somente MySQL, com validação de `usuario_horta.papel`. O contrato antigo de campos foi mantido por aliases. Migração idempotente, exclusão lógica, transferência e filtros: [PLANTAS.md](PLANTAS.md).

O router e os arquivos `.htaccess` continuam bloqueando storage/config/services. Configure regras equivalentes em servidores sem suporte a `.htaccess`, use HTTPS, logs privados e não publique a raiz inteira do repositório.

## Testes reproduzíveis

1. Para verificar ambiente/schema: `php database/test_connection.php --schema`.
2. Para configuração sem servidor: `php database/tests/configuracao.php`.
3. Para integridade (somente desenvolvimento): `php database/tests/integridade.php`.
4. Para integração HTTP de autenticação/autorização: disponibilize um **banco separado**, com schema 1.1.0, sem usuários e nome terminado em `_test` ou `_tests`; configure ambiente e chave para ele. Requer Python >= 3.10, somente biblioteca padrão.

```powershell
# Executar da raiz, com ambiente apontando exclusivamente ao banco de testes.
python database/tests/autenticacao.py --executar --php C:/xampp/php/php.exe
```

O teste inicia PHP em porta temporária e cópia temporária da aplicação, não altera o JSON original e remove suas fixtures ao final. Não execute em paralelo contra o mesmo banco nem use uma base com pessoas reais. A conta de teste precisa de DML, sem DDL. Para criar o schema inicial use administrador separado.

O teste cobre cadastro, duplicatas, hashes, login genérico, sessões antigas, CSRF, validação, usuários terceiros, troca de e-mail/senha, revogação, tokens, papéis, isolamento JSON, exclusão com histórico, último proprietário, logout e limitação entre cookies novos. Resultados executados: [VALIDACAO-AUTH.md](VALIDACAO-AUTH.md).

Referências: [PHP password_verify](https://www.php.net/manual/en/function.password-verify.php), [regeneração de sessão](https://www.php.net/manual/en/function.session-regenerate-id.php), [OWASP — autenticação e limitação de tentativas](https://cheatsheetseries.owasp.org/cheatsheets/Authentication_Cheat_Sheet.html).
