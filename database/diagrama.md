# Hydroponex — diagrama entidade-relacionamento

As cardinalidades indicam o que o banco permite. Uma horta ativa deve ter pelo menos um proprietário, regra transacional da aplicação. `o|` representa referência opcional. As FKs compostas incluem `id_horta` para garantir a mesma horta em ambos os lados.

```mermaid
erDiagram
    estado ||--o{ cidade : possui
    cidade ||--o{ cep : possui
    cep o|--o{ usuario : endereco
    cep o|--o{ horta : endereco
    usuario o|--o{ cultura : cria_catalogo_pessoal
    usuario o|--o{ nutriente : cria_catalogo_pessoal
    usuario ||--o{ usuario_horta : participa
    horta ||--o{ usuario_horta : membros
    usuario ||--o{ token_usuario : recebe
    horta ||--o{ horta_cultura : habilita
    cultura ||--o{ horta_cultura : catalogo
    horta ||--o{ reservatorio : possui
    horta ||--o{ estrutura : possui
    reservatorio o|--o{ estrutura : abastece
    horta ||--o{ planta : contem
    horta_cultura o|--o{ planta : classifica
    estrutura o|--o{ planta : acomoda
    horta ||--o{ sensor : instala
    reservatorio o|--o{ sensor : alvo
    estrutura o|--o{ sensor : alvo
    planta o|--o{ sensor : alvo
    nutriente o|--o{ sensor : mede_especificamente
    reservatorio ||--o{ reservatorio_nutriente : recebe_aplicacao
    nutriente ||--o{ reservatorio_nutriente : dosado
    usuario o|--o{ reservatorio_nutriente : registra
    horta ||--o{ monitoramento : historico
    usuario o|--o{ monitoramento : autor
    monitoramento ||--o{ monitoramento_sensor : leituras
    sensor ||--o{ monitoramento_sensor : produz
    horta ||--o{ consumo_agua : consumo_diario
    planta ||--o| importacao_json : origem_preservada

    estado {
        int id_estado PK
        char sigla UK
        varchar nome
    }
    cidade {
        int id_cidade PK
        int id_estado FK
        varchar nome "único no estado"
    }
    cep {
        int id_cep PK
        char codigo UK "8 dígitos"
        int id_cidade FK
    }
    usuario {
        int id_usuario PK
        varchar email UK
        varchar senha_hash "opcional somente se inativo"
        boolean ativo
        int auth_versao "revogação de sessões"
        datetime excluido_em "exclusão anonimizada"
        int id_cep FK
    }
    usuario_horta {
        int id_usuario PK,FK
        int id_horta PK,FK
        varchar papel
    }
    token_usuario {
        bigint id_token PK
        int id_usuario FK
        binary token_hash UK
        datetime expira_em
    }
    horta {
        int id_horta PK
        int id_cep FK
        varchar nome
        datetime arquivada_em
    }
    cultura {
        int id_cultura PK
        int id_criador FK "NULL: catálogo global"
        varchar nome
        decimal ph_ideal
    }
    horta_cultura {
        int id_horta PK,FK
        int id_cultura PK,FK
    }
    reservatorio {
        int id_reservatorio PK
        int id_horta FK
        decimal capacidade_l
        decimal nivel_atual_l
    }
    estrutura {
        int id_estrutura PK
        int id_horta FK
        int id_reservatorio FK "opcional; mesma horta"
    }
    planta {
        int id_planta PK
        int id_horta FK
        int id_cultura FK "opcional; habilitada na horta"
        int id_estrutura FK "opcional; mesma horta"
        varchar nome
        varchar status_planta
    }
    nutriente {
        int id_nutriente PK
        int id_criador FK "NULL: catálogo global"
        varchar nome
    }
    reservatorio_nutriente {
        bigint id_aplicacao PK
        int id_reservatorio FK
        int id_nutriente FK
        int id_usuario FK "opcional"
        decimal quantidade
        varchar unidade
        datetime aplicado_em
    }
    sensor {
        int id_sensor PK
        int id_horta FK
        int id_reservatorio FK "no máximo um alvo"
        int id_estrutura FK
        int id_planta FK
        int id_nutriente FK "opcional"
        varchar tipo
        varchar unidade
        decimal limite_min
        decimal limite_max
    }
    monitoramento {
        bigint id_monitoramento PK
        int id_horta FK
        int id_usuario FK "obrigatório se manual"
        datetime data_hora
        datetime registrado_em "NULL no legado"
        varchar origem "manual, sensor, importacao, simulacao"
    }
    monitoramento_sensor {
        bigint id_leitura PK
        bigint id_monitoramento FK
        int id_sensor FK
        int id_horta FK "parte das duas FKs compostas"
        decimal valor
        varchar tipo_medicao
        varchar unidade
        decimal limite_min "snapshot"
        decimal limite_max "snapshot"
    }
    consumo_agua {
        bigint id_consumo PK
        int id_horta FK
        date data_consumo "única por horta"
        decimal volume_l
        varchar origem "legado: importacao"
    }
    importacao_json {
        char origem_sha256 PK
        int id_origem PK
        int id_planta FK,UK
        json payload
    }
    schema_version {
        varchar versao PK
        datetime aplicada_em
    }
    auth_limite {
        binary chave PK "HMAC-SHA256; sem e-mail/IP bruto"
        datetime janela_em
        int tentativas
    }
```

`schema_version` e `auth_limite` são tabelas técnicas sem relacionamento de domínio. Sensor sem alvo específico mede o ambiente da própria horta; com alvo, escolhe exatamente um entre reservatório, estrutura e planta. Cada monitoramento admite uma leitura por sensor; nova amostra cria outro monitoramento. O vínculo direto de horta em `monitoramento_sensor` é controlado pelas duas FKs compostas, não é um endereço ou uma associação independente.

Schema 1.2.0: autoria opcional em cultura/nutriente distingue catálogo global de pessoal. Itens pessoais ficam consultáveis pelos membros das hortas que os utilizam; edição/exclusão pelo criador somente sem vínculos. Autoria usa RESTRICT; exclusão de conta é anonimizada. Aplicações e leituras preservam o histórico. Veja [INFRAESTRUTURA.md](INFRAESTRUTURA.md).

Schema 1.3.0: limites de sensor são copiados para cada leitura. Evento guarda coleta UTC e recebimento; simulações são explícitas. Tipo/unidade/alvo do sensor com histórico são protegidos pela aplicação. Consumo tem origem, sem presumir procedência do legado. Veja [MONITORAMENTO.md](MONITORAMENTO.md).
