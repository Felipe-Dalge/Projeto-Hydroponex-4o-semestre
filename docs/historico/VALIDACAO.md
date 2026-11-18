# Validação executada — 27/10/2026

Ambiente: MySQL Community Server **8.0.44**, instância temporária ligada somente em `127.0.0.1:13316`, com diretório de dados próprio, separado do MariaDB local. Cliente PHP **8.2.12**, `pdo_mysql`, prepares nativos. As bases temporárias foram usadas apenas para testes; não foi configurada conexão da aplicação com elas. A instância foi encerrada após a validação.

## Resultados

| Verificação | Resultado |
| --- | --- |
| Instalação de `hydroponex.sql` em duas bases vazias | Aprovada, sem erros SQL |
| Importação opcional de `seed.sql` | Aprovada nas duas bases |
| Inventário no `information_schema` | 20 tabelas, todas InnoDB e `utf8mb4_0900_ai_ci`; 29 FKs; 26 CHECKs |
| Regras de exclusão | 28 RESTRICT e 1 CASCADE, somente tokens de usuário |
| Execução de `verificar.sql` | Todas as instruções aceitas no MySQL 8 |
| Sintaxe dos dois arquivos PHP novos | `php -l` aprovado |
| `tests/integridade.php` | **24 verificações aprovadas**, incluindo rejeições por códigos MySQL 1062, 1451, 1452 e 3819; dados de teste revertidos |
| Simulação do importador | Cinco registros válidos, sem escrita |
| Primeira importação do JSON atual | Cinco plantas criadas e cinco payloads de auditoria |
| Repetição da mesma importação | Zero criadas, cinco já importadas |
| Snapshot repetido com outra horta | Recusado, sem escrita |
| Snapshot alterado em horta já importada | Recusado, sem escrita |
| ID duplicado na origem | Recusado, sem escrita |
| Status desconhecido | Recusado, sem escrita |
| Horta inexistente | Recusada, sem escrita |
| Erro simulado na segunda inserção | Trigger temporário no banco de teste provocou falha; primeira planta e auditoria revertidas; zero plantas na horta de teste |
| Comparação de payloads migrados | Cinco registros originais semanticamente idênticos ao JSON preservado |
| Contagem após importação/repetição/falhas | Oito plantas: três seed + cinco importadas, sem duplicações |
| Preservação dos arquivos originais | **13 arquivos da árvore extraída idênticos byte a byte aos respectivos membros do ZIP**; DDL original não alterado |
| Preservação do JSON em uso | SHA-256 antes/depois idêntico: `4aa9aed73760224a8402334fc5fa85edfc9a76a58e936ec7426f8eaa8ba22253` |

O teste de rollback criou e removeu um trigger exclusivamente na instância temporária; esse trigger não faz parte do modelo entregue. Os arquivos JSON usados nos casos inválidos eram cópias temporárias, removidas ao terminar.

## Limites do que foi validado

- Importação executada pelo driver PDO no MySQL real; não foi operada a interface gráfica do phpMyAdmin. Os arquivos são SQL convencional sem comandos de cliente ou `DELIMITER`.
- Não foi executada migração de base Oracle/relacional populada, pois tal base/exportação não foi fornecida. O roteiro em `migrations/LEGADO.md` explicita os mapeamentos pendentes nesses cenários.
- Os arquivos com sufixos `(2)` e `(1)` não estavam disponíveis nas pastas verificadas; fontes efetivas e hashes estão no README.
- Login, recuperação real, autorização HTTP, atualização da API para PDO e desempenho sob carga não fazem parte da implantação validada. O protótipo continua operando com JSON.
- O diagrama descreve o schema entregue; regras agregadas como pelo menos um proprietário por horta precisam ser implementadas na camada de aplicação conforme README.
