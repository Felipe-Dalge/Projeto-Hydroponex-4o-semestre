# Validação — sensores e monitoramentos

Data: 28/10/2026. Ambiente: PHP 8.2.12, MySQL 8.0.44 isolado, conta de aplicação limitada a SELECT/INSERT/UPDATE/DELETE e Edge com Playwright para o navegador. A instalação em uso do usuário não recebeu DDL automaticamente.

## Resultados

| Suíte | Verificações aprovadas |
| --- | --- |
| `tests/monitoramento.py` | 92 |
| `tests/infraestrutura.py` | 83 |
| `tests/plantas.py` | 72 |
| `tests/hortas.py` | 62 |
| `tests/autenticacao.py` | 64 |
| `tests/configuracao.php` | 18 |
| `tests/integridade.php` | 24 |
| **Total** | **415** |

As suítes HTTP usam servidor PHP e MySQL reais. A nova suíte cobre três usuários, duas hortas do mesmo usuário e outra de um segundo usuário; consulta de terceiros, leitores/gestores, CSRF, campos proibidos, tipos/unidades, limites, alvos exclusivos da mesma horta, dados legados e exclusões com dependências. Não são apenas testes que reproduzem a função de validação.

Também foram verificados: autor obtido da sessão; recebimento gerado pelo servidor; datas inválidas/futuras; unidade incompatível; zero preservado; lote com sensor repetido ou de outra horta sem gravação parcial; simulação explícita sem ocultar alerta manual; snapshots de limites preservados após edição; histórico imutável; filtros por planta/sensor/origem/período; paginação com empate de horário sem duplicações; sensor inativo bloqueado; manutenção de sensor de planta arquivada sem permitir reativação; consumo simulado excluído e ausência diferente de zero.

Diagnóstico `test_connection.php --schema` aprovado: PDO nativo, UTF-8, UTC e schema 1.3.0 com 21 tabelas InnoDB/utf8mb4. Sintaxe dos arquivos PHP e JavaScript verificada. O teste de integridade reverte seus dados ao terminar.

## Navegador e preservação visual

Sete fluxos conferidos no Edge:

1. Cadastro e consulta de sensor pelo formulário integrado.
2. Leitura manual fora dos limites exibindo alerta.
3. Simulação identificada no histórico.
4. Filtro por sensor com gráfico de séries separadas por origem.
5. Dashboard exibindo a medição manual e a simulação separadamente, mantendo o alerta manual.
6. Troca de horta limpando os dados anteriores e mostrando ausência de consumo/medições.
7. Formulário em viewport de 390 px sem transbordamento horizontal ou erros JavaScript de página; captura inspecionada visualmente.

O roteiro reproduzível está em [MONITORAMENTO.md](MONITORAMENTO.md). Os testes de navegador foram executados no ambiente de desenvolvimento; a suíte entregue requer apenas Python/PHP/MySQL, sem Playwright como dependência do projeto. O layout, classes e identidade existentes foram reaproveitados. CSS, configuração visual, logotipo e JSON original permanecem intactos.

## Instalação e migração

O instalador completo 1.3.0 foi importado em banco vazio. O seed atualizado também foi importado em outra base vazia: todas as leituras e consumos demonstrativos ficaram explicitamente simulados.

A migração 004 foi aplicada a cópia do schema 1.2.0, com usuário, horta, proprietário, sensor, evento, leitura decimal e consumo preexistentes. Todas as colunas anteriores dessas entidades foram comparadas antes/depois: IDs, conteúdo, valores e relacionamentos permaneceram iguais. Recebimento e snapshots legados permaneceram NULL; consumo legado passou a ter origem `importacao` (não verificada). Isso valida a atualização aditiva; a base real ainda exige backup e aplicação administrativa da migração.

As fixtures das suítes e do navegador foram removidas do banco exclusivo de testes. Servidores temporários encerrados e arquivo temporário de credenciais removido ao concluir. Bancos descartáveis de demonstração/migração permanecem apenas no servidor de testes desligado; não há credenciais adicionadas ao código.

## Arquivos novos

- `database/migrations/004_monitoramento.sql`
- `database/MONITORAMENTO.md`
- `database/VALIDACAO-MONITORAMENTO.md`
- `database/tests/monitoramento.py`
- `Hydroponex_2/hydro/Hydroponexteste2/php/services/monitoring.php`
- `Hydroponex_2/hydro/Hydroponexteste2/php/api/sensores.php`
- `Hydroponex_2/hydro/Hydroponexteste2/php/api/monitoramentos.php`
- `Hydroponex_2/hydro/Hydroponexteste2/php/api/dashboard.php`
- `Hydroponex_2/hydro/Hydroponexteste2/monitoramento.js`

## Arquivos modificados

- `database/hydroponex.sql`, `database/seed.sql`, `database/test_connection.php`, `database/verificar.sql`, `database/diagrama.md`
- `README.md`, `database/README.md` e os avisos de versão em `database/AUTENTICACAO.md`, `database/HORTAS.md`, `database/PLANTAS.md`, `database/INFRAESTRUTURA.md`
- Dentro de `Hydroponex_2/hydro/Hydroponexteste2/`: `index.html`, `script.js`, `hortas.js`, `infraestrutura.js`, `README-CRUD.md`
- `Hydroponex_2/hydro/Hydroponexteste2/php/api/nutrientes_vinculos.php`: valida o tipo do sensor e informa a origem das leituras à interface de nutrientes.

## Limites explícitos

Não há ingestão de dispositivo sem autenticação própria nem confirmação de conexão física IoT. O registro manual declara um valor; não atesta sua precisão. Importações não são promovidas a medições atuais. Tipos não reconhecidos do legado não recebem semântica ou unidade arbitrária. Dados de catálogo de cultura não se transformam em tolerâncias agronômicas inferidas. Não há edição/exclusão pública de eventos históricos, cadastro diário de consumo ou cálculo inventado de sustentabilidade. Alertas consideram os últimos registros e suas datas; não prometem leitura contínua.
