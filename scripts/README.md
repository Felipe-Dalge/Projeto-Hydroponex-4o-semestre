# Arquitetura da instalação

O BAT invoca PowerShell em processo próprio, com caminhos relativos ao próprio arquivo e código de saída preservado. O assistente aceita XamppPath, DatabasePort, HttpPort, NoBrowser e ConfigureApache. Não aceita senha administrativa como parâmetro. Read-Host -AsSecureString recebe as senhas; a conversão para texto só existe na memória para a comunicação por stdin com PHP. Não há download de ferramentas.

install-hydroponex.ps1 identifica binários/portas, verifica serviços/conflitos, aplica ACL à pasta privada, conduz perguntas, chama o módulo CLI e publica arquivos. Antes de sobrescrever uma instalação, copia a aplicação para backup privado; não remove arquivos do usuário. Arquivos .env, configurações locais e backups da origem não são publicados. Escrever arquivos novos requer permissão no XAMPP.

install-database.php é exclusivamente CLI. Faz conexão administrativa PDO nativa, valida o perfil MariaDB testado, cria somente banco inexistente/vazio, importa instruções separadas por um lexer de aspas/comentários e verifica nomes de tabelas, versão, engine, collation, constraints e órfãos de todas as FKs compostas. Banco populado incompatível é bloqueado. Não oferece DROP, TRUNCATE, ALTER de versão, desativação de constraints ou alteração de senha existente.

A conta hydroponex_app@127.0.0.1 é autenticada antes do GRANT; nova conta recebe senha aleatória. SHOW GRANTS é conferido, admitindo somente USAGE e SELECT/INSERT/UPDATE/DELETE no banco. Privilégios adicionais são recusados, sem revogação automática. As credenciais novas e a chave são preservadas antes de criar a conta, para recuperar falhas parciais. Arquivos privados anteriores são copiados para backup antes de substituição. Credencial administrativa nunca é persistida.

Seed é transacional e só roda em banco sem registros de domínio, mediante S explícito. Atualiza somente o usuário 1 criado pelo próprio seed para permitir login com hash bcrypt custo 12 da senha informada. A flag demo privada evita repetição. Não altera senha da demonstração nas reinstalações. Falha no seed faz rollback; DDL do instalador não é transacional e uma importação parcial deve ser analisada antes de retomar.

Configuração: local.php lê o marcador protegido e o JSON externo. hydroponex_setting centraliza configuração privada para PDO e chave de autenticação; sem marcador, conserva getenv. Configuração inválida falha fechada, com mensagem genérica e log constante. Não é criado endpoint administrativo. Não houve mudança de regras de CRUD/autorização.

No XAMPP padrão, Require local/.htaccess são suficientes e não há edição global ou reinício. ConfigureApache é a opção excepcional: backup do httpd.conf e Include anterior, sintaxe validada antes de reiniciar, restauração dos textos anteriores em erro de sintaxe. Apache de console pode exigir reinício manual pelo painel. Após mudanças de código em instalação com opcache personalizado, reinicie Apache para invalidar caches.

Verificações finais: diagnóstico CLI, teste de integridade existente (transação com rollback; AUTO_INCREMENT pode avançar), chave, página HTTP, API de sessão com CSRF, API privada retornando 401 esperado e bloqueios de arquivos sensíveis. Sucesso só é exibido após todos os passos. Configuração/env temporária do processo PowerShell é restaurada ao sair. O processo BAT separado não modifica as variáveis do terminal do usuário.

Backups não substituem backup do banco. Reexecução não apaga dados; em importação DDL parcial, esquema divergente ou permissões incompatíveis, interrompe para intervenção segura. Nenhuma versão MariaDB adicional foi declarada homologada. Veja o relatório de cenários executados.
