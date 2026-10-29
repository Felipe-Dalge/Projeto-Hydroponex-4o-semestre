CREATE TABLE Usuario (
    Id_usuario NUMBER(10) PRIMARY KEY,
    nome VARCHAR2(100),
    email VARCHAR2(100),
    senha VARCHAR2(100),
    tipo VARCHAR2(50)
);

CREATE TABLE Cultura (
    tempo_crescimento NUMBER(3),
    temperatura_ideal NUMBER(3),
    nome VARCHAR2(100),
    Id_cultura NUMBER(10) PRIMARY KEY,
    ph_ideal NUMBER(3,2),
    tipo VARCHAR2(100),
    fk_Celula_id_horta NUMBER(100)
);

CREATE TABLE Horta (
    id_horta NUMBER(100) PRIMARY KEY,
    nome VARCHAR2(200),
    tamanho NUMBER(60),
    localizacao VARCHAR2(200),
    fk_CEP_ID_CEP NUMBER(10),
    fk_Estrutura_id_estrutura NUMBER(10),
    id_planta NUMBER(10)
);

CREATE TABLE Reservatorio (
    id_reservatório NUMBER(10) PRIMARY KEY,
    capacidade NUMBER(5),
    nivel_atual NUMBER(5),
    ph_atual NUMBER(3, 2),
    temperatura NUMBER(3)
);

CREATE TABLE Monitoramento (
    Medição VARCHAR2(100),
    data_hora DATETIME,
    ID_Monitoramento NUMBER(10) PRIMARY KEY,
    fk_Usuario_Id_usuario NUMBER(10)
);

CREATE TABLE Nutrientes (
    classificacao VARCHAR2(100),
    descricao VARCHAR2(1000),
    tipo VARCHAR2(100),
    ID_Nutriente NUMBER(10) PRIMARY KEY
);

CREATE TABLE Sensores (
    id_sensor NUMBER(10) PRIMARY KEY,
    tipo VARCHAR2(100),
    status VARCHAR(15),
    fk_Nutrientes_ID_Nutriente NUMBER(10)
);

CREATE TABLE CEP (
    ID_CEP NUMBER(10) PRIMARY KEY,
    CD_CEP NUMBER(8),
    FK_Usuario_Id_usuario NUMBER(10)
);

CREATE TABLE Cidade (
    ID_Cidade NUMBER(10) PRIMARY KEY,
    Nm_Cidade VARCHAR2(100),
    fk_CEP_ID_CEP NUMBER(10)
);

CREATE TABLE Estado (
    Nm_Estado VARCHAR2(100),
    ID_Estado VARCHAR2(10) PRIMARY KEY,
    SG_Estado CHAR(2),
    fk_Cidade_ID_Cidade NUMBER(10)
);

CREATE TABLE Estrutura (
    id_estrutura NUMBER(10) PRIMARY KEY,
    nome VARCHAR2(100),
    tipo VARCHAR2(100),
    qnt_unidade NUMBER(4),
    id_reservatório NUMBER(10),
    fk_Reservatorio_id_reservatório NUMBER(10)
);

CREATE TABLE Usuario_celula (
    fk_Usuario_Id_usuario NUMBER(10),
    fk_Celula_id_horta NUMBER(100)
);

CREATE TABLE monito_sensor (
    fk_Sensores_id_sensor NUMBER(10),
    fk_Monitoramento_ID_Monitoramento NUMBER(10),
    valor NUMBER(15),
    tipo_medicao VARCHAR2(100)
);

CREATE TABLE Planta (
    id_planta NUMBER(10) PRIMARY KEY,
    nome VARCHAR2(100),
    tipo VARCHAR2(100),
    localizacao VARCHAR2(100),
    dias_crescimento NUMBER(3),
    ph_atual NUMBER(3,2),
    temperatura_agua NUMBER(3),
    nivel_nutrientes NUMBER(6),
    nivel_irrigacao NUMBER(10),
    status_planta VARCHAR2(15)
);
 
ALTER TABLE Cultura ADD CONSTRAINT FK_Cultura_2
    FOREIGN KEY (fk_Celula_id_horta)
    REFERENCES Horta (id_horta)
    ON DELETE RESTRICT;
 
ALTER TABLE Horta ADD CONSTRAINT FK_Horta_2
    FOREIGN KEY (fk_CEP_ID_CEP)
    REFERENCES CEP (ID_CEP)
    ON DELETE RESTRICT;
 
ALTER TABLE Horta ADD CONSTRAINT FK_Horta_3
    FOREIGN KEY (fk_Estrutura_id_estrutura)
    REFERENCES Estrutura (id_estrutura)
    ON DELETE RESTRICT;
 
ALTER TABLE Horta ADD CONSTRAINT FK_Horta_4
    FOREIGN KEY (id_planta)
    REFERENCES Planta (id_planta)
    ON DELETE RESTRICT;
 
ALTER TABLE Monitoramento ADD CONSTRAINT FK_Monitoramento_2
    FOREIGN KEY (fk_Usuario_Id_usuario)
    REFERENCES Usuario (Id_usuario)
    ON DELETE RESTRICT;
 
ALTER TABLE Sensores ADD CONSTRAINT FK_Sensores_2
    FOREIGN KEY (fk_Nutrientes_ID_Nutriente)
    REFERENCES Nutrientes (ID_Nutriente)
    ON DELETE RESTRICT;
 
ALTER TABLE CEP ADD CONSTRAINT FK_CEP_2
    FOREIGN KEY (FK_Usuario_Id_usuario)
    REFERENCES Usuario (Id_usuario)
    ON DELETE RESTRICT;
 
ALTER TABLE Cidade ADD CONSTRAINT FK_Cidade_2
    FOREIGN KEY (fk_CEP_ID_CEP)
    REFERENCES CEP (ID_CEP)
    ON DELETE RESTRICT;
 
ALTER TABLE Estado ADD CONSTRAINT FK_Estado_2
    FOREIGN KEY (fk_Cidade_ID_Cidade)
    REFERENCES Cidade (ID_Cidade)
    ON DELETE RESTRICT;
 
ALTER TABLE Estrutura ADD CONSTRAINT FK_Estrutura_2
    FOREIGN KEY (fk_Reservatorio_id_reservatório)
    REFERENCES Reservatorio (id_reservatório)
    ON DELETE RESTRICT;
 
ALTER TABLE Estrutura ADD CONSTRAINT FK_Estrutura_3
    FOREIGN KEY (id_reservatório)
    REFERENCES Reservatorio (id_reservatório)
    ON DELETE RESTRICT;
 
ALTER TABLE Usuario_celula ADD CONSTRAINT FK_Usuario_celula_1
    FOREIGN KEY (fk_Usuario_Id_usuario)
    REFERENCES Usuario (Id_usuario)
    ON DELETE RESTRICT;
 
ALTER TABLE Usuario_celula ADD CONSTRAINT FK_Usuario_celula_2
    FOREIGN KEY (fk_Celula_id_horta)
    REFERENCES Horta (id_horta)
    ON DELETE RESTRICT;
 
ALTER TABLE monito_sensor ADD CONSTRAINT FK_monito_sensor_1
    FOREIGN KEY (fk_Sensores_id_sensor)
    REFERENCES Sensores (id_sensor)
    ON DELETE RESTRICT;
 
ALTER TABLE monito_sensor ADD CONSTRAINT FK_monito_sensor_2
    FOREIGN KEY (fk_Monitoramento_ID_Monitoramento)
    REFERENCES Monitoramento (ID_Monitoramento)
    ON DELETE RESTRICT;