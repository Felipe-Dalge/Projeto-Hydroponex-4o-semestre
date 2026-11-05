# Gerenciamento de hortas e plantas por horta

> Versão atual: schema 1.3.0. Em banco 1.2.0, aplique 004 após backup; versões anteriores aplicam antes 002/003 conforme necessário. Instalação nova já incorpora as mudanças. Consulte [monitoramento](MONITORAMENTO.md) e [infraestrutura](INFRAESTRUTURA.md).

**Atualização de plantas:** a API agora usa exclusivamente MySQL; não há mais modo JSON sem `?horta`. Formulário completo, filtros, detalhes, transferência e exclusão lógica estão em [PLANTAS.md](PLANTAS.md).

## Execução e utilização

Esta etapa usa o schema **1.1.0 existente**, sem alterar tabelas nem exigir nova migração SQL. A configuração PDO, autenticação, CSRF e `HYDROPONEX_AUTH_KEY` são os mesmos do [guia de autenticação](AUTENTICACAO.md). PHP >= 8.1 e MySQL >= 8.0.16 continuam obrigatórios. Não reaplique `hydroponex.sql` sobre um banco em uso.

1. Inicie PHP no diretório `Hydroponex_2/hydro/Hydroponexteste2` com `php -S localhost:8000 server/router.php`.
2. Cadastre uma conta ou faça login. Conta sem hortas abre **Minhas Hortas**, mostrando o estado vazio.
3. Clique **Cadastrar Horta**, informe nome e, opcionalmente, tamanho em m², localização e CEP. O usuário autenticado se torna proprietário/responsável; esse ID não é aceito do navegador.
4. Opcionalmente, preencha o nome/tipo/unidades de uma estrutura. Horta, proprietário e estrutura são gravados na mesma transação. Uma estrutura existente de outra horta nunca é reaproveitada por esse formulário.
5. Use **Ver plantas** no cartão ou o seletor **Horta selecionada** para escolher o cultivo. O cadastro/edição/exclusão de plantas da interface passa a usar MySQL, sempre com o ID da horta.
6. Use **Editar** para alterar a horta. Estruturas já vinculadas aparecem no formulário; escolha uma para editá-la ou deixe a opção de adicionar e preencha um novo nome. Limpar o campo não apaga instalações existentes.
7. Use **Excluir** e digite `EXCLUIR` para remover uma horta vazia. Dependências impedem a operação, com mensagem explicativa. Usuários continuam existindo após a exclusão da horta.

As cores, Tailwind, temas claro/escuro, gráficos e navegação existentes foram preservados. A nova interface inclui cartões, paginação (12 por página), carregamento, mensagens de erro/sucesso, ausência de resultados e formulários responsivos. O seletor inclui **todas** as hortas acessíveis, mesmo que os cartões ocupem várias páginas. A escolha fica em memória; recarregar seleciona a primeira horta autorizada. Não se guarda autorização no `localStorage`.

## Regras de modelagem e autorização

- `horta.id_horta` é gerado com AUTO_INCREMENT. Não é possível enviar um identificador ao criar.
- `usuario_horta` permanece N:N; cada horta criada recebe a associação do usuário da sessão com papel `proprietario`. Usuários podem ter várias hortas e hortas podem ter vários membros.
- **Leitor:** consulta a horta e suas plantas. **Gestor:** também edita informações/estruturas da horta e cria/edita/exclui plantas quando permitido pela integridade. **Proprietário:** também exclui a horta vazia.
- Listagem e consulta utilizam a associação do usuário autenticado. Horta inexistente, arquivada ou sem acesso retorna a mesma resposta 404. Membro existente sem o papel exigido recebe 403.
- A revogação de participação vale na próxima requisição, sem depender de logout ou do estado exibido na interface. Botões são apenas uma conveniência: o servidor valida todas as operações.
- O campo `tipo` de usuário não concede acesso global. Não há administrador global ou promoção automática. Esta etapa não adiciona convites, promoção de membros ou transferência de propriedade à interface. As associações já existentes são respeitadas; a administração dessas associações continua sendo uma operação separada/autorizada.
- Estruturas pertencem à horta, no lado N do relacionamento, conforme a correção do modelo original. O payload não pode mover estruturas de outras hortas. Não se cria uma FK invertida em `horta`.
- Responsáveis são os proprietários ativos da associação. A resposta permite vários responsáveis e inclui somente ID e nome, sem expor seus e-mails, hashes ou dados de perfil.
- CEP é um código de oito dígitos (com hífen opcional na entrada) resolvido no catálogo normalizado `cep → cidade → estado`. Zeros iniciais são preservados. Um CEP desconhecido retorna 422, sem consulta externa ou criação de cidade/estado por inferência. É opcional: deixar vazio grava NULL. O catálogo deve ser populado por importação/administração de dados; o seed de desenvolvimento inclui um exemplo.

## API de hortas

Todas as rotas exigem sessão. Mutações exigem `X-CSRF-Token` e JSON, seguindo o wrapper de autenticação existente.

| Método e rota | Comportamento |
| --- | --- |
| `GET /php/api/hortas.php?pagina=1` | Lista somente hortas autorizadas e não arquivadas. Retorna `data` e `meta: {pagina,por_pagina:12,total}`. Página deve ser inteiro positivo. |
| `GET /php/api/hortas.php?id=42` | Consulta a horta autorizada, seus responsáveis, estruturas, total de plantas ativas, endereço e papel do solicitante. |
| `POST /php/api/hortas.php` | Cria horta + proprietário da sessão + estrutura opcional. Retorna 201 e a horta criada. |
| `PATCH /php/api/hortas.php?id=42` | Atualiza somente os campos enviados. Exige gestor/proprietário. |
| `PUT /php/api/hortas.php?id=42` | Alias compatível de atualização parcial, com as mesmas regras de PATCH. |
| `DELETE /php/api/hortas.php?id=42` | Exige proprietário e `{ "confirmacao": "EXCLUIR" }`; só remove horta sem dependências de domínio. |

Payload de criação/atualização:

```json
{
  "nome": "Horta do Terraço",
  "tamanho_m2": "12.50",
  "localizacao": "Terraço, bloco A",
  "cep": null,
  "estrutura": {
    "nome": "Bancada A1",
    "tipo": "NFT",
    "qnt_unidade": 12
  }
}
```

Somente `nome` é obrigatório na criação (1–200 caracteres). `tamanho_m2` pode ser nulo ou decimal positivo de até 10 dígitos inteiros e duas casas decimais; `localizacao` tem até 200 caracteres. Não se aceita zero, negativo, booleano, array ou arredondamento silencioso de mais casas decimais. CEP pode ser nulo, vazio ou código conhecido. `estrutura` omitida/nula não muda instalações.

Estrutura exige nome (1–100), tipo opcional (até 100) e `qnt_unidade` inteira entre 1 e 65535, com padrão 1. Na atualização, pode conter `id_estrutura` existente **da mesma horta**; sem ID cria uma nova instalação. Na criação da horta não se aceita ID de estrutura preexistente. Não há exclusão de estruturas nesta tela.

Campos não permitidos (`id_usuario`, `responsavel`, `papel`, `arquivada_em`, IDs arbitrários de endereço etc.) retornam 422. O responsável é atribuído pelo servidor e não pode ser alterado por mass assignment. Identificadores de recursos são inteiros positivos dentro do domínio do banco.

Cada objeto retornado contém `id_horta`, `nome`, `tamanho_m2`, `localizacao`, `cep`, `cidade`, `estado`, `criado_em`, `total_plantas`, `papel`, `responsaveis`, `estruturas`, `pode_editar` e `pode_excluir`. Os dois últimos campos orientam a interface; não substituem validação de permissão.

## Integridade e exclusão

Criação/edição/exclusão usam transações. Antes de escrever, o usuário ativo e sua versão de autenticação são confirmados sob bloqueio. A horta e a associação são lidas com `FOR UPDATE` para impedir mudanças concorrentes de permissão e serializar escritas relacionadas.

A exclusão é **física apenas para horta vazia** e bloqueada com 409 quando existir qualquer registro em:

- `planta`, inclusive arquivada;
- `estrutura` ou `reservatorio`;
- `sensor` ou `monitoramento`, mesmo sem leituras;
- `consumo_agua` ou `horta_cultura`.

Não há cascata de plantas, equipamentos, culturas ou histórico. Associações de acesso `usuario_horta` são removidas explicitamente na mesma transação da exclusão da horta, inclusive se houver outros colaboradores; nenhuma conta de usuário é apagada. As FKs continuam como última barreira: dependência não prevista também causa rollback e 409. Uma falha nunca deixa membros removidos com a horta ainda existente.

Hortas com instalações/histórico exigem tratamento administrativo das dependências ou arquivamento por um fluxo próprio. Esta tela não oferece apagar em massa nem arquivar uma horta por trás de uma solicitação de exclusão. A transferência/arquivamento mencionada na exclusão de conta continua sendo operação separada.

## Plantas por horta e preservação do legado

O endpoint existente ganhou um modo MySQL explícito:

| Método e rota | Corpo/resultado |
| --- | --- |
| `GET /php/api/plantas.php?horta=42` | Lista somente plantas ativas da horta autorizada. Retorna a permissão de edição para a interface. |
| `GET /php/api/plantas.php?horta=42&id=7` | Consulta planta pelo par horta + ID. |
| `POST /php/api/plantas.php?horta=42` | `{nome,bancada,temp,nutrientes}`; aceita também `id_estrutura` e `id_cultura` opcionais da mesma horta. |
| `PUT/PATCH /php/api/plantas.php?horta=42` | `{id,...campos alterados}`; exige gestor/proprietário. |
| `DELETE /php/api/plantas.php?horta=42` | `{id,confirmacao:"EXCLUIR"}`; exige gestor/proprietário; exclusão lógica preserva FKs. |

Os nomes do contrato frontend foram preservados: `id`, `nome`, `bancada`, `temp`, `umidade`, `nutrientes`, `agua`, `status`, `lastUpdate`. São mapeados para `planta.id_planta`, `localizacao`, `temperatura_agua_c`, `nivel_nutrientes_ppm` etc. `id_horta` é determinado pela query e validado pelo servidor; enviar IDs de planta de outra horta não seleciona ou move essa planta. Estrutura deve pertencer à horta e cultura deve estar habilitada em `horta_cultura`.

A exclusão lógica permite preservar auditoria e sensores inativos; sensor ativo exige desativação antes (409). Transferências com sensores/auditoria são bloqueadas. Snapshots importados sem valores numéricos são mostrados sem erro; não são inventadas medições faltantes. O simulador de telemetria anterior permanece apenas em memória e os gráficos demonstrativos de consumo não se tornam medições reais por esta etapa.

**Os arquivos JSON existentes não foram apagados ou migrados automaticamente.** A API exige `?horta` e opera somente em MySQL. Sem parâmetro retorna 422, sem ler ou criar arquivos. O JSON global/pessoal fica reservado para migração administrativa conforme [PLANTAS.md](PLANTAS.md).

Para importar dados existentes, crie a horta de destino, confira seu proprietário, faça backup e congele gravações na origem. Use o importador administrativo já existente, explicitando a origem quando for pessoal:

```powershell
# Variáveis de conexão definidas conforme README; executar na raiz.
& C:/xampp/php/php.exe database/migrations/001_importar_json.php --horta=42 --arquivo=C:/backup/plantas.json
& C:/xampp/php/php.exe database/migrations/001_importar_json.php --horta=42 --arquivo=C:/backup/plantas.json --aplicar
```

Repita o mesmo snapshot sem duplicação, compare os dados e passe a usar a horta pela interface. Não continue gravando na fonte antiga supondo sincronização: a API deixou de gravar na origem JSON. O importador preserva payloads e bloqueia snapshots diferentes na mesma horta, conforme o guia do banco. Plantas importadas podem ser excluídas logicamente, preservando o registro e sua auditoria; não são restauradas ao repetir a importação.

## Testes

Use um banco **separado**, com nome terminado em `_test`/`_tests`, schema 1.1.0 e sem usuários. A conta precisa de DML, sem privilégios DDL. Configure as variáveis de conexão e a chave de autenticação para essa base. Python >= 3.10, sem bibliotecas adicionais:

```powershell
python database/tests/hortas.py --executar --php C:/xampp/php/php.exe
python database/tests/autenticacao.py --executar --php C:/xampp/php/php.exe
& C:/xampp/php/php.exe database/tests/configuracao.php
& C:/xampp/php/php.exe database/tests/integridade.php
& C:/xampp/php/php.exe database/test_connection.php --schema
```

A suíte de hortas inicia PHP em cópia/porta temporária e remove suas fixtures. Não usa o JSON real, não faz DROP/TRUNCATE e não deve rodar em paralelo contra a mesma base. Ela cobre três usuários, múltiplas hortas, compartilhamento por papéis, paginação, CEP/estrutura, rollback, plantas por horta, revogação e todas as dependências de exclusão. Resultados e arquivos alterados: [VALIDACAO-HORTAS.md](VALIDACAO-HORTAS.md).
