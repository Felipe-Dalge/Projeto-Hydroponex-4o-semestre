# Hydroponex — execução local

Aplicativo em `Hydroponex_2/hydro/Hydroponexteste2/`. Cadastro, login, logout e perfil agora usam MySQL/PDO e sessões PHP reais. Hortas e plantas da horta selecionada usam MySQL/PDO. A API de plantas agora usa somente MySQL; arquivos JSON antigos permanecem intactos para migração explícita. Guias de rotas, permissões e testes: [plantas e migração](database/PLANTAS.md), [hortas](database/HORTAS.md) e [autenticação](database/AUTENTICACAO.md).

Culturas e nutrientes possuem catálogos globais/pessoais; reservatórios e estruturas pertencem a hortas. A interface **Cultivo e infraestrutura** oferece formulários, vínculos de culturas, aplicações de nutrientes e associação a sensores existentes. Consulte [APIs e permissões de infraestrutura](database/INFRAESTRUTURA.md) e [validação desta etapa](database/VALIDACAO-INFRAESTRUTURA.md).

Sensores e monitoramentos agora têm cadastro, leituras manuais/simuladas, histórico paginado, filtros e gráficos por sensor. O dashboard consulta o banco e distingue ausência de dados, origem e alertas. Veja [modelo e APIs de monitoramento](database/MONITORAMENTO.md) e [testes desta entrega](database/VALIDACAO-MONITORAMENTO.md).

## Requisitos

- PHP **8.1 ou superior**: o código existente usa `never`, e os scripts administrativos usam `array_is_list`.
- Extensões `PDO`, `pdo_mysql`, `session` e `json`.
- **MySQL >= 8.0.16** para o modelo de `database/hydroponex.sql`. MariaDB fornecido por algumas versões do XAMPP não substitui o MySQL 8 neste projeto.
- Servidor PHP local, navegador e acesso à internet para as bibliotecas de frontend servidas por CDN.

No Windows, o PHP do XAMPP pode ser usado com um MySQL 8 instalado separadamente. Se ambos os bancos estiverem ativos, configure portas diferentes e informe a porta do MySQL 8 nas variáveis. Não altere o banco de outro projeto.

```powershell
& C:/xampp/php/php.exe -v
& C:/xampp/php/php.exe --ini
& C:/xampp/php/php.exe -r 'echo extension_loaded("pdo_mysql") ? "pdo_mysql OK\n" : "pdo_mysql ausente\n";'
```

Se necessário, habilite `extension=pdo_mysql` no `php.ini` indicado pelo mesmo executável e reinicie o servidor PHP/Apache. Não publique `phpinfo()` para diagnosticar credenciais.

## Preparar o banco

Em instalação nova, crie um banco vazio `hydroponex` com `utf8mb4_0900_ai_ci`, selecione-o no phpMyAdmin e importe `database/hydroponex.sql`. O instalador inclui schema **1.3.0**, com 21 tabelas; não aplique migrações novamente sobre ele. `database/seed.sql` é opcional, exclusivamente demonstrativo, com medições/consumos simulados e contas inativas. Em banco existente, faça backup e suspenda escritas: schema 1.2.0 recebe somente `database/migrations/004_monitoramento.sql`; schema 1.1.0 recebe antes 003; schema 1.0.0 recebe antes 002 e 003. Execute cada migração uma vez, em ordem, após conferir `schema_version`. Não importe o instalador sobre base populada. Consulte [database/README.md](database/README.md) e [monitoramento](database/MONITORAMENTO.md).

Pelo administrador do MySQL/phpMyAdmin, crie uma conta própria, por exemplo `hydroponex_app`, com senha individual forte e acesso apenas ao banco Hydroponex. Para as APIs, conceda `SELECT`, `INSERT`, `UPDATE`, `DELETE`; não conceda administração, criação de usuários, `FILE`, DDL ou privilégios globais. O host autorizado da conta deve corresponder à conexão configurada. Instalar o schema é operação administrativa separada. O teste de conexão necessita apenas de `SELECT`; o teste de integridade e a migração precisam dos privilégios de escrita acima.

## Configurar credenciais sem colocá-las no código

Use as variáveis descritas em [.env.example](.env.example). **Esse arquivo é uma referência: PHP não lê `.env` automaticamente e não foi adicionado carregador de `.env`.** Não basta copiá-lo para `.env`. Defina os valores no ambiente do processo PHP ou na configuração privada do serviço que o executa. Não coloque senha no JavaScript, em URL, arquivo SQL versionado ou linha de comando do PHP.

Na raiz do projeto, antes de iniciar o PHP, no **mesmo terminal PowerShell**:

```powershell
$env:HYDROPONEX_DB_HOST='127.0.0.1'
$env:HYDROPONEX_DB_PORT='3306' # ajuste se o MySQL 8 estiver em outra porta
$env:HYDROPONEX_DB_NAME='hydroponex'
$env:HYDROPONEX_DB_USER='hydroponex_app'
$credencial=Get-Credential -UserName $env:HYDROPONEX_DB_USER -Message 'Senha da conta MySQL do Hydroponex'
$env:HYDROPONEX_DB_PASSWORD=$credencial.GetNetworkCredential().Password

$env:HYDROPONEX_AUTH_KEY = (& C:/xampp/php/php.exe -r 'echo bin2hex(random_bytes(32));').Trim()
& C:/xampp/php/php.exe database/test_connection.php --schema
```

Para autenticação, mantenha `HYDROPONEX_AUTH_KEY` estável entre reinícios: são exatamente 64 caracteres hexadecimais aleatórios. O comando acima gera uma chave para instalação local nova; recupere a mesma chave do armazenamento privado nos próximos reinícios.

A conexão exige nome do banco, usuário e variável de senha presentes. Host e porta têm os padrões `127.0.0.1` e `3306` quando ausentes. Configure uma senha para a conta da aplicação; em alguns ambientes, atribuir valor vazio remove a variável. `.gitignore` ignora `.env`, variantes e arquivos `*.local.*` usuais. Isso não protege arquivos publicados pelo servidor: mantenha segredos fora do diretório público e não crie arquivos com credenciais nesta entrega.

Alternativa compatível com os comandos da etapa anterior:

```powershell
$env:HYDROPONEX_DSN='mysql:host=127.0.0.1;port=3306;dbname=hydroponex;charset=utf8mb4'
# HYDROPONEX_DB_USER e HYDROPONEX_DB_PASSWORD continuam obrigatórias.
```

Quando `HYDROPONEX_DSN` estiver presente, ele prevalece sobre HOST/PORT/NAME. Para voltar aos campos separados, execute `Remove-Item Env:HYDROPONEX_DSN`. O DSN aceita somente `host`, `port`, `dbname` e `charset=utf8mb4`, sem campos repetidos. O nome do banco aceita letras ASCII, números e `_`, até 64 caracteres; host aceita DNS/IP sem opções adicionais. Não há fallback para `root`, banco de teste, outro driver ou JSON quando uma API solicitar PDO.

No Linux/macOS, use `export` para definir as mesmas variáveis e leia a senha sem gravá-la no histórico, por exemplo `read -r -s HYDROPONEX_DB_PASSWORD; export HYDROPONEX_DB_PASSWORD` em Bash. Inicie o PHP a partir desse processo. Em Apache/PHP-FPM/serviço do Windows, configure as variáveis no ambiente privado do serviço e reinicie-o: as variáveis de um terminal separado não alteram o ambiente de um Apache já iniciado.

## Iniciar o aplicativo

Após configurar o ambiente, a partir da raiz:

```powershell
Set-Location Hydroponex_2/hydro/Hydroponexteste2
& C:/xampp/php/php.exe -S localhost:8000 server/router.php
```

Abra [http://localhost:8000/](http://localhost:8000/). Não abra `index.html` por `file://`. O router deve ser passado explicitamente para bloquear o armazenamento JSON no servidor de desenvolvimento. Encerre com Ctrl+C. Depois de usar as credenciais, remova a variável com `Remove-Item Env:HYDROPONEX_DB_PASSWORD` e descarte a variável `$credencial`/feche o terminal. O servidor embutido PHP é para desenvolvimento local.

As APIs privadas, inclusive plantas, agora exigem autenticação confirmada no MySQL. Incluir `config.php` continua disponibilizando conexão sob demanda; a API de sessão anônima não autentica. Cadastro/login requerem banco configurado, migração 002 aplicada quando necessária e `HYDROPONEX_AUTH_KEY` válida.

## Organização do backend

| Arquivo | Responsabilidade |
| --- | --- |
| `php/config/database.php` | Configuração por ambiente, validação do destino, conexão PDO central sob demanda, tratamento seguro de falhas e configuração da sessão MySQL. Não abre sessão HTTP. |
| `php/config/config.php` | Sessão, cookies, CSRF e cabeçalhos. Não contém persistência de plantas em arquivos. |
| `php/config/auth.php`, `php/services/users.php` | Autenticação, autorização por horta, limites de tentativas e transações de perfil/exclusão. |
| `php/api/session.php`, `php/api/plantas.php` | Sessão fornece CSRF. Plantas exige `?horta=ID`, usa somente MySQL e valida papéis; sem parâmetro retorna 422. |
| `php/api/hortas.php`, `php/services/gardens.php`, `php/services/garden_plants.php` | CRUD de hortas e plantas por horta, vínculos, integridade e permissões transacionais. |
| `php/services/infrastructure.php`, `php/api/culturas.php`, `nutrientes.php`, `reservatorios.php`, `estruturas.php`, `culturas_horta.php`, `nutrientes_vinculos.php` | Catálogos e instalações, autorização, validações, vínculos e histórico. |
| `php/services/monitoring.php`, `php/api/sensores.php`, `monitoramentos.php`, `dashboard.php` | Sensores, limites, registros transacionais, histórico por posição e agregados privados. |
| `monitoramento.js` | Formulários, filtros, gráficos e dashboard sem números demonstrativos fixos. |
| `infraestrutura.js` | Formulários e cartões integrados à navegação existente. |
| `hortas.js`, `plantas.js` | Cartões e seleção de horta; formulário completo, detalhes, filtros, transferência e indicadores de plantas. |
| `php/api/auth/*.php`, `php/api/perfil.php` | Cadastro, login, logout, identidade, alteração de senha e gerenciamento do próprio perfil. |
| `database/test_connection.php` | Diagnóstico CLI somente leitura; retorna 0 em sucesso, 1 em falha e 2 para argumento inválido. |
| `database/tests/configuracao.php` | Testes sem conexão: validação de ambiente/DSN e proteção dos segredos. |
| `database/migrations/001_importar_json.php`, `database/tests/integridade.php` | Reutilizam o módulo central; migração continua sendo CLI explícita, com simulação por padrão. |

`database_connection()` retorna a mesma instância PDO durante a requisição/processo. Usa `ERRMODE_EXCEPTION`, `ATTR_EMULATE_PREPARES=false`, `FETCH_ASSOC`, conexões não persistentes, timeout de conexão de cinco segundos e múltiplas instruções SQL desabilitadas. Charset `utf8mb4`, collation `utf8mb4_0900_ai_ci`, UTC e modo estrito são aplicados apenas à sessão dessa conexão. A versão do servidor é validada antes de executar operações da aplicação. O módulo não verifica a versão do schema em todas as requisições; use o diagnóstico `--schema` na instalação.

Charset/comandos de sessão são literais internos; dados externos sempre devem ser vinculados por parâmetros. Nomes de tabelas/colunas, `ORDER BY` e outros identificadores não são parâmetros: restrinja-os a listas permitidas no código. Em processos CLI longos, a conexão fica retida até o fim do processo; não há reconexão ou repetição automática de comandos/transações, para evitar repetir escritas.

### Exemplo para uma API futura de recursos de horta

As APIs de conta já estão implementadas. As próximas APIs de cultivo devem exigir a permissão da horta, em vez de confiar no tipo ou ID enviado pelo cliente:

```php
<?php
require_once __DIR__ . '/../config/auth.php';
auth_api(['GET'], function (): void {
    $horta = validate_id($_GET['horta'] ?? null);
    require_horta_permission($horta);
    $query = database_connection()->prepare(
        'SELECT id_planta,nome,status_planta FROM planta WHERE id_horta=? AND arquivada_em IS NULL'
    );
    $query->execute([$horta]);
    send_json(['success'=>true,'data'=>$query->fetchAll()]);
});
```

Para escrita use `require_horta_permission($horta, true)` e `require_csrf()`; para gestão de membros, o terceiro parâmetro do helper deve ser `true`. O wrapper transforma falhas em JSON seguro. Trate transações com rollback no serviço. O CRUD principal de plantas já usa esse escopo no modo MySQL por horta; não existe mais fallback de plantas para JSON.

## Testes e diagnóstico

Com terminal na raiz e ambiente configurado:

```powershell
& C:/xampp/php/php.exe database/tests/configuracao.php
& C:/xampp/php/php.exe database/test_connection.php
& C:/xampp/php/php.exe database/test_connection.php --schema
# Somente em banco de desenvolvimento: cria fixtures e reverte os dados.
& C:/xampp/php/php.exe database/tests/integridade.php
```

`test_connection.php` confirma conexão, reutilização, opções PDO, parâmetro preparado com acentos/emoji, charset, collation e UTC, sem inserir dados. `--schema` verifica versão `1.3.0` e 21 tabelas InnoDB/utf8mb4 visíveis para a conta. Conceda acesso a todas as tabelas do banco Hydroponex para essa contagem; não conceda privilégios em outros bancos. O diagnóstico não substitui `database/verificar.sql` para conferência das FKs/CHECKs. Os scripts CLI retornam 404 e não executam diagnóstico quando incluídos por HTTP.

Na aplicação, falha ao solicitar conexão via `database_for_api()` retorna HTTP 503 e JSON genérico. Nos logs privados, as categorias fixas permitem localizar a etapa sem imprimir valores:

| Categoria | Verificação local |
| --- | --- |
| `invalid_address`, `invalid_dsn`, `invalid_charset` | Nome, host, porta e formato permitidos; UTF-8 completo obrigatório. |
| `missing_credentials` | Usuário e variável de senha presentes no ambiente do mesmo processo PHP. |
| `missing_pdo_mysql` | Extensão habilitada no `php.ini` do executável/servidor em uso. |
| `connection_failed` | MySQL iniciado, porta, banco existente, conta, senha e acesso do host. |
| `unsupported_server` | MySQL >= 8.0.16; MariaDB não atende ao schema entregue. |
| `session_setup_failed` | Servidor aceita collation e opções de sessão do MySQL 8. |
| `database_diagnostic_failed` | Schema instalado e acessível para a conta do diagnóstico. |

Não há página pública de status do banco. Configure `display_errors=Off`, `log_errors=On` e log fora do diretório público ao publicar, além de HTTPS. O diagnóstico CLI não inclui `config.php`, portanto não inicia cookies/sessão HTTP.

Resultados da infraestrutura PDO: [database/VALIDACAO-PDO.md](database/VALIDACAO-PDO.md). Autenticação atual: [database/AUTENTICACAO.md](database/AUTENTICACAO.md) e [database/VALIDACAO-AUTH.md](database/VALIDACAO-AUTH.md). Referências: [driver PDO MySQL](https://www.php.net/manual/en/ref.pdo-mysql.php) e [atributos PDO](https://www.php.net/manual/en/pdo.setattribute.php).

## Gerenciar hortas

Após login, abra **Minhas Hortas**, cadastre uma horta e use **Ver plantas** ou o seletor de horta. O usuário da sessão é o proprietário; gestores podem editar e leitores somente consultar. Exclusão exige confirmação e horta sem dependências. Não há nova migração de schema para esta etapa. Rotas, campos, preservação de JSON e testes: [database/HORTAS.md](database/HORTAS.md).

## Plantas: migração concluída no código

API exclusivamente MySQL, horta obrigatória, formulário completo, filtros por nome/status, detalhes, exclusão lógica confirmada e transferência com autorização nas duas hortas. Consulte [database/PLANTAS.md](database/PLANTAS.md) para mapeamento de campos, procedimento idempotente do JSON preservado e testes. A opção de atualização automática consulta o banco; não fabrica leituras ou status.
