-- Schema 1.3.0 -> 1.4.0. Faça backup e suspenda as escritas antes de executar uma vez.
-- Contas anteriores mantêm a capacidade de criar hortas e os vínculos permanecem intactos.
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE usuario
 ADD COLUMN perfil_entrada VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'administrador',
 ADD CONSTRAINT ck_usuario_perfil_entrada CHECK (perfil_entrada IN ('administrador','agricultor'));
ALTER TABLE usuario_horta
 DROP CONSTRAINT ck_usuario_horta_papel,
 ADD CONSTRAINT ck_usuario_horta_papel CHECK (papel IN ('proprietario','gestor','agricultor','leitor'));

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE horta_tarefa (
    id_tarefa INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    id_horta INT UNSIGNED NOT NULL,
    id_criador INT UNSIGNED NOT NULL,
    id_responsavel INT UNSIGNED NOT NULL,
    titulo VARCHAR(160) NOT NULL,
    descricao VARCHAR(1000) NULL,
    estado VARCHAR(20) NOT NULL DEFAULT 'pendente',
    criado_em DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    atualizado_em DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    INDEX ix_tarefa_horta_responsavel (id_horta, id_responsavel, id_tarefa),
    CONSTRAINT ck_tarefa_estado CHECK (estado IN ('pendente','em_andamento','concluida')),
    CONSTRAINT fk_tarefa_horta FOREIGN KEY (id_horta) REFERENCES horta(id_horta),
    CONSTRAINT fk_tarefa_criador FOREIGN KEY (id_criador) REFERENCES usuario(id_usuario),
    CONSTRAINT fk_tarefa_responsavel FOREIGN KEY (id_responsavel) REFERENCES usuario(id_usuario)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE horta_registro (
    id_registro INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    id_horta INT UNSIGNED NOT NULL,
    id_autor INT UNSIGNED NOT NULL,
    tipo VARCHAR(16) NOT NULL,
    descricao VARCHAR(1000) NOT NULL,
    criado_em DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    INDEX ix_registro_horta (id_horta, id_registro),
    CONSTRAINT ck_registro_tipo CHECK (tipo IN ('observacao','intervencao')),
    CONSTRAINT fk_registro_horta FOREIGN KEY (id_horta) REFERENCES horta(id_horta),
    CONSTRAINT fk_registro_autor FOREIGN KEY (id_autor) REFERENCES usuario(id_usuario)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO schema_version (versao) VALUES ('1.4.0');
