-- Schema 1.4.0 -> 1.5.0. Faça backup e suspenda as escritas antes de executar uma vez.
SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci;

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
