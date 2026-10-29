# Validação da migração do CRUD de plantas — 28/10/2026

PHP 8.2.12, MySQL Community 8.0.44 em instância temporária isolada e conta SELECT/INSERT/UPDATE/DELETE sem DDL. Schema 1.1.0, sem alteração de estrutura. As APIs e a importação foram exercitadas contra MySQL real; operações de interface usaram cópias temporárias da aplicação. Nenhum banco do MariaDB local foi alterado.

## Resultados

| Verificação | Resultado |
| --- | --- |
| `tests/plantas.py` | **72 verificações HTTP/SQL/migração aprovadas** |
| Seleção e isolamento | Horta obrigatória, visitantes/terceiros bloqueados, duas hortas com plantas diferentes e sem mistura por ID |
| Campos | Nome/tipo/localização/crescimento/pH/temperatura/umidade/nutrientes/irrigação/água/status/datas e aliases preservados |
| Validação | Intervalos, booleanos, tipos, precisão excessiva, datas inexistentes/offset inválido e colisão entre alias/campo canônico recusados |
| Opcionais | PATCH pode limpar valores com NULL; não substitui NULL por zero nem pelo valor antigo |
| Filtros | Nome + status combinados, `%` literal e busca sem injeção SQL; resumo da horta independe do filtro |
| Indicadores | Contagens de plantas/status e consumo diário lido de `consumo_agua` da horta correta; ausência de consumo não fabrica valor |
| Permissões | Leitor consulta; gestor/proprietário escreve; transferência exige escrita no destino e na origem |
| Exclusão | Confirmação obrigatória, arquivamento lógico, contagens atualizadas, sensor ativo bloqueia e sensor inativo mantém FK histórica |
| Transferência | ID/dados mantidos; estrutura de outra horta recusada; vínculos exigem tratamento explícito; sensores e auditoria bloqueiam mudança insegura |
| Migração original | Cinco registros reais do JSON foram importados em horta explícita de teste |
| Idempotência | Segunda execução cria zero; mesmo snapshot em outra horta e snapshot alterado no destino são recusados |
| Preservação | Cinco payloads integrais iguais aos registros originais; `Agora` não vira timestamp de medição; umidade/água/irrigação não são confundidas |
| Migração e exclusão | Planta importada pode sair da listagem sem perder auditoria; repetir importação não a recria/reativa |
| Atomicidade | Falha de vínculo na segunda linha reverte também a primeira planta e a auditoria da execução |
| Regressão de autenticação | **64 verificações aprovadas**, adaptadas ao escopo MySQL obrigatório |
| Regressão de hortas | **62 verificações aprovadas**, inclusive ausência de fallback JSON |
| Configuração / integridade | **18 / 24 verificações aprovadas** |
| Diagnóstico PDO/schema | Schema 1.1.0 com 21 tabelas aprovado |
| Sintaxe | Todos os arquivos PHP aprovados em `php -l`; `script.js`, `hortas.js` e `plantas.js` aprovados em `node --check` |

## Interface real no navegador

Microsoft Edge, contextos novos/isolados, sem instalar biblioteca ou framework no projeto:

- Cadastro pelo formulário ampliado com todos os campos de domínio.
- Detalhes recuperados da API e conversão de horário local de São Paulo para UTC.
- Duas plantas cadastradas, filtros combinados e dashboard mantendo o total da horta.
- Edição de pH/status persistida; planta deixa de corresponder ao filtro e indicadores são atualizados.
- Transferência confirmada pelo diálogo, alternância entre duas hortas e listagens sem mistura.
- Exclusão confirmada pelo diálogo existente, retirada da listagem e contagem atualizada.
- Formulário completo responsivo em 390 × 844 sem overflow horizontal.
- Indicador Consumo Hoje exibe valor real `9,25 L` registrado na horta, sem usar água da planta.
- Edição somente do nome conserva `ultima_medicao_em` com seis casas de microssegundos.
- Nenhum erro JavaScript de aplicação durante os fluxos completos.

## Arquivos e dados preservados

O JSON original continua idêntico byte a byte, com SHA-256 `4aa9aed73760224a8402334fc5fa85edfc9a76a58e936ec7426f8eaa8ba22253`. CSS, configuração Tailwind e imagem também continuam idênticos ao ZIP de referência. Não foi apagado/movido nenhum arquivo de dados.

A API deixou de conter funções de leitura/escrita de arquivos JSON. `json_encode`/`json_decode` continuam sendo usados para o protocolo HTTP e para o importador/auditoria, não como persistência de arquivos. Sessões PHP e suas proteções foram mantidas.

As fixtures SQL foram removidas, os servidores PHP de teste foram encerrados e a instância MySQL temporária foi encerrada ao concluir. Credenciais de teste não estão no projeto. A importação executada foi em **banco isolado**: não foi escolhido um destino de produção automaticamente.

## Arquivos novos

- `Hydroponex_2/hydro/Hydroponexteste2/php/services/plant_fields.php`
- `Hydroponex_2/hydro/Hydroponexteste2/php/api/plantas_transferir.php`
- `Hydroponex_2/hydro/Hydroponexteste2/plantas.js`
- `database/tests/plantas.py`
- `database/PLANTAS.md`
- `database/VALIDACAO-PLANTAS.md`

## Arquivos modificados

- `Hydroponex_2/hydro/Hydroponexteste2/php/api/plantas.php`
- `Hydroponex_2/hydro/Hydroponexteste2/php/config/config.php`
- `Hydroponex_2/hydro/Hydroponexteste2/php/services/garden_plants.php`
- `Hydroponex_2/hydro/Hydroponexteste2/script.js`
- `Hydroponex_2/hydro/Hydroponexteste2/index.html`
- `Hydroponex_2/hydro/Hydroponexteste2/hortas.js`
- `database/migrations/001_importar_json.php`
- `database/tests/autenticacao.py`
- `database/tests/hortas.py`
- `README.md`
- `database/README.md`
- `database/AUTENTICACAO.md`
- `database/HORTAS.md`
- `Hydroponex_2/hydro/Hydroponexteste2/README-CRUD.md`

Todos os caminhos são relativos à raiz do projeto.

## Limites explícitos

Não há nova migração de DDL. O operador precisa escolher a horta/responsável para cada arquivo antigo e executar o importador; o arquivo global não tem dono confiável. O importador não é sincronizador contínuo e bloqueia conflitos de snapshots para exigir reconciliação.

Exclusão é lógica, sem purga/restauração pública. Sensores ativos precisam ser desativados antes de excluir a planta. Transferências com sensores ou auditoria exigem procedimento administrativo próprio; o CRUD comum não quebra esses vínculos.

A unidade de irrigação e a interpretação operacional de água de registros legados não são inventadas. Gráficos históricos demonstrativos de consumo não foram transformados em telemetria real; o indicador diário e os indicadores de plantas usam o banco. A atualização automática agora somente consulta dados, sem gerar temperaturas/status aleatórios.

Contrato, campos, procedimento idempotente e conferência pós-importação: [PLANTAS.md](PLANTAS.md).
