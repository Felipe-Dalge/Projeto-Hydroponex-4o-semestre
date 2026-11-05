# Instalação rápida do Hydroponex

1. Instale o XAMPP para Windows com PHP >=8.1. A versão MariaDB homologada é **10.4.32**. O instalador não baixa dependências e mantém o bloqueio para versões ainda não testadas.
2. Abra o XAMPP Control Panel e inicie **Apache** e **MySQL** (MariaDB). Se houver erro no banco, resolva-o antes de continuar; não apague arquivos de dados. O instalador tenta iniciar MariaDB configurado quando ele está desligado, mas não repara bancos corrompidos.
3. Baixe/extraia ou clone o repositório em uma pasta própria, por exemplo `C:/Projetos/Hydroponex`. Não coloque o clone dentro de `htdocs/hydroponex`.
4. Execute **INSTALAR_HYDROPONEX.bat**. Se faltar permissão de escrita na pasta do XAMPP, execute como administrador. O caminho padrão é C:/xampp; se não existir, o assistente pede o caminho correto.
5. Informe o usuário administrador do banco (Enter sugere root) e sua senha, em entrada protegida. O instalador não presume senha vazia. Se a conta hydroponex_app já existir e não houver configuração privada anterior, informe também a senha dela. O instalador não troca senhas existentes.
6. Responda S ou N para dados fictícios. Na demonstração, informe uma senha de 12 a 72 bytes para **ana@example.test**. Não há senha fixa no código. Em banco com dados reais, a inclusão de demonstração é recusada; execute novamente com N. Repetir uma demonstração já instalada preserva os dados e a senha.
7. Aguarde a mensagem **INSTALAÇÃO CONCLUÍDA**. O navegador abre http://localhost/hydroponex/ (ou a porta configurada). Um erro significa instalação incompleta; corrija e repita, sem apagar banco/tabelas.

Sem demonstração: cadastre uma conta e depois faça login. Crie uma horta, cultura, reservatório, estrutura e planta. Em Monitoramento, crie sensor ph/pH com limites 5.5 e 6.5; registre manualmente 8 e confirme o alerta. Simulações devem ser escolhidas explicitamente no formulário. Teste outra horta e outra conta para conferir o isolamento.

Com demonstração: entre como ana@example.test usando a senha informada e explore as duas hortas acadêmicas, plantas e sensores. As leituras e consumos de exemplo são simulados. Há uma segunda conta inativa, que não permite login.

## Caminho ou portas diferentes

No terminal, a partir da raiz do clone:

```powershell
.\INSTALAR_HYDROPONEX.bat -XamppPath "D:\Ferramentas\xampp"
```

Portas normalmente são detectadas nos arquivos de configuração. Para uma instância já configurada em portas alternativas:

```powershell
.\INSTALAR_HYDROPONEX.bat -XamppPath "D:\Ferramentas\xampp" -DatabasePort 3307 -HttpPort 8080
```

As opções não mudam portas do servidor: elas selecionam o serviço que você já configurou. A aplicação será http://localhost:8080/hydroponex/. Se a porta indicada estiver desligada e for diferente do my.ini, o instalador não iniciará outro banco por engano.

## Onde ficam os arquivos

Código publicado: `<XAMPP>/htdocs/hydroponex`. Configuração com senha/chave: `<XAMPP>/hydroponex-private/config.local.json`, fora de htdocs, protegida por ACL. A aplicação contém somente um marcador de caminho em php/config/installation.local.php. Esse marcador é ignorado pelo Git. Com marcador válido, a configuração privada prevalece sobre variáveis antigas; sem marcador, as variáveis de ambiente anteriores continuam funcionando.

Guarde backup da pasta privada, especialmente a chave HMAC. Nunca a envie ao GitHub. Em reinstalações, a chave e a senha da conta são reutilizadas. Backups da aplicação e de configurações alteradas ficam na pasta privada. O instalador não exporta automaticamente o banco: faça backup do banco real antes de manutenção conforme MIGRACAO_MARIADB.md.

## Quando houver erro

- Senha administrativa incorreta: execute de novo com a senha correta. Ela não é salva nem enviada como argumento de processo.
- Conta hydroponex_app existente: informe sua senha; não apague a conta para contornar erro 1396. Privilégios adicionais impedem concluir: revise-os com o administrador, sem revogar privilégios de outros projetos automaticamente.
- Banco incompatível ou importação parcial: o instalador para. Faça backup e siga MIGRACAO_MARIADB.md. Não importe o instalador por cima nem desative FKs/CHECKs.
- Erro Aria/checksum: problema do serviço MariaDB, não um motivo para apagar arquivos do datadir. A ocorrência conhecida do XAMPP original desta máquina está registrada em RELATORIO_MARIADB.md.
- Porta Apache ocupada: feche o programa conflitante ou configure outra porta no Apache e repita.
- AllowOverride desabilitado: use `INSTALAR_HYDROPONEX.bat -ConfigureApache`. O assistente cria backup, adiciona somente um Include específico e valida a sintaxe. Em Apache iniciado como console pelo Control Panel, pode pedir para parar/iniciar Apache e pressionar Enter. Não instala serviços Windows nem encerra à força servidores de outros projetos.
- Serviço Apache com identidade personalizada: essa identidade precisa de leitura na pasta privada; conceda somente ao usuário correto. O padrão atende o usuário instalador, SYSTEM e Administradores.
- Erro HTTP/arquivos privados acessíveis: instalação não é marcada como concluída. Confira logs do Apache e as proteções .htaccess; não publique a pasta privada.

Para diagnóstico técnico: INSTALACAO_XAMPP.md, MIGRACAO_MARIADB.md e RELATORIO_INSTALADOR.md. O assistente precisa de acesso local; não libera acesso externo ao banco nem cria instalador web. Bibliotecas visuais da interface continuam usando CDN/internet.
