# Validação da integração PDO — 28/10/2026

PHP **8.2.12**, driver `pdo_mysql` e MySQL Community **8.0.44**, em instância temporária acessível apenas por `127.0.0.1:13316`. Banco, usuários e credenciais de teste separados do serviço MariaDB local. O schema da etapa anterior foi instalado em uma base temporária nova. Nenhuma configuração real de produção ou senha foi gravada no projeto.

## Resultados executados

| Verificação | Resultado |
| --- | --- |
| Sintaxe dos seis arquivos PHP novos/alterados | `php -l` aprovado |
| `database/tests/configuracao.php` | **18 casos aprovados**, sem conexão: campos obrigatórios, defaults, precedência do DSN, porta inválida, injeção em host/nome, driver/charset incorretos, campos repetidos/opções extras e mensagens/logs sem segredo |
| Conexão com variáveis separadas | Aprovada; mesma instância PDO reutilizada no processo |
| Conexão com `HYDROPONEX_DSN` da etapa anterior | Aprovada |
| Diagnóstico com conta limitada a `SELECT` | Aprovado, inclusive `--schema`; nenhuma escrita exigida |
| Opções PDO | Exceções, prepared statements nativos e fetch associativo confirmados |
| Parâmetro com acentos, emoji, aspas e pontuação SQL | Retornou o mesmo texto, como dado; charset/collation/UTC corretos |
| Múltiplas instruções na mesma chamada | Recusadas pelo driver/servidor |
| Credencial incorreta, porta indisponível e banco inexistente | Código de saída 1, mensagem genérica, sem SQLSTATE, credenciais ou stack trace |
| Configuração ausente | Código de saída 1 e erro seguro |
| PHP sem extensões (`php -n`) | Driver ausente detectado e erro seguro |
| `--schema` contra banco vazio | Falha controlada, sem expor tabela/query ou detalhes do driver |
| Argumento desconhecido / `--help` sem banco | Códigos de saída 2 / 0, respectivamente |
| `database/tests/integridade.php` com conta SELECT/INSERT/UPDATE/DELETE, sem DDL | **24 verificações aprovadas**, com rollback das fixtures |
| Importador JSON usando conexão central | Cinco plantas importadas; repetição criou zero e identificou cinco existentes |
| Sessão/CSRF por HTTP em cópia isolada do aplicativo | Token de 64 caracteres, cookies HttpOnly/SameSite=Lax e cabeçalhos preservados |
| CRUD JSON com destino MySQL indisponível | GET, POST, PUT, PATCH e DELETE aprovados na cópia; API não depende de conexão PDO |
| Rejeições do CRUD | CSRF ausente/inválido → 403; dados inválidos → 400; método inválido → 405; ID removido → 404 |
| Proteção do armazenamento no router | Acesso HTTP direto ao JSON → 404 |
| `database_for_api()` com configuração inválida ou servidor indisponível | HTTP 503 com JSON genérico, mesmo com `display_errors=1` no servidor de teste |
| Diagnóstico CLI incluído por HTTP | 404, sem execução do diagnóstico |
| Logs da cópia HTTP | Sem o marcador usado como host/banco/usuário/senha de teste |
| Preservação do código original | `config.php` tem somente duas adições: inclusão do módulo e helper para futuras APIs. Todas as funções/proteções anteriores preservadas |
| Preservação do frontend/endpoints/router/JSON | Os 11 arquivos originais restantes, excluídos `config.php` e README alterados intencionalmente, continuam idênticos byte a byte ao ZIP de referência |

As operações HTTP de escrita foram realizadas em **cópia temporária**, não no JSON em uso. O teste do helper de API utilizou endpoints somente nessa cópia; não foi adicionado endpoint público de diagnóstico ao projeto. O diagnóstico permanente é CLI e somente leitura.

Durante a validação com conta de privilégios mínimos, o MySQL retornou 1216/1217 para violações de FK em vez de 1452/1451. O teste agora aceita os pares equivalentes, sem conceder privilégios adicionais ou deixar de confirmar a rejeição. Esse comportamento é documentado no [registro oficial do MySQL](https://bugs.mysql.com/bug.php?id=99336).

## Arquivos desta etapa

Novos:

- `/.env.example`
- `/.gitignore`
- `/README.md`
- `/Hydroponex_2/hydro/Hydroponexteste2/php/config/database.php`
- `/database/test_connection.php`
- `/database/tests/configuracao.php`
- `/database/VALIDACAO-PDO.md`

Atualizados:

- `/Hydroponex_2/hydro/Hydroponexteste2/php/config/config.php`
- `/Hydroponex_2/hydro/Hydroponexteste2/README-CRUD.md`
- `/database/migrations/001_importar_json.php`
- `/database/tests/integridade.php`
- `/database/README.md`

Os caminhos acima são relativos à raiz do projeto. `index.html`, `script.js`, estilos, imagem, endpoints e JSON de produção do protótipo não foram alterados.

## Limites

Esta etapa prepara a infraestrutura PDO; não implementa autenticação, autorização HTTP ou migração automática da API. A função `database_for_api()` só é chamada por uma futura API quando ela estiver pronta para MySQL. As configurações de sessão MySQL não alteram opções globais do servidor. As variáveis precisam ser definidas pelo operador no ambiente do processo que inicia PHP; `.env.example` não é carregado automaticamente.

O serviço MySQL temporário foi encerrado após os testes. A distribuição de teste permanece no diretório temporário da etapa anterior; não faz parte da aplicação nem é necessária para usar o módulo com um MySQL local configurado.
