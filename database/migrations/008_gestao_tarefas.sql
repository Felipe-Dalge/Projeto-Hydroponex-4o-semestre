-- Schema 1.6.0 -> 1.7.0. Faça backup e suspenda as escritas antes de executar uma vez.
SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci;
ALTER TABLE monitoramento_sensor ADD UNIQUE KEY uq_leitura_horta (id_leitura,id_horta);
ALTER TABLE horta_tarefa
 MODIFY COLUMN id_responsavel INT UNSIGNED NULL,
 ADD COLUMN prioridade VARCHAR(10) NOT NULL DEFAULT 'media',
 ADD COLUMN prazo_em DATETIME(6) NULL,
 ADD COLUMN versao INT UNSIGNED NOT NULL DEFAULT 1,
 ADD COLUMN problema VARCHAR(1000) NULL,
 ADD COLUMN origem_problema VARCHAR(20) NOT NULL DEFAULT 'manual',
 ADD COLUMN id_leitura BIGINT UNSIGNED NULL,
 ADD COLUMN problema_hash BINARY(32) NULL,
 ADD COLUMN criacao_hash BINARY(32) NULL UNIQUE,
 ADD COLUMN conteudo_hash BINARY(32) NULL,
 ADD UNIQUE KEY uq_tarefa_horta (id_tarefa,id_horta),
 ADD INDEX ix_tarefa_horta_estado (id_horta,estado,prioridade,prazo_em),
 ADD INDEX ix_tarefa_problema (id_horta,problema_hash,estado),
 ADD INDEX ix_tarefa_leitura (id_leitura,id_horta),
 DROP CHECK ck_tarefa_estado,
 ADD CONSTRAINT ck_tarefa_estado CHECK (estado IN ('pendente','em_andamento','em_verificacao','concluida','cancelada')),
 ADD CONSTRAINT ck_tarefa_prioridade CHECK (prioridade IN ('baixa','media','alta','critica')),
 ADD CONSTRAINT ck_tarefa_origem CHECK (origem_problema IN ('manual','estado_planta','leitura')),
 ADD CONSTRAINT fk_tarefa_leitura FOREIGN KEY (id_leitura,id_horta) REFERENCES monitoramento_sensor(id_leitura,id_horta) ON DELETE RESTRICT ON UPDATE RESTRICT;

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

-- A migração registra a importação do estado conhecido, sem atribuir a ação a um usuário.
INSERT INTO tarefa_historico (id_tarefa,id_horta,id_autor,evento,detalhes)
SELECT id_tarefa,id_horta,NULL,'migracao',JSON_OBJECT('estado',estado,'id_responsavel',id_responsavel,'versao',versao)
FROM horta_tarefa;
INSERT INTO schema_version (versao) VALUES ('1.7.0');
