# Hydroponex — CRUD de plantas em PHP + MySQL

O CRUD existente do frontend (`index.html`, `script.js`, `style.css`) continua intacto na interface e utiliza `fetch()` com respostas JSON. O arquivo antigo `php/storage/plantas.json` é apenas um registro legado: **não é mais lido ou gravado** pela API. Não há migração automática.

## Requisitos e instalação (XAMPP)

1. Instale PHP **8.2+** e MySQL 8+ (ou MariaDB recente), habilite `pdo_mysql` e `mbstring` no `php.ini`.
2. Inicie **Apache e MySQL** no XAMPP.
3. No phpMyAdmin, importe `database/schema.sql` (cria `hydroponex` e tabela `plantas`). Não execute o SQL antigo em `CRUD e HTML/...`: ele usa sintaxe Oracle (`NUMBER`, `VARCHAR2`), incompatível com MySQL.
4. Copie esta pasta (`Hydroponexteste2`) para `xampp/htdocs/Hydroponexteste2` e acesse `http://localhost/Hydroponexteste2/`.
5. Em XAMPP local padrão, a conexão usa `127.0.0.1:3306`, banco `hydroponex`, usuário `root` e senha vazia. Configure de preferência via variáveis de ambiente `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASSWORD`, `APP_ENV=local`. **Não use root ou senha vazia em produção.**

Alternativa de execução local (com MySQL rodando): `php -S localhost:8000 server/router.php` e `http://localhost:8000/`.

## Operações da API

- `GET php/api/plantas.php` lista; `GET php/api/plantas.php?id=1` consulta.
- `POST php/api/plantas.php` cria (JSON: `nome`, `bancada`, `temp`, `nutrientes`).
- `PUT` / `PATCH php/api/plantas.php` atualiza (JSON inclui `id` e os campos a alterar).
- `DELETE php/api/plantas.php` exclui (JSON: `id`).
- Para operações de escrita, envie `X-CSRF-Token`, obtido de `GET php/api/session.php`, mantendo o cookie de sessão. O frontend faz isso automaticamente.
- Os IDs são `AUTO_INCREMENT`. Uma tabela nova começa vazia. Dados anteriores do JSON não são importados automaticamente.

## Segurança e limites

PDO singleton, `ERRMODE_EXCEPTION`, `FETCH_ASSOC`, `EMULATE_PREPARES=false`, statements preparados em todas as queries, UTF8MB4, validação no servidor, escape HTML na interface, método HTTP restrito, CSRF `hash_equals`, cookie HttpOnly/SameSite Strict/Secure sob HTTPS, log de erros sem expor detalhes. HTTPS e HSTS são obrigatórios quando `APP_ENV=production`; configure também HTTPS, CSP, Apache `DocumentRoot` e usuário SQL com privilégios mínimos.

**IMPORTANTE:** O login demonstrativo presente no JavaScript (`localStorage`) **não autentica usuários no servidor**. Sem autenticação real, qualquer visitante que obtenha a própria sessão CSRF pode manipular as plantas. Este CRUD é apropriado para testes locais, **não para publicação pública**. Para produção, criar login com `password_hash`/`password_verify`, `session_regenerate_id(true)` e controle de autorização em todos os endpoints. Isso não pode ser substituído pelo token CSRF.

## Verificações manuais sugeridas

Crie, liste, edite, exclua e recarregue a página; verifique a persistência no phpMyAdmin. Tente escrever sem CSRF (403), cadastrar com temperatura fora de 0..60 (400), consultar ID inexistente (404), enviar `nome` com tags HTML (deve aparecer escapado na tabela) e texto de SQL Injection no nome (deve permanecer texto sem alterar a consulta). Refaça os testes após configurar MySQL.
