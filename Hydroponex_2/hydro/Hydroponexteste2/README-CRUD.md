# Hydroponex — CRUD MySQL

A API de plantas usa exclusivamente MySQL/PDO. Selecione uma horta; sem `?horta=ID`, a API retorna 422. Nome/status são filtros opcionais; permissões vêm de `usuario_horta`, nunca de um ID de usuário enviado pelo navegador.

- `GET php/api/plantas.php?horta=42` — listar e resumir indicadores.
- `GET php/api/plantas.php?horta=42&id=7` — detalhes.
- `POST php/api/plantas.php?horta=42` — cadastrar.
- `PATCH/PUT php/api/plantas.php?horta=42` — editar, com `id` no corpo.
- `DELETE php/api/plantas.php?horta=42` — exclusão lógica com `id` e `confirmacao: EXCLUIR`.
- `POST php/api/plantas_transferir.php?horta=42` — transferência explicitamente autorizada.

Todas exigem autenticação; mutações também exigem CSRF. Leitor consulta; gestor/proprietário gerencia. Sensores/auditoria são preservados. O frontend mantém HTML, estilos e scripts separados e usa `fetch()`.

O JSON original e os arquivos pessoais antigos não são alterados ou lidos pela API. O importador CLI preservado faz migração explícita/idempotente para uma horta, guardando o payload integral no banco.

Configuração e execução: [README principal](../../../README.md). Contrato completo, campos, ranges, segurança, procedimento de migração e testes: [PLANTAS.md](../../../database/PLANTAS.md).

Servidor local, dentro de `Hydroponexteste2`:

```bash
php -S localhost:8000 server/router.php
```

Abra [localhost:8000](http://localhost:8000/). Configure MySQL/schema 1.3.0 e chave de autenticação no ambiente antes de iniciar o PHP. Não abra o HTML por `file://` e mantenha a proteção HTTP do armazenamento antigo.

Infraestrutura e catálogos: veja [guia de APIs](../../../database/INFRAESTRUTURA.md). Em banco 1.1.0, aplique a migração 003 após backup antes de abrir os novos módulos.

Sensores e dashboard: [guia de monitoramento](../../../database/MONITORAMENTO.md). Em banco 1.2.0, aplique 004 após backup; instalação nova já inclui as mudanças.
