# Migração e compatibilidade MariaDB

Guia atual: INSTALACAO_XAMPP.md. Versão executada e validada: **MariaDB 10.4.32 do XAMPP**; PHP 8.2.12. Outras versões MariaDB são deliberadamente rejeitadas até a execução dos testes e ampliação explícita do perfil em database.php. O suporte anterior a MySQL 8 permanece, sem afirmar que toda versão entre produtos tem comparação Unicode idêntica.

## Decisões

A análise anterior às alterações está em database/mariadb/ANALISE_PREVIA.md. Original `database/hydroponex.sql`, seed e migrações MySQL foram preservados. Novos instalador, seed, verificação e migrações MariaDB estão em database/mariadb/.

- Collation unicode_ci substitui 0900 apenas nos arquivos MariaDB e na sessão PDO MariaDB. Mantém utf8mb4 e comparação usual sem distinção de acentos/maiúsculas, mas não promete equivalência de todas as regras Unicode/ordenação. Colisões em email/chaves únicas exigem análise antes de transferir dados.
- REGEXP BINARY substitui REGEXP_LIKE com flag c; CHECK continua rejeitando siglas minúsculas e CEP inválido. CHECK foi testado como efetivo, código MariaDB 4025.
- DROP CONSTRAINT substitui DROP CHECK na migração 004. PKs, AUTO_INCREMENT, índices, 31 FKs e regras de exclusão/atualização foram preservados; nenhuma entidade removida.
- JSON MariaDB usa LONGTEXT/utf8mb4_bin e validação JSON automática na versão testada. Payload inválido foi rejeitado. Auditoria continua armazenando o JSON integral sem depender da representação binária MySQL.
- DECIMAL, DATETIME(6), UTC, funções de data, ROW_NUMBER, prepared statements nativos e transações funcionaram nas suítes reais. CHECK e foreign_key_checks são ativados na sessão da aplicação; não se alteram configurações globais.
- PDO segue pdo_mysql, exceções, prepared statements sem emulação e múltiplas instruções desabilitadas. Senhas/sessão/CSRF/autorização continuam intactos. Não houve necessidade de reescrever CRUD ou frontend para trocar de produto.
- Apache/router bloqueiam dotfiles, backups, scripts administrativos e diretórios privados. Configuração local é externa ao diretório público; exemplo fornecido sem segredos.

Referências: [REGEXP](https://mariadb.com/docs/server/reference/sql-functions/string-functions/regular-expressions-functions/regexp), [CHECK](https://mariadb.com/docs/server/reference/sql-statements/data-definition/constraint), [JSON](https://mariadb.com/docs/server/reference/data-types/string-data-types/json).

## Nova instalação versus atualização

**Nova:** banco vazio + hydroponex_mariadb.sql. Não executar migrações novamente. Seed opcional e exclusivamente de desenvolvimento.

**MariaDB já existente:** backup com restauração conferida, suspenda escritas e confira schema_version. Em 1.0.0 aplique 002, 003, 004; em 1.1.0 aplique 003, 004; em 1.2.0 somente 004. Use exclusivamente os arquivos `database/mariadb/migrations/`. Em 1.3.0 não reaplique nenhum. DDL faz commit implícito: erro exige inspeção antes de retomar. 001_importar_json.php continua compartilhado, com simulação padrão e origem preservada; leia suas instruções em database/PLANTAS.md.

**Transferência futura MySQL -> MariaDB:** é uma cópia lógica entre bancos separados, não executar ALTER no MySQL para convertê-lo em MariaDB. Não importe dump estrutural MySQL diretamente sobre o destino.

1. Pare escritas e faça backup completo da origem (phpMyAdmin Exportar personalizado, estrutura/dados; inclua hashes e todas as entidades). Guarde fora de htdocs. Teste restauração em outra base do mesmo produto.
2. Crie destino MariaDB vazio com instalador correspondente à versão lógica atual. Não importe seed. Preserve backup e origem sem alteração.
3. Exporte somente dados com nomes explícitos de colunas e IDs completos; exporte binários como hex e preserve precisão DECIMAL, datas UTC, JSON, hashes de senha e tokens. Não migre contas/privilégios de mysql.*. Exclua somente schema_version do conjunto importado, porque o destino já registra suas versões.
4. Importe em ordem de dependência das FKs do instalador: endereço -> usuário -> horta/catálogos -> associações/instalações -> plantas -> sensores -> monitoramentos/leituras -> aplicações/consumo/auditoria. Culturas/nutrientes pessoais exigem criador existente; estruturas exigem reservatório existente. Evite ferramentas que adicionem DROP/TRUNCATE/REPLACE, ignorem duplicatas ou desativem FKs/CHECKs.
5. Valide previamente colisões sob unicode_ci e todos os órfãos. Falha de duplicidade/constraint deve interromper a importação; não escolher arbitrariamente uma conta para conservar. Para volumes grandes use ferramenta de transferência auditada em lotes transacionais, começando por cópia de teste, não simples substituição textual do dump.
6. Compare por tabela: contagens, IDs, relacionamentos e valores; DECIMAL sem conversão float; JSON por conteúdo e payload original quando aplicável; hashes de senha exatamente iguais. Valide AUTO_INCREMENT maior que o maior ID importado. Não regenere senhas ou identificadores.
7. Execute verificar_mariadb.sql, diagnóstico e suites em uma cópia descartável. Teste login real da conta transferida e acesso às suas hortas sem divulgar credenciais. A autenticação depende também de manter a chave HMAC privada; sessões antigas podem exigir novo login após a troca de servidor.
8. Só então aponte as variáveis do Apache ao destino; reinicie e faça validação funcional. Para reverter, suspenda novas escritas, preserve-as para reconciliação e volte à origem. Não descarte silenciosamente alterações feitas após a troca.

Essas são orientações para transferência futura, não uma ferramenta automática de conversão de qualquer dump. Nenhuma base de usuário foi apagada, deduplicada ou migrada automaticamente nesta entrega. As migrações versionadas convertidas foram executadas com dados de teste preservados.
