-- Atualização 1.0.0 -> 1.1.0, preservando usuários, hashes e todas as entidades.
-- Execute UMA vez, com backup e escritas suspensas. MySQL DDL tem commit implícito.
-- Não executar após instalar o hydroponex.sql atualizado (já inclui esta alteração).
SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci;
ALTER TABLE usuario
    ADD COLUMN auth_versao INT UNSIGNED NOT NULL DEFAULT 1 AFTER ativo,
    ADD COLUMN excluido_em DATETIME(6) NULL AFTER auth_versao;
CREATE TABLE auth_limite (
    chave BINARY(32) PRIMARY KEY,
    janela_em DATETIME(6) NOT NULL,
    tentativas INT UNSIGNED NOT NULL DEFAULT 0,
    KEY ix_auth_limite_janela (janela_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
INSERT INTO schema_version (versao) VALUES ('1.1.0');
