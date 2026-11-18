# Validação do painel operacional

Etapa 3 executada no Windows com PHP 8.2.12 e MariaDB 10.4.32 do XAMPP. As bases de teste ficaram em uma instância isolada em loopback.

## Resultados executados

| Suíte | Verificações aprovadas |
| --- | --- |
| Painel operacional | 46 |
| Colaboração | 50 |
| Equipe | 40 |
| Plantas e migração JSON | 72 |
| Hortas | 62 |
| Autenticação | 64 |
| Infraestrutura | 83 |
| Monitoramento | 92 |
| Integridade do banco | 24 |

O diagnóstico PDO confirmou o schema 1.6.0, as 26 tabelas InnoDB, utf8mb4, UTC, prepared statements nativos e restrições ativas. A sintaxe foi conferida em 52 arquivos PHP e 10 JavaScript. O HTML foi conferido quanto a IDs repetidos e caminhos dos scripts, e os links locais da documentação principal foram verificados.

O teste da migração 007 importou uma base 1.5.0 com dados iniciais, uma tarefa concluída e um registro de trabalho. Comparou as colunas anteriores de sete tabelas antes e depois e confirmou a preservação dos valores, as 43 chaves estrangeiras e a ausência de uma data inventada na conclusão antiga.

## Fluxos operacionais

Os testes HTTP verificaram conta sem hortas, uma ou várias hortas atribuídas, administrador trabalhando sozinho, horta vazia, estados Atenção e Alerta, filtros combinados, medição antiga, simulação identificada, leitura ausente, paginação, histórico autorizado e isolamento entre hortas. Também verificaram a persistência do andamento, a estabilidade da data de conclusão em requisições repetidas, a reabertura de tarefa, observações vinculadas à planta, rejeição de associações cruzadas e revogação na sessão atual.

No Edge, foram executados o login por perfil, o acesso administrativo à visão operacional, a consulta de hortas e plantas, o detalhamento de uma leitura antiga fora do limite, o registro de observação e a conclusão de tarefa. O recarregamento recuperou os dados persistidos. O teste usou texto contendo marcação HTML em uma observação e conferiu sua apresentação como texto.

A revisão visual encontrou o menu lateral comprimindo o conteúdo no celular. O roteamento foi corrigido para manter a regra responsiva do menu. A captura posterior e as verificações de largura confirmaram o painel em 320 e 390 pixels, com o menu móvel abrindo e fechando pelo botão do cabeçalho.

## Arquivos da etapa

- `public/php/api/operacional.php` e `public/php/services/operational.php` implementam as consultas operacionais.
- `public/assets/js/operacional.js` implementa o painel e os detalhes.
- `public/index.html`, `public/assets/css/style.css`, `script.js`, `hortas.js` e `colaboracao.js` integram formulários, navegação e exibição responsiva.
- As APIs `tarefas.php` e `registros.php` aceitam o vínculo opcional com planta. Os serviços `collaboration.php` e `garden_plants.php` validam e preservam os relacionamentos.
- Os esquemas MySQL e MariaDB incluem 1.6.0, e cada produto tem a migração incremental `007_painel_operacional.sql`.
- Os scripts de instalação e diagnóstico esperam 1.6.0. A documentação de instalação, APIs e modelo foi atualizada.
- `tests/operacional.py` cobre os novos fluxos. [Contrato da API e critérios dos indicadores](../apis/OPERACIONAL.md)

## Executar localmente

Faça backup e aplique a migração 007 em uma base 1.5.0 antes de publicar o código. Entre como agricultor para abrir o painel. Na conta administrativa, use **Painel do agricultor**. Para atribuir uma tarefa a uma planta, abra **Trabalho na horta**, selecione a horta e preencha a planta opcional no formulário de tarefa.

Para repetir os testes, use uma base descartável 1.6.0 sem usuários e execute `python tests/operacional.py --executar --php C:/xampp/php/php.exe`. [Preparação do ambiente](README.md)

O teste no navegador usou o servidor de desenvolvimento do PHP do XAMPP. A publicação Apache da instalação do usuário e a variante MySQL 8 dependem de validação nesses ambientes.
