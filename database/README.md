# Banco de dados Hydroponex

Modelagem para **MySQL 8.0.16 ou superior**, InnoDB e `utf8mb4_0900_ai_ci`. Identificadores em minúsculas, sem acentos, para funcionar também em servidores Linux. Datas `DATETIME(6)` em UTC; a aplicação converte para `America/Sao_Paulo` na apresentação. A etapa inicial preservou frontend e APIs. Na etapa atual, a autenticação real integra as telas existentes; o JSON legado continua preservado. Veja [AUTENTICACAO.md](AUTENTICACAO.md).

**Atualização de plantas:** API exclusivamente MySQL, sem funções de persistência em JSON. Os arquivos originais são preservados para o importador idempotente. Mapeamento, filtros, transferência, exclusão lógica e testes: [PLANTAS.md](PLANTAS.md).

**Atualização atual:** schema **1.3.0**, 21 tabelas. Sensores têm CRUD e limites; monitoramentos registram leituras com origem e histórico; o dashboard e os gráficos consultam o MySQL. Em banco 1.2.0, faça backup, suspenda escritas e aplique uma vez `migrations/004_monitoramento.sql`. Em versões anteriores, aplique antes 002/003 conforme a versão instalada. Instalação nova já contém as alterações: não reaplique migrações sobre ela. Veja [MONITORAMENTO.md](MONITORAMENTO.md), [validação](VALIDACAO-MONITORAMENTO.md), [infraestrutura](INFRAESTRUTURA.md) e [plantas](PLANTAS.md).

**Atualização da etapa PDO:** a configuração central está em `php/config/database.php` dentro do aplicativo e é usada também pelo importador e testes CLI. Consulte [../README.md](../README.md) para variáveis de ambiente, execução PHP/MySQL, teste de conexão e exemplo de uso em APIs. `config.php` disponibiliza o módulo sem abrir conexão. O modo JSON anterior foi removido; a API atual usa plantas MySQL por horta. A descrição da análise original abaixo registra o estado anterior à integração.

## Referências efetivamente disponíveis

Os nomes solicitados `Projeto-Hydroponex-4o-semestre-main(2).zip` e `criacao_tabelas(1).sql` **não foram localizados nas pastas verificadas do workspace, Desktop e Downloads**. Foram utilizados os seguintes arquivos disponíveis, sem presumir que sejam versões idênticas aos nomes solicitados:

| Referência analisada | Local | SHA-256 |
| --- | --- | --- |
| ZIP do projeto | `../Projeto-Hydroponex-4o-semestre-main.zip` em relação à raiz do projeto | `956967E23D35C89A072CCBDEBFD68F1F7EA4C43FBE67F2434182F6E8883B84AE` |
| DDL original | `../criacao_tabelas.sql` em relação a esta pasta | `6BD8B569E48A21A9005197FEED87C1B6ADB338B6D88B0A8569519652CA303F01` |

O ZIP contém a árvore do aplicativo, sem arquivo SQL. A árvore extraída no workspace foi a base da inspeção. O SQL original está preservado. Caso sejam fornecidas as versões com sufixo, compare-as antes de adotar esta modelagem em produção.

## Inspeção da arquitetura e reaproveitamento (registro da etapa inicial)

Raiz: DDL Oracle, `HydroponexNomeRA.txt` (identificação acadêmica) e aplicativo em `Hydroponex_2/hydro/Hydroponexteste2/`. Não há Composer, framework, ORM, configuração PDO, migrações anteriores ou suíte de testes no projeto original.

| Componente existente | Função e avaliação |
| --- | --- |
| `index.html` | Interface de página única: landing, login, cadastro, recuperação, dashboard, plantas, monitoramento, consumo e perfil/configurações. Reaproveitável sem mudanças nesta etapa. |
| `script.js` | Navegação por hash, tema, gráficos e `fetch` do CRUD. Login apenas navega; cadastro/perfil usam `localStorage`; recuperação exibe alerta. Não autentica. Simulador altera telemetria só em memória e gráficos de consumo usam constantes. |
| `style.css`, `tailwind.config.js`, `img/ImagemLogoSite.png` | Apresentação e identidade preservadas. HTML depende de Tailwind, Chart.js e Lucide por CDN. |
| `php/api/plantas.php` | CRUD GET/POST/PUT/PATCH/DELETE; contrato JSON reaproveitável na futura camada de repositório PDO. Hoje não filtra usuário/horta. |
| `php/api/session.php` | Emite token CSRF em sessão; não é sessão de usuário autenticado. |
| `php/config/config.php` | Validação, respostas JSON, sessão, CSRF, lock de arquivo e substituição do JSON. Separar posteriormente conexão, autenticação, autorização, serviço e repositório. `never` já exige PHP 8.1+. |
| `php/storage/plantas.json` | Persistência real atual: cinco registros. Mantido intacto; migração administrativa oferecida abaixo. |
| `php/storage/index.php` | Bloqueia listagem por esse índice, não acesso direto ao JSON. |
| `server/router.php` | Roteamento do servidor PHP e bloqueio de `/php/storage/`. Em Apache esse router não é executado. |
| `README-CRUD.md` | Documenta o protótipo. Cita proteção Apache, porém nenhum `.htaccess` está presente no ZIP/árvore analisados. Antes de publicar, mover dados para fora do diretório público ou configurar bloqueio no servidor. |

Na etapa inicial o protótipo não possuía autenticação e não deveria ser publicado como se possuísse. Esse estado foi substituído pela implementação documentada em [AUTENTICACAO.md](AUTENTICACAO.md): APIs de conta usam MySQL e plantas mantêm JSON pessoal com sessão obrigatória. O serviço local encontrado na inspeção era MariaDB 10.4; nenhum banco desse serviço foi alterado para os testes.

## Auditoria das 14 tabelas originais

O arquivo original tem 12 PKs simples, duas associações sem PK e 15 FKs declaradas, todas `ON DELETE RESTRICT`. A palavra `Celula` aparece em nomes de FKs, mas não existe entidade `Celula`: as referências apontam para `Horta`.

| Tabela original e PK | FKs originais | Correção e preservação |
| --- | --- | --- |
| `Usuario`, `Id_usuario` | nenhuma | `usuario`: email único, hash de senha, estado ativo, verificação, perfil e endereço opcionais. `tipo` é descrição legada, não autorização. |
| `Cultura`, `Id_cultura` | `fk_Celula_id_horta → Horta` | `cultura` vira catálogo; `horta_cultura` preserva cada associação e permite reutilização. Planta pode referenciar cultura habilitada. |
| `Horta`, `id_horta` | `fk_CEP_ID_CEP → CEP`, `fk_Estrutura_id_estrutura → Estrutura`, `id_planta → Planta` | Endereço mantido; estrutura e planta passam a ter `id_horta`. Corrige o sentido 1:N. Não copia uma planta silenciosamente se ela estiver em várias hortas legadas. |
| `Reservatorio`, `id_reservatório` | nenhuma | `reservatorio`: pertence a uma horta, capacidade e volume em litros, pH e temperatura decimais. |
| `Monitoramento`, `ID_Monitoramento` | `fk_Usuario_Id_usuario → Usuario` | Mantém autor, descrição `Medição → medicao` e data; acrescenta horta e origem. Autor pode ser nulo para coleta automática/importada. |
| `Nutrientes`, `ID_Nutriente` | nenhuma | `nutriente`: mantém classificação, descrição, tipo; acrescenta nome. Aplicações em reservatórios têm quantidade, unidade, instante e autor. |
| `Sensores`, `id_sensor` | `fk_Nutrientes_ID_Nutriente → Nutrientes` | `sensor`: nutriente opcional mantido para medição específica. Sensor pertence à horta e pode medir um reservatório, estrutura ou planta; pH/temperatura não precisam de nutriente. |
| `CEP`, `ID_CEP` | `FK_Usuario_Id_usuario → Usuario` | Inverte: usuário aponta ao CEP; CEP aponta à cidade. Código vira `CHAR(8)` único, com zeros iniciais. |
| `Cidade`, `ID_Cidade` | `fk_CEP_ID_CEP → CEP` | Cidade aponta ao estado; CEP aponta à cidade. Nome único dentro do estado. |
| `Estado`, `ID_Estado` textual | `fk_Cidade_ID_Cidade → Cidade` | Estado não depende da cidade. PK numérica, sigla única de duas letras; IDs textuais legados exigem mapa de conversão. |
| `Estrutura`, `id_estrutura` | `id_reservatório → Reservatorio` e `fk_Reservatorio_id_reservatório → Reservatorio` | Uma FK `id_reservatorio`, opcional, e vínculo obrigatório à horta. Se os dois campos antigos discordarem, exigir reconciliação; não escolher arbitrariamente. Um reservatório abastece várias estruturas da mesma horta. |
| `Usuario_celula`, sem PK | usuário e horta | Renomeada `usuario_horta`, PK composta, sem duplicatas, papel por associação. |
| `monito_sensor`, sem PK | sensor e monitoramento | `monitoramento_sensor`: PK de leitura, unicidade por evento/sensor, valor decimal, unidade e duas FKs compostas para a mesma horta. |
| `Planta`, `id_planta` | nenhuma | `planta`: horta obrigatória; cultura/estrutura opcionais. Preserva nome, tipo, localização, crescimento, pH, temperatura, nutrientes, irrigação e status; acrescenta umidade, água e instantes. |

### Usuários, hortas e autorização

`usuario_horta` mantém o N:N original em vez de impor um único usuário por horta. Um usuário pode administrar várias hortas e uma horta pode ter vários colaboradores. Papéis: `proprietario` gerencia membros e horta; `gestor` altera recursos e registros; `leitor` consulta. A PK `(id_usuario,id_horta)` permite um papel por membro/horta. Não há papel global de administrador concedido pelo campo `tipo`.

A aplicação deve criar horta e proprietário na mesma transação, bloquear a horta com `SELECT ... FOR UPDATE` ao mudar membros e impedir remoção/rebaixamento do último proprietário. Essa regra agregada **não é garantida por CHECK/FK**; `verificar.sql` detecta hortas ativas sem proprietário. A FK de autor dos históricos aponta a `usuario`, para preservar autoria mesmo após revogação da participação. A aplicação valida a permissão atual no momento da operação, inclusive em aplicações de nutrientes e monitoramentos manuais.

### Endereços

Hierarquia: `estado 1:N cidade 1:N cep`; tanto `usuario` quanto `horta` apontam opcionalmente para `cep`. Não armazenar cidade/estado também em usuário ou horta. Número/complemento pertencem ao endereço do usuário/horta. CEP contém logradouro/bairro opcionais, pois CEP genérico de município pode não definir rua. Não é um cadastro postal nacional completo.

### Cultivo, equipamentos e histórico

Cada planta pertence a exatamente uma horta; várias plantas podem usar uma estrutura. Cultura é uma classificação reutilizável; plantas legadas sem classificação podem manter cultura nula, sem inventar dados. `horta_cultura` lista culturas habilitadas por horta. Estruturas podem ser cadastradas antes da instalação do reservatório. Reservatórios pertencem a uma horta e não são compartilhados entre hortas.

FKs compostas impedem que estrutura use reservatório de outra horta, planta use estrutura de outra horta, sensor use alvo de outra horta e leitura misture sensor/monitoramento de hortas distintas. Os índices únicos `(id_recurso,id_horta)` são chaves candidatas necessárias a essas referências. A repetição controlada de `id_horta` nas leituras impõe integridade entre as duas origens.

Um sensor tem no máximo um alvo específico; sem alvo mede o ambiente da horta. A relação opcional com nutriente preserva o conceito original. `reservatorio_nutriente` registra aplicações repetidas, não apenas composição atual. Cada monitoramento agrupa uma amostra de cada sensor; nova coleta cria novo evento. Unidade/tipo ficam também na leitura como registro histórico. A aplicação valida unidade/tipo e faixa conforme sensor, conserva histórico e evita transferir sensores com leituras para outra horta. Os CHECKs gerais não substituem validação agronômica.

Campos atuais em planta/reservatório são snapshots para o dashboard; leituras são histórico. Atualizar snapshot e leitura na mesma transação. `consumo_agua` armazena volume diário e referência estimada de cultivo em solo para os gráficos existentes. Não confundir esse volume com `agua` do JSON, cuja semântica não está explicitada no protótipo.

### Tipos, índices e exclusões

- `VARCHAR2 → VARCHAR`; IDs `NUMBER → INT UNSIGNED AUTO_INCREMENT`; eventos/aplicações/tokens usam `BIGINT UNSIGNED`. Associações usam PK composta; `schema_version` tem PK textual. Não há `NUMBER(100)` válido a transportar literalmente para inteiro MySQL: é preciso validar IDs legados.
- `NUMBER(3,2)` comportava só 0–9,99 positivos: pH passa a `DECIMAL(4,2)` com faixa 0–14. Volumes, temperatura, medições e nutrientes usam `DECIMAL`, não inteiros truncados ou ponto flutuante. Temperaturas aceitam decimais negativos; intervalos operacionais são responsabilidade de cada tipo de sensor.
- Campos obrigatórios têm `NOT NULL`; códigos CEP usam ASCII binário e regex; email tem unicidade conforme a collation sem distinção de maiúsculas/acentos. Na aplicação, normalizar e validar email; essa collation é uma política de identidade deliberada, inclusive para endereços internacionalizados.
- Índices de FKs estão explícitos ou cobertos por prefixo de PK/UNIQUE; consultas frequentes indexam horta/status, horta/data, sensor/horta e reservatório/data. Medir com `EXPLAIN` na integração e ajustar conforme volume real.
- **RESTRICT em todas as FKs de domínio**, inclusive associações: excluir explicitamente vínculos quando permitido, arquivar horta/planta e inativar usuário/sensor. IDs não são reescritos (`ON UPDATE RESTRICT`). Histórico não é apagado em cascata. Remoção física exige operação administrativa com política de retenção/anomização e revisão das dependências.
- **Única cascata: `token_usuario → usuario`**. Tokens de verificação/recuperação são credenciais temporárias sem valor histórico independente; se um usuário sem dependências for fisicamente excluído, seus tokens deixam de fazer sentido. Não há cascata de horta, planta, reservatório ou leituras.

## Autenticação implementada

`php/config/auth.php` e `php/services/users.php` implementam sessões reais, cadastro, login, logout, perfil, senha atual obrigatória nas operações sensíveis, revogação de outros dispositivos, limitação persistente de tentativas e exclusão anonimizada com preservação das FKs históricas. O frontend não autentica pelo `localStorage`.

`usuario.auth_versao` revoga sessões; `usuario.excluido_em` registra o tombstone. `auth_limite` guarda apenas identificadores HMAC e contadores. O schema 1.1.0 adiciona essas estruturas sem remover entidades. O campo `tipo` continua descritivo: não há administrador global ou listagem pública de contas. A autorização de domínio é por `usuario_horta.papel`.

Políticas de senha, exclusão, segredo de HMAC, rotas e instruções completas estão em [AUTENTICACAO.md](AUTENTICACAO.md). `token_usuario` permanece disponível para fluxos futuros de verificação/recuperação por e-mail; envio de e-mail não foi implementado. Os usuários de seed continuam inativos e sem senha utilizável.

## Importação no phpMyAdmin

1. Confirme **MySQL >= 8.0.16**. MariaDB do XAMPP não é o mesmo produto; este script usa collation/regex MySQL 8. Não basta trocar o nome da versão no guia. CHECK só é efetivo no MySQL a partir de 8.0.16.
2. Para instalação nova, crie no phpMyAdmin um banco vazio, por exemplo `hydroponex`, com `utf8mb4_0900_ai_ci`. Se o provedor já entregar um banco, selecione um banco vazio autorizado. O arquivo não cria banco nem usuário SQL.
3. Selecione esse banco, abra **Importar**, escolha `database/hydroponex.sql`, formato SQL e codificação UTF-8 e execute. Não desative verificação de chaves estrangeiras.
4. Opcionalmente, em desenvolvimento, importe `database/seed.sql` uma única vez. Para migrar dados reais, prefira banco sem seed e cadastre usuário/horta/proprietário de destino antes da importação.
5. Execute `database/verificar.sql` pela aba SQL ou Importar. Confirme 21 tabelas, InnoDB/utf8mb4, versão `1.3.0`, FKs/CHECKs e resultados vazios nas consultas de inconsistência. Com seed, Ana tem duas hortas; as hortas A/B têm duas/uma plantas.

Não há `DROP`, `TRUNCATE`, `REPLACE`, `INSERT IGNORE` ou desativação de FKs. Também não há `CREATE TABLE IF NOT EXISTS`: a instalação falha se encontrar tabela de mesmo nome, para não mascarar schema incompatível. **DDL MySQL faz commit implícito**: uma falha pode deixar instalação parcial; não reaplique cegamente, não use `--force`, inspecione e retome apenas os comandos faltantes ou escolha outro banco vazio. O importador do phpMyAdmin pode continuar após erro conforme configuração; confirme o relatório inteiro. Seed também não é idempotente e deve ser revertido em caso de erro antes de confirmar transação.

Referências técnicas: [CREATE TABLE](https://dev.mysql.com/doc/refman/8.0/en/create-table.html), [FKs e índices](https://dev.mysql.com/doc/refman/8.0/en/create-table-foreign-keys.html), [CHECK e versão mínima](https://dev.mysql.com/doc/refman/8.0/en/create-table-check-constraints.html).

## Dados existentes e migração

**Nunca importe o instalador sobre tabelas em uso.** Faça backup/exportação e teste restauração. Para dados legados em JSON, há migração executável em `migrations/001_importar_json.php`, sem tocar na origem. Para banco legado Oracle/MySQL já populado, siga [migrations/LEGADO.md](migrations/LEGADO.md); os conflitos do modelo original exigem mapas explícitos, não conversões silenciosas. Esse roteiro não afirma ser uma migração automática executada de uma base que não foi fornecida.

### JSON atual → novo MySQL

Requer PHP >= 8.1, `pdo_mysql`, `json` e schema instalado. Ferramenta **CLI somente**. Pare gravações na API durante captura e corte; copie o JSON para backup fora da pasta pública. Defina previamente a horta e seu proprietário (não há essa informação no JSON). Bancada é mantida como texto; não cria equipamento ou cultura por inferência de nome.

No PowerShell, executando a partir da raiz do projeto (ajuste caminho do PHP):

```powershell
# Apenas valida o arquivo; não conecta ao banco.
& C:/xampp/php/php.exe database/migrations/001_importar_json.php --horta=1

# Informe credenciais locais por ambiente, não por argumento de linha de comando.
$env:HYDROPONEX_DSN='mysql:host=127.0.0.1;port=3306;dbname=hydroponex;charset=utf8mb4'
$env:HYDROPONEX_DB_USER='hydroponex_migracao'
$credencial=Get-Credential -UserName $env:HYDROPONEX_DB_USER
$env:HYDROPONEX_DB_PASSWORD=$credencial.GetNetworkCredential().Password
& C:/xampp/php/php.exe database/migrations/001_importar_json.php --horta=1 --aplicar
Remove-Item Env:HYDROPONEX_DB_PASSWORD
```

Pode informar `--arquivo=C:/caminho/backup-plantas.json`. O processo valida IDs únicos, nomes, tipos/faixas numéricas e status antes de escrever. Usa transação e lock administrativo; erros revertem todos os inserts da execução. Para a mesma origem, SHA-256 + ID legados permitem repetir sem duplicar. Uma origem já importada em outra horta é recusada. Snapshot diferente na mesma horta é recusado para evitar duplicação de versões; reconciliação posterior exige migração nova revisada. Não é sincronização contínua.

Mapeamento: `id` fica em `importacao_json.id_origem` e recebe novo ID em `planta`; `nome → nome`, `bancada → localizacao`, `temp → temperatura_agua_c`, `umidade → umidade_percentual`, `nutrientes → nivel_nutrientes_ppm`, `agua → agua_l`, `status → status_planta`. Os nomes temp/agua não definem perfeitamente o instrumento/unidade no código; a primeira associação acompanha o campo de temperatura de água já existente no DDL e deve ser confirmada antes de telemetria real. Não converter `agua` em irrigação nem consumo diário. `lastUpdate` como `Agora` ou hora sem data não é timestamp absoluto: permanece no `payload`, com `ultima_medicao_em` nula. Todo registro original, incluindo campos desconhecidos, é preservado em JSON; decimais normalizados seguem a precisão das colunas. IDs novos evitam colidir com dados já cadastrados.

Após importação, compare quantidade, valores e payloads. A interface atual usa plantas MySQL por horta e não importa automaticamente esse arquivo legado. O endpoint sem `?horta` agora retorna 422; não há fallback JSON. A transferência dos dados antigos exige o procedimento explícito descrito em [HORTAS.md](HORTAS.md). Não retome gravação em duas fontes acreditando que estejam sincronizadas. Reversão antes do corte: mantenha aplicação no JSON original. Após haver novas escritas MySQL, voltar ao JSON exige exportar/reconciliar essas escritas; não apagar o novo banco.

## Verificação e testes

Diagrama e principais chaves: [diagrama.md](diagrama.md). SQL é o dicionário completo de campos, domínios, índices e constraints; não há entidades originais descartadas.

Além das consultas de `verificar.sql`, execute em banco de desenvolvimento, com variáveis de conexão definidas:

```powershell
& C:/xampp/php/php.exe -l database/migrations/001_importar_json.php
& C:/xampp/php/php.exe -l database/tests/integridade.php
& C:/xampp/php/php.exe database/tests/integridade.php
```

O teste cria registros dentro de transação e executa `ROLLBACK`; sequências AUTO_INCREMENT podem avançar. Não é para produção, nem servidor MariaDB. Usa o código de erro do MySQL para confirmar a natureza de cada rejeição. Para contas sem privilégios de metadados, aceita também 1216/1217 como equivalentes a 1452/1451, mantendo a checagem de violação de FK. A sigla `ZZ` e CEP `00000001` são fixtures reservadas nesse teste, que pressupõe não haver esses valores no banco de desenvolvimento.

Lista objetiva:

1. Importação limpa sem erros: 21 tabelas, PKs/FKs/índices, InnoDB e charset corretos.
2. Seed: dois usuários inativos sem credenciais utilizáveis, dois jardins, três plantas, duas leituras e consumo diário.
3. Usuário com duas hortas, múltiplas plantas na mesma horta e participação compartilhada sem duplicação.
4. Recusar email duplicado, papel inválido, ativação sem hash e senha em texto puro.
5. Aceitar pH 14 e decimal de leitura; recusar pH/umidade/volume fora do domínio e CEP alfanumérico; preservar zeros do CEP.
6. Recusar vínculos entre hortas em estruturas, plantas, culturas, sensores e leituras; recusar dois alvos e leitura duplicada por sensor/evento.
7. Bloquear exclusão de horta/membro/histórico com dependentes; confirmar cascata somente para token descartável.
8. JSON: simular, importar cinco registros, repetir sem duplicação e recusar destino diferente, arquivo alterado, ID duplicado/status inválido e horta inexistente; confirmar rollback e origem intacta.
9. Confirmar payload integral e mapeamento de IDs; verificar que não houve alteração de frontend/backend originais.
10. Autenticação e autorização agora têm suíte HTTP própria em `tests/autenticacao.py`; execute em banco separado conforme [AUTENTICACAO.md](AUTENTICACAO.md).

Resultado da validação executada nesta entrega: [VALIDACAO.md](VALIDACAO.md).
