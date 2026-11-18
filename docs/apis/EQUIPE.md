# Equipe e convites por e-mail

Em **Equipe**, o proprietário vê os agricultores com vínculo ativo nas hortas que administra e os convites emitidos pela própria conta. Para convidar, informe o e-mail e selecione até 30 hortas. O convite concede o papel de agricultor nas hortas selecionadas. Os demais papéis continuam disponíveis para alteração de vínculos em **Trabalho na horta**.

## Aceitação e revogação

1. O destinatário abre o link do e-mail. Se ainda não tiver conta, pode se cadastrar como agricultor na mesma página e depois entrar.
2. Após o login com o e-mail convidado, a página mostra as hortas oferecidas. O destinatário escolhe aceitar ou recusar.
3. Ao aceitar, a sessão e o token comprovam juntos a identidade da conta e a posse do endereço. O servidor registra `email_verificado_em`, cria todos os vínculos e consome o token na mesma transação.
4. O proprietário pode cancelar um convite pendente ou remover um vínculo existente. O agricultor perde o acesso àquela horta na próxima requisição, inclusive se a sessão continuar aberta. Tarefas, observações e intervenções preservam seus autores.

Cada link vale sete dias. O token aleatório de 256 bits fica no fragmento da URL e é apagado da barra de endereço ao abrir a página. A página guarda o token em memória e envia-o no corpo de uma requisição HTTPS em produção. Se recarregar antes de responder, reabra o link do e-mail. O banco armazena somente o SHA-256 do token.

Um reenvio substitui o token e reinicia o prazo. Há um intervalo mínimo de 60 segundos por convite e um limite de 20 tentativas de envio por conta em uma janela de uma hora. O servidor recusa um novo convite se houver um vínculo ou um convite aberto para aquele endereço em qualquer horta selecionada. A aceitação verifica novamente todas as hortas e o acesso atual do emissor. Se alguma horta estiver indisponível, nenhuma recebe o novo vínculo.

O estado do convite é independente da entrega. O estado **Aceito pelo SMTP** informa que o servidor de e-mail aceitou a mensagem para processamento. **Falha no envio** permite corrigir a configuração e reenviar. **Envio sem confirmação** permite recuperar uma tentativa interrompida com um novo link. Convites aceitos, recusados ou cancelados ficam encerrados.

## Rotas

As rotas abaixo ficam em `php/api/`. Todas exigem sessão. Escritas e a prévia do destinatário exigem `X-CSRF-Token`. Os tokens aparecem somente no e-mail. [Autenticação](AUTENTICACAO.md)

| Rota | Método | Dados |
| --- | --- | --- |
| `equipe.php` | GET | Hortas administradas, agricultores agrupados por conta e convites do emissor no seu escopo. |
| `equipe.php` | POST | `email`, `hortas` com IDs das hortas. Retorna o ID e o estado de entrega após confirmação SMTP. |
| `equipe.php` | PATCH | `id_convite` para reenviar. |
| `equipe.php` | DELETE | `id_convite` para cancelar. |
| `convite_email.php` | POST | `token`, `acao` com `consultar`, `aceitar` ou `recusar`. |
| `membros.php?horta=ID` | DELETE | `id_usuario` para revogar o vínculo na horta. |

Uma sessão ausente recebe `401`. Um papel insuficiente ou CSRF inválido recebe `403`. Convite inválido, já usado, fora do prazo ou pertencente a outro e-mail recebe `404`. Vínculo existente ou convite sobreposto recebe `409`, tentativas excedidas recebem `429` e falhas de transporte recebem `502`.

Códigos manuais anteriores à versão 1.5.0 podem ser aceitos ou revogados na tela **Trabalho na horta**, respeitando sua validade original. A emissão de novos códigos manuais foi encerrada.

[Configurar SMTP no XAMPP](../instalacao/CONVITES_SMTP.md) · [Migrações](../banco-de-dados/MIGRACAO_MARIADB.md)

[Relatório da revisão e testes de transporte](../desenvolvimento/VALIDACAO-EQUIPE.md)
