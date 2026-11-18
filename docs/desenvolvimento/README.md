# Desenvolvimento e testes

Para preparar a base e as credenciais, siga a [instalação manual](../instalacao/INSTALACAO_XAMPP.md). Defina as variáveis da [configuração de exemplo](../../.env.example) no mesmo terminal que executará o PHP. O arquivo é uma referência. O PHP lê as variáveis do processo ou a configuração privada apontada pelo marcador da instalação.

Para executar o servidor de desenvolvimento a partir da raiz do repositório:

```powershell
& C:/xampp/php/php.exe -S localhost:8000 -t public public/server/router.php
```

Acesse [http://localhost:8000/](http://localhost:8000/). O router protege os diretórios internos e serve os recursos estáticos de `public/`.

## Verificações locais

Execute os comandos da raiz. As três suítes abaixo verificam configuração, perfis de servidor e carregamento da configuração privada sem conexão ao banco:

```powershell
& C:/xampp/php/php.exe tests/configuracao.php
& C:/xampp/php/php.exe tests/compatibilidade.php
& C:/xampp/php/php.exe tests/instalacao_local.php
```

## Integração com MariaDB

Crie uma base descartável cujo nome termine em `_test` ou `_tests`, importe o [esquema MariaDB](../../database/mariadb/hydroponex_mariadb.sql) e configure as variáveis `HYDROPONEX_DB_*` para essa base. Use uma conta restrita a ela e uma chave `HYDROPONEX_AUTH_KEY` de teste com 64 caracteres hexadecimais. Use Python 3.10+ para as suítes HTTP.

```powershell
& C:/xampp/php/php.exe scripts/diagnostico_mariadb.php --schema
& C:/xampp/php/php.exe tests/integridade.php
python tests/colaboracao.py --executar --php C:/xampp/php/php.exe
python tests/equipe.py --executar --php C:/xampp/php/php.exe
python tests/operacional.py --executar --php C:/xampp/php/php.exe
python tests/tarefas.py --executar --php C:/xampp/php/php.exe
python tests/autenticacao.py --executar --php C:/xampp/php/php.exe
python tests/hortas.py --executar --php C:/xampp/php/php.exe
python tests/plantas.py --executar --php C:/xampp/php/php.exe
python tests/infraestrutura.py --executar --php C:/xampp/php/php.exe
python tests/monitoramento.py --executar --php C:/xampp/php/php.exe
```

Execute as suítes sequencialmente na mesma base. Elas usam cópias temporárias de `public/` e removem os registros de teste. O teste de integridade reverte a transação ao terminar. As instruções de publicação local do assistente estão em [Arquitetura do instalador](INSTALADOR.md).

A suíte de equipe requer `composer install` na raiz do clone. Ela fornece seu próprio SMTP de teste em loopback. [Configuração SMTP e Mailpit](../instalacao/CONVITES_SMTP.md)

Para verificar o transporte seguro em loopback, execute `python tests/smtp.py --php C:/xampp/php/php.exe --openssl C:/xampp/apache/bin/openssl.exe`. A suíte verifica STARTTLS, TLS implícito e rejeição de certificados sem CA confiável. Os certificados ficam em uma pasta temporária, e o banco fica fora desse teste.
