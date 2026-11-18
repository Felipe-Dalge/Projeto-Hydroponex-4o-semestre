# Convites por e-mail no XAMPP

1. Faça backup e atualize o banco conforme sua versão registrada em `schema_version`. Uma instalação nova já traz a versão 1.7.0. Uma base 1.6.0 precisa da migração 008 antes de publicar o código atualizado. [Guia de migração](../banco-de-dados/MIGRACAO_MARIADB.md) · [Instalação do XAMPP](INSTALACAO_XAMPP.md)
2. Instale o Composer usando o PHP do XAMPP. Na raiz do projeto, execute `composer install --no-dev`. Se o Composer informar que falta suporte a ZIP, habilite `extension=zip` no PHP usado pelo Composer ou disponibilize um descompactador compatível. O diretório `vendor/` fica na raiz do clone, fora da pasta publicada. [PHPMailer](https://github.com/PHPMailer/PHPMailer)
3. Baixe e extraia o executável Windows do Mailpit. Abra o PowerShell na pasta extraída e inicie o capturador em loopback. [Instalação do Mailpit](https://mailpit.axllent.org/docs/install/)

   ```powershell
   .\mailpit.exe --smtp 127.0.0.1:1025 --listen 127.0.0.1:8025
   ```

4. Configure as variáveis no Apache usando um arquivo fora de `htdocs`, como `C:/xampp/hydroponex-private/mail.local.conf`. Ajuste o caminho do autoloader para a pasta em que o Composer foi executado. O bloco abaixo usa a publicação padrão do instalador em `C:/xampp/htdocs/hydroponex`.

   ```apache
   <Directory "C:/xampp/htdocs/hydroponex">
       SetEnv HYDROPONEX_SMTP_HOST "127.0.0.1"
       SetEnv HYDROPONEX_SMTP_PORT "1025"
       SetEnv HYDROPONEX_SMTP_SECURITY "none"
       SetEnv HYDROPONEX_SMTP_FROM "convites@example.test"
       SetEnv HYDROPONEX_SMTP_FROM_NAME "Hydroponex"
       SetEnv HYDROPONEX_PUBLIC_URL "http://localhost/hydroponex"
       SetEnv HYDROPONEX_MAIL_AUTOLOAD "C:/caminho/do/clone/vendor/autoload.php"
   </Directory>
   ```

   Inclua o arquivo no `C:/xampp/apache/conf/httpd.conf` com `IncludeOptional "C:/xampp/hydroponex-private/mail.local.conf"`. Valide com `C:/xampp/apache/bin/httpd.exe -t` e reinicie o Apache no painel do XAMPP. O arquivo separado permanece fora do diretório servido. Para o servidor de desenvolvimento do PHP, defina as variáveis no terminal que o inicia. O [arquivo de exemplo](../../.env.example) lista as opções.

5. Entre no Hydroponex em `http://localhost/hydroponex`, crie duas hortas e abra **Equipe**. Convide um endereço fictício como `agricultor@example.test` para ambas. Abra a mensagem no [Mailpit local](http://localhost:8025/) e copie o link completo para uma janela anônima. Cadastre-se com o mesmo e-mail, entre, confira as duas hortas e aceite. Use um segundo convite para testar a recusa ou o cancelamento. Remova um vínculo na Equipe e confira se a horta removida deixa de abrir na sessão do agricultor.

## SMTP com um provedor

Defina `HYDROPONEX_SMTP_HOST`, `HYDROPONEX_SMTP_PORT` e `HYDROPONEX_SMTP_SECURITY` conforme o provedor. Use `tls` para STARTTLS ou `smtps` para TLS implícito. `none` só é aceito em loopback e sem credenciais. Se houver autenticação SMTP, preencha `HYDROPONEX_SMTP_USER` e `HYDROPONEX_SMTP_PASSWORD` no ambiente privado. Configure um remetente autorizado em `HYDROPONEX_SMTP_FROM` e a URL real em `HYDROPONEX_PUBLIC_URL`, incluindo o caminho da aplicação. Fora de loopback, o link exige HTTPS. Mantenha a validação de certificados TLS ativa.

Se ocorrer **Falha no envio**, confira o Mailpit ou provedor, as portas, o autoloader e a URL. Depois de 60 segundos, clique em **Reenviar**. O log PHP registra uma mensagem genérica de falha. O navegador não recebe o link do convite nem a configuração de transporte.

## Teste automatizado

Com o Composer instalado, uma base descartável 1.7.0 e as variáveis de banco configuradas, execute `python tests/equipe.py --executar --php C:/xampp/php/php.exe`. O teste inicia dois processos PHP e um capturador SMTP local que pode simular rejeição. [Configuração da base de testes](../desenvolvimento/README.md)

Para verificar STARTTLS e TLS implícito, execute `python tests/smtp.py --php C:/xampp/php/php.exe --openssl C:/xampp/apache/bin/openssl.exe`. Essa suíte gera certificados temporários e verifica a entrega criptografada e a rejeição de uma cadeia sem autoridade confiável. O teste usa somente loopback e dispensa conexão ao banco.
