# Sensores, monitoramentos e dashboard

O painel usa `sensor`, `monitoramento`, `monitoramento_sensor`, `planta` e `consumo_agua` no MySQL, com as sessões/PDO existentes. Não há gerador automático de valores nem integração IoT conectada nesta entrega. A interface mantém o dashboard e a navegação originais; a tela **Monitoramento** oferece sensores, registro de medição, filtros, histórico e gráfico.

## Atualizar o banco

Instalação nova: importe `hydroponex.sql` em banco vazio, schema **1.3.0**, 21 tabelas. O seed opcional tem medições e consumos demonstrativos identificados como `simulacao`; as contas continuam inativas. Não aplique migrações novamente sobre o instalador novo.

Instalação existente: faça backup e valide sua restauração; suspenda escritas e importe `migrations/004_monitoramento.sql` **uma vez**, sobre schema 1.2.0. Se estiver em 1.1.0, aplique antes 003; em 1.0.0, aplique antes 002 e 003. Confirme `schema_version` antes de executar. O phpMyAdmin deve estar com o banco correto selecionado. DDL MySQL faz commit implícito: se houver erro, inspecione o estado parcial antes de retomar; não reaplique cegamente e não importe o instalador sobre dados existentes.

A migração adiciona limites opcionais ao sensor e snapshots desses limites nas leituras, `registrado_em` no evento, origem de consumo e suporte à origem `simulacao`. IDs, leituras e relações existentes são preservados. Limites/instante de recebimento antigos ficam NULL: a migração não inventa dados anteriores. Consumo legado recebe origem `importacao`, que significa **não verificada**, sem afirmar que foi medido por dispositivo. Não foram acrescentadas cascatas de exclusão.

Após atualizar: `php database/test_connection.php --schema` a partir da raiz; deve confirmar 1.3.0 e 21 tabelas. Configuração PHP/MySQL, ambiente privado e execução local continuam no [README principal](../README.md). APIs usam conta apenas com SELECT/INSERT/UPDATE/DELETE; DDL é administrativo.

## Origem, disponibilidade e alertas

| Origem | Significado na interface | Registro por estas APIs |
| --- | --- | --- |
| `manual` | Valor informado por usuário autenticado; não é atestado de precisão nem dispositivo conectado | Permitido para gestor/proprietário |
| `simulacao` | Dado de teste, sempre explicitamente identificado | Permitido para gestor/proprietário |
| `sensor` | Origem de dispositivo declarada em registros existentes; não comprova uma conexão IoT ativa | Somente consulta; requer futura ingestão autenticada específica |
| `importacao` | Origem não verificada/importada | Somente consulta |
| Sem leitura | Não disponível | Nunca substituída por zero |

O dashboard separa a última medição manual/de dispositivo da última simulação **por sensor**. Uma simulação nunca substitui uma medição informada nem elimina seu alerta. Importações aparecem no histórico, com sua origem; não são assumidas como medições atuais no dashboard. O instante exibido é o da coleta, não o do último acesso à página. Leituras com mais de 24 horas recebem indicação de antigas; sensores inativos/manutenção permanecem visíveis, mas não contribuem para a contagem de alertas ativos. Não há promessa de dados em tempo real.

O seed novo está identificado como simulação. Se uma instalação antiga importou o seed anterior, sua coleta chamada `Coleta demonstrativa` pode ainda estar marcada como `sensor`: o operador deve conferir a procedência e corrigir explicitamente essa origem em sua base de desenvolvimento. A migração não reclassifica medições desconhecidas automaticamente. O consumo anterior continua visível como origem não verificada; revise ou classifique demonstrações como `simulacao` após conferir sua procedência.

Limites inferior/superior opcionais são definidos por sensor na unidade escolhida. São copiados para cada leitura no registro. Valor fora de qualquer limite preenchido gera `alerta`; dentro gera `normal`; ambos ausentes geram `sem_limites`. Igualdade ao limite é permitida. Alterar limites do sensor afeta somente **novas** leituras, sem reescrever avaliações históricas. A interface não presume faixas agronômicas a partir de um único pH/temperatura ideal de cultura: tolerâncias exigem definição explícita.

O estado das plantas no dashboard vem do cadastro `planta.status_planta`, identificado como tal. Não é recalculado a partir de uma leitura de reservatório que poderia representar várias plantas. O estado geral do painel considera os últimos registros dos sensores ativos e distingue alertas, registros antigos, limites pendentes e ausência de medições.

## Modelo e integridade

Uma horta contém sensores; cada sensor pode ter **no máximo um** alvo opcional: planta, estrutura ou reservatório da mesma horta. Sem alvo, mede no contexto geral da horta. FKs compostas mantêm a separação mesmo fora da API. Nutriente específico é opcional, aplicável ao tipo `nutrientes` e deve ser um catálogo acessível.

Cada evento `monitoramento` contém de 1 a 25 leituras de sensores distintos da mesma horta, com data, origem e autor obtido da sessão. O evento inteiro é transacional: qualquer leitura inválida impede gravar todas. `monitoramento_sensor` preserva valor, tipo, unidade e limites da coleta. Data da coleta e recebimento são armazenadas em UTC; a interface converte para o fuso do navegador. É possível registrar uma coleta passada. Mais de cinco minutos no futuro é rejeitado.

Sensor com leituras pode alterar nome, status e limites, mas não tipo, unidade, alvo ou nutriente; isso reinterpretaria o histórico. Para outro contexto, cadastre um novo sensor e inative o anterior. Sensor com histórico não pode ser excluído. Sensor sem histórico pode ser excluído mediante confirmação, sujeito às FKs. Eventos/leituras são imutáveis nas APIs públicas. Tipos/unidades legados não reconhecidos permanecem consultáveis e permitem manutenção de nome/status; não recebem novas leituras até um mapeamento administrativo explícito ou substituição. Uma planta arquivada mantém seus sensores históricos inativos; eles não podem ser reativados sobre a planta arquivada.

## Tipos e unidades aceitos

| `tipo` / `tipo_medicao` | Unidades exatas | Intervalo aceito |
| --- | --- | --- |
| `temperatura` | `°C` | -273.15 a 999.99 |
| `ph` | `pH` | 0 a 14 |
| `nutrientes` | `ppm`, `mg/L` | 0 a 999999999.999 |
| `umidade` | `%` | 0 a 100 |
| `nivel_agua` | `L`, `%` | 0 a 999999999.999 L; 0 a 100% |
| `condutividade` | `mS/cm` | 0 a 1000 |
| `luminosidade` | `lux` | 0 a 1000000 |

Valores e limites usam até seis casas decimais, ponto decimal; booleans, NaN, infinito e valores textuais em notação exponencial não são aceitos. São limites físicos/de armazenamento definidos nesta implementação, não recomendações de cultivo. Não se convertem unidades ou concentração automaticamente. O volume de reservatório continua validado por seu CRUD; uma leitura manual em litros não altera silenciosamente o nível cadastrado.

## APIs

Todas exigem sessão autenticada e `?horta=H`. Proprietário/gestor escreve; leitor somente consulta. Horta/objeto sem acesso retorna 404; papel insuficiente/CSRF retorna 403; visitante 401; dados inválidos 422; histórico/dependência/conflito 409; método não oferecido 405. Falhas internas são genéricas, sem SQL/credenciais. Mutações exigem `Content-Type: application/json` e `X-CSRF-Token` obtido na API de sessão. Não envie usuário ou papel no corpo. IDs vêm do banco e são validados; a seleção do navegador não concede autorização.

### Sensores — `php/api/sensores.php`

- GET `?horta=H`: `data` lista sensores, `tipos` retorna tipos/unidades/intervalos aceitos e `pode_editar` informa a permissão.
- GET `?horta=H&id=S`: detalhe do sensor, incluindo `possui_leituras`.
- POST `?horta=H`: cria; PATCH/PUT `?horta=H&id=S`: atualiza parcialmente.
- DELETE `?horta=H&id=S`: corpo `{"confirmacao":"EXCLUIR"}`.

```json
{"nome":"pH do reservatório","tipo":"ph","unidade":"pH","status":"ativo","id_reservatorio":1,"limite_min":5.5,"limite_max":6.5}
```

Nome obrigatório até 100 caracteres; status `ativo`, `inativo` ou `manutencao`. IDs opcionais `id_reservatorio`, `id_estrutura`, `id_planta`, `id_nutriente` aceitam null para remover vínculo quando permitido. Para trocar o alvo de sensor sem histórico, envie null para o alvo anterior e o novo ID. Sensor não muda de horta pelo formulário. Limites podem ser null; mínimo não pode superar máximo.

### Leituras — `php/api/monitoramentos.php`

POST `?horta=H`:

```json
{"origem":"manual","data_hora":"2026-10-28T10:30:00-03:00","descricao":"Conferência manual","leituras":[{"id_sensor":1,"valor":6.2,"tipo_medicao":"ph","unidade":"pH"}]}
```

`origem` aceita somente `manual` (padrão) ou `simulacao`. Data/hora é obrigatória: ISO com fuso explícito ou `YYYY-MM-DD HH:MM:SS[.ffffff]` interpretado como UTC. `descricao` opcional até 100 caracteres. Cada leitura deve coincidir com o tipo/unidade de seu sensor ativo. Sensor repetido no evento é rejeitado. O formulário registra uma leitura por vez; a API aceita lote de até 25, limitado também a 8 KB de JSON.

Resposta 201: `data.id_monitoramento`, `data.origem`, `data.leituras` (quantidade). Recebimento e autor são gerados pelo servidor. Não existem endpoints públicos para registrar origem `sensor` sem credenciais de dispositivo, nem para editar/apagar histórico.

GET `?horta=H` aceita:

| Filtro | Regra |
| --- | --- |
| `sensor=S` | Sensor dessa horta |
| `planta=P` | Sensor com alvo **direto** nessa planta, inclusive histórico de planta arquivada |
| `inicio`, `fim` | Data/hora completa; limites inclusivos; início não pode superar fim |
| `origem` | Uma das quatro origens do modelo |
| `limite` | 1 a 100, padrão 50 |
| `cursor` | Valor opaco `proximo_cursor` devolvido pela página anterior |

Resposta `data` contém leituras, nome do sensor, data/hora, origem, `simulado`, `antiga`, limites e `estado`. `proximo_cursor` null indica fim. Ordem: coleta decrescente, ID da leitura decrescente para desempate. A paginação é por posição, sem OFFSET ou contagem de todo o histórico; alterou filtros, volte à primeira página. Novos registros anteriores à posição já percorrida aparecem ao recarregar a primeira página. Sensor de reservatório/estrutura não é atribuído automaticamente às plantas, evitando misturar contextos.

O gráfico usa a página filtrada de **um sensor**, com séries separadas por origem e simulações tracejadas. Não soma pH, temperatura ou unidades diferentes. Lacunas permanecem null; não há geração de valores intermediários.

### Painel — `php/api/dashboard.php?horta=H`

GET retorna `data` com `plantas` (contagens por status cadastrado), `total_plantas`, `sensores` com `ultima_medicao` e `ultima_simulacao`, `alertas_medicoes`, `consumo_hoje_l`, `consumo_diario`, `consumo_mensal`, `gerado_em`, `data_consumo_hoje`, `fuso_consumo` e `nota_consumo`. Sem leitura/consumo, valores são null; zero medido continua zero.

Últimas leituras são obtidas em consulta em lote com função de janela, sem uma consulta por sensor. Histórico usa índices por horta/data e sensor/horta, junções com a horta e limite de página. A lista de sensores é o catálogo completo da horta; a paginação destina-se ao histórico crescente de leituras. Não há cache público de dados privados.

Consumo vem exclusivamente de `consumo_agua`, excluindo `simulacao`. Legado/importação é exibido como origem não verificada. Dias ausentes no gráfico são null, não zero. Consumo diário usa o calendário `America/Sao_Paulo`, igual ao módulo de plantas; datas de medições continuam UTC. O gráfico mensal soma somente dias registrados, nos últimos seis meses, e informa quantos existem. Economia é **estimativa**, apenas onde `referencia_solo_l` foi cadastrada; pode ser negativa. Não se inventa sustentabilidade ou eficiência: o índice antigo fica “Não disponível”. O cadastro diário de consumo/ingestão IoT não foi acrescentado implicitamente a esta etapa.

A opção existente “Atualização automática” apenas consulta novamente o banco a cada 15 segundos quando habilitada. O registro de simulação exige escolha explícita no formulário e gravação autenticada.

## Testar

1. Atualize o schema, configure ambiente privado e inicie PHP conforme README. Cadastre uma conta, entre e crie duas hortas.
2. Em Monitoramento, cadastre sensor pH na primeira horta, unidade pH, limites 5.5/6.5. Sem leituras, o painel deve mostrar “não disponível”.
3. Registre manualmente pH 8 com data local: deve aparecer alerta no histórico e no dashboard. Registre depois uma simulação pH 6: ela deve ficar identificada e não esconder o alerta manual.
4. Filtre por sensor, origem e período. O gráfico deve separar origens. Consulte a próxima página; mudar filtros volta ao início.
5. Tente outra unidade, pH 15, data inválida, sensor de outra horta ou lote com sensor repetido: deve falhar sem evento parcial.
6. Edite os limites: o alerta histórico deve conservar os limites originais. Trocar unidade/alvo de sensor com leitura e excluí-lo deve falhar; inativar deve funcionar.
7. Troque para a segunda horta: histórico e indicadores anteriores devem desaparecer. Outra conta sem vínculo não deve acessar os IDs da primeira horta. Um leitor consulta, mas não registra ou altera sensores.

Suíte automatizada, sem bibliotecas extras: Python >=3.10, PHP e **banco MySQL descartável exclusivo**, schema 1.3.0, sem usuários e nome terminado em `_test`/`_tests`. Não execute na base real. Configure as variáveis usuais para essa base e rode da raiz:

```powershell
python database/tests/monitoramento.py --executar --php C:/xampp/php/php.exe
```

A suíte cria servidor PHP temporário, três usuários, duas hortas de um usuário e uma de outro; remove suas fixtures. Veja [VALIDACAO-MONITORAMENTO.md](VALIDACAO-MONITORAMENTO.md) para cobertura, regressões, navegador e arquivos alterados.
