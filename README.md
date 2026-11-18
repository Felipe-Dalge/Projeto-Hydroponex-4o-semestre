# Hydroponex

O Hydroponex é uma aplicação web para gerenciar hortas hidropônicas. Ele reúne o cadastro de plantas, a infraestrutura de cultivo e o histórico de medições por horta, facilitando o acompanhamento de várias hortas e o compartilhamento de acesso. O sistema foi desenvolvido como projeto acadêmico do curso de Sistemas de Informação.

## Funcionalidades

- **Usuários** — perfis de entrada administrador e agricultor, login por área, alternância de área, edição de perfil e gestão da própria conta. As APIs autenticadas usam sessões PHP e as operações de escrita exigem proteção CSRF.
- **Hortas** — cadastro, edição, seleção da horta e controle de acesso por papéis de proprietário, gestor, agricultor e leitor.
- **Colaboração** — gestão da equipe, convites por e-mail para várias hortas, tarefas, observações e intervenções. As permissões são específicas de cada horta. [Equipe](docs/apis/EQUIPE.md) · [Matriz de permissões](docs/apis/COLABORACAO.md)
- **Plantas e culturas** — cadastro e filtros por horta, associação a culturas e estruturas, transferência entre hortas autorizadas e exclusão lógica para preservar o histórico.
- **Infraestrutura** — gerenciamento de reservatórios, estruturas e nutrientes, com registro de aplicações e vínculos com sensores.
- **Monitoramento** — cadastro de sensores, registro manual de medições e simulações identificadas, filtros, histórico e gráfico por sensor. A coleta automática por dispositivos IoT fica fora da implementação atual.
- **Dashboard** — resumo de plantas, últimas leituras, alertas por limites configurados e gráficos a partir de registros de consumo de água. O painel distingue medições de simulações e sinaliza dados indisponíveis.

## Tecnologias

- HTML5, CSS3 e JavaScript no navegador.
- Tailwind CSS para estilos, Lucide para ícones e Chart.js para gráficos, carregados por CDN.
- PHP 8.1 ou superior e PDO com driver `pdo_mysql`.
- MariaDB 10.4.32 no ambiente XAMPP homologado e Apache como servidor HTTP.
- PowerShell para o instalador Windows e Python para as suítes de integração HTTP.

## Arquitetura e estrutura

```text
Hydroponex/
├── public/              Aplicação publicada pelo Apache
│   ├── index.html
│   ├── assets/          CSS, JavaScript e imagens
│   ├── php/             APIs JSON, configuração, serviços e dados legados
│   └── server/          Router para desenvolvimento e exemplo Apache
├── database/            Esquemas, migrações, seeds e SQL legado
│   └── mariadb/         Instalação e migrações do ambiente XAMPP
├── scripts/             Instalação e diagnósticos CLI
├── tests/               Testes PHP e suítes HTTP
├── docs/                Guias, contratos de API e histórico técnico
├── .env.example
└── INSTALAR_HYDROPONEX.bat
```

O frontend chama as APIs PHP por caminhos relativos em `php/api/`. O backend valida a sessão, as permissões da horta e os dados antes de consultar o banco via PDO. O instalador publica o conteúdo de `public/` em `htdocs/hydroponex` e guarda a configuração com credenciais fora de `htdocs`. O Apache bloqueia o acesso direto às pastas internas de configuração, serviços e armazenamento. [Arquitetura da aplicação](docs/arquitetura/README.md)

## Pré-requisitos

- Windows com XAMPP e permissão de escrita na pasta de instalação.
- PHP 8.1+ com `pdo_mysql`, `session` e `json`. As verificações foram realizadas com PHP 8.2.12.
- Apache com suporte a `.htaccess` e `mod_rewrite`, e MariaDB **10.4.32** para o instalador.
- Acesso à internet para as bibliotecas da interface carregadas por CDN.

## Instalação e execução

1. Clone o repositório ou extraia o código em uma pasta fora de `htdocs`.
2. Inicie **Apache** e **MySQL** no painel do XAMPP. Nesse ambiente, o botão MySQL controla o serviço MariaDB.
3. Execute `INSTALAR_HYDROPONEX.bat` na raiz e informe as credenciais administrativas do banco quando solicitado. Escolha se deseja carregar os dados fictícios de demonstração.
4. Aguarde a verificação final e abra [http://localhost/hydroponex/](http://localhost/hydroponex/). Se optar por uma instalação sem demonstração, crie sua conta pela interface.

O assistente publica a aplicação, prepara o esquema e configura a conta restrita usada pelas APIs. Para uma instalação existente compatível, ele reutiliza o banco e a configuração privada. [Guia rápido](docs/instalacao/INSTALACAO_RAPIDA.md)

No procedimento manual, copie **o conteúdo de `public/`** para `C:/xampp/htdocs/hydroponex/`, incluindo os arquivos `.htaccess`. Em banco vazio, importe o esquema MariaDB e configure a conta da aplicação e a chave de autenticação no ambiente do PHP. Use `.env.example` como referência para os nomes das variáveis. [Instalação manual](docs/instalacao/INSTALACAO_XAMPP.md)

## Banco de dados

O MariaDB armazena contas, permissões, plantas, infraestrutura e histórico de monitoramento. `usuario_horta` relaciona usuários e hortas com um papel de acesso. Uma horta reúne plantas, estruturas, reservatórios e sensores. Os eventos de monitoramento registram as leituras por sensor. As chaves estrangeiras protegem a integridade dessas associações.

- [Esquema MariaDB 1.7.0](database/mariadb/hydroponex_mariadb.sql) para banco novo.
- [Gestão de tarefas e intervenções](docs/apis/TAREFAS.md).
- [Painel operacional do agricultor](docs/apis/OPERACIONAL.md).
- [Convites SMTP e Mailpit no XAMPP](docs/instalacao/CONVITES_SMTP.md).
- [Migrações MariaDB](database/mariadb/migrations/) para atualizações entre versões. Siga o [guia de migração](docs/banco-de-dados/MIGRACAO_MARIADB.md) para identificar a sequência adequada.
- [Modelo de dados e diagrama entidade-relacionamento](docs/banco-de-dados/diagrama.md).
- [Instalação, seeds e scripts legados](docs/banco-de-dados/README.md).

## Capturas de tela

Espaço reservado para capturas da interface em execução, incluindo o dashboard, a gestão de hortas e o histórico de monitoramento.

## Testes e validação

A partir da raiz, execute as verificações locais de configuração e compatibilidade:

```powershell
& C:/xampp/php/php.exe tests/configuracao.php
& C:/xampp/php/php.exe tests/compatibilidade.php
& C:/xampp/php/php.exe tests/instalacao_local.php
```

Para testar a conexão, a integridade e os fluxos HTTP, configure um **banco descartável** com o esquema MariaDB e use o PHP do XAMPP e Python 3.10+. As suítes cobrem autenticação, hortas, plantas, infraestrutura e monitoramento. [Configuração do ambiente e comandos de teste](docs/desenvolvimento/README.md)

## Contexto acadêmico

O Hydroponex é um projeto do curso de Sistemas de Informação. O grupo é composto por Andrey Eduardo Silva e Souza, Felipe Dalgé de Araújo, Geraldo Orsari Planche, Miguel Gonçalves Pesce, Murilo Mariano Vilhena e Pietro Perazzo da Silva.

## Status do projeto

O sistema está em estágio acadêmico, com os módulos descritos disponíveis para instalação local e demonstração. Os relatórios em `docs/historico/` registram as verificações e os limites de cada etapa.

## Licença

O repositório ainda não possui uma licença definida. A escolha cabe aos responsáveis pelo projeto.
