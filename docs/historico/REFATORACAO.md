# Relatório da refatoração estrutural

## Organização

`public/` reúne o conteúdo publicado em `htdocs/hydroponex`. A pasta possui `assets/` para recursos da interface, `php/` para a aplicação PHP e `server/` para execução local. Essa estrutura mantém os caminhos relativos das APIs e permite que o instalador publique a aplicação no DocumentRoot atual do XAMPP. O Apache e o router de desenvolvimento bloqueiam as pastas PHP internas.

| Origem | Destino |
| --- | --- |
| `Hydroponex_2/hydro/Hydroponexteste2/` | `public/` |
| JavaScript, CSS e `img/` da pasta anterior | `public/assets/js/`, `public/assets/css/` e `public/assets/images/` |
| `database/tests/` | `tests/` |
| `database/diagnostico_mariadb.php` e `database/test_connection.php` | `scripts/` |
| `criacao_tabelas.sql` | `database/legado/criacao_tabelas.sql` |
| Identificação acadêmica | `docs/academico/` |
| Guias XAMPP na raiz | `docs/instalacao/` |
| Contratos de API em `database/` e guia CRUD da aplicação | `docs/apis/` |
| Migração, diagrama e banco legado | `docs/banco-de-dados/` |
| Relatórios e registro original da modelagem | `docs/historico/` |

O README apresenta as funcionalidades implementadas, requisitos, instalação e links para os guias. A documentação de arquitetura e desenvolvimento foi organizada em `docs/arquitetura/` e `docs/desenvolvimento/`.

O instalador passou a copiar `public/` e executar os diagnósticos nos caminhos atuais. Os scripts CLI, testes e importador de JSON receberam os novos caminhos de inclusão. O HTML e o JavaScript usam os novos endereços de recursos estáticos. As rotas, as regras de negócio e o esquema SQL foram preservados.

## Validação

O ambiente isolado usou PHP 8.2.12, Apache do XAMPP e MariaDB 10.4.32. A base e o DocumentRoot de teste eram temporários.

| Verificação executada | Resultado |
| --- | --- |
| `php -l`, `node --check` e análise sintática dos testes Python | 40 arquivos PHP, seis JavaScript e cinco testes Python aprovados |
| `php scripts/diagnostico_mariadb.php --schema` | Conexão PDO e schema 1.3.0 com 21 tabelas validados |
| `php tests/configuracao.php`, `compatibilidade.php`, `instalacao_local.php` e `integridade.php` | 54 verificações aprovadas |
| `python tests/<suite>.py --executar --php C:/xampp/php/php.exe` | 373 verificações aprovadas em autenticação, hortas, plantas, infraestrutura e monitoramento |
| Instalador PowerShell com XAMPP isolado | Banco, conta, configuração e publicação concluídos |
| Requisições ao Apache instalado | Oito recursos estáticos servidos com o conteúdo esperado. Diretórios internos e router bloqueados por HTTP |
| Fluxo autenticado no Apache | Cadastro, login, criação de horta e planta, e contagem no dashboard aprovados |
| Links relativos, `git status` e `git diff --check` | Referências locais encontradas e checagem de espaços aprovada |

As verificações executadas foram no ambiente MariaDB. A execução em MySQL fica fora desta rodada. A seção de capturas de tela está preparada para a inclusão de imagens reais. `dist/` continua ignorado e contém artefatos de distribuição anteriores.

## Revisão no Git

Os movimentos foram realizados com `git mv`. O índice permite revisar o conjunto com `git diff --cached --stat` e `git diff --cached`. Sugestão de mensagem: `Reorganiza estrutura e documentação do Hydroponex`.
