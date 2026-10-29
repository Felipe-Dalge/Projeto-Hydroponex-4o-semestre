# Validação da infraestrutura — 28/10/2026

Implementação validada com PHP 8.2.12, MySQL 8.0.44 isolado e conta limitada a SELECT/INSERT/UPDATE/DELETE. Banco descartável, separado de qualquer base em uso. Nenhuma migração foi aplicada automaticamente a uma instalação do usuário.

## Resultados

| Verificação | Resultado |
| --- | --- |
| `tests/infraestrutura.py` | 83 verificações aprovadas |
| Regressão `tests/plantas.py` | 72 aprovadas |
| Regressão `tests/hortas.py` | 62 aprovadas |
| Regressão `tests/autenticacao.py` | 64 aprovadas |
| `tests/configuracao.php` | 18 aprovadas |
| `tests/integridade.php` | 24 aprovadas, transação revertida |
| `test_connection.php --schema` | PDO nativo, UTF-8, UTC, schema 1.2.0, 21 tabelas |
| Sintaxe PHP e JavaScript | Sem erros nos arquivos PHP do backend/database e scripts JavaScript da aplicação |

Total: **323 verificações automatizadas**, além do diagnóstico, sintaxe e navegação descritos abaixo. As suítes HTTP exercitam requisições reais ao servidor PHP e MySQL, sem substituir o banco por mocks. A suíte nova pode ser repetida conforme [INFRAESTRUTURA.md](INFRAESTRUTURA.md).

No Edge por Playwright, foram exercitados cinco fluxos: cultura pessoal com habilitação na horta; criação/edição de reservatório e rejeição de nível acima da capacidade; estrutura vinculada ao reservatório; nutriente criado por classificação e aplicação exibida no histórico; formulário em viewport de 390 px sem transbordamento horizontal. Nenhum erro JavaScript de página foi observado. Esses testes de navegador foram executados no ambiente de desenvolvimento; o roteiro manual reproduzível está no guia. As permissões, leituras e vínculos de sensores são cobertos pela suíte HTTP.

Instalação nova: SQL completo importado com sucesso, 21 tabelas e **31 FKs**. Atualização: migração 003 executada em cópia de teste 1.1.0 com dados preexistentes; conteúdo original de culturas/nutrientes, IDs e contagens das entidades relacionadas foram comparados antes/depois e preservados. Os novos criadores ficaram NULL, como definido para o catálogo anterior. Essa comparação não dispensa backup e validação da base real antes da atualização.

Após os testes, foram confirmados zero usuários, hortas, plantas, culturas, nutrientes, reservatórios e estruturas de teste no banco exclusivo. Servidores temporários encerrados e arquivo temporário de credenciais removido ao concluir a validação. `style.css`, `tailwind.config.js`, logotipo e `php/storage/plantas.json` foram comparados byte a byte com o ZIP original e preservados.

## Cobertura de relacionamentos e autorização

- Visitante não consulta os quatro módulos; mutações sem CSRF são rejeitadas.
- Criador é obtido da sessão; campos extras e tentativa de atribuir outro usuário são rejeitados.
- Catálogo global consultável, sem edição/exclusão por usuário comum; item pessoal privado e compartilhamento por horta respeitados.
- Leitor consulta, gestor/proprietário escreve na sua horta; outra conta e outra horta não dão acesso a instalações ou históricos.
- Cultura habilitada em uma horta é independente de outra; FK impede associar planta a cultura não habilitada e impede desvinculação em uso.
- Reservatório não aceita nível acima da capacidade nem redução de capacidade incompatível com o nível existente; falha não deixa atualização parcial.
- Estrutura não aceita reservatório de outra horta. Dependências de plantas e sensores bloqueiam exclusões.
- Aplicação valida horta do reservatório, acesso ao nutriente, quantidade/unidade/data e mantém histórico.
- Vínculo de sensor exige sensor da horta; leituras impedem alteração que reinterprete seu nutriente; consultas não misturam históricos.
- Catálogos em uso e recursos com dependências retornam conflito; nenhuma nova exclusão em cascata foi introduzida.

## Arquivos entregues nesta etapa

Novos, relativos à raiz:

- `database/migrations/003_infraestrutura.sql`
- `database/INFRAESTRUTURA.md`
- `database/VALIDACAO-INFRAESTRUTURA.md`
- `database/tests/infraestrutura.py`
- `Hydroponex_2/hydro/Hydroponexteste2/infraestrutura.js`
- `Hydroponex_2/hydro/Hydroponexteste2/php/services/infrastructure.php`
- `Hydroponex_2/hydro/Hydroponexteste2/php/api/culturas.php`
- `Hydroponex_2/hydro/Hydroponexteste2/php/api/nutrientes.php`
- `Hydroponex_2/hydro/Hydroponexteste2/php/api/reservatorios.php`
- `Hydroponex_2/hydro/Hydroponexteste2/php/api/estruturas.php`
- `Hydroponex_2/hydro/Hydroponexteste2/php/api/culturas_horta.php`
- `Hydroponex_2/hydro/Hydroponexteste2/php/api/nutrientes_vinculos.php`

Modificados:

- `database/hydroponex.sql`, `database/test_connection.php`, `database/verificar.sql`, `database/diagrama.md`
- `README.md`, `database/README.md`, `database/AUTENTICACAO.md`, `database/HORTAS.md`, `database/PLANTAS.md`
- `Hydroponex_2/hydro/Hydroponexteste2/index.html`, `script.js`, `hortas.js`, `plantas.js`, `README-CRUD.md` (todos dentro da mesma pasta)
- `Hydroponex_2/hydro/Hydroponexteste2/php/services/gardens.php`

## Limites definidos

O modelo não prevê administrador global: a API não concede esse papel a partir de `usuario.tipo`. Catálogos globais são curados pelo operador autorizado do banco. Sensores existentes podem ser vinculados a nutrientes; instalação de sensores e coleta de novas leituras não fazem parte deste CRUD. Aplicações são históricas e não são editadas/excluídas por estas rotas. A aplicação não transforma uma dose em concentração automaticamente. Essas regras estão documentadas, sem remover tabelas ou registros anteriores.
