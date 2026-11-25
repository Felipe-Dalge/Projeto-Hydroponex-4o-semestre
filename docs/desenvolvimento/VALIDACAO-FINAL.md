# Etapa 5 — integração, segurança e validação final

Validação executada em 25 de novembro de 2026. O ambiente usado foi Windows com PHP 8.2.12, MariaDB 10.4.32 e Apache 2.4.58 do XAMPP. A interface foi exercitada no Edge, e os convites foram capturados pelo Mailpit. As bases e a configuração Apache da validação ficaram em diretórios temporários isolados.

O resultado reúne **679 verificações automatizadas aprovadas**, os cinco cenários A a E no navegador, instalação limpa, atualização incremental e restauração de backup de teste. As correções desta etapa usam o schema existente **1.7.0**.

## 1. Funcionalidades integradas

- Cadastro, login por área, sessão PHP, perfil, senha, logout e exclusão da própria conta.
- Hortas e permissões individuais por vínculo, com papéis proprietário, gestor, agricultor e leitor.
- Equipe, convite SMTP para uma ou várias hortas, aceitação ou recusa e revogação.
- CRUD de plantas, culturas, infraestrutura, sensores e monitoramentos.
- Dashboard administrativo e painel do agricultor com dados persistidos e identificação de simulações.
- Tarefas com prioridade, prazo, atribuição, intervenções, verificação, aprovação, correção, reabertura e cancelamento.
- Histórico de tarefas e trabalho agrícola com autoria preservada após revogação.

O agricultor opera nas hortas atribuídas. O administrador trabalha sozinho quando atribui uma tarefa a si mesmo. A aprovação da tarefa mantém o estado da planta conforme o cadastro e as avaliações autorizadas.

## 2. Fluxos completos validados

| Cenário | Fluxo executado | Resultado observado |
| --- | --- | --- |
| A — Administrador individual | Cadastro administrativo, criação de horta e planta em Alerta, tarefa da planta atribuída à própria conta, início, intervenção, verificação e aprovação. | Ciclo concluído com histórico. A planta permaneceu em Alerta. |
| B — Administrador com agricultor | Convite SMTP, prévia, cadastro pelo link, login, aceitação, atribuição, início, intervenção e aprovação administrativa. | Vínculo concedido após aceitação explícita. Autor e datas do trabalho persistidos. |
| C — Agricultor com várias hortas | Aceitação de convites emitidos por dois administradores distintos. | O painel agregou somente as hortas autorizadas. |
| D — Revogação | Remoção do vínculo com sessão do agricultor ainda aberta e novas consultas aos módulos. | APIs bloquearam a horta revogada, mantiveram a outra horta e preservaram intervenções e autoria. |
| E — Permissões diferentes | Conta proprietária de uma horta aceitou papel agricultor em outra e alternou a área de trabalho. | A conta editou sua própria horta e recebeu recusa ao tentar administrar a outra. |

As suítes HTTP exercitaram esses cenários com contas e dados reais na base descartável. No navegador, foram usados formulários de cadastro, login, convite, aceitação, tarefa, intervenção e remoção de vínculo. Algumas entidades complementares dos cenários C e E foram criadas pelas APIs reais para preparar a validação.

No Apache, o site foi publicado sob `/hydroponex/`, com configuração privada externa e o autoloader do PHPMailer explicitamente configurado. Foram verificadas as telas desktop e larguras móveis de 320 e 390 pixels, abertura dos detalhes, navegação, filtros, estados vazios, persistência após recarregar e ausência de erros JavaScript nos fluxos exercitados.

## 3. Testes executados e resultados

| Arquivo de teste | Verificações aprovadas |
| --- | --- |
| `tests/configuracao.php` | 18 |
| `tests/compatibilidade.php` | 8 |
| `tests/instalacao_local.php` | 4 |
| `tests/integridade.php` | 24 |
| `tests/integridade_tarefas.php` | 9 |
| `tests/autenticacao.py` | 64 |
| `tests/hortas.py` | 62 |
| `tests/plantas.py` | 72 |
| `tests/infraestrutura.py` | 83 |
| `tests/monitoramento.py` | 92 |
| `tests/colaboracao.py` | 50 |
| `tests/equipe.py` | 40 |
| `tests/operacional.py` | 46 |
| `tests/tarefas.py` | 50 |
| `tests/smtp.py` | 5 |
| `tests/integracao.py` | 43 |
| `tests/frontend_integracao.cjs` | 9 |
| **Total** | **679** |

As dez suítes HTTP foram executadas novamente após a correção da validação de textos. Os testes específicos de JavaScript confirmaram a correção das respostas atrasadas e da paginação.

### Segurança

| Tema | Evidência executada |
| --- | --- |
| Autenticação e sessão | Hash de senha, regeneração do identificador no login, invalidação do identificador anterior, expiração por inatividade e duração absoluta, logout e revogação de sessões após exclusão. |
| CSRF | Cadastro, login e operações autenticadas recusaram token ausente ou inválido. Respostas antigas de CSRF passaram a preservar a sessão renovada no navegador. |
| Autorizações | Trocas manuais de IDs de horta, planta, tarefa e usuário foram recusadas. Perfis enviados para elevar permissões foram rejeitados. |
| Convites | Conta destinatária, hash do token, prazo, cancelamento, recusa, uso único, reenvio, perda da permissão do emissor, duplicidade e aceitação transacional para vários destinos. |
| Concorrência | Dois processos PHP aceitaram o mesmo convite uma única vez. Atualizações de tarefa com a mesma versão produziram uma confirmação e um conflito. A criação com a mesma chave retornou a mesma tarefa. |
| Limites | Limites por e-mail e IP de login, intervalo mínimo de reenvio e tamanho de payload continuaram sendo aplicados. A configuração dos limites de emissão e consulta de convites também foi revisada. |
| SQL Injection | Identificadores e filtros malformados foram recusados, com consultas preparadas PDO e emulação desativada. |
| XSS | Nomes de plantas e intervenções contendo marcação foram exibidos como texto no navegador. O teste verificou que os elementos HTML inseridos no texto não foram criados no DOM. |
| SMTP | Captura real no Mailpit, rejeição SMTP, recuperação por reenvio, STARTTLS e TLS implícito. Certificados sem autoridade confiável foram rejeitados. |
| Credenciais e arquivos | Testes verificaram respostas sem credenciais, logs sem senhas ou tokens e acesso HTTP bloqueado às pastas privadas, JSON legado, arquivos de ambiente e scripts internos. |

O Composer validou os arquivos de dependências e sua auditoria consultada nesta execução retornou ausência de avisos de vulnerabilidade conhecidos. A validação do manifesto apresentou o aviso de metadados de licença ausente. A escolha da licença permanece com os responsáveis pelo projeto.

### Banco e instalação

1. O esquema completo foi importado em MariaDB vazio. O diagnóstico confirmou 28 tabelas InnoDB, utf8mb4, sessão UTC, restrições ativas e prepared statements nativos.
2. As migrações 005, 006, 007 e 008 foram executadas em sequência sobre uma cópia 1.3.0 com dados iniciais. As colunas preexistentes foram comparadas depois de cada migração. Convite manual, observação e tarefa antigos também foram preservados.
3. A tarefa antiga concluída conservou a data desconhecida e recebeu um evento de migração com autor desconhecido. O banco final apresentou 48 chaves estrangeiras.
4. Os testes diretos no banco recusaram planta de outra horta, histórico e intervenção com horta incompatível, leitura de outra horta, JSON inválido e valores de estado e prioridade fora das restrições.
5. Os índices de busca por horta, estado, prioridade, prazo e referência do problema foram conferidos no esquema migrado.
6. Um dump de teste com `--single-transaction --hex-blob` foi restaurado em outra base. As colunas comparadas conservaram seus valores.
7. O instalador CLI foi executado em outra instância MariaDB vazia, incluindo demonstração, e executado novamente. Os hashes de senha, a chave e as credenciais configuradas foram preservados.

## 4. Problemas encontrados e corrigidos

| Problema | Correção | Regressão |
| --- | --- | --- |
| Uma resposta atrasada de autenticação podia encerrar uma sessão mais recente ou substituir seu CSRF. | O cliente verifica a conta e o token associados à requisição antes de alterar o estado global de autenticação. | Sessão de outra conta, token renovado, resposta antiga de CSRF e expiração válida. |
| Os controles de paginação de tarefas podiam conservar a página e os botões de uma consulta anterior após troca de horta ou erro. | A página e os botões são limpos ao iniciar a consulta e ao limpar o contexto. | Consulta sem horta com controles desativados e rótulo vazio. |
| A caixa de texto de observações operacionais permitia quebras de linha, mas o backend as rejeitava. | A validação comum ganhou uma opção explícita de texto multilinha, utilizada em registros e tarefas. Os demais campos mantêm sua validação específica. | Observação multilinha persistida, UTF-8, limites de tamanho e caracteres de controle rejeitados. |
| O diagrama marcava o responsável da tarefa como obrigatório e omitia atributos dos novos relacionamentos. | Cardinalidade opcional corrigida, entidades de convite, tarefa, histórico, intervenção e registro detalhadas e escopo composto indicado. | Comparação documental com o schema 1.7.0 e as chaves verificadas no banco. |
| Um resumo de API ainda descrevia a atualização antiga de tarefas sem versão. | O contrato de colaboração foi alinhado às ações, versão e justificativas do módulo atual. | Revisão das rotas documentadas contra os endpoints. |

Na validação, o capturador SMTP e o Apache exigiram o caminho do autoloader da cópia publicada. A configuração de teste foi ajustada conforme o guia existente e o fluxo de envio passou. O teste integrado também teve sua limpeza ajustada para remover os registros de observação criados como fixtures.

### Arquivos de implementação alterados

- `public/assets/js/script.js`
- `public/assets/js/tarefas.js`
- `public/php/config/auth.php`
- `public/php/api/registros.php`
- `public/php/services/tasks.php`

### Testes acrescentados

- `tests/integracao.py`
- `tests/frontend_integracao.cjs`
- `tests/integridade_tarefas.php`

O README, os guias de instalação e SMTP, os contratos de colaboração, equipe, operação e tarefas, a arquitetura, as orientações de migração e o diagrama foram atualizados.

## 5. Pendências e limites

- O provedor SMTP externo, suas credenciais reais, a entrega na caixa de entrada e a configuração TLS pública dependem do ambiente de implantação. O envio local foi validado com Mailpit e os transportes criptografados com certificados temporários.
- A variante MySQL 8 e versões MariaDB diferentes de 10.4.32 dependem de execução nesses ambientes. As migrações 002 a 004 receberam revisão estática nesta etapa. A cadeia executada começou em 1.3.0.
- A publicação interativa pelo arquivo BAT, as permissões de uma instalação Windows específica e os serviços existentes no computador de destino precisam ser conferidos ao atualizar esse computador. Nesta etapa, o instalador de banco CLI e a publicação Apache isolada foram executados.
- O teste de backup/restauração usou dados descartáveis. A atualização de uma base real exige um backup próprio e a conferência dos dados desse ambiente.

## 6. Instalação, atualização e reprodução

### Instalação nova

1. Extraia o clone fora de `htdocs`, inicie Apache e MySQL no XAMPP e prepare o Composer.
2. Execute `composer install --no-dev` na raiz.
3. Execute `INSTALAR_HYDROPONEX.bat`. Escolha dados de demonstração somente quando esse for o objetivo da instalação.
4. Configure o SMTP e `HYDROPONEX_MAIL_AUTOLOAD` no ambiente privado do Apache. Para uso local, inicie Mailpit em loopback.
5. Abra a aplicação pelo servidor HTTP, crie a conta e valide os cenários abaixo.

[Guia rápido](../instalacao/INSTALACAO_RAPIDA.md) · [Instalação XAMPP](../instalacao/INSTALACAO_XAMPP.md) · [SMTP](../instalacao/CONVITES_SMTP.md)

### Atualização de instalação existente

1. Exporte estrutura e dados no phpMyAdmin. Preserve a pasta privada, incluindo a chave de autenticação, e a pasta publicada atual.
2. Confira `schema_version` e suspenda o uso da aplicação durante a atualização.
3. Aplique somente as migrações pendentes, em ordem. Uma base 1.6.0 exige a migração 008. Uma base já em 1.7.0 pode receber diretamente as correções desta etapa.
4. Execute o instalador a partir do clone atualizado para publicar os arquivos. Em uma base com dados, responda N à inclusão de demonstração.
5. Confira Composer, SMTP e o caminho do autoloader. Recarregue com `Ctrl+F5`, entre novamente e execute o roteiro manual.

[Sequência completa de migrações e preservação de dados](../banco-de-dados/MIGRACAO_MARIADB.md)

### Roteiro manual

| Cenário | Procedimento | Conferir |
| --- | --- | --- |
| A | Cadastre conta administrativa, horta e planta em Alerta. Crie uma tarefa da planta para si, inicie, registre trabalho, solicite verificação e aprove. | Histórico completo, conclusão persistida e planta ainda com o estado cadastrado. |
| B | Convide um endereço no Mailpit. Em janela anônima, cadastre ou autentique o destinatário, confira as hortas e aceite. Atribua uma tarefa a ele e realize o ciclo até a aprovação. | Acesso concedido após aceitação e autoria correta da intervenção. |
| C | Com outra conta administrativa, crie outra horta e convide o mesmo agricultor. | Duas hortas no painel e acesso negado a uma terceira horta alheia. |
| D | Mantenha a sessão do agricultor aberta e remova seu vínculo com uma das hortas. Recarregue ou faça nova consulta. | Horta revogada bloqueada, outra horta acessível e histórico consultável pela gestão. |
| E | Uma conta com horta própria aceita convite operacional de outra gestão. Alterne as áreas e tente editar ambas. | Edição apenas na horta administrada e ações operacionais apenas conforme atribuição. |

### Suítes automatizadas

Configure uma base descartável 1.7.0 sem usuários com nome terminado em `_test` ou `_tests`, uma conta restrita a essa base e a chave de autenticação de teste. Execute os comandos do [guia de desenvolvimento](README.md) sequencialmente. As suítes removem suas fixtures, e os testes de integridade revertem suas transações.

```powershell
node tests/frontend_integracao.cjs
& C:/xampp/php/php.exe tests/integridade_tarefas.php
python tests/integracao.py --executar --php C:/xampp/php/php.exe
python tests/smtp.py --php C:/xampp/php/php.exe --openssl C:/xampp/apache/bin/openssl.exe
```

## 7. Possíveis melhorias futuras

- Automatizar a aplicação controlada de migrações com registro de execução parcial e validação de backup.
- Ampliar a matriz de homologação para MySQL 8 e outras versões MariaDB com execução das mesmas suítes.
- Integrar os testes a um pipeline com serviços MariaDB e SMTP descartáveis.
- Avaliar fila de envio e observabilidade do SMTP conforme o volume de uso.

Essas melhorias permanecem como propostas para próximas etapas. As alterações desta revisão estão disponíveis localmente para revisão.
