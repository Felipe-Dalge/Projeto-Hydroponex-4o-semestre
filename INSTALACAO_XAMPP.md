# Hydroponex no Windows/XAMPP

> Para a instalação guiada atual, use INSTALAR_HYDROPONEX.bat e INSTALACAO_RAPIDA.md. O procedimento manual abaixo continua disponível.

Este é o guia atual para **MariaDB 10.4.32**, PHP >=8.1 e Apache 2.4 do XAMPP. A documentação anterior que exige somente MySQL registra etapas anteriores. Não é necessário instalar MySQL separado para esta edição. Outras versões MariaDB são rejeitadas até passarem pela suíte; a verificação não foi simplesmente removida.

## 1. Arquivos e serviços

Extraia o ZIP fora de htdocs, por exemplo em `C:/Projetos/Hydroponex`. Copie **o conteúdo** de `Hydroponex_2/hydro/Hydroponexteste2/` para `C:/xampp/htdocs/hydroponex/`, incluindo .htaccess e todas as subpastas. O arquivo `index.html` deve ficar diretamente nessa pasta. Mantenha database, SQLs, ZIPs e documentação fora de htdocs. As alterações feitas no projeto original precisam ser copiadas novamente para essa pasta publicada.

Se optar por extrair a árvore completa em `C:/xampp/htdocs/Hydroponex`, preserve também o .htaccess da raiz e use a URL `http://localhost/Hydroponex/Hydroponex_2/hydro/Hydroponexteste2/`; ajuste o Directory da configuração a esse caminho. A pasta database e os arquivos administrativos devem continuar bloqueados por HTTP.

No XAMPP Control Panel, inicie **Apache** e **MySQL** (esse botão inicia MariaDB). Não altere o diretório de dados de outros projetos. Abra `http://localhost/phpmyadmin/`. Execute na aba SQL:

```sql
SELECT VERSION(), @@port;
```

O servidor deve informar 10.4.32-MariaDB. Em caso de erro Aria/checksum, veja o diagnóstico abaixo; não apague arquivos do banco.

## 2. Banco NOVO

No phpMyAdmin, crie `hydroponex` com collation `utf8mb4_unicode_ci` ou execute:

```sql
CREATE DATABASE hydroponex CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

Selecione o banco, clique Importar e escolha **database/mariadb/hydroponex_mariadb.sql**, formato SQL, UTF-8. Deve criar 21 tabelas, versão lógica 1.3.0, 31 FKs. Não importe database/hydroponex.sql: esse é o original MySQL preservado. Não execute as migrações sobre uma instalação nova. Confira o relatório inteiro de importação: não continue após erro.

Seed é opcional: `database/mariadb/seed_mariadb.sql`, uma vez, somente em desenvolvimento. Contas são inativas e não servem para login; medições demonstrativas são simulações. Para experimentar o sistema, prefira sem seed e cadastre sua própria conta.

Banco existente: faça backup e siga MIGRACAO_MARIADB.md; nunca importe o instalador sobre dados existentes.

## 3. Conta privada da aplicação

Usando a conta administrativa no phpMyAdmin, substitua a senha no SQL antes de executar:

```sql
CREATE USER 'hydroponex_app'@'127.0.0.1' IDENTIFIED BY 'SUBSTITUA_POR_SENHA_FORTE';
GRANT SELECT, INSERT, UPDATE, DELETE ON hydroponex.* TO 'hydroponex_app'@'127.0.0.1';
```

A aplicação não deve usar root sem senha. Administração/DDL e conta da aplicação são separadas. Não coloque credenciais em JavaScript, no ZIP ou no diretório público.

## 4. Ambiente do Apache

Copie `server/hydroponex.local.conf.example` do aplicativo para `C:/xampp/apache/conf/extra/hydroponex-local.conf`. Ajuste caminho Directory, senha e porta. Gere a chave no PowerShell:

```powershell
& C:/xampp/php/php.exe -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'
```

Use o resultado de 64 caracteres em HYDROPONEX_AUTH_KEY e mantenha a mesma chave entre reinícios. No XAMPP: Apache > Config > httpd.conf, acrescente:

```apache
Include conf/extra/hydroponex-local.conf
```

Confirme mod_env e mod_rewrite habilitados e AllowOverride All conforme o exemplo. Em Apache > Config > PHP (php.ini), habilite `extension=pdo_mysql` se necessário. Reinicie Apache. `.env.example` é referência; PHP não carrega .env automaticamente. Configuração por SetEnv do Apache não fica automaticamente disponível a comandos CLI em outro terminal.

## 5. Acessar e testar

Abra **http://localhost/hydroponex/**; não abra o HTML com file://. Bibliotecas visuais usam CDN e precisam de internet. Cadastre conta, senha de pelo menos 12 caracteres, e depois faça login.

1. Crie Horta A e B; selecione A.
2. Crie cultura e habilite em A; reservatório 100 L/60 L; estrutura vinculada; planta nessa horta com cultura/estrutura.
3. Cadastre nutriente e registre aplicação. Tente nível 101 L e excluir reservatório vinculado: operações devem falhar.
4. Em Monitoramento, crie sensor ph/pH com limites 5.5/6.5. Registre manualmente 8: deve alertar. Registre simulação 6: não deve esconder o alerta manual.
5. Filtre histórico por sensor/origem/período; consulte gráfico. Recarregue a página: dados devem permanecer.
6. Troque para B: dados de A não devem aparecer. Outra conta sem vínculo não pode consultar IDs de A.
7. Teste perfil, troca de senha mediante senha atual, logout e novo login. Exclusões precisam de confirmação e respeitam dependências.

Ausência de leituras/consumo aparece como indisponível, sem inventar valores. Não há dispositivo IoT automaticamente conectado.

Importe `database/mariadb/verificar_mariadb.sql` para consultar versão/tabelas/relações. Consultas de inconsistência devem retornar vazio; contagens e origens são informativas. Não desative foreign_key_checks/check_constraint_checks para contornar erros.

## 6. Diagnóstico e testes CLI

A partir da raiz extraída fora de htdocs, no PowerShell, configure o mesmo destino e segredo do Apache (não imprima senhas):

```powershell
$env:HYDROPONEX_DB_HOST='127.0.0.1'
$env:HYDROPONEX_DB_PORT='3306'
$env:HYDROPONEX_DB_NAME='hydroponex'
$env:HYDROPONEX_DB_USER='hydroponex_app'
$cred=Get-Credential -UserName $env:HYDROPONEX_DB_USER -Message 'Senha MySQL/MariaDB do Hydroponex'
$env:HYDROPONEX_DB_PASSWORD=$cred.GetNetworkCredential().Password
& C:/xampp/php/php.exe database/diagnostico_mariadb.php --schema
```

Para as suítes, crie **outro banco**, por exemplo hydroponex_tests, importe o instalador sem seed e use conta limitada a esse banco; nunca use a base real. Defina DB_NAME/USER/PASSWORD desse destino e HYDROPONEX_AUTH_KEY com uma chave de teste de 64 hexadecimais. Então:

```powershell
& C:/xampp/php/php.exe database/tests/configuracao.php
& C:/xampp/php/php.exe database/tests/compatibilidade.php
& C:/xampp/php/php.exe database/tests/integridade.php
python database/tests/autenticacao.py --executar --php C:/xampp/php/php.exe
python database/tests/hortas.py --executar --php C:/xampp/php/php.exe
python database/tests/plantas.py --executar --php C:/xampp/php/php.exe
python database/tests/infraestrutura.py --executar --php C:/xampp/php/php.exe
python database/tests/monitoramento.py --executar --php C:/xampp/php/php.exe
```

Python >=3.10 para as suítes HTTP. Elas iniciam PHP temporário e removem suas fixtures; o teste de integridade usa rollback. Remova variáveis de segredo/feche o terminal ao concluir.

## Problemas comuns

- Se aparecer “IfModule not allowed here”, confira AllowOverride All também no diretório ancestral publicado ao extrair a árvore completa.
- 500: confira `C:/xampp/apache/logs/error.log` e sintaxe do arquivo incluído; não publique logs nem phpinfo.
- Banco indisponível: confirme serviço, versão exata testada, porta, conta, senha, privilégios e ambiente do Apache. Reinicie após configurar.
- Driver ausente: habilite pdo_mysql no PHP usado pelo Apache e pelo CLI.
- Erro 4025: CHECK rejeitou dados; corrija entrada, não desative a restrição.
- 403/404 em php/storage, php/config, php/services, server, .env e SQLs: bloqueio esperado. Nas APIs, 401 pede login; 403 indica CSRF/papel; 404 pode indicar ausência de acesso à horta.
- Apache em outra porta: acrescente a porta na URL. Não misture hosts/portas ao testar cookies de sessão.
- Erro Aria 176/checksum: foi observado no serviço original desta máquina ao criar conta administrativa. Os testes desta entrega usaram instância limpa isolada dos mesmos binários 10.4.32. Faça backup e procure recuperação específica do MariaDB; não apague aria_log, ibdata ou diretórios de bancos. Essa ocorrência pode impedir instalar no serviço original até ele ser recuperado; não indica falha do DDL do Hydroponex.
