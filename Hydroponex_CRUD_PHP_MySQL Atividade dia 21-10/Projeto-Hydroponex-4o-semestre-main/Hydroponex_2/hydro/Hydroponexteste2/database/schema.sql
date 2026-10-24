-- MySQL 8 / MariaDB (XAMPP). Tabela compatível com a interface atual.
CREATE DATABASE IF NOT EXISTS hydroponex CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE hydroponex;
CREATE TABLE IF NOT EXISTS plantas (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    nome VARCHAR(100) NOT NULL,
    bancada VARCHAR(100) NOT NULL,
    temp DECIMAL(5,2) NOT NULL,
    umidade TINYINT UNSIGNED NOT NULL DEFAULT 60,
    nutrientes INT UNSIGNED NOT NULL,
    agua DECIMAL(8,2) NOT NULL DEFAULT 1.50,
    status VARCHAR(15) NOT NULL DEFAULT 'Normal',
    last_update VARCHAR(50) NOT NULL DEFAULT 'Agora',
    PRIMARY KEY (id),
    CONSTRAINT chk_temp CHECK (temp BETWEEN 0 AND 60),
    CONSTRAINT chk_nutrientes CHECK (nutrientes BETWEEN 0 AND 10000),
    CONSTRAINT chk_umidade CHECK (umidade BETWEEN 0 AND 100)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
