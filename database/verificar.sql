-- Consultas somente leitura; executar no banco selecionado no phpMyAdmin.
SELECT VERSION() AS versao_servidor, DATABASE() AS banco_selecionado;
SELECT * FROM schema_version;
-- Esperado: 21 tabelas base, todas InnoDB/utf8mb4_0900_ai_ci; schema 1.3.0.
SELECT table_name, engine, table_collation FROM information_schema.tables
 WHERE table_schema=DATABASE() AND table_type='BASE TABLE' ORDER BY table_name;
SELECT table_name, constraint_name, constraint_type FROM information_schema.table_constraints
 WHERE table_schema=DATABASE() ORDER BY table_name,constraint_type;
SELECT table_name, constraint_name, referenced_table_name, delete_rule, update_rule
 FROM information_schema.referential_constraints WHERE constraint_schema=DATABASE();
-- Resultado vazio em todas as consultas de inconsistência abaixo.
SELECT h.id_horta, h.nome FROM horta h LEFT JOIN usuario_horta uh
 ON uh.id_horta=h.id_horta AND uh.papel='proprietario'
 WHERE h.arquivada_em IS NULL GROUP BY h.id_horta,h.nome HAVING COUNT(uh.id_usuario)=0;
SELECT p.id_planta FROM planta p JOIN estrutura e ON e.id_estrutura=p.id_estrutura WHERE p.id_horta<>e.id_horta;
SELECT l.id_leitura FROM monitoramento_sensor l JOIN sensor s ON s.id_sensor=l.id_sensor
 JOIN monitoramento m ON m.id_monitoramento=l.id_monitoramento WHERE l.id_horta<>s.id_horta OR l.id_horta<>m.id_horta;
SELECT id_usuario FROM usuario WHERE ativo=1 AND senha_hash IS NULL;
-- Após seed: Ana tem 2 hortas; horta 1 tem 2 plantas; horta 2 tem 1.
SELECT u.id_usuario,u.nome,COUNT(uh.id_horta) AS hortas FROM usuario u
 LEFT JOIN usuario_horta uh ON uh.id_usuario=u.id_usuario GROUP BY u.id_usuario,u.nome;
SELECT h.id_horta,h.nome,COUNT(p.id_planta) AS plantas FROM horta h
 LEFT JOIN planta p ON p.id_horta=h.id_horta GROUP BY h.id_horta,h.nome;
-- Permissões: substituir parâmetros na futura API, sem interpolação de valores.
-- SELECT p.* FROM planta p JOIN usuario_horta uh ON uh.id_horta=p.id_horta
-- JOIN usuario u ON u.id_usuario=uh.id_usuario
-- WHERE u.ativo=1 AND u.id_usuario=:usuario_sessao AND p.id_horta=:horta AND p.arquivada_em IS NULL;

-- Autoria opcional dos catálogos: resultado vazio. NULL significa catálogo global.
SELECT c.id_cultura FROM cultura c LEFT JOIN usuario u ON u.id_usuario=c.id_criador WHERE c.id_criador IS NOT NULL AND u.id_usuario IS NULL;
SELECT n.id_nutriente FROM nutriente n LEFT JOIN usuario u ON u.id_usuario=n.id_criador WHERE n.id_criador IS NOT NULL AND u.id_usuario IS NULL;
SELECT id_reservatorio FROM reservatorio WHERE nivel_atual_l>capacidade_l;

-- Snapshot de alertas e origens: resultados informativos, não erros.
SELECT origem,COUNT(*) AS eventos FROM monitoramento GROUP BY origem;
SELECT origem,COUNT(*) AS dias FROM consumo_agua GROUP BY origem;
SELECT id_sensor FROM sensor WHERE limite_min>limite_max;
SELECT id_leitura FROM monitoramento_sensor WHERE limite_min>limite_max;
