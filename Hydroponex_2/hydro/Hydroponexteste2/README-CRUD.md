# Hydroponex — CRUD PHP local

O CRUD de plantas foi implementado em PHP sem banco de dados.

## Regras preservadas
- `index.html` continua sendo HTML.
- `script.js`, CSS e imagens continuam separados.
- Os dados persistidos ficam em `php/storage/plantas.json`.
- O frontend conversa com a API PHP usando `fetch()`.

## Operações
- `GET /php/api/plantas.php` — listar plantas.
- `GET /php/api/plantas.php?id=1` — consultar uma planta.
- `POST /php/api/plantas.php` — criar.
- `PUT /php/api/plantas.php` — atualizar.
- `DELETE /php/api/plantas.php` — excluir.

As operações que alteram dados exigem o token CSRF obtido em `GET /php/api/session.php`.

## Armazenamento
O JSON é gravado com lock e substituição atômica. A pasta de armazenamento possui proteção para Apache e o projeto inclui um router que bloqueia acesso ao JSON quando usado com o servidor embutido do PHP.

## Como executar
No terminal, dentro da pasta `Hydroponexteste2`:

```bash
php -S localhost:8000 server/router.php
```

Depois acesse:

`http://localhost:8000/`

Não abra o `index.html` diretamente pelo `file://`, pois o CRUD depende do PHP.

## Observação de segurança
Neste estágio o projeto não possui autenticação real de usuários. Portanto, o CRUD tem validação de servidor, proteção CSRF, controle de método, proteção contra path traversal, escape de saída e persistência segura, mas não há autorização por usuário. Isso deve ser acrescentado quando o sistema ganhar login real.
