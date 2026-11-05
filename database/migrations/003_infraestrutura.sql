-- Schema 1.1.0 -> 1.2.0. Execute uma vez após backup, com escritas suspensas.
-- Dados preexistentes permanecem globais (NULL), sem atribuição arbitrária a usuários.
-- DDL MySQL tem commit implícito; interrompa e inspecione falhas, sem reaplicar cegamente.
SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci;
ALTER TABLE cultura
    ADD COLUMN id_criador INT UNSIGNED NULL AFTER id_cultura,
    ADD KEY ix_cultura_criador (id_criador),
    ADD CONSTRAINT fk_cultura_criador FOREIGN KEY (id_criador) REFERENCES usuario (id_usuario) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE nutriente
    ADD COLUMN id_criador INT UNSIGNED NULL AFTER id_nutriente,
    ADD KEY ix_nutriente_criador (id_criador),
    ADD CONSTRAINT fk_nutriente_criador FOREIGN KEY (id_criador) REFERENCES usuario (id_usuario) ON DELETE RESTRICT ON UPDATE RESTRICT;
INSERT INTO schema_version (versao) VALUES ('1.2.0');
