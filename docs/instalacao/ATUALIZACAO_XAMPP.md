# Atualizador do Hydroponex no Windows

O atualizador compara a versão do banco e o conteúdo dos arquivos públicos. Identifica também arquivos de uma atualização incompleta, mesmo quando o número da versão não mudou. Atende instalações MariaDB feitas pelo assistente XAMPP, com a configuração privada preservada.

## Como atualizar

1. Faça uma cópia externa do banco e da pasta C:/xampp/hydroponex-private. O assistente produz também um backup próprio; uma cópia externa protege contra falha do computador.
2. Baixe a versão nova do GitHub e extraia, por exemplo, em C:/Projetos/Hydroponex. Mantenha essa pasta separada de C:/xampp/htdocs/hydroponex. Não copie os arquivos por cima da instalação antes de executar o assistente.
3. Inicie **Apache** e **MySQL** no painel XAMPP. Suspenda importações, tarefas administrativas e outros programas que escrevam no mesmo banco.
4. Na pasta nova, execute **ATUALIZAR_HYDROPONEX.bat**. O padrão usa os arquivos dessa pasta, sem baixar outra versão.
5. Confira as versões detectadas e informe o usuário administrativo do MariaDB e sua senha, em entrada protegida. É a credencial de administração do banco, não o login do Hydroponex. A conta restrita da aplicação continua a mesma.
6. Aguarde **Atualização concluída**, guarde o caminho do backup e abra o site. Atualize com **Ctrl+F5** e confirme login, hortas, plantas e tarefas.

Não importe o esquema completo por cima de um banco existente.

## Somente verificar ou baixar diretamente do GitHub

Na raiz da versão baixada:

~~~powershell
.\ATUALIZAR_HYDROPONEX.bat -CheckOnly -NoBrowser
.\ATUALIZAR_HYDROPONEX.bat -FromGitHub
~~~

CheckOnly não altera o banco nem os arquivos publicados. Pode criar a pasta de trabalho privada e o arquivo de bloqueio e verificar as permissões dessa pasta.

FromGitHub consulta a main de Felipe-Dalge/Projeto-Hydroponex-4o-semestre, fixa o commit encontrado e baixa seu ZIP por HTTPS. Não modifica seu clone nem envia alterações ao GitHub. Depende de internet e dos limites da API GitHub. Uma versão que existe somente no seu computador deve ser aplicada pelo modo padrão: o modo GitHub só a encontrará depois da publicação. Pacotes anteriores à instalação são recusados.

## Caminhos e portas diferentes

~~~powershell
.\ATUALIZAR_HYDROPONEX.bat -XamppPath "D:\xampp" -HttpPort 8080
~~~

A porta do banco vem da configuração privada existente. A porta HTTP é detectada na configuração Apache ou informada com HttpPort. As opções não mudam portas, serviços ou configuração global do Apache.

Para instalações personalizadas:

~~~powershell
.\ATUALIZAR_HYDROPONEX.bat -SourcePath "C:\Projetos\Hydroponex" -ApplicationPath "C:\xampp\htdocs\hydroponex" -ConfigurationPath "C:\xampp\hydroponex-private\config.local.json" -HttpPort 80
~~~

Não combine SourcePath e FromGitHub. A aplicação deve estar acessível por http://localhost:PORTA/NOME-DA-PASTA/. Virtual hosts, HTTPS e outras URLs exigem adaptação do diagnóstico HTTP.

## O que o assistente faz

1. Valida XAMPP, configuração privada, chave existente, MariaDB homologado e pacote. Recusa links/junções nas árvores de código.
2. Bloqueia outro assistente na mesma configuração e planeja a cadeia incremental a partir de schema_version. Recusa banco/aplicação mais novos que o pacote.
3. Prepara arquivos em pasta privada e valida a sintaxe PHP. Copia PHPMailer de vendor quando disponível ou instala a partir de composer.lock. Verifica o instalador Composer por SHA-384 e desabilita scripts de Composer.
4. Coloca o site em manutenção por .htaccess, confirmada por HTTP. Aguarda 35 segundos por padrão para requisições em andamento. Aumente MaintenanceSeconds se a instalação permitir requisições mais longas; suspenda também processos externos.
5. Faz backup da aplicação, configuração e dump do banco com dados, gatilhos, rotinas e eventos.
6. Aplica somente migrações pendentes, com diário antes de cada arquivo e trava no banco. Valida tabelas, restrições, engine, collation e vínculos órfãos usando a conta da aplicação.
7. Publica o conteúdo novo de public, preservando chave e credenciais privadas. Recria o marcador PHP. Guarda o autoloader de e-mail em pasta privada, sem depender da permanência do clone baixado.
8. Verifica a página inicial, API de sessão/CSRF e bloqueio HTTP de arquivos internos. Registra versão e caminho do backup.

As regras locais Require local e SetEnv HYDROPONEX_* do .htaccess são preservadas. Outras personalizações do código e do .htaccess ficam no backup: revise-as antes de atualizar e incorpore regras próprias à configuração Apache fora da pasta publicada. Arquivos extras da pasta pública não são republicados, para evitar arquivos obsoletos ou segredos. JSON legado permanece no backup; o atualizador não executa a migração JSON para SQL nem adivinha uma horta de destino.

O transporte SMTP configurado no Apache é mantido. O autoloader definido na configuração privada tem preferência sobre o caminho antigo do clone. O teste final não envia e-mails: valide o envio para uma conta de teste após atualizar.

## Backups e recuperação

Backups padrão:

~~~text
C:/xampp/hydroponex-private/update-DATA-HORA-ID/backup/
  database.sql
  config.local.json
  public/
~~~

Esses arquivos contêm dados e credenciais. Não envie ao GitHub, não coloque em htdocs e não compartilhe o dump. Não apague pastas update-* em uso: podem conter o autoloader ativo. installed-version.json identifica o último backup bem-sucedido; o terminal informa também backups de tentativas incompletas.

Uma falha **antes das migrações** restaura o .htaccess anterior e mantém o banco e a versão instalada.

Uma falha **durante/depois das migrações** mantém o site em manutenção e informa o backup e update-migrations.json. MariaDB confirma DDL implicitamente: não há rollback transacional do esquema inteiro. Um diário executing impede repetir SQL parcialmente aplicado. Não apague esse diário para tentar novamente.

Recuperação:

1. Mantenha o site indisponível e preserve uma exportação do estado que falhou.
2. No phpMyAdmin crie **outro banco vazio**, por exemplo hydroponex_recuperacao. Importe backup/database.sql nesse destino, sem sobrescrever o original. O dump não usa --databases, portanto o destino é o banco selecionado.
3. Confira tabelas, contagens, usuários, vínculos e versão. Conceda à conta restrita SELECT, INSERT, UPDATE, DELETE nesse destino usando uma conta administrativa.
4. Pare Apache no painel. Preserve a pasta pública que falhou com outro nome fora de htdocs. Copie o conteúdo de backup/public para a pasta publicada e restaure backup/config.local.json no caminho privado original, ajustando somente o nome do banco para o destino restaurado.
5. Se o backup já contiver um bloco HYDROPONEX UPDATE MAINTENANCE de tentativa anterior, retire somente esse bloco após verificar a versão restaurada.
6. Arquive o diário de falha fora do caminho ativo **somente após restaurar e verificar o banco de destino**. Inicie Apache e valide login/dados. Preserve ambos os bancos para comparação.
7. Corrija a causa antes de atualizar novamente. Se o diário estiver completed e apenas a publicação falhou, o assistente pode ser repetido: valida o banco e republica sem reaplicar migrações concluídas.

Uma interrupção exatamente entre movimentações de pastas pode deixar a pasta publicada ausente. Restaure backup/public com Apache parado antes de repetir. O atualizador não apaga o banco nem restaura automaticamente um dump por cima dele.

## Limites e versões futuras

- PHP 8.1+ e MariaDB **10.4.32**, conforme a homologação atual da conexão.
- Cadeia de banco conhecida no manifesto: **1.0.0 a 1.7.0**. Aplicação desta entrega: **1.7.1**; esquema: **1.7.0**.
- Instalações sem schema_version, com importação parcial, senha/chave perdida, banco remoto ou configuração somente em variáveis exigem diagnóstico/migração assistida. Não há tentativa de adivinhar credenciais ou reconstruir relacionamentos.
- É necessário espaço para pacote, duas cópias dos arquivos antigos, dependências e dump. Erros de espaço/permissão interrompem o processo.
- A verificação de integridade/disponibilidade não substitui os testes funcionais nem corrige automaticamente defeitos de versões futuras.

Para distribuir outra versão, atualize public/version.json com versão semântica crescente da aplicação e esquema correspondente. Havendo mudança de banco, acrescente uma migração incremental e uma transição única no manifesto, atualize o esquema de instalação nova e teste ambos os caminhos. Não altere migrações já aplicadas.

Publique código revisado e testado na main quando estiver pronto para distribuição. O assistente executa código e SQL da origem escolhida; use somente o repositório oficial ou uma pasta revisada.

Se o PHP usa OPcache com verificação de alterações desabilitada, reinicie Apache pelo painel XAMPP após a publicação para carregar o código novo. [Relatório de testes e limites](../desenvolvimento/ATUALIZADOR.md)
