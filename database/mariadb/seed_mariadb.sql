-- OPCIONAL: somente em banco novo de desenvolvimento; execute após hydroponex.sql.
-- Não contém credenciais utilizáveis; usuários ficam inativos e sem senha.
SET NAMES utf8mb4;
SET SESSION time_zone = '+00:00';
START TRANSACTION;
INSERT INTO estado (id_estado,nome,sigla) VALUES (1,'São Paulo','SP');
INSERT INTO cidade (id_cidade,id_estado,nome) VALUES (1,1,'São Paulo');
INSERT INTO cep (id_cep,codigo,id_cidade,logradouro,bairro) VALUES (1,'01001000',1,'Praça da Sé','Sé');
INSERT INTO usuario (id_usuario,nome,email,ativo,id_cep) VALUES
 (1,'Ana — demonstração','ana@example.test',0,1), (2,'Bruno — demonstração','bruno@example.test',0,NULL);
INSERT INTO horta (id_horta,nome,tamanho_m2,id_cep) VALUES (1,'Horta acadêmica A',12.5,1),(2,'Horta acadêmica B',8,1);
INSERT INTO usuario_horta (id_usuario,id_horta,papel) VALUES (1,1,'proprietario'),(1,2,'proprietario'),(2,1,'leitor');
INSERT INTO cultura (id_cultura,nome,tipo,tempo_crescimento_dias,temperatura_ideal_c,ph_ideal) VALUES
 (1,'Alface','Folhosa',45,22,6),(2,'Rúcula','Folhosa',35,23,6.2);
INSERT INTO horta_cultura VALUES (1,1),(1,2),(2,1);
INSERT INTO reservatorio (id_reservatorio,id_horta,nome,capacidade_l,nivel_atual_l,ph_atual,temperatura_c) VALUES
 (1,1,'Reservatório A',100,75,6.1,21.5),(2,2,'Reservatório B',50,40,6,22);
INSERT INTO estrutura (id_estrutura,id_horta,id_reservatorio,nome,tipo,qnt_unidade) VALUES
 (1,1,1,'Bancada A1','NFT',12),(2,1,1,'Bancada A2','NFT',8),(3,2,2,'Bancada B1','NFT',6);
INSERT INTO planta (id_planta,id_horta,id_cultura,id_estrutura,nome,localizacao,temperatura_agua_c,umidade_percentual,nivel_nutrientes_ppm,agua_l,status_planta,ultima_medicao_em) VALUES
 (1,1,1,1,'Alface Americana','Bancada A1',21.5,68,750,1.2,'Normal','2026-10-27 12:00:00'),
 (2,1,2,2,'Rúcula Hidropônica','Bancada A2',24.8,52,620,1.8,'Atenção','2026-10-27 12:00:00'),
 (3,2,1,3,'Alface B','Bancada B1',22,65,780,1.3,'Normal','2026-10-27 12:00:00');
INSERT INTO nutriente (id_nutriente,nome,classificacao,descricao,tipo) VALUES (1,'Solução A','Mistura','Exemplo fictício; não é recomendação agronômica','Solução');
INSERT INTO reservatorio_nutriente (id_reservatorio,id_nutriente,quantidade,unidade,aplicado_em,id_usuario) VALUES (1,1,10,'mL','2026-10-27 11:00:00',1);
INSERT INTO sensor (id_sensor,id_horta,nome,tipo,unidade,id_reservatorio) VALUES (1,1,'pH A','ph','pH',1),(2,1,'Temperatura A','temperatura','°C',1),(3,2,'pH B','ph','pH',2);
INSERT INTO monitoramento (id_monitoramento,id_horta,origem,medicao,data_hora) VALUES (1,1,'simulacao','Coleta demonstrativa','2026-10-27 12:00:00');
INSERT INTO monitoramento_sensor (id_monitoramento,id_sensor,id_horta,valor,tipo_medicao,unidade) VALUES (1,1,1,6.1,'ph','pH'),(1,2,1,21.5,'temperatura','°C');
INSERT INTO consumo_agua (id_horta,data_consumo,volume_l,referencia_solo_l,origem) VALUES (1,'2026-10-26',12,120,'simulacao'),(1,'2026-10-27',11,115,'simulacao'),(2,'2026-10-27',8,80,'simulacao');
COMMIT;
