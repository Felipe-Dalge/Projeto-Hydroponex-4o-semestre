-- Hydroponex: instalação NOVA, MySQL >= 8.0.16 (CHECK efetivo).
-- Selecione um banco VAZIO no phpMyAdmin. Não contém DROP nem desativa FKs.
-- Não usar para atualizar banco existente: consulte README.md e migrations/.
SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci;
SET SESSION time_zone = '+00:00';
SET SESSION sql_mode = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';

CREATE TABLE schema_version (
    versao VARCHAR(30) PRIMARY KEY,
    aplicada_em DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE estado (
    id_estado INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(100) NOT NULL,
    sigla CHAR(2) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    UNIQUE KEY uq_estado_sigla (sigla),
    CONSTRAINT ck_estado_sigla CHECK (REGEXP_LIKE(sigla, '^[A-Z]{2}$', 'c'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE cidade (
    id_cidade INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    id_estado INT UNSIGNED NOT NULL,
    nome VARCHAR(100) NOT NULL,
    UNIQUE KEY uq_cidade_estado_nome (id_estado, nome),
    CONSTRAINT fk_cidade_estado FOREIGN KEY (id_estado) REFERENCES estado (id_estado) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE cep (
    id_cep INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    codigo CHAR(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    id_cidade INT UNSIGNED NOT NULL,
    logradouro VARCHAR(200) NULL,
    bairro VARCHAR(100) NULL,
    UNIQUE KEY uq_cep_codigo (codigo),
    KEY ix_cep_cidade (id_cidade),
    CONSTRAINT ck_cep_codigo CHECK (REGEXP_LIKE(codigo, '^[0-9]{8}$', 'c')),
    CONSTRAINT fk_cep_cidade FOREIGN KEY (id_cidade) REFERENCES cidade (id_cidade) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE usuario (
    id_usuario INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(100) NOT NULL,
    email VARCHAR(254) NOT NULL,
    senha_hash VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NULL,
    tipo VARCHAR(50) NULL COMMENT 'Descrição legada; não concede permissões',
    ativo BOOLEAN NOT NULL DEFAULT FALSE,
    perfil_entrada VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'administrador',
    auth_versao INT UNSIGNED NOT NULL DEFAULT 1,
    excluido_em DATETIME(6) NULL,
    email_verificado_em DATETIME(6) NULL,
    avatar_url VARCHAR(2048) NULL,
    id_cep INT UNSIGNED NULL,
    numero_endereco VARCHAR(20) NULL,
    complemento VARCHAR(100) NULL,
    criado_em DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    atualizado_em DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    UNIQUE KEY uq_usuario_email (email),
    KEY ix_usuario_cep (id_cep),
    CONSTRAINT ck_usuario_perfil_entrada CHECK (perfil_entrada IN ('administrador','agricultor')),
    CONSTRAINT ck_usuario_ativo CHECK (ativo IN (0,1)),
    CONSTRAINT ck_usuario_credencial CHECK (ativo = 0 OR senha_hash IS NOT NULL),
    CONSTRAINT ck_usuario_hash CHECK (senha_hash IS NULL OR senha_hash LIKE '$2y$%' OR senha_hash LIKE '$argon2id$%'),
    CONSTRAINT fk_usuario_cep FOREIGN KEY (id_cep) REFERENCES cep (id_cep) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE token_usuario (
    id_token BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    id_usuario INT UNSIGNED NOT NULL,
    finalidade VARCHAR(20) NOT NULL,
    token_hash BINARY(32) NOT NULL COMMENT 'SHA-256 binário de token aleatório; nunca o token original',
    criado_em DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    expira_em DATETIME(6) NOT NULL,
    utilizado_em DATETIME(6) NULL,
    UNIQUE KEY uq_token_hash (token_hash),
    KEY ix_token_usuario_finalidade (id_usuario, finalidade, expira_em),
    CONSTRAINT ck_token_finalidade CHECK (finalidade IN ('recuperar_senha','verificar_email')),
    CONSTRAINT ck_token_datas CHECK (expira_em > criado_em AND (utilizado_em IS NULL OR utilizado_em >= criado_em)),
    CONSTRAINT fk_token_usuario FOREIGN KEY (id_usuario) REFERENCES usuario (id_usuario) ON DELETE CASCADE ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE horta (
    id_horta INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(200) NOT NULL,
    tamanho_m2 DECIMAL(12,2) NULL,
    localizacao VARCHAR(200) NULL COMMENT 'Descrição local, não substitui o endereço',
    id_cep INT UNSIGNED NULL,
    numero_endereco VARCHAR(20) NULL,
    complemento VARCHAR(100) NULL,
    arquivada_em DATETIME(6) NULL,
    criado_em DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    KEY ix_horta_cep (id_cep),
    CONSTRAINT ck_horta_tamanho CHECK (tamanho_m2 IS NULL OR tamanho_m2 > 0),
    CONSTRAINT fk_horta_cep FOREIGN KEY (id_cep) REFERENCES cep (id_cep) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE usuario_horta (
    id_usuario INT UNSIGNED NOT NULL,
    id_horta INT UNSIGNED NOT NULL,
    papel VARCHAR(15) NOT NULL DEFAULT 'leitor',
    criado_em DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id_usuario, id_horta),
    KEY ix_usuario_horta_horta_papel (id_horta, papel),
    CONSTRAINT ck_usuario_horta_papel CHECK (papel IN ('proprietario','gestor','agricultor','leitor')),
    CONSTRAINT fk_uh_usuario FOREIGN KEY (id_usuario) REFERENCES usuario (id_usuario) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_uh_horta FOREIGN KEY (id_horta) REFERENCES horta (id_horta) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE cultura (
    id_cultura INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    id_criador INT UNSIGNED NULL COMMENT 'NULL: catálogo global curado; usuário: catálogo pessoal',
    nome VARCHAR(100) NOT NULL,
    tipo VARCHAR(100) NULL,
    tempo_crescimento_dias SMALLINT UNSIGNED NULL,
    temperatura_ideal_c DECIMAL(5,2) NULL,
    ph_ideal DECIMAL(4,2) NULL,
    KEY ix_cultura_criador (id_criador),
    CONSTRAINT fk_cultura_criador FOREIGN KEY (id_criador) REFERENCES usuario (id_usuario) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT ck_cultura_ph CHECK (ph_ideal IS NULL OR ph_ideal BETWEEN 0 AND 14)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE horta_cultura (
    id_horta INT UNSIGNED NOT NULL,
    id_cultura INT UNSIGNED NOT NULL,
    PRIMARY KEY (id_horta, id_cultura),
    KEY ix_hc_cultura (id_cultura),
    CONSTRAINT fk_hc_horta FOREIGN KEY (id_horta) REFERENCES horta (id_horta) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_hc_cultura FOREIGN KEY (id_cultura) REFERENCES cultura (id_cultura) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE reservatorio (
    id_reservatorio INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    id_horta INT UNSIGNED NOT NULL,
    nome VARCHAR(100) NOT NULL,
    capacidade_l DECIMAL(12,3) NOT NULL,
    nivel_atual_l DECIMAL(12,3) NULL,
    ph_atual DECIMAL(4,2) NULL,
    temperatura_c DECIMAL(5,2) NULL,
    atualizado_em DATETIME(6) NULL,
    UNIQUE KEY uq_reservatorio_horta (id_reservatorio, id_horta),
    KEY ix_reservatorio_horta (id_horta),
    CONSTRAINT ck_reservatorio_volume CHECK (capacidade_l > 0 AND (nivel_atual_l IS NULL OR nivel_atual_l BETWEEN 0 AND capacidade_l)),
    CONSTRAINT ck_reservatorio_ph CHECK (ph_atual IS NULL OR ph_atual BETWEEN 0 AND 14),
    CONSTRAINT fk_reservatorio_horta FOREIGN KEY (id_horta) REFERENCES horta (id_horta) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE estrutura (
    id_estrutura INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    id_horta INT UNSIGNED NOT NULL,
    id_reservatorio INT UNSIGNED NULL,
    nome VARCHAR(100) NOT NULL,
    tipo VARCHAR(100) NULL,
    qnt_unidade SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    UNIQUE KEY uq_estrutura_horta (id_estrutura, id_horta),
    KEY ix_estrutura_horta (id_horta),
    KEY ix_estrutura_reservatorio (id_reservatorio, id_horta),
    CONSTRAINT ck_estrutura_unidades CHECK (qnt_unidade > 0),
    CONSTRAINT fk_estrutura_horta FOREIGN KEY (id_horta) REFERENCES horta (id_horta) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_estrutura_reservatorio FOREIGN KEY (id_reservatorio, id_horta) REFERENCES reservatorio (id_reservatorio, id_horta) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE planta (
    id_planta INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    id_horta INT UNSIGNED NOT NULL,
    id_cultura INT UNSIGNED NULL,
    id_estrutura INT UNSIGNED NULL,
    nome VARCHAR(100) NOT NULL,
    tipo VARCHAR(100) NULL,
    localizacao VARCHAR(100) NOT NULL,
    dias_crescimento SMALLINT UNSIGNED NULL,
    ph_atual DECIMAL(4,2) NULL,
    temperatura_agua_c DECIMAL(5,2) NULL,
    umidade_percentual DECIMAL(5,2) NULL,
    nivel_nutrientes_ppm DECIMAL(12,3) NULL,
    nivel_irrigacao DECIMAL(12,3) NULL COMMENT 'Unidade a confirmar para dados legados; não confundir com agua do frontend',
    agua_l DECIMAL(12,3) NULL COMMENT 'Valor agua do CRUD; significado operacional a confirmar',
    status_planta VARCHAR(15) NOT NULL DEFAULT 'Normal',
    ultima_medicao_em DATETIME(6) NULL,
    criado_em DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    atualizado_em DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    arquivada_em DATETIME(6) NULL,
    UNIQUE KEY uq_planta_horta (id_planta, id_horta),
    KEY ix_planta_horta_status (id_horta, status_planta),
    KEY ix_planta_horta_cultura (id_horta, id_cultura),
    KEY ix_planta_estrutura (id_estrutura, id_horta),
    CONSTRAINT ck_planta_ph CHECK (ph_atual IS NULL OR ph_atual BETWEEN 0 AND 14),
    CONSTRAINT ck_planta_umidade CHECK (umidade_percentual IS NULL OR umidade_percentual BETWEEN 0 AND 100),
    CONSTRAINT ck_planta_nutrientes CHECK (nivel_nutrientes_ppm IS NULL OR nivel_nutrientes_ppm >= 0),
    CONSTRAINT ck_planta_irrigacao CHECK (nivel_irrigacao IS NULL OR nivel_irrigacao >= 0),
    CONSTRAINT ck_planta_agua CHECK (agua_l IS NULL OR agua_l >= 0),
    CONSTRAINT ck_planta_status CHECK (status_planta IN ('Normal','Atenção','Alerta')),
    CONSTRAINT fk_planta_horta FOREIGN KEY (id_horta) REFERENCES horta (id_horta) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_planta_cultura FOREIGN KEY (id_horta, id_cultura) REFERENCES horta_cultura (id_horta, id_cultura) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_planta_estrutura FOREIGN KEY (id_estrutura, id_horta) REFERENCES estrutura (id_estrutura, id_horta) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE nutriente (
    id_nutriente INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    id_criador INT UNSIGNED NULL COMMENT 'NULL: catálogo global curado; usuário: catálogo pessoal',
    nome VARCHAR(100) NOT NULL,
    classificacao VARCHAR(100) NULL,
    descricao VARCHAR(1000) NULL,
    tipo VARCHAR(100) NULL,
    KEY ix_nutriente_criador (id_criador),
    CONSTRAINT fk_nutriente_criador FOREIGN KEY (id_criador) REFERENCES usuario (id_usuario) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE reservatorio_nutriente (
    id_aplicacao BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    id_reservatorio INT UNSIGNED NOT NULL,
    id_nutriente INT UNSIGNED NOT NULL,
    quantidade DECIMAL(12,3) NOT NULL,
    unidade VARCHAR(5) NOT NULL,
    aplicado_em DATETIME(6) NOT NULL,
    id_usuario INT UNSIGNED NULL,
    KEY ix_rn_reservatorio_data (id_reservatorio, aplicado_em),
    KEY ix_rn_nutriente (id_nutriente),
    KEY ix_rn_usuario (id_usuario),
    CONSTRAINT ck_rn_quantidade CHECK (quantidade > 0),
    CONSTRAINT ck_rn_unidade CHECK (unidade IN ('g','mg','mL','L')),
    CONSTRAINT fk_rn_reservatorio FOREIGN KEY (id_reservatorio) REFERENCES reservatorio (id_reservatorio) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_rn_nutriente FOREIGN KEY (id_nutriente) REFERENCES nutriente (id_nutriente) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_rn_usuario FOREIGN KEY (id_usuario) REFERENCES usuario (id_usuario) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE sensor (
    id_sensor INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    id_horta INT UNSIGNED NOT NULL,
    nome VARCHAR(100) NOT NULL,
    tipo VARCHAR(100) NOT NULL,
    unidade VARCHAR(20) NOT NULL,
    status VARCHAR(15) NOT NULL DEFAULT 'ativo',
    id_reservatorio INT UNSIGNED NULL,
    id_estrutura INT UNSIGNED NULL,
    id_planta INT UNSIGNED NULL,
    id_nutriente INT UNSIGNED NULL COMMENT 'Opcional: nutriente específico medido, não fonte da localização',
    limite_min DECIMAL(20,6) NULL,
    limite_max DECIMAL(20,6) NULL,
    CONSTRAINT ck_sensor_limites CHECK (limite_min IS NULL OR limite_max IS NULL OR limite_min<=limite_max),
    UNIQUE KEY uq_sensor_horta (id_sensor, id_horta),
    KEY ix_sensor_horta_status (id_horta, status),
    KEY ix_sensor_reservatorio (id_reservatorio, id_horta),
    KEY ix_sensor_estrutura (id_estrutura, id_horta),
    KEY ix_sensor_planta (id_planta, id_horta),
    KEY ix_sensor_nutriente (id_nutriente),
    CONSTRAINT ck_sensor_status CHECK (status IN ('ativo','inativo','manutencao')),
    CONSTRAINT ck_sensor_alvo CHECK ((id_reservatorio IS NOT NULL) + (id_estrutura IS NOT NULL) + (id_planta IS NOT NULL) <= 1),
    CONSTRAINT fk_sensor_horta FOREIGN KEY (id_horta) REFERENCES horta (id_horta) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_sensor_reservatorio FOREIGN KEY (id_reservatorio, id_horta) REFERENCES reservatorio (id_reservatorio, id_horta) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_sensor_estrutura FOREIGN KEY (id_estrutura, id_horta) REFERENCES estrutura (id_estrutura, id_horta) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_sensor_planta FOREIGN KEY (id_planta, id_horta) REFERENCES planta (id_planta, id_horta) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_sensor_nutriente FOREIGN KEY (id_nutriente) REFERENCES nutriente (id_nutriente) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE monitoramento (
    id_monitoramento BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    id_horta INT UNSIGNED NOT NULL,
    id_usuario INT UNSIGNED NULL,
    origem VARCHAR(15) NOT NULL DEFAULT 'manual',
    registrado_em DATETIME(6) NULL COMMENT 'NULL nos registros legados',
    medicao VARCHAR(100) NULL COMMENT 'Descrição original; valores numéricos ficam nas leituras',
    data_hora DATETIME(6) NOT NULL,
    UNIQUE KEY uq_monitoramento_horta (id_monitoramento, id_horta),
    KEY ix_monitoramento_horta_data (id_horta, data_hora),
    KEY ix_monitoramento_usuario (id_usuario),
    CONSTRAINT ck_monitoramento_origem CHECK (origem IN ('manual','sensor','importacao','simulacao')),
    CONSTRAINT ck_monitoramento_autor CHECK (origem <> 'manual' OR id_usuario IS NOT NULL),
    CONSTRAINT fk_monitoramento_horta FOREIGN KEY (id_horta) REFERENCES horta (id_horta) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_monitoramento_usuario FOREIGN KEY (id_usuario) REFERENCES usuario (id_usuario) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE monitoramento_sensor (
    id_leitura BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    id_monitoramento BIGINT UNSIGNED NOT NULL,
    id_sensor INT UNSIGNED NOT NULL,
    id_horta INT UNSIGNED NOT NULL,
    valor DECIMAL(20,6) NOT NULL,
    tipo_medicao VARCHAR(100) NOT NULL,
    unidade VARCHAR(20) NOT NULL COMMENT 'Snapshot da unidade na coleta',
    limite_min DECIMAL(20,6) NULL,
    limite_max DECIMAL(20,6) NULL,
    UNIQUE KEY uq_leitura_horta (id_leitura,id_horta),
    CONSTRAINT ck_leitura_limites CHECK (limite_min IS NULL OR limite_max IS NULL OR limite_min<=limite_max),
    KEY ix_leitura_horta_sensor (id_horta,id_sensor,id_monitoramento),
    UNIQUE KEY uq_leitura_monitoramento_sensor (id_monitoramento, id_sensor),
    KEY ix_leitura_monitoramento_horta (id_monitoramento, id_horta),
    KEY ix_leitura_sensor_horta (id_sensor, id_horta),
    CONSTRAINT fk_leitura_monitoramento FOREIGN KEY (id_monitoramento, id_horta) REFERENCES monitoramento (id_monitoramento, id_horta) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_leitura_sensor FOREIGN KEY (id_sensor, id_horta) REFERENCES sensor (id_sensor, id_horta) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE consumo_agua (
    id_consumo BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    id_horta INT UNSIGNED NOT NULL,
    data_consumo DATE NOT NULL,
    origem VARCHAR(15) NOT NULL DEFAULT 'importacao',
    CONSTRAINT ck_consumo_origem CHECK (origem IN ('manual','sensor','importacao','simulacao')),
    volume_l DECIMAL(12,3) NOT NULL,
    referencia_solo_l DECIMAL(12,3) NULL COMMENT 'Estimativa, não uma medição da horta tradicional',
    UNIQUE KEY uq_consumo_horta_data (id_horta, data_consumo),
    CONSTRAINT ck_consumo_volume CHECK (volume_l >= 0 AND (referencia_solo_l IS NULL OR referencia_solo_l >= 0)),
    CONSTRAINT fk_consumo_horta FOREIGN KEY (id_horta) REFERENCES horta (id_horta) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE importacao_json (
    origem_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    id_origem INT UNSIGNED NOT NULL,
    id_planta INT UNSIGNED NOT NULL,
    payload JSON NOT NULL COMMENT 'Registro original integral para auditoria e campos sem semântica definida',
    importado_em DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (origem_sha256, id_origem),
    UNIQUE KEY uq_importacao_planta (id_planta),
    CONSTRAINT fk_importacao_planta FOREIGN KEY (id_planta) REFERENCES planta (id_planta) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE auth_limite (
    chave BINARY(32) PRIMARY KEY,
    janela_em DATETIME(6) NOT NULL,
    tentativas INT UNSIGNED NOT NULL DEFAULT 0,
    KEY ix_auth_limite_janela (janela_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE horta_convite (
    id_convite INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    id_horta INT UNSIGNED NOT NULL,
    id_criador INT UNSIGNED NOT NULL,
    email VARCHAR(254) NOT NULL,
    papel VARCHAR(15) NOT NULL,
    codigo_hash BINARY(32) NOT NULL UNIQUE,
    criado_em DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    expira_em DATETIME(6) NOT NULL,
    aceito_em DATETIME(6) NULL,
    revogado_em DATETIME(6) NULL,
    INDEX ix_convite_horta (id_horta, id_convite),
    CONSTRAINT ck_convite_papel CHECK (papel IN ('gestor','agricultor','leitor')),
    CONSTRAINT fk_convite_horta FOREIGN KEY (id_horta) REFERENCES horta(id_horta),
    CONSTRAINT fk_convite_criador FOREIGN KEY (id_criador) REFERENCES usuario(id_usuario)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE horta_tarefa (
    id_tarefa INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    id_planta INT UNSIGNED NULL,
    concluida_em DATETIME(6) NULL,
    id_horta INT UNSIGNED NOT NULL,
    id_criador INT UNSIGNED NOT NULL,
    id_responsavel INT UNSIGNED NULL,
    prioridade VARCHAR(10) NOT NULL DEFAULT 'media',
    prazo_em DATETIME(6) NULL,
    versao INT UNSIGNED NOT NULL DEFAULT 1,
    problema VARCHAR(1000) NULL,
    origem_problema VARCHAR(20) NOT NULL DEFAULT 'manual',
    id_leitura BIGINT UNSIGNED NULL,
    problema_hash BINARY(32) NULL,
    criacao_hash BINARY(32) NULL UNIQUE,
    conteudo_hash BINARY(32) NULL,
    UNIQUE KEY uq_tarefa_horta (id_tarefa,id_horta),
    INDEX ix_tarefa_horta_estado (id_horta,estado,prioridade,prazo_em),
    INDEX ix_tarefa_problema (id_horta,problema_hash,estado),
    INDEX ix_tarefa_leitura (id_leitura,id_horta),
    CONSTRAINT ck_tarefa_prioridade CHECK (prioridade IN ('baixa','media','alta','critica')),
    CONSTRAINT ck_tarefa_origem CHECK (origem_problema IN ('manual','estado_planta','leitura')),
    CONSTRAINT fk_tarefa_leitura FOREIGN KEY (id_leitura,id_horta) REFERENCES monitoramento_sensor(id_leitura,id_horta) ON DELETE RESTRICT ON UPDATE RESTRICT,
    titulo VARCHAR(160) NOT NULL,
    descricao VARCHAR(1000) NULL,
    estado VARCHAR(20) NOT NULL DEFAULT 'pendente',
    criado_em DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    atualizado_em DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    INDEX ix_tarefa_horta_responsavel (id_horta, id_responsavel, id_tarefa),
    INDEX ix_tarefa_planta_horta (id_planta,id_horta),
    CONSTRAINT fk_tarefa_planta_horta FOREIGN KEY (id_planta,id_horta) REFERENCES planta(id_planta,id_horta) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT ck_tarefa_estado CHECK (estado IN ('pendente','em_andamento','em_verificacao','concluida','cancelada')),
    CONSTRAINT fk_tarefa_horta FOREIGN KEY (id_horta) REFERENCES horta(id_horta),
    CONSTRAINT fk_tarefa_criador FOREIGN KEY (id_criador) REFERENCES usuario(id_usuario),
    CONSTRAINT fk_tarefa_responsavel FOREIGN KEY (id_responsavel) REFERENCES usuario(id_usuario)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE horta_registro (
    id_registro INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    id_planta INT UNSIGNED NULL,
    id_horta INT UNSIGNED NOT NULL,
    id_autor INT UNSIGNED NOT NULL,
    tipo VARCHAR(16) NOT NULL,
    descricao VARCHAR(1000) NOT NULL,
    criado_em DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    INDEX ix_registro_horta (id_horta, id_registro),
    INDEX ix_registro_planta_horta (id_planta,id_horta),
    CONSTRAINT fk_registro_planta_horta FOREIGN KEY (id_planta,id_horta) REFERENCES planta(id_planta,id_horta) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT ck_registro_tipo CHECK (tipo IN ('observacao','intervencao')),
    CONSTRAINT fk_registro_horta FOREIGN KEY (id_horta) REFERENCES horta(id_horta),
    CONSTRAINT fk_registro_autor FOREIGN KEY (id_autor) REFERENCES usuario(id_usuario)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

INSERT INTO schema_version (versao) VALUES ('1.0.0'), ('1.1.0'), ('1.2.0'), ('1.3.0'), ('1.4.0');

CREATE TABLE equipe_convite (
    id_convite INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    id_emissor INT UNSIGNED NOT NULL,
    papel VARCHAR(15) NOT NULL DEFAULT 'agricultor',
    email VARCHAR(254) NOT NULL,
    codigo_hash BINARY(32) NOT NULL UNIQUE,
    criado_em DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    expira_em DATETIME(6) NOT NULL,
    tentativa_em DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    enviado_em DATETIME(6) NULL,
    falhou_em DATETIME(6) NULL,
    aceito_em DATETIME(6) NULL,
    recusado_em DATETIME(6) NULL,
    cancelado_em DATETIME(6) NULL,
    INDEX ix_equipe_convite_emissor (id_emissor, id_convite),
    INDEX ix_equipe_convite_email (email, expira_em),
    CONSTRAINT fk_equipe_convite_emissor FOREIGN KEY (id_emissor) REFERENCES usuario(id_usuario),
    CONSTRAINT ck_equipe_convite_papel CHECK (papel='agricultor'),
    CONSTRAINT ck_equipe_convite_decisao CHECK (
        (aceito_em IS NOT NULL) + (recusado_em IS NOT NULL) + (cancelado_em IS NOT NULL) <= 1
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- O papel desta modalidade é agricultor. Uma referência apagada invalida o lote inteiro.
CREATE TABLE equipe_convite_horta (
    id_alvo INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    id_convite INT UNSIGNED NOT NULL,
    id_horta INT UNSIGNED NULL,
    UNIQUE KEY uq_equipe_convite_horta (id_convite, id_horta),
    INDEX ix_equipe_horta_convite (id_horta, id_convite),
    CONSTRAINT fk_equipe_alvo_convite FOREIGN KEY (id_convite) REFERENCES equipe_convite(id_convite),
    CONSTRAINT fk_equipe_alvo_horta FOREIGN KEY (id_horta) REFERENCES horta(id_horta) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

INSERT INTO schema_version (versao) VALUES ('1.5.0');

INSERT INTO schema_version (versao) VALUES ('1.6.0');

CREATE TABLE tarefa_historico (
    id_evento BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    id_tarefa INT UNSIGNED NOT NULL,
    id_horta INT UNSIGNED NOT NULL,
    id_autor INT UNSIGNED NULL,
    evento VARCHAR(20) NOT NULL,
    detalhes JSON NOT NULL,
    criado_em DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    INDEX ix_historico_tarefa (id_tarefa,id_horta,id_evento),
    CONSTRAINT ck_historico_autor CHECK (id_autor IS NOT NULL OR evento='migracao'),
    CONSTRAINT fk_historico_tarefa FOREIGN KEY (id_tarefa,id_horta) REFERENCES horta_tarefa(id_tarefa,id_horta) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_historico_autor FOREIGN KEY (id_autor) REFERENCES usuario(id_usuario) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE tarefa_intervencao (
    id_intervencao BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    id_tarefa INT UNSIGNED NOT NULL,
    id_horta INT UNSIGNED NOT NULL,
    id_autor INT UNSIGNED NOT NULL,
    tipo VARCHAR(15) NOT NULL,
    descricao VARCHAR(2000) NOT NULL,
    observacoes VARCHAR(1000) NULL,
    realizada_em DATETIME(6) NOT NULL,
    registrado_em DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    INDEX ix_intervencao_tarefa (id_tarefa,id_horta,id_intervencao),
    CONSTRAINT ck_intervencao_tipo CHECK (tipo IN ('observacao','intervencao')),
    CONSTRAINT fk_intervencao_tarefa FOREIGN KEY (id_tarefa,id_horta) REFERENCES horta_tarefa(id_tarefa,id_horta) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_intervencao_autor FOREIGN KEY (id_autor) REFERENCES usuario(id_usuario) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;


INSERT INTO schema_version (versao) VALUES ('1.7.0');
