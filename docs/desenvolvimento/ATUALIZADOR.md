# Arquitetura e validação do atualizador

## Arquivos desta implementação

- ATUALIZAR_HYDROPONEX.bat: entrada Windows, repasse de parâmetros e código de saída.
- scripts/update-hydroponex.ps1: identificação, origem local/GitHub, manutenção, backups, dependências, publicação e diagnóstico HTTP.
- scripts/update-database.php: entrada exclusivamente CLI com JSON por stdin, PDO nativo, compatibilidade MariaDB, planejamento e migrações incrementais.
- scripts/lib/database_schema.php: leitor SQL e validador compartilhados com o instalador. O instalador continua exigindo esquema exato; o atualizador admite tabelas adicionais, sem descartá-las.
- database/mariadb/migrations/manifest.json: cadeia de versões, sem SQL destrutivo novo.
- public/version.json: aplicação 1.7.1 e esquema 1.7.0.
- public/php/services/mail.php: reconhece o autoloader mantido na configuração privada.
- scripts/install-database.php: reutiliza o validador e preserva configurações adicionais em reinstalações compatíveis.
- tests/atualizador.py: testes do migrador em bancos aleatórios exclusivos.
- README e guias de instalação, migração e SMTP: instruções de atualização e recuperação.

O fluxo de atualização não cria endpoints administrativos nem altera APIs CRUD, interface ou privilégios da conta da aplicação. Credenciais administrativas transitam por stdin; para mysqldump, ficam temporariamente em arquivo na pasta privada e são removidas no finally. Não são passadas como argumento de processo ou persistidas na configuração da aplicação.

Os arquivos antigos são preservados no backup. A substituição usa uma pasta preparada e duas movimentações, mantendo a anterior em pasta privada. Não há atomicidade conjunta entre filesystem e MariaDB. DDL pode confirmar implicitamente: o diário é gravado antes de cada migração e bloqueia repetição se houver interrupção.

## Resultados executados em 25/11/2026

Ambiente: Windows, Windows PowerShell 5.1, PHP 8.2.12, MariaDB 10.4.32 e Apache 2.4.58 do XAMPP. Servidores e dados foram isolados em pastas temporárias, com MariaDB nas portas 13329/13330 e Apache em 18944. Não foram atualizados banco ou Apache globais do usuário.

| Verificação | Resultado executado |
|---|---|
| tests/atualizador.py, incluindo esquema legado 1.3.0 | 25 verificações aprovadas |
| tests/integracao.py, fluxos A–E e autorização | 43 verificações aprovadas |
| tests/smtp.py | 5 verificações aprovadas |
| tests/instalacao_local.php | 4 verificações aprovadas |
| tests/configuracao.php | 18 verificações aprovadas |
| tests/compatibilidade.php | 8 verificações aprovadas |
| tests/frontend_integracao.cjs | 9 verificações aprovadas |
| Sintaxe PHP do projeto, excluindo vendor e dist | 59 arquivos aprovados |
| Análise sintática Windows PowerShell 5.1 | Aprovada |
| git diff --check | Aprovado |

As sete suítes acima totalizam **112 verificações automatizadas**, além da análise sintática. O migrador foi testado com senha administrativa de teste não vazia e contendo caracteres especiais, sem exposição em saída.

Verificações adicionais realmente executadas:

- Instalação CLI nova e reinstalação compatível com a biblioteca compartilhada; chave, credenciais e configuração adicional preservadas.
- Atualização completa pelo assistente: banco 1.3.0 para 1.7.0, quatro migrações, backup SQL/arquivos/configuração, publicação e verificações HTTP. Dois usuários e três plantas de demonstração preservados.
- Comparação de registros antigos antes/depois nas tabelas usuario, horta, usuario_horta, planta, monitoramento e monitoramento_sensor, incluindo hash de senha bcrypt.
- Atualização somente de arquivos com banco já em 1.7.0.
- Preparação de dependências a partir de pacote sem vendor: download do instalador Composer, verificação SHA-384, instalação pelo lock e carregamento PHPMailer.
- Download real da main pelo GitHub: o pacote remoto, então anterior à aplicação 1.7.1 local, foi corretamente recusado como downgrade.
- Reexecução da mesma versão sem solicitar credenciais nem republicar; arquivo CSS divergente da mesma versão detectado por hash.
- Diário interrompido recusado também quando os arquivos publicados já correspondem à versão atual.
- Cadastro, login, criação e listagem de horta no Apache após atualizar; visitante recebeu 401 e escrita sem CSRF recebeu 403.
- Restauração do dump em outro banco vazio, confirmando dois usuários, três plantas e versão 1.7.0.
- Falha de backup antes de migrar em instância de testes sem tabela de rotinas: operação abortada e manutenção desfeita. A execução completa foi repetida em instância inicializada corretamente.

Durante a validação foram corrigidas incompatibilidades reais com Windows PowerShell 5.1: codificação UTF-8 do stdin de processos, resposta binária do checksum HTTP, arquivos ocultos na inspeção e importação de módulos nativos quando o ambiente foi herdado de PowerShell 7.

## Reproduzir os testes do migrador

Use uma instância **descartável** MariaDB 10.4.32. A conta administrativa de teste precisa criar/alterar/excluir bancos de teste. A suíte cria nomes aleatórios hx_updater_*_tests e remove somente os bancos que criou com sucesso. Não utiliza o banco da instalação.

~~~powershell
$env:HYDROPONEX_TEST_DB_PORT = "13330"
$env:HYDROPONEX_TEST_DB_USER = "administrador_de_teste"
python tests/atualizador.py --executar --php C:/xampp/php/php.exe
~~~

A senha é solicitada sem exibição quando HYDROPONEX_TEST_DB_PASSWORD não estiver definida. Em automação, forneça-a somente no ambiente privado do processo.

Para incluir a cadeia antiga, forneça o esquema MariaDB original 1.3.0:

~~~powershell
python tests/atualizador.py --executar --php C:/xampp/php/php.exe --legacy-schema "C:/Testes/schema_1_3.sql"
~~~

Na execução registrada, esse arquivo foi extraído sem alterações do commit 155517d, caminho database/mariadb/hydroponex_mariadb.sql. Sem esse argumento, os casos da cadeia legada são explicitamente omitidos e o total de verificações é menor.

As outras suítes seguem o [guia de desenvolvimento](README.md) e exigem uma base descartável vazia, com nome terminado em _test ou _tests e credenciais PDO de teste. SMTP utiliza captura em loopback.

## Conferência manual antes de distribuir outra versão

1. Em cópia da instalação antiga, registre contagens de usuários, hortas, plantas, leituras e tarefas; preserve a configuração privada.
2. Execute CheckOnly e confira a cadeia prevista. Verifique também a recusa de pacote anterior.
3. Execute a atualização e confira o backup. Interrompa uma cópia de teste durante DDL e confirme a manutenção e o bloqueio do diário, sem repetir a migração.
4. Confira versões, contagens, integridade, login de uma conta preexistente, permissões por horta e dados após atualizar o navegador.
5. Teste convites com SMTP de teste e os cenários A–E; confirme que arquivos internos continuam inacessíveis.
6. Restaure o backup em outro banco e valide o procedimento de recuperação documentado.

Não foram simulados corte físico de energia, falta real de espaço, configurações Apache personalizadas ou todas as versões intermediárias de banco desde 1.0.0. A cadeia completa efetivamente executada nesta etapa começou em 1.3.0. A aplicação em produção e serviços SMTP externos não foram alterados. Não houve commit nem push nesta etapa.
