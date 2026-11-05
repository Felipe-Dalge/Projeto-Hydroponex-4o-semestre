# Cultivo e infraestrutura

Os módulos usam MySQL/PDO, sessões PHP e os papéis de `usuario_horta`. A interface está em **Cultivo e infraestrutura**, com abas Culturas, Reservatórios, Estruturas e Nutrientes. Selecione a horta no seletor existente. Os catálogos também podem ser consultados e criados sem selecionar uma horta.

## Instalação e atualização

Instalação nova: importe `hydroponex.sql` em banco vazio pelo phpMyAdmin. O instalador já contém o schema **1.3.0**, com 21 tabelas. Não execute as migrações sobre essa instalação.

Banco existente: faça backup, teste sua restauração e suspenda as escritas durante a atualização; em schema 1.1.0, importe **somente `migrations/003_infraestrutura.sql`**, uma vez, no banco selecionado. Em schema 1.0.0, aplique antes `002_autenticacao.sql`. A migração 003 acrescenta autoria opcional a cultura/nutriente e duas FKs; preserva IDs, conteúdo e relacionamentos. Registros anteriores ficam globais, sem atribuir sua propriedade a um usuário arbitrário. Não há DROP, TRUNCATE ou desativação de FKs. A alteração DDL deve ser executada pelo administrador, separadamente da conta da aplicação; DDL MySQL pode fazer commit implícito. Consulte `schema_version` antes de executar e interrompa em caso de erro. Em seguida, aplique 004 para schema 1.3.0 antes de usar o aplicativo atualizado; consulte [MONITORAMENTO.md](MONITORAMENTO.md).

As mesmas variáveis de ambiente e credenciais privadas da [configuração local](../README.md) continuam válidas. A conta das APIs precisa apenas de SELECT/INSERT/UPDATE/DELETE no banco. Execute `php database/test_connection.php --schema` a partir da raiz: deve informar schema 1.3.0 e 21 tabelas. `verificar.sql` fornece consultas adicionais para o phpMyAdmin.

## Propriedade e permissões

| Dados | Consulta | Criação e alteração |
| --- | --- | --- |
| Cultura/nutriente global (`id_criador IS NULL`) | Qualquer autenticado | Curadoria administrativa fora das APIs públicas |
| Cultura/nutriente pessoal | Criador e membros de hortas ativas que o utilizam | Criador; edição/exclusão somente enquanto não houver vínculos |
| Reservatório/estrutura | Membros da respectiva horta ativa | Proprietário ou gestor dessa horta |
| Vínculos e aplicações | Membros da respectiva horta ativa | Proprietário ou gestor dessa horta |

Não foi inventado um administrador global: `usuario.tipo` continua descritivo, sem conceder privilégios. A criação de catálogo pela interface sempre usa o usuário da sessão como criador. Um leitor pode manter seu catálogo pessoal, mas não instalar recursos nem vincular itens à horta. Ao compartilhar um item numa horta, seus membros passam a consultar seus dados de catálogo; histórico, aplicações e instalações continuam restritos à horta consultada.

Catálogos em uso ficam congelados, inclusive para seu criador, para não alterar o significado de cultivos e registros históricos de outros membros. **Copiar** cria uma versão pessoal editável, sem copiar vínculos. Para culturas, habilite a versão desejada na horta e selecione-a no formulário da planta. Culturas não são aplicadas automaticamente a todas as plantas.

## Contrato comum das APIs

Base: `php/api/`. Todas exigem sessão autenticada; mutações também exigem `X-CSRF-Token`, obtido pela API de sessão. Envie `Content-Type: application/json`, `credentials: 'same-origin'` e objetos JSON. Não envie usuário/criador/papel no corpo. Campos desconhecidos são rejeitados. Identificadores são inteiros positivos.

| Rota | Operação |
| --- | --- |
| `culturas.php`, `nutrientes.php` | GET lista; GET `?id=ID` detalhe; POST cria; PATCH/PUT `?id=ID` edita; DELETE `?id=ID` exclui |
| `reservatorios.php?horta=H` | Mesmos métodos; detalhe/edição/exclusão acrescentam `&id=ID` |
| `estruturas.php?horta=H` | Mesmos métodos; detalhe/edição/exclusão acrescentam `&id=ID` |
| `culturas_horta.php?horta=H` | GET vínculos; POST habilita; DELETE desvincula |
| `nutrientes_vinculos.php?horta=H` | GET sensores/aplicações/leituras; PATCH vínculo do sensor; POST aplicação |

Nos catálogos, `?horta=H` é opcional: valida acesso à horta e, em culturas, acrescenta `vinculada` à resposta. Não concede acesso a catálogos privados de terceiros. Listas usam `data: []`; detalhes e criações usam `data: {}`. Há `id` além da chave específica, `pode_editar` e `pode_excluir`; catálogos têm `escopo` (`global`, `pessoal`, `compartilhado`) e `em_uso`. Para globais, `em_uso` é null, evitando divulgar utilização agregada em hortas privadas. O identificador do criador não é exposto.

Sucesso: GET/edição/exclusão 200, criação 201. Erros: 401 sessão ausente/inválida; 403 papel insuficiente, CSRF ou tentativa de alterar catálogo global/de terceiros; 404 objeto inexistente ou fora do escopo; 409 dependências, catálogo pessoal em uso ou conflito; 422 dados inválidos; 500 falha interna com mensagem genérica. SQL e credenciais não são devolvidos. PUT e PATCH aceitam atualização parcial; campos omitidos são preservados.

### Culturas

Corpo exemplo:

```json
{"nome":"Alface","categoria":"Folhosa","tempo_crescimento_dias":35,"temperatura_ideal_c":22.5,"ph_ideal":6.25}
```

`nome` obrigatório, até 100 caracteres. `categoria` mapeia a coluna existente `tipo`; envie somente um desses nomes. Categoria/tipo até 100 caracteres; crescimento inteiro de 0 a 65535 dias; temperatura de -273.15 a 999.99 °C; pH de 0 a 14. Decimais aceitam até duas casas. Campos opcionais podem ser null. São limites de armazenamento/validação, não recomendações agronômicas.

Habilitar: POST `culturas_horta.php?horta=H` com `{"id_cultura":C}`; repetir o mesmo vínculo é idempotente. Desvincular: DELETE com `{"id_cultura":C,"confirmacao":"DESVINCULAR"}`. Plantas associadas, inclusive arquivadas, impedem desvinculação por FK. O campo `id_cultura` de plantas exige cultura previamente habilitada nessa mesma horta.

### Reservatórios

```json
{"nome":"Reservatório principal","capacidade_l":100,"nivel_atual_l":60,"temperatura_c":23.5,"ph_atual":6.2}
```

Nome obrigatório até 100 caracteres; capacidade obrigatória, maior que zero, até 999999999.999 L. Nível opcional, de zero até a capacidade, até três casas decimais. A edição valida o estado resultante: reduzir a capacidade abaixo do nível já armazenado também é rejeitado. Temperatura e pH usam os limites de culturas. Cada reservatório pertence à horta informada; não é transferido implicitamente ao editar.

### Estruturas

```json
{"nome":"Bancada A","tipo":"NFT","qnt_unidade":12,"id_reservatorio":1}
```

Nome obrigatório até 100 caracteres; tipo opcional até 100; unidades inteiras entre 1 e 65535 (padrão 1); reservatório opcional/null, necessariamente da mesma horta. A FK composta mantém essa garantia mesmo fora da API. O formulário de plantas também oferece as estruturas da horta selecionada.

### Nutrientes, aplicações e sensores

```json
{"nome":"Solução A","classificacao":"Macronutrientes","tipo":"Mineral","descricao":"Composição e observações"}
```

Nome ou classificação é obrigatório; se o nome estiver vazio, usa-se a classificação. Nome/classificação/tipo até 100 caracteres; descrição opcional até 1000 caracteres Unicode, permitindo múltiplas linhas. Nome e classificação continuam armazenados separadamente.

GET `nutrientes_vinculos.php?horta=H` retorna `data.sensores`, as 100 aplicações mais recentes em `data.aplicacoes` e as 20 leituras mais recentes de sensores vinculados a nutrientes em `data.leituras`. Todos os resultados pertencem à horta autorizada. As datas são UTC.

PATCH nessa rota: `{"id_sensor":S,"id_nutriente":N}`; use null para remover a associação. Sensor deve pertencer à horta, nutriente deve ser acessível. Havendo leituras, não se pode trocar/remover o nutriente, para preservar o significado histórico; repetir o vínculo atual é permitido. A interface lista sensores existentes; o CRUD de sensores e a coleta manual estão implementados na tela Monitoramento, conforme [MONITORAMENTO.md](MONITORAMENTO.md).

POST nessa rota: `{"id_reservatorio":R,"id_nutriente":N,"quantidade":2.5,"unidade":"mL"}`. Quantidade maior que zero até 999999999.999, três casas; unidades exatas: `g`, `mg`, `mL`, `L`. Opcionalmente `aplicado_em` aceita data ISO com fuso explícito; por padrão usa o instante atual. O responsável vem da sessão. Reservatório e nutriente são validados na transação. Aplicações são registros históricos imutáveis nesta API; não se calculam concentração, receita ou telemetria simulada a partir da quantidade aplicada.

## Exclusões e integridade

DELETE de recurso exige `{"confirmacao":"EXCLUIR"}`. Não se usam cascatas novas. Catálogo pessoal só é excluído sem dependências. Estruturas referenciadas por plantas/sensores e reservatórios referenciados por estruturas/sensores/aplicações não podem ser excluídos: resposta 409 e nenhuma alteração parcial. Registros arquivados também contam como dependências. Corrija os vínculos permitidos ou mantenha o registro histórico; não apague FKs para contornar o bloqueio.

As duas novas FKs de autoria usam RESTRICT: remoção física de usuário não pode apagar catálogo. A exclusão da própria conta existente continua lógica/anonimizada, preservando os identificadores e o histórico. As FKs compostas já existentes mantêm sensor/estrutura/planta/reservatório dentro da mesma horta. Operações com vínculos usam transações e bloqueios do usuário, horta e catálogo; autorização é revalidada no servidor, sem confiar na seleção do navegador.

## Roteiro de uso e testes

1. Cadastre uma conta, entre, crie duas hortas e abra Cultivo e infraestrutura.
2. Crie uma cultura pessoal, habilite-a somente na primeira horta e selecione-a ao cadastrar uma planta. Na segunda horta, essa opção só aparece após habilitação própria.
3. Crie reservatório com capacidade 100 e nível 60; tente nível 101 ou reduzir a capacidade para 50: deve falhar sem modificar os dados.
4. Crie estrutura associada ao reservatório. Tente excluir o reservatório: deve falhar por dependência. Estruturas da outra horta não devem ser oferecidas.
5. Crie nutriente, registre aplicação e, havendo sensor instalado, associe-o. Confira histórico e leituras. Aplicação impede excluir o nutriente/reservatório; leituras impedem mudar o significado do sensor.
6. Entre com outra conta sem acesso: hortas, equipamentos e históricos privados devem ser inacessíveis, inclusive digitando IDs na API. Um leitor consulta; gestor pode escrever na própria horta. Catálogo global permanece somente leitura.

Teste automatizado com Python 3.10+, PHP e MySQL real, usando as mesmas variáveis de ambiente, mas **banco descartável exclusivo**, com nome terminado em `_test` ou `_tests`, schema 1.2.0 e sem usuários. Não rode em produção. O script cria servidor PHP temporário, dados e três usuários e remove suas fixtures ao concluir:

```powershell
python database/tests/infraestrutura.py --executar --php C:/xampp/php/php.exe
```

A suíte verifica 83 condições, incluindo autenticação, CSRF, catálogo global/pessoal/compartilhado, leitores/gestores, duas hortas sem mistura, FKs, validação, atomicidade, culturas em plantas, aplicações e sensores com histórico. Regressões: `autenticacao.py`, `hortas.py`, `plantas.py` com os mesmos argumentos; testes PHP `configuracao.php` e `integridade.php` (este usa transação com rollback). Veja [VALIDACAO-INFRAESTRUTURA.md](VALIDACAO-INFRAESTRUTURA.md) para os resultados desta entrega.
