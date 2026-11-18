# Validação da Etapa 2

Revisão executada em 18 de novembro de 2026 no Windows, com PHP 8.2.12 e MariaDB 10.4.32 do XAMPP. Os testes usaram uma instância MariaDB isolada em loopback e uma conta com permissões de leitura e escrita restritas à base descartável.

O anexo reenviado corresponde à especificação já implementada. A revisão corrigiu o mapeamento de `HYDROPONEX_SMTP_SECURITY=smtps` para `PHPMailer::ENCRYPTION_SMTPS`. Também limpou os dados visíveis da Equipe quando a conta deixa de ter hortas com permissão para gerenciar acessos.

## Resultados executados nesta revisão

| Verificação | Resultado |
| --- | --- |
| Importação do esquema completo MariaDB e diagnóstico PDO | Versão 1.5.0, 26 tabelas InnoDB, utf8mb4, CHECK e chaves estrangeiras ativos. |
| `tests/equipe.py` | 40 verificações aprovadas. |
| `tests/smtp.py` | 5 verificações aprovadas. |
| Sintaxe PHP do transporte e JavaScript da Equipe | Aprovada. |
| `git diff --check` e verificação do conteúdo preparado anteriormente no Git | Sem erros de espaços em branco. |

A suíte de Equipe verifica contas existentes e novas, vários destinos, prévia sem concessão, identidade do destinatário, CSRF, expiração, uso único, cancelamento, recusa, reenvio, limites de reenvio, aceitação concorrente em dois processos PHP, revogação na sessão atual, histórico preservado e falha de transporte. A regressão adicionada nesta revisão cria um vínculo conflitante na última horta e confirma que a transação reverte as concessões anteriores, preserva o papel existente e deixa o convite sem aceitação.

A suíte de transporte executa conexões SMTP reais em loopback com certificados temporários. Ela verifica STARTTLS e TLS implícito, rejeição de cadeia sem autoridade confiável em ambos os modos e rejeição de uma configuração de segurança inválida. Os certificados temporários não alteram as autoridades confiáveis do sistema ou do PHP instalado.

## Reproduzir

Instale as dependências com `composer install --no-dev`. Prepare uma base descartável sem usuários com nome terminado em `_test` ou `_tests`, importe o esquema MariaDB 1.5.0 e configure as variáveis de banco e a chave de autenticação. [Configuração dos testes](README.md)

```powershell
& C:/xampp/php/php.exe scripts/diagnostico_mariadb.php --schema
python tests/equipe.py --executar --php C:/xampp/php/php.exe
python tests/smtp.py --php C:/xampp/php/php.exe --openssl C:/xampp/apache/bin/openssl.exe
```

## Arquivos desta revisão

- `public/php/services/mail.php` corrige o modo SMTPS.
- `public/assets/js/equipe.js` limpa a listagem ao perder o acesso de gestão.
- `tests/equipe.py` acrescenta a regressão de reversão transacional.
- `tests/smtp.py` verifica os transportes criptografados e certificados.
- `docs/instalacao/CONVITES_SMTP.md` e `docs/desenvolvimento/README.md` documentam o teste de transporte.
- `docs/desenvolvimento/VALIDACAO-EQUIPE.md` registra esta execução.

O teste atual cobre o servidor de desenvolvimento do PHP e transportes SMTP de loopback. A publicação Apache da instalação do usuário, a variante MySQL 8 e a entrega por um provedor externo dependem de validação nesses ambientes. Bases já em 1.5.0 podem receber estes ajustes de código diretamente. Para bases 1.4.0, aplique primeiro a migração 006. [Instalação e configuração SMTP](../instalacao/CONVITES_SMTP.md)
