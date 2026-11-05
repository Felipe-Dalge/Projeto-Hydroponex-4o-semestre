# Entrega MariaDB/XAMPP — 28/10/2026

## Resultado executado

MariaDB **10.4.32**, PHP **8.2.12**, binários reais do XAMPP desta máquina. Instalador importado integralmente: 21 tabelas, 31 FKs, InnoDB/utf8mb4_unicode_ci, schema lógico 1.3.0. Conta de aplicação de teste com apenas SELECT/INSERT/UPDATE/DELETE. Nenhuma base existente substituída.

| Teste executado no MariaDB | Aprovados |
|---|---:|
| Configuração de ambiente/DSN | 18 |
| Perfis e versões de servidor | 8 |
| Integridade real (CHECK/FKs/transações) | 24 |
| Autenticação e perfil | 64 |
| Hortas | 62 |
| Plantas/importação idempotente | 72 |
| Infraestrutura/catálogos | 83 |
| Sensores/monitoramentos/dashboard | 92 |
| Total das suítes | **423** |

Além das suítes: diagnóstico MariaDB com --schema aprovado; seed importado; CHECK de sigla minúscula e JSON inválido rejeitados efetivamente com 4025. Migrações MariaDB 002, 003 e 004 executadas em cópia lógica 1.0.0 com dados preexistentes; todas as colunas originais dos usuários, hortas, vínculos, sensores, eventos e leituras comparadas antes/depois e preservadas. Instalação e atualização foram testadas como caminhos distintos.

Edge/Playwright: cadastro de sensor por formulário, nome com texto de SQL Injection armazenado literalmente sem execução, persistência após reload, leitura manual fora dos limites, dashboard com alerta, largura 390 px sem overflow, arquivos privados bloqueados e ausência de erros JavaScript. As suítes HTTP também cobrem CSRF, validações, leitores/gestores, isolamento entre usuários/hortas, exclusões com dependências, transações sem gravação parcial e paginação.

Apache real do XAMPP em instância temporária isolada, sem alterar sua configuração persistente: página e sessão 200; .env e armazenamento privados 403; cadastro, login e identidade usando PHP/SetEnv/PDO e o MariaDB isolado aprovados. Sintaxe PHP e JavaScript aprovada. A importação SQL foi executada pelo cliente MariaDB; os cliques de importação no phpMyAdmin não foram automatizados. O mesmo arquivo e procedimento foram documentados para importação manual.

## Ocorrência no serviço original e limites

A versão instalada foi identificada no serviço original em 3306, mas CREATE USER falhou com erro Aria 176 (checksum). Não houve reparo nem alteração de tabelas de outros projetos. Os testes completos usaram instância limpa isolada dos **mesmos binários 10.4.32**, em 127.0.0.1:13317, e Apache temporário em 18082. O serviço original pode precisar de recuperação administrativa antes de receber a instalação; não declarar esse serviço como saudável. Não apague arquivos do datadir como solução genérica.

A versão MariaDB liberada no código é 10.4.32 (também reconhece prefixo de compatibilidade 5.5.5-). Outras versões são rejeitadas até validação explícita. MySQL 8 mantém seu perfil/collation original; suas suítes anteriores não foram reexecutadas neste trabalho, portanto não se declara nova homologação MySQL nesta entrega. Collations não são equivalentes para todas as cadeias Unicode; transferência real exige detecção de colisões. JSON mantém validade/conteúdo mas tem representação de armazenamento diferente.

Fixtures das suítes e do navegador removidas. Instâncias temporárias de Apache/PHP/MariaDB encerradas; segredos temporários excluídos. Bancos de migração/seed apenas de teste podem permanecer no datadir isolado desligado. Nenhuma senha real foi incluída nos arquivos ou ZIP.

## Arquivos novos

- `.htaccess` na raiz, protegendo distribuição/arquivos administrativos se publicar a árvore completa.
- `INSTALACAO_XAMPP.md`, `MIGRACAO_MARIADB.md`, `RELATORIO_MARIADB.md`.
- `database/mariadb/ANALISE_PREVIA.md` (registrada antes das correções).
- `database/mariadb/hydroponex_mariadb.sql`, `seed_mariadb.sql`, `verificar_mariadb.sql`.
- `database/mariadb/migrations/002_autenticacao.sql`, `003_infraestrutura.sql`, `004_monitoramento.sql`.
- `database/diagnostico_mariadb.php`, `database/tests/compatibilidade.php`.
- `Hydroponex_2/hydro/Hydroponexteste2/server/hydroponex.local.conf.example`.

## Arquivos modificados

- `Hydroponex_2/hydro/Hydroponexteste2/php/config/database.php`: perfil explícito por servidor/collation, CHECK/FKs ativos e mensagens seguras.
- `database/test_connection.php`: diagnóstico aceita perfil/collation do servidor homologado.
- `database/tests/integridade.php`: reconhece 4025 como CHECK MariaDB, mantendo as rejeições efetivas.
- `Hydroponex_2/hydro/Hydroponexteste2/server/router.php` e `.htaccess` dessa pasta: bloqueios adicionais de arquivos privados/administrativos.
- `.env.example`, `README.md`, `database/README.md`: referência e direcionamento ao guia MariaDB.

Os endpoints, serviços de negócio, HTML, JavaScript, estilos e dados JSON originais foram analisados e reaproveitados sem alteração funcional nesta migração. Sessões, hashes, CSRF, autorização por horta, PDO preparado nativo e regras de exclusão foram preservados. `database/hydroponex.sql` e migrações/seed MySQL continuam intactos como referência. Não há reconstrução do aplicativo ou eliminação de tabelas/campos.

## Distribuição

`dist/Hydroponex-MariaDB-XAMPP.zip` contém o projeto completo atualizado e os guias. Não contém binários XAMPP, datadirs, credenciais locais, caches ou o próprio diretório dist. O ZIP foi reaberto e verificado quanto a CRC e presença dos arquivos obrigatórios. Para executar do início ao fim, siga INSTALACAO_XAMPP.md; para dados existentes, MIGRACAO_MARIADB.md. Testes CLI precisam do ambiente próprio, pois SetEnv do Apache não configura outro terminal.
