# Análise anterior às correções — 28/10/2026

Inspecionados todos os 80 arquivos atuais: instalador, seed, verificações, migrações, testes, configuração PDO/sessão/autenticação, serviços e endpoints, HTML/JavaScript, router, .htaccess e documentação. Ambiente encontrado: PHP do XAMPP e servidor ativo **10.4.32-MariaDB** em 127.0.0.1:3306. Nenhuma base existente será substituída; testes usarão banco exclusivo.

Incompatibilidades identificadas antes da implementação:

- PDO rejeita explicitamente MariaDB e fixa collation MySQL 0900; diagnóstico repete essa exigência.
- DDL usa utf8mb4_0900_ai_ci e REGEXP_LIKE, indisponíveis no MariaDB instalado. Alternativas: utf8mb4_unicode_ci e REGEXP com operando BINARY para sensibilidade a maiúsculas.
- Migração 004 usa DROP CHECK; MariaDB exige DROP CONSTRAINT. Migrações 002/003 também fixam collation 0900.
- Testes de integridade esperam erro CHECK 3819; MariaDB retorna 4025. É preciso reconhecer o código equivalente, sem aceitar comandos inválidos como sucesso.
- JSON MariaDB é LONGTEXT com validação JSON, diferente do armazenamento binário MySQL. O uso atual é auditoria, sem índices/consultas por caminhos JSON; preservar o payload válido é suficiente, conferindo round-trip.
- Comparações Unicode não são perfeitamente idênticas entre collations: uma transferência de dados deve detectar colisões de chaves únicas no destino e parar, nunca deduplicar silenciosamente.
- CHECK, FKs compostas, DECIMAL, DATETIME(6), UTC, AUTO_INCREMENT, transações e ROW_NUMBER precisam de testes reais, especialmente o dashboard e limites históricos.
- Router e Apache bloqueiam diretórios PHP privados, mas precisam bloquear também dotfiles, backups e arquivos administrativos se a árvore completa for colocada em htdocs.
- Documentação atual exige MySQL: será mantida como histórico, com novo guia MariaDB prevalecendo para XAMPP.

Referências: [REGEXP](https://mariadb.com/docs/server/reference/sql-functions/string-functions/regular-expressions-functions/regexp), [CHECK](https://mariadb.com/docs/server/reference/sql-statements/data-definition/constraint), [JSON](https://mariadb.com/docs/server/reference/data-types/string-data-types/json).
