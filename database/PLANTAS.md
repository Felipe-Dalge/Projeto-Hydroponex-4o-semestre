# Plantas — MySQL, filtros e migração de JSON

> Versão atual: schema 1.3.0. Em banco 1.2.0, aplique 004 após backup; versões anteriores aplicam antes 002/003 conforme necessário. Instalação nova já incorpora as mudanças. Consulte [monitoramento](MONITORAMENTO.md) e [infraestrutura](INFRAESTRUTURA.md).

O CRUD de plantas agora usa **somente MySQL/PDO**. Não existe leitura, criação, fallback ou gravação de arquivos JSON nas APIs. `php/config/config.php` conserva sessão, cookies, CSRF, cabeçalhos e conexão; as funções de persistência JSON foram removidas. Os arquivos originais permanecem intactos e bloqueados por HTTP, disponíveis apenas para backup/migração administrativa.

O schema **1.1.0** já contém todos os campos; esta etapa não exige migração de DDL nem reaplicação de `hydroponex.sql`. Configuração PHP/MySQL e autenticação continuam descritas no [README principal](../README.md) e em [AUTENTICACAO.md](AUTENTICACAO.md).

## Uso na interface

1. Faça login e selecione uma horta no seletor existente ou em **Minhas Hortas → Ver plantas**.
2. Clique em **Cadastrar Nova Planta**. Nome e localização são obrigatórios. Tipo, crescimento, medições e estrutura são opcionais; deixe desconhecidos em branco, não preencha zeros para representá-los.
3. Use nome/status para filtrar a tabela. Horta é sempre um filtro obrigatório no servidor. O dashboard e os cartões de hortas mostram totais de plantas ativas da horta inteira, independentemente do filtro da tabela.
4. Use **Detalhes da planta** para consultar todos os campos, **Editar** para alterá-los e **Excluir** para confirmar a retirada da listagem, preservando o histórico.
5. Use **Transferir planta** para escolher outra horta em que tenha acesso de escrita. A operação mantém o ID da planta. Vínculos de estrutura/cultura precisam ser explicitamente tratados; sensores e auditoria de importação bloqueiam transferências comuns.

Os formulários existentes foram reaproveitados, com novos campos e diálogos nas mesmas classes visuais. O seletor de hortas permanece a fonte do contexto em memória, nunca da autorização. O antigo simulador deixou de fabricar temperaturas/status: a opção **Atualização automática** apenas consulta o banco a cada 15 segundos e vem desligada. Gráficos históricos de consumo demonstrativos continuam fora do escopo desta etapa; não representam leituras criadas por este CRUD.

O indicador **Consumo Hoje** lê `consumo_agua.volume_l` da horta selecionada para a data em `America/Sao_Paulo`. Sem registro, mostra “Sem registro”, em vez de um número fictício. Esse indicador não soma `agua_l` das plantas. A listagem retorna esses dados em `indicadores: {data, consumo_hoje_l}`.

## Mapeamento de campos

| Frontend/JSON anterior | Coluna MySQL / campo canônico | Decisão |
| --- | --- | --- |
| `id` | `id_planta` | Novo ID AUTO_INCREMENT; ID antigo fica em `importacao_json.id_origem`. A API retorna ambos `id` e `id_planta`. |
| Horta selecionada | `id_horta` | Obrigatória; definida pelo parâmetro `horta`, com permissão verificada no banco. Não é aceita para transferência implícita no corpo de edição. |
| `nome` | `nome` | 1–100 caracteres Unicode. |
| `tipo` | `tipo` | Até 100 caracteres, opcional; não se infere cultura por nome. |
| `bancada` | `localizacao` | Texto de localização, 1–100 caracteres. Não se cria estrutura automaticamente a partir desse texto. |
| `dias_crescimento` | `dias_crescimento` | Inteiro opcional, 0–65535 dias. |
| `ph_atual` | `ph_atual` | 0–14, até duas casas decimais; não usa o antigo limite incorreto 9,99. |
| `temp` | `temperatura_agua_c` | Temperatura da água em °C, até duas casas; domínio físico/coluna de −273,15 a 999,99. Isso não representa faixa agronômica recomendada. O protótipo chamava o campo de “ideal”; a tela agora identifica temperatura da água. |
| `umidade` | `umidade_percentual` | 0–100%, até duas casas, preservado como medida independente. |
| `nutrientes` | `nivel_nutrientes_ppm` | Não negativo, até 999999999,999 e três casas. |
| `nivel_irrigacao` | `nivel_irrigacao` | Não negativo, três casas. Unidade da instalação/legado precisa ser definida operacionalmente; não se inventa uma unidade. |
| `agua` | `agua_l` | Água informada em litros, não negativa, três casas. Não é convertida em irrigação ou consumo diário. O significado operacional de registros antigos deve ser confirmado; o payload original é preservado. |
| `status` | `status_planta` | `Normal`, `Atenção` ou `Alerta`. Sem status na criação: `Normal`; não se calcula classificação por dados fictícios. |
| `lastUpdate` | `ultima_medicao_em` / `atualizado_em` | Na importação, só uma data absoluta válida pode virar medição. `Agora` ou hora sem data ficam apenas no payload. Na API, `lastUpdate` é alias de última medição, se conhecida, ou atualização do cadastro; campos explícitos distinguem as duas coisas. |
| Estrutura/cultura | `id_estrutura`, `id_cultura` | Opcionais. Estrutura deve estar na horta; cultura deve estar habilitada em `horta_cultura`. |

Aliases `bancada`, `temp`, `umidade`, `nutrientes`, `agua` e `status` continuam aceitos/retornados para compatibilidade de contrato, mas **não significam persistência em JSON**. Enviar alias e campo canônico juntos é recusado para evitar ambiguidade. Booleanos, arrays, números não finitos, intervalos inválidos e precisão excessiva são recusados; não há arredondamento silencioso. Campos opcionais podem ser explicitamente definidos como `null`, inclusive em PATCH.

## Rotas

Todas exigem usuário ativo autenticado. Leitor consulta; gestor/proprietário escreve. O usuário é identificado exclusivamente pela sessão. Mutações exigem JSON e `X-CSRF-Token`.

| Método e rota | Operação |
| --- | --- |
| `GET /php/api/plantas.php?horta=42` | Lista plantas ativas, permissões e `resumo` com `total`, `normal`, `atencao`, `alerta` da horta inteira. |
| `GET /php/api/plantas.php?horta=42&nome=alface&status=Normal` | Combina filtros. Nome é busca literal parcial, sem permitir injeção ou interpretar `%`/`_` como curingas. |
| `GET /php/api/plantas.php?horta=42&id=7` | Consulta detalhes pelo par horta + planta. |
| `POST /php/api/plantas.php?horta=42` | Cadastra; retorna 201 com o novo ID. |
| `PUT/PATCH /php/api/plantas.php?horta=42` | Atualização parcial; corpo inclui `id` e os campos alterados. |
| `DELETE /php/api/plantas.php?horta=42` | Corpo `{ "id": 7, "confirmacao": "EXCLUIR" }`; exclusão lógica confirmada. |
| `POST /php/api/plantas_transferir.php?horta=42` | Transferência explícita com autorização nas duas hortas, confirmação e transação. |

Sem `horta`, a API retorna 422; não escolhe uma horta automaticamente e não acessa o JSON antigo. Terceiros recebem 404 para horta/planta fora de seu alcance; membro sem papel de escrita recebe 403. Identificadores inválidos/campos não permitidos retornam 422. Horta arquivada não é destino ativo.

Exemplo de cadastro:

```json
{
  "nome": "Alface Americana",
  "tipo": "Folhosa",
  "localizacao": "Bancada A1",
  "dias_crescimento": 35,
  "ph_atual": 6.25,
  "temperatura_agua_c": 21.5,
  "umidade_percentual": 68,
  "nivel_nutrientes_ppm": 750.125,
  "nivel_irrigacao": 1.25,
  "agua_l": 2.5,
  "status_planta": "Normal",
  "ultima_medicao_em": "2026-10-28T12:30:00Z",
  "id_estrutura": null,
  "id_cultura": null
}
```

Datas aceitam ISO 8601 completo com fuso ou data/hora MySQL interpretada em UTC, com até seis casas de segundos. O formulário utiliza o horário local do navegador e converte para UTC. `criado_em`/`atualizado_em` são gerados pelo banco e não são graváveis pelo cliente. Não se cria monitoramento/sensor fictício ao cadastrar uma medição manual de snapshot.

Datas inexistentes e offsets inválidos são recusados (offsets limitados a ±14 horas). Ao editar outro campo sem mudar o horário apresentado, o formulário conserva os microssegundos originais da medição, mesmo exibindo o controle de data com precisão de segundos.

## Exclusão e transferência seguras

A exclusão define `planta.arquivada_em`, retira a planta de listas/contagens ativas e conserva a PK, sensores históricos e auditoria. Uma planta com sensor ativo ou em manutenção exige desativá-lo antes de excluir (409). Sensor inativo continua associado ao registro arquivado. Não há cascata nem exclusão física automática, e a API comum não restaura planta excluída. Horta com plantas arquivadas continua com dependências e não é liberada para exclusão física só por elas saírem da listagem.

Exemplo de transferência:

```json
{
  "id": 7,
  "horta_destino": 43,
  "confirmacao": "TRANSFERIR",
  "id_estrutura": null,
  "id_cultura": null
}
```

Os valores nulos removem vínculos **explicitamente**, após confirmação. Alternativamente, a API aceita IDs válidos no destino. Se houver vínculo na origem e nenhum tratamento explícito, retorna 409. O formulário oferece confirmação para remover os vínculos e permite associar uma estrutura depois no destino.

Plantas com qualquer sensor ou `importacao_json` não são transferidas pelo CRUD comum (409): esses vínculos têm contexto/horta e rastreabilidade que precisam de um procedimento administrativo específico. Não se muda o contexto histórico de sensores nem se quebra a idempotência do importador. Para as plantas sem esses impedimentos, ID, campos e datas de medição permanecem, atualizando somente horta/associações e timestamp do cadastro.

As escritas confirmam a conta e a associação sob bloqueio, usam transação e mantêm FKs. Transferência bloqueia as duas hortas em ordem de ID para reduzir deadlocks. Falha de autorização, vínculo, validação ou banco provoca rollback. Não há alteração de usuário/papel a partir do corpo da requisição.

## Migração dos arquivos existentes

O importador `database/migrations/001_importar_json.php` foi reaproveitado e passou a compartilhar o validador da API. Ele reconhece aliases/campos canônicos, preserva todos os campos conhecidos e guarda **o registro original completo**, incluindo campos desconhecidos, em `importacao_json.payload`.

1. Faça backup e congele gravações de clientes antigos. A API atual já não escreve JSON. Inventarie o arquivo global `php/storage/plantas.json` e eventuais arquivos pessoais `php/storage/usuarios/<id>/plantas.json`.
2. Crie/escolha explicitamente a horta de destino e confirme seus responsáveis. O arquivo global não tem dono confiável: não o atribua automaticamente ao primeiro usuário.
3. Com credenciais administrativas DML autorizadas no ambiente, simule a importação e revise a origem/quantidade. A simulação valida sem conectar ao banco.
4. Aplique e confira os resultados SQL/API. Repita o mesmo comando para confirmar que cria zero registros adicionais. O JSON não é apagado, movido ou alterado, mesmo após sucesso.

```powershell
# Raiz do projeto, variáveis de conexão definidas conforme README.
& C:/xampp/php/php.exe database/migrations/001_importar_json.php --horta=42
& C:/xampp/php/php.exe database/migrations/001_importar_json.php --horta=42 --aplicar
# Para um arquivo pessoal/backup, especifique também:
& C:/xampp/php/php.exe database/migrations/001_importar_json.php --horta=43 --arquivo=C:/backup/plantas-pessoais.json --aplicar
```

Sem `--arquivo`, usa o arquivo global original. O `--horta` é obrigatório e a horta deve existir e estar ativa. IDs de origem ficam no mapa de auditoria; novos IDs evitam colisões com plantas existentes. IDs/nomes/valores/status/datas são validados antes de gravar. Vínculos de estrutura/cultura, quando fornecidos, são conferidos no destino dentro da transação.

Idempotência: SHA-256 dos bytes da origem + ID original. Repetir o mesmo snapshot na mesma horta não duplica. O mesmo snapshot em outra horta é recusado. Um snapshot diferente na horta já importada também é recusado para exigir reconciliação, em vez de duplicar versões. Não é sincronização contínua. Plantas importadas e depois excluídas logicamente não são recriadas/reativadas por repetição da migração.

Uma falha em qualquer linha reverte plantas e auditoria de toda a execução. O payload mantém `lastUpdate` relativo e qualquer informação sem semântica definida; não se inventam timestamps, equipamentos ou unidades para preencher lacunas. Não use arquivos diferentes para contornar conflitos sem revisar o mapa de origem.

Conferência após importar, no banco selecionado:

```sql
SELECT p.id_horta, COUNT(*) AS registros, SUM(p.arquivada_em IS NULL) AS ativos
FROM planta p WHERE p.id_horta = 42 GROUP BY p.id_horta;
SELECT i.id_origem,i.id_planta,i.origem_sha256,i.payload
FROM importacao_json i JOIN planta p ON p.id_planta=i.id_planta
WHERE p.id_horta=42 ORDER BY i.id_origem;
```

Use também `GET /php/api/plantas.php?horta=42` com sessão autorizada. Compare os valores, o payload e a quantidade; depois confirme outra execução sem duplicação. Não apague o backup antes da conferência/restauração testada. A entrega testa o procedimento em banco isolado; não escolhe um destino de produção sem o mapeamento do responsável.

## Testes

Configure banco **separado** com schema 1.1.0, nome `_test`/`_tests` e sem usuários, usando uma conta DML e chave de autenticação válidas. Python >=3.10, biblioteca padrão:

```powershell
python database/tests/plantas.py --executar --php C:/xampp/php/php.exe
python database/tests/hortas.py --executar --php C:/xampp/php/php.exe
python database/tests/autenticacao.py --executar --php C:/xampp/php/php.exe
& C:/xampp/php/php.exe database/tests/configuracao.php
& C:/xampp/php/php.exe database/tests/integridade.php
```

Os testes iniciam PHP em cópia temporária, não escrevem no arquivo original e removem suas fixtures. Não execute simultaneamente na mesma base. Cobrem duas hortas com plantas diferentes, terceiros, papéis, filtros, campos/ranges/precisão, transferência, exclusão lógica, migração real/idempotência, dados integrais e rollback após primeira inserção. Resultados e arquivos alterados: [VALIDACAO-PLANTAS.md](VALIDACAO-PLANTAS.md).
