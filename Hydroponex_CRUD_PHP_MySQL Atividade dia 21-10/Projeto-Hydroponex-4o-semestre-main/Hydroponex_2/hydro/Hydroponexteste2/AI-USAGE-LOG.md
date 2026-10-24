# Registro de uso de IA — CRUD PHP/MySQL

## Ferramenta e arquivos gerados
ChatGPT (GPT-6), a pedido do usuário, em 23/10/2026. Novos: `php/config/database.php`, `php/src/Repository/PlantaRepository.php`, `database/schema.sql`, arquivos `.htaccess`, este log. Alterados: `php/config/config.php`, `php/api/plantas.php`, `server/router.php`, `script.js` (comentário), `README-CRUD.md`.

## Prompt fornecido (literal)
> Você é um desenvolvedor de software sênior e irá implementar uma funcionalidade
> Missão:  Implemente o CRUD na criação de plantas agora utilizando PHP e banco de dados. por enquanto os testes estão sendo realizados em PHP e mysql por isso quero que realize ele nessas condições:
> Preciso que siga as diretrizes de segurança presentes no pdf e siga a implementação a risca

Não houve prompts v1/v2/v3 fornecidos pelo usuário. Não inventar auditorias inexistentes.

## Decisões e justificativas
- PDO em vez de mysqli: requisito do material, portabilidade e prepared statements.
- `ATTR_EMULATE_PREPARES=false`: parâmetros tratados pelo driver/banco, não concatenados na query.
- CSRF armazenado em sessão e comparado por `hash_equals`: token não fica exposto em URL nem em cookies acessíveis a scripts.
- Cookie SameSite Strict/HttpOnly e Secure sob HTTPS: mitigação de vazamento do cookie. HTTP somente para teste local.
- Nenhum cadastro/autenticação de usuário foi implementado neste escopo; por isso não se aplicam BCRYPT e `session_regenerate_id(true)` ao CRUD atual. São obrigatórios ao implementar login real.
- `PDOException` vai para `error_log`, resposta ao cliente genérica: não revela SQL ou credenciais.
- Tabela MySQL substitui `NUMBER`/`VARCHAR2` presentes no SQL Oracle de referência.

## Verificação efetuada e pendências
- `php -l` executado nos arquivos PHP e aprovado.
- Não foi possível executar consultas reais contra MySQL nesta execução: ambiente não tem servidor/driver `pdo_mysql` configurado. Testes CRUD integrados devem ser feitos no XAMPP.
- Sem autenticação no backend, o projeto permanece destinado somente a testes locais.
- Auditoria dinâmica OWASP ZAP/PHPStan não foi realizada; não afirmar que ocorreu.

## Tópicos para defesa oral
SQL Injection é entrada não confiável transformada em código SQL; parâmetros PDO mantêm dados separados. CSRF falsifica requisição do navegador; XSS injeta HTML/JS em páginas. Session fixation é reutilização maliciosa de identificador de sessão; no futuro login regenerar sessão. BCRYPT e Argon2id são algoritmos de hash de senha (nenhum é necessário enquanto não há cadastro de senha). O log armazena detalhes de exceções somente no servidor.
