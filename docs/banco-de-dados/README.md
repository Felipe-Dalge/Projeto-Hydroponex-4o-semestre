# Banco de dados

A instalação XAMPP usa MariaDB 10.4.32, InnoDB, `utf8mb4_unicode_ci` e schema lógico 1.7.0. O instalador valida a versão antes de reutilizar um banco existente. A conta da aplicação tem permissões `SELECT`, `INSERT`, `UPDATE` e `DELETE` restritas ao banco do projeto.

A Etapa 5 mantém o schema 1.7.0. A execução de teste conferiu a instalação limpa, a sequência de migrações 005 a 008 sobre uma base 1.3.0, preservação das colunas antigas, 48 chaves estrangeiras e backup/restauração. [Relatório final](../desenvolvimento/VALIDACAO-FINAL.md)

- [Esquema MariaDB para banco novo](../../database/mariadb/hydroponex_mariadb.sql)
- [Dados fictícios opcionais](../../database/mariadb/seed_mariadb.sql)
- [Consultas de verificação MariaDB](../../database/mariadb/verificar_mariadb.sql)
- [Modelo relacional](diagrama.md)
- [Migração de versões e transferência MySQL → MariaDB](MIGRACAO_MARIADB.md)
- [Importação de plantas do JSON legado](../apis/PLANTAS.md)

O script de demonstração manual cria contas inativas. Para uma demonstração com login, use a opção do instalador e forneça a senha solicitada. A migração de JSON pode operar em modo de simulação antes de gravar. O JSON incluído em `public/php/storage/` fica protegido pelo servidor.

`database/` também guarda o [esquema MySQL 8](../../database/hydroponex.sql), suas [migrações](../../database/migrations/) e o [DDL Oracle original](../../database/legado/criacao_tabelas.sql). O [histórico da modelagem MySQL](../historico/MODELAGEM-MYSQL.md) e o [roteiro de migração de banco legado](LEGADO.md) documentam esses artefatos.
