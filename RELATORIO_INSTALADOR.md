# Instalador XAMPP — implementação e validação

Data: 04/11/2026. Trabalho incremental sobre a edição MariaDB existente. A solicitação anexada foi lida integralmente. Foram revisados guias, SQL/seed/verificação MariaDB, diagnóstico, configuração PDO/sessão/autenticação, servidor/.htaccess, .gitignore e suítes existentes.

## Problemas encontrados e decisões

A execução dependia de copiar arquivos, criar banco/conta, definir SetEnv e gerar uma chave manualmente. Não havia instalador guiado, nem leitura central de configuração privada compartilhada pelo Apache/CLI. Havia risco de erro 1396 em conta existente, mudança desnecessária de chave e reimportação destrutiva por engano.

O novo instalador usa BAT -> PowerShell -> módulo PHP exclusivamente CLI. Segredos administrativos passam por stdin, nunca por argumentos de processo ou arquivos. A conta permanente é hydroponex_app@127.0.0.1 com DML limitado. Configuração operacional fica fora de htdocs, com ACL, e marcador protegido na aplicação. Foi necessário ajustar a chamada PHP no PowerShell e autenticar a conta nova sem selecionar um banco antes do GRANT; esses problemas foram corrigidos e retestados.

Banco novo/vazio recebe o instalador SQL. Banco existente precisa ter 21 tabelas esperadas, schema 1.3.0, InnoDB/collation, constraints obrigatórias e nenhum órfão; divergência bloqueia a publicação. Não há DROP, TRUNCATE ou migração DDL automática de bases existentes. Conta existente não tem a senha trocada. Privilégios excedentes são recusados sem revogar permissões de outros sistemas.

Senhas/chave são reutilizadas. A criação de conta nova salva configuração de recuperação antes do CREATE USER, com backup se substituir arquivo anterior. Assim, falha parcial não perde a única cópia da senha gerada. DDL não é transacional: importação parcial exige inspeção administrativa, sem reaplicação cega.

Na instalação padrão, o Apache já aceita .htaccess: não se alteram suas configurações globais. -ConfigureApache oferece o caminho excepcional, com backup, validação de sintaxe e restauração se inválida. Em Apache iniciado por console, -k restart pode não estar disponível como controle de serviço; o assistente pode pedir um reinício pelo XAMPP Control Panel. Não encerra à força servidores de outros projetos.

## Testes realmente executados

Binários reais do XAMPP: PHP 8.2.12, MariaDB 10.4.32 e Apache 2.4. Os testes usaram datadirs limpos isolados em 13317/13318 e Apache isolado em 18084, com DocumentRoot privado de teste. O serviço original em 3306, que anteriormente apresentou erro Aria, não foi reparado nem substituído. A instalação alternativa usou diretório com espaços e junctions para os binários existentes, com configuração Apache própria; não foi feita nova instalação binária completa do XAMPP em outra unidade física.

| Cenário solicitado | Evidência e resultado |
|---|---|
| A — banco vazio | Instância MariaDB limpa em 13318: banco hydroponex e conta criados pelo assistente, sem seed; publicação/diagnóstico/HTTP concluídos |
| B — banco válido | Reutilização da base de demonstração, preservando duas hortas, usuários, plantas e leituras |
| C — conta existente | Conta reutilizada com credencial conhecida; teste do módulo CLI sem configuração prévia também passou, sem ALTER USER |
| D — repetição | Assistente completo repetido; configuração privada comparada byte a byte em uma repetição; chave e senha comparadas novamente após testes, sem mudança; seed não duplicado |
| E — serviços desligados | Apache inicialmente desligado foi iniciado automaticamente. MariaDB indicado em porta desligada diferente do my.ini foi bloqueado antes de iniciar outro banco; partida automática do MariaDB com my.ini real desligado **não foi executada** |
| F — senha administrativa incorreta | Módulo CLI rejeitou a senha com mensagem genérica, sem exibir segredo |
| G — esquema incompatível | Versão 1.3.0 temporariamente removida apenas na base isolada; módulo recusou; registro de versão restaurado com sua data original |
| H — caminho alternativo | Instalação completa em pasta temporária com espaços; configurações e publicação usaram esse caminho |
| I — falha de configuração Apache | Diretiva inválida introduzida só no Apache isolado; -ConfigureApache abortou na validação e restaurou o texto anterior sem reiniciar; configuração de teste válida restaurada após a prova |
| J — demonstração | Seed transacional; senha informada em prompt protegido; login real como ana@example.test via Apache; duas hortas, plantas e dashboard com simulações confirmados |

Um repositório Git temporário foi criado a partir da árvore fonte sem segredos, depois clonado localmente. O próprio **INSTALAR_HYDROPONEX.bat do clone limpo** foi executado até a conclusão, validando resolução de caminhos e a entrega sem marcador/configuração local. Não foi realizado clone remoto autenticado do GitHub; a instalação não depende de APIs do GitHub.

Foram conferidos: página HTTP 200, API de sessão com CSRF 200, API privada 401 esperado, arquivos privados 403/404 e execução CLI com configuração privada. O teste de integridade existente roda no assistente dentro de transação revertida; AUTO_INCREMENT pode avançar sem adicionar registros persistentes. Não se usam escritas de teste não reversíveis na base real.

Regressões realmente executadas em outra base isolada sem usuários: autenticação 64, hortas 62, plantas 72, infraestrutura 83, monitoramento 92, integridade 24, configuração 18 e perfis de servidor 8: **423 verificações**. A suíte nova `database/tests/instalacao_local.php` aprovou mais **4 verificações**, sem banco: fallback para ambiente, prioridade do arquivo privado, JSON inválido falhando fechado e recusa de arquivo dentro da aplicação pública. Sintaxe PHP e PowerShell validada.

## Arquivos criados

- INSTALAR_HYDROPONEX.bat
- scripts/install-hydroponex.ps1
- scripts/install-database.php
- scripts/check-environment.php
- scripts/README.md
- Hydroponex_2/hydro/Hydroponexteste2/php/config/local.php
- database/tests/instalacao_local.php
- INSTALACAO_RAPIDA.md
- RELATORIO_INSTALADOR.md

## Arquivos alterados

- php/config/database.php e php/config/auth.php dentro da aplicação: leitura central por hydroponex_setting, preservando getenv quando não há instalação privada.
- .htaccess da raiz e da aplicação: bloqueio adicional de scripts administrativos PS1/BAT.
- .gitignore: distribuição, logs, cache e configuração Apache local.
- README.md, INSTALACAO_XAMPP.md e .env.example: direcionamento ao assistente e configuração privada.

SQL, entidades, endpoints de negócio, JavaScript e estilos não foram reescritos. A versão lógica do schema continua 1.3.0.

## Execução e limites

Siga INSTALACAO_RAPIDA.md. Ponto de entrada: duplo clique em INSTALAR_HYDROPONEX.bat. Para caminho diferente: `INSTALAR_HYDROPONEX.bat -XamppPath "D:\Ferramentas\xampp"`. -NoBrowser apenas evita abrir o navegador; não elimina perguntas. Não existe opção de senha administrativa na linha de comando.

Somente MariaDB 10.4.32 foi efetivamente homologado. Não se ampliou a lista sem testes. Falta de permissões de escrita/ACL, serviço corrompido, conta sobreprivilegiada, opcache personalizado ou serviço Apache com identidade diferente podem exigir intervenção. A partida automática de MariaDB desligado e o reinício de Apache instalado como serviço não foram homologados ponta a ponta nesta sessão; a falha é explícita, sem mensagem falsa de sucesso. Não foram testadas outras versões Windows/PHP/MariaDB ou outra unidade física.

Os testes temporários não são a instalação real em C:/xampp. Ao concluir a verificação, as instâncias isoladas são encerradas; nenhum processo do serviço original é finalizado. Backups e configurações privadas da instalação de teste ficam fora do repositório, em pasta protegida; configurações administrativas não são persistidas. A distribuição ZIP anterior não foi regenerada nesta tarefa: a entrega atual é a árvore de código pronta para versionar.
