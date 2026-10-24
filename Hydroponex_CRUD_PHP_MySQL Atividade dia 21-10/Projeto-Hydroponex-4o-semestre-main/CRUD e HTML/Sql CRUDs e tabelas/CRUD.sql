-- CRUD Oracle para as tabelas de criacao_tabelas.sql.
-- Execute os comandos individualmente e substitua os binds (:parametro) pelos valores desejados.

-- 1. Usuario
INSERT INTO Usuario (Id_usuario, nome, email, senha, tipo)
VALUES (:Id_usuario, :nome, :email, :senha, :tipo);

SELECT * FROM Usuario
WHERE Id_usuario = :Id_usuario;

UPDATE Usuario
SET nome = :nome, email = :email, senha = :senha, tipo = :tipo
WHERE Id_usuario = :Id_usuario;

DELETE FROM Usuario
WHERE Id_usuario = :Id_usuario;

-- 2. Cultura
INSERT INTO Cultura (tempo_crescimento, temperatura_ideal, nome, Id_cultura, ph_ideal, tipo, fk_Celula_id_horta)
VALUES (:tempo_crescimento, :temperatura_ideal, :nome, :Id_cultura, :ph_ideal, :tipo, :fk_Celula_id_horta);

SELECT * FROM Cultura
WHERE Id_cultura = :Id_cultura;

UPDATE Cultura
SET tempo_crescimento = :tempo_crescimento,
		temperatura_ideal = :temperatura_ideal,
		nome = :nome,
		ph_ideal = :ph_ideal,
		tipo = :tipo,
		fk_Celula_id_horta = :fk_Celula_id_horta
WHERE Id_cultura = :Id_cultura;

DELETE FROM Cultura
WHERE Id_cultura = :Id_cultura;

-- 3. Horta
INSERT INTO Horta (id_horta, nome, tamanho, localizacao, fk_CEP_ID_CEP, fk_Estrutura_id_estrutura, id_planta)
VALUES (:id_horta, :nome, :tamanho, :localizacao, :fk_CEP_ID_CEP, :fk_Estrutura_id_estrutura, :id_planta);

SELECT * FROM Horta
WHERE id_horta = :id_horta;

UPDATE Horta
SET nome = :nome,
		tamanho = :tamanho,
		localizacao = :localizacao,
		fk_CEP_ID_CEP = :fk_CEP_ID_CEP,
		fk_Estrutura_id_estrutura = :fk_Estrutura_id_estrutura,
		id_planta = :id_planta
WHERE id_horta = :id_horta;

DELETE FROM Horta
WHERE id_horta = :id_horta;

-- 4. Reservatorio
INSERT INTO Reservatorio (id_reservatório, capacidade, nivel_atual, ph_atual, temperatura)
VALUES (:id_reservatorio, :capacidade, :nivel_atual, :ph_atual, :temperatura);

SELECT * FROM Reservatorio
WHERE id_reservatório = :id_reservatorio;

UPDATE Reservatorio
SET capacidade = :capacidade,
		nivel_atual = :nivel_atual,
		ph_atual = :ph_atual,
		temperatura = :temperatura
WHERE id_reservatório = :id_reservatorio;

DELETE FROM Reservatorio
WHERE id_reservatório = :id_reservatorio;

-- 5. Monitoramento
INSERT INTO Monitoramento (Medição, data_hora, ID_Monitoramento, fk_Usuario_Id_usuario)
VALUES (:medicao, :data_hora, :ID_Monitoramento, :fk_Usuario_Id_usuario);

SELECT * FROM Monitoramento
WHERE ID_Monitoramento = :ID_Monitoramento;

UPDATE Monitoramento
SET Medição = :medicao,
		data_hora = :data_hora,
		fk_Usuario_Id_usuario = :fk_Usuario_Id_usuario
WHERE ID_Monitoramento = :ID_Monitoramento;

DELETE FROM Monitoramento
WHERE ID_Monitoramento = :ID_Monitoramento;

-- 6. Nutrientes
INSERT INTO Nutrientes (classificacao, descricao, tipo, ID_Nutriente)
VALUES (:classificacao, :descricao, :tipo, :ID_Nutriente);

SELECT * FROM Nutrientes
WHERE ID_Nutriente = :ID_Nutriente;

UPDATE Nutrientes
SET classificacao = :classificacao, descricao = :descricao, tipo = :tipo
WHERE ID_Nutriente = :ID_Nutriente;

DELETE FROM Nutrientes
WHERE ID_Nutriente = :ID_Nutriente;

-- 7. Sensores
INSERT INTO Sensores (id_sensor, tipo, status, fk_Nutrientes_ID_Nutriente)
VALUES (:id_sensor, :tipo, :status, :fk_Nutrientes_ID_Nutriente);

SELECT * FROM Sensores
WHERE id_sensor = :id_sensor;

UPDATE Sensores
SET tipo = :tipo, status = :status, fk_Nutrientes_ID_Nutriente = :fk_Nutrientes_ID_Nutriente
WHERE id_sensor = :id_sensor;

DELETE FROM Sensores
WHERE id_sensor = :id_sensor;

-- 8. CEP
INSERT INTO CEP (ID_CEP, CD_CEP, FK_Usuario_Id_usuario)
VALUES (:ID_CEP, :CD_CEP, :FK_Usuario_Id_usuario);

SELECT * FROM CEP
WHERE ID_CEP = :ID_CEP;

UPDATE CEP
SET CD_CEP = :CD_CEP, FK_Usuario_Id_usuario = :FK_Usuario_Id_usuario
WHERE ID_CEP = :ID_CEP;

DELETE FROM CEP
WHERE ID_CEP = :ID_CEP;

-- 9. Cidade
INSERT INTO Cidade (ID_Cidade, Nm_Cidade, fk_CEP_ID_CEP)
VALUES (:ID_Cidade, :Nm_Cidade, :fk_CEP_ID_CEP);

SELECT * FROM Cidade
WHERE ID_Cidade = :ID_Cidade;

UPDATE Cidade
SET Nm_Cidade = :Nm_Cidade, fk_CEP_ID_CEP = :fk_CEP_ID_CEP
WHERE ID_Cidade = :ID_Cidade;

DELETE FROM Cidade
WHERE ID_Cidade = :ID_Cidade;

-- 10. Estado
INSERT INTO Estado (Nm_Estado, ID_Estado, SG_Estado, fk_Cidade_ID_Cidade)
VALUES (:Nm_Estado, :ID_Estado, :SG_Estado, :fk_Cidade_ID_Cidade);

SELECT * FROM Estado
WHERE ID_Estado = :ID_Estado;

UPDATE Estado
SET Nm_Estado = :Nm_Estado, SG_Estado = :SG_Estado, fk_Cidade_ID_Cidade = :fk_Cidade_ID_Cidade
WHERE ID_Estado = :ID_Estado;

DELETE FROM Estado
WHERE ID_Estado = :ID_Estado;

-- 11. Estrutura
INSERT INTO Estrutura (id_estrutura, nome, tipo, qnt_unidade, id_reservatório, fk_Reservatorio_id_reservatório)
VALUES (:id_estrutura, :nome, :tipo, :qnt_unidade, :id_reservatorio, :fk_Reservatorio_id_reservatorio);

SELECT * FROM Estrutura
WHERE id_estrutura = :id_estrutura;

UPDATE Estrutura
SET nome = :nome,
		tipo = :tipo,
		qnt_unidade = :qnt_unidade,
		id_reservatório = :id_reservatorio,
		fk_Reservatorio_id_reservatório = :fk_Reservatorio_id_reservatório
WHERE id_estrutura = :id_estrutura;

DELETE FROM Estrutura
WHERE id_estrutura = :id_estrutura;

-- 12. Usuario_celula (a tabela nao possui chave primaria; use as duas FKs como identificador)
INSERT INTO Usuario_celula (fk_Usuario_Id_usuario, fk_Celula_id_horta)
VALUES (:fk_Usuario_Id_usuario, :fk_Celula_id_horta);

SELECT * FROM Usuario_celula
WHERE fk_Usuario_Id_usuario = :fk_Usuario_Id_usuario
	AND fk_Celula_id_horta = :fk_Celula_id_horta;

UPDATE Usuario_celula
SET fk_Usuario_Id_usuario = :novo_fk_Usuario_Id_usuario,
		fk_Celula_id_horta = :novo_fk_Celula_id_horta
WHERE fk_Usuario_Id_usuario = :fk_Usuario_Id_usuario
	AND fk_Celula_id_horta = :fk_Celula_id_horta;

DELETE FROM Usuario_celula
WHERE fk_Usuario_Id_usuario = :fk_Usuario_Id_usuario
	AND fk_Celula_id_horta = :fk_Celula_id_horta;

-- 13. monito_sensor (a tabela nao possui chave primaria; use as duas FKs como identificador)
INSERT INTO monito_sensor (fk_Sensores_id_sensor, fk_Monitoramento_ID_Monitoramento, valor, tipo_medicao)
VALUES (:fk_Sensores_id_sensor, :fk_Monitoramento_ID_Monitoramento, :valor, :tipo_medicao);

SELECT * FROM monito_sensor
WHERE fk_Sensores_id_sensor = :fk_Sensores_id_sensor
	AND fk_Monitoramento_ID_Monitoramento = :fk_Monitoramento_ID_Monitoramento;

UPDATE monito_sensor
SET valor = :valor, tipo_medicao = :tipo_medicao
WHERE fk_Sensores_id_sensor = :fk_Sensores_id_sensor
	AND fk_Monitoramento_ID_Monitoramento = :fk_Monitoramento_ID_Monitoramento;

DELETE FROM monito_sensor
WHERE fk_Sensores_id_sensor = :fk_Sensores_id_sensor
	AND fk_Monitoramento_ID_Monitoramento = :fk_Monitoramento_ID_Monitoramento;

-- 14. Planta
INSERT INTO Planta (id_planta, nome, tipo, localizacao, dias_crescimento, ph_atual, temperatura_agua, nivel_nutrientes, nivel_irrigacao, status_planta)
VALUES (:id_planta, :nome, :tipo, :localizacao, :dias_crescimento, :ph_atual, :temperatura_agua, :nivel_nutrientes, :nivel_irrigacao, :status_planta);

SELECT * FROM Planta
WHERE id_planta = :id_planta;

UPDATE Planta
SET nome = :nome,
		tipo = :tipo,
		localizacao = :localizacao,
		dias_crescimento = :dias_crescimento,
		ph_atual = :ph_atual,
		temperatura_agua = :temperatura_agua,
		nivel_nutrientes = :nivel_nutrientes,
		nivel_irrigacao = :nivel_irrigacao,
		status_planta = :status_planta
WHERE id_planta = :id_planta;

DELETE FROM Planta
WHERE id_planta = :id_planta;
