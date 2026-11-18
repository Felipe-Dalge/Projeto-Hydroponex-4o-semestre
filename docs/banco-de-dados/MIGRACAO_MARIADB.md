# Migração e compatibilidade MariaDB

Consulte a [instalação XAMPP](../instalacao/INSTALACAO_XAMPP.md). Versão executada e validada: **MariaDB 10.4.32 do XAMPP**; PHP 8.2.12. Outras versões MariaDB são deliberadamente rejeitadas até a execução dos testes e ampliação explícita do perfil em database.php. O suporte anterior a MySQL 8 permanece, sem afirmar que toda versão entre produtos tem comparação Unicode idêntica.

## Decisões

A análise da adaptação inicial para MariaDB está em [análise prévia](../historico/ANALISE_PREVIA.md). Os esquemas de instalação MySQL e MariaDB agora incluem a colaboração. Cada produto tem sua própria migração 005 e mantém os arquivos de dados iniciais e migrações anteriores.

- Collation unicode_ci substitui 0900 apenas nos arquivos MariaDB e na sessão PDO MariaDB. Mantém utf8mb4 e comparação usual sem distinção de acentos/maiúsculas, mas não promete equivalência de todas as regras Unicode/ordenação. Colisões em email/chaves únicas exigem análise antes de transferir dados.
- REGEXP BINARY substitui REGEXP_LIKE com flag c; CHECK continua rejeitando siglas minúsculas e CEP inválido. CHECK foi testado como efetivo, código MariaDB 4025.
- DROP CONSTRAINT substitui DROP CHECK na migração 004. PKs, AUTO_INCREMENT, índices, FKs e regras de exclusão/atualização foram preservados; nenhuma entidade removida.
- JSON MariaDB usa LONGTEXT/utf8mb4_bin e validação JSON automática na versão testada. Payload inválido foi rejeitado. Auditoria continua armazenando o JSON integral sem depender da representação binária MySQL.
- DECIMAL, DATETIME(6), UTC, funções de data, ROW_NUMBER, prepared statements nativos e transações funcionaram nas suítes reais. CHECK e foreign_key_checks são ativados na sessão da aplicação; não se alteram configurações globais.
- PDO segue pdo_mysql, exceções, prepared statements sem emulação e múltiplas instruções desabilitadas. Senhas/sessão/CSRF/autorização continuam intactos. Não houve necessidade de reescrever CRUD ou frontend para trocar de produto.
- Apache/router bloqueiam dotfiles, backups, scripts administrativos e diretórios privados. Configuração local é externa ao diretório público; exemplo fornecido sem segredos.

Referências: [REGEXP](https://mariadb.com/docs/server/reference/sql-functions/string-functions/regular-expressions-functions/regexp), [CHECK](https://mariadb.com/docs/server/reference/sql-statements/data-definition/constraint), [JSON](https://mariadb.com/docs/server/reference/data-types/string-data-types/json).

## Nova instalação versus atualização

**Nova:** banco vazio + hydroponex_mariadb.sql. Não executar migrações novamente. Seed opcional e exclusivamente de desenvolvimento.

**MariaDB já existente.** Faça backup, teste sua restauração, suspenda escritas e confira `schema_version`. Aplique os arquivos em ordem a partir da última versão concluída.

| Versão da base | Migrações em `database/mariadb/migrations/` |
| --- | --- |
| 1.0.0 | 002, 003, 004, 005, 006, 007 e 008 |
| 1.1.0 | 003, 004, 005, 006, 007 e 008 |
| 1.2.0 | 004, 005, 006, 007 e 008 |
| 1.3.0 | 005, 006, 007 e 008 |
| 1.4.0 | 006, 007 e 008 |
| 1.5.0 | 007 e 008 |
| 1.6.0 | [008_gestao_tarefas.sql](../../database/mariadb/migrations/008_gestao_tarefas.sql) |
| 1.7.0 | Base atual |

A migração 005 adiciona `perfil_entrada` e as tabelas de convites, tarefas e registros. As contas anteriores passam a ter perfil de entrada administrador para preservar a criação de hortas próprias. Papéis, senhas, sessões compatíveis e vínculos anteriores são preservados. Execute a migração antes de publicar o código atualizado ou rodar o instalador sobre uma base 1.3.0. DDL faz commit implícito. Se houver erro, inspecione a execução parcial antes de retomar. Para o MySQL 8 use a [versão equivalente da migração](../../database/migrations/005_perfis_colaboracao.sql).

A migração 006 adiciona as tabelas `equipe_convite` e `equipe_convite_horta` e registra a versão 1.5.0. Ela preserva os convites manuais e os vínculos existentes. A exclusão de uma horta mantém a linha de destino com referência nula para invalidar o lote inteiro. No fluxo da aplicação, a exclusão também cancela os convites abertos que incluíam a horta. Execute a migração antes de publicar esta versão. [Variante MySQL 8](../../database/migrations/006_equipe_convites.sql)

O importador `001_importar_json.php` mantém o modo de simulação padrão e preserva a origem dos dados. [Documentação de plantas](../apis/PLANTAS.md)

A migração 007 adiciona o vínculo opcional das tarefas e dos registros com uma planta da mesma horta e a data de conclusão das tarefas. Preserva os valores existentes e mantém a data de conclusões legadas desconhecida. Aplique antes de atualizar o código. [Painel operacional](../apis/OPERACIONAL.md) · [Variante MySQL 8](../../database/migrations/007_painel_operacional.sql)

A migração 008 adiciona prioridade, prazo, responsável opcional, versão e referência do problema à tarefa. Cria histórico e intervenções com integridade por horta. Tarefas existentes preservam seus valores, e o evento de migração identifica o estado conhecido com autor desconhecido. Aplique antes de publicar a versão 1.7.0. [Tarefas](../apis/TAREFAS.md) · [Variante MySQL 8](../../database/migrations/008_gestao_tarefas.sql)

**Transferência futura MySQL -> MariaDB:** é uma cópia lógica entre bancos separados, não executar ALTER no MySQL para convertê-lo em MariaDB. Não importe dump estrutural MySQL diretamente sobre o destino.

1. Pare escritas e faça backup completo da origem (phpMyAdmin Exportar personalizado, estrutura/dados; inclua hashes e todas as entidades). Guarde fora de htdocs. Teste restauração em outra base do mesmo produto.
2. Crie destino MariaDB vazio com instalador correspondente à versão lógica atual. Não importe seed. Preserve backup e origem sem alteração.
3. Exporte somente dados com nomes explícitos de colunas e IDs completos; exporte binários como hex e preserve precisão DECIMAL, datas UTC, JSON, hashes de senha e tokens. Não migre contas/privilégios de mysql.*. Exclua somente schema_version do conjunto importado, porque o destino já registra suas versões.
4. Importe na ordem das dependências de chaves estrangeiras do esquema. Depois de importar endereços, usuários e hortas, importe vínculos de acesso, convites, tarefas e registros. Importe `equipe_convite` antes de `equipe_convite_horta`. Culturas e nutrientes pessoais exigem criador existente. Estruturas exigem o reservatório referenciado. Importe plantas antes de suas medições e sensores antes das leituras vinculadas. Evite ferramentas que adicionem DROP/TRUNCATE/REPLACE, ignorem duplicatas ou desativem FKs/CHECKs.
5. Valide previamente colisões sob unicode_ci e todos os órfãos. Falha de duplicidade/constraint deve interromper a importação; não escolher arbitrariamente uma conta para conservar. Para volumes grandes use ferramenta de transferência auditada em lotes transacionais, começando por cópia de teste, não simples substituição textual do dump.
6. Compare por tabela: contagens, IDs, relacionamentos e valores; DECIMAL sem conversão float; JSON por conteúdo e payload original quando aplicável; hashes de senha exatamente iguais. Valide AUTO_INCREMENT maior que o maior ID importado. Não regenere senhas ou identificadores.
7. Execute verificar_mariadb.sql, diagnóstico e suites em uma cópia descartável. Teste login real da conta transferida e acesso às suas hortas sem divulgar credenciais. A autenticação depende também de manter a chave HMAC privada; sessões antigas podem exigir novo login após a troca de servidor.
8. Só então aponte as variáveis do Apache ao destino; reinicie e faça validação funcional. Para reverter, suspenda novas escritas, preserve-as para reconciliação e volte à origem. Não descarte silenciosamente alterações feitas após a troca.

Essas são orientações para transferência futura, não uma ferramenta automática de conversão de qualquer dump. Nenhuma base de usuário foi apagada, deduplicada ou migrada automaticamente nesta entrega. As migrações versionadas convertidas foram executadas com dados de teste preservados.
