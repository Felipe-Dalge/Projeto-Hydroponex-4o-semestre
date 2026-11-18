-- Schema 1.5.0 -> 1.6.0. Faça backup e suspenda as escritas antes de executar uma vez.
SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci;
ALTER TABLE horta_tarefa
 ADD COLUMN id_planta INT UNSIGNED NULL,
 ADD COLUMN concluida_em DATETIME(6) NULL,
 ADD INDEX ix_tarefa_planta_horta (id_planta,id_horta),
 ADD CONSTRAINT fk_tarefa_planta_horta FOREIGN KEY (id_planta,id_horta)
 REFERENCES planta(id_planta,id_horta) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE horta_registro
 ADD COLUMN id_planta INT UNSIGNED NULL,
 ADD INDEX ix_registro_planta_horta (id_planta,id_horta),
 ADD CONSTRAINT fk_registro_planta_horta FOREIGN KEY (id_planta,id_horta)
 REFERENCES planta(id_planta,id_horta) ON DELETE RESTRICT ON UPDATE RESTRICT;
-- Tarefas e registros antigos continuam gerais da horta.
-- Conclusões anteriores mantêm a data desconhecida, sem inventar um horário.
INSERT INTO schema_version (versao) VALUES ('1.6.0');
