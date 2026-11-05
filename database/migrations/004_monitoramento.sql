-- Schema 1.2.0 -> 1.3.0. Backup, escritas suspensas; execute uma única vez.
-- DDL MySQL faz commit implícito: inspecione erros antes de retomar.
SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci;
ALTER TABLE sensor
 ADD COLUMN limite_min DECIMAL(20,6) NULL,
 ADD COLUMN limite_max DECIMAL(20,6) NULL,
 ADD CONSTRAINT ck_sensor_limites CHECK (limite_min IS NULL OR limite_max IS NULL OR limite_min<=limite_max);
ALTER TABLE monitoramento
 DROP CHECK ck_monitoramento_origem,
 ADD CONSTRAINT ck_monitoramento_origem CHECK (origem IN ('manual','sensor','importacao','simulacao')),
 ADD COLUMN registrado_em DATETIME(6) NULL COMMENT 'NULL nos registros legados; não presume instante de recebimento';
ALTER TABLE monitoramento_sensor
 ADD COLUMN limite_min DECIMAL(20,6) NULL COMMENT 'Snapshot na coleta; NULL no legado',
 ADD COLUMN limite_max DECIMAL(20,6) NULL,
 ADD CONSTRAINT ck_leitura_limites CHECK (limite_min IS NULL OR limite_max IS NULL OR limite_min<=limite_max),
 ADD KEY ix_leitura_horta_sensor (id_horta,id_sensor,id_monitoramento);
ALTER TABLE consumo_agua
 ADD COLUMN origem VARCHAR(15) NOT NULL DEFAULT 'importacao' COMMENT 'Legado: origem não verificada',
 ADD CONSTRAINT ck_consumo_origem CHECK (origem IN ('manual','sensor','importacao','simulacao'));
INSERT INTO schema_version (versao) VALUES ('1.3.0');
