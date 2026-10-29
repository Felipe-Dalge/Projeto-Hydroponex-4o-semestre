<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__, 2) . '/Hydroponex_2/hydro/Hydroponexteste2/php/config/database.php';
$pdo = null;
try {
    $pdo = database_connection();
    $pdo->beginTransaction();
    $checks = 0;
    $run = function (string $sql, array $args = []) use ($pdo): PDOStatement { $s=$pdo->prepare($sql); $s->execute($args); return $s; };
    $insert = function (string $sql, array $args = []) use ($run,$pdo): string { $run($sql,$args); return $pdo->lastInsertId(); };
    $ok = function (bool $condition, string $name) use (&$checks): void { if (!$condition) throw new RuntimeException($name); $checks++; echo "OK $name\n"; };
    $reject = function (string $sql, array $args, int $code, string $name) use ($run,$ok): void {
        try { $run($sql,$args); } catch (PDOException $e) {
            $actual=(int)($e->errorInfo[1]??0);
            // MySQL pode omitir metadados de FKs para contas sem REFERENCES.
            $accepted=match($code) { 1452=>[1452,1216], 1451=>[1451,1217], default=>[$code] };
            if (!in_array($actual,$accepted,true)) throw new RuntimeException("$name: código esperado $code, recebido $actual");
            $ok(true,$name); return;
        }
        throw new RuntimeException("Aceitou dados inválidos: $name");
    };
    $suffix=bin2hex(random_bytes(8));
    $u=$insert('INSERT INTO usuario (nome,email) VALUES (?,?)',['Teste',"$suffix@example.test"]);
    $h1=$insert('INSERT INTO horta (nome) VALUES (?)',["Teste A $suffix"]);
    $h2=$insert('INSERT INTO horta (nome) VALUES (?)',["Teste B $suffix"]);
    $run("INSERT INTO usuario_horta (id_usuario,id_horta,papel) VALUES (?,?,'proprietario'),(?,?,'gestor')",[$u,$h1,$u,$h2]);
    $ok((int)$run('SELECT COUNT(*) FROM usuario_horta WHERE id_usuario=?',[$u])->fetchColumn()===2,'usuário gerencia várias hortas');
    $r=$insert('INSERT INTO reservatorio (id_horta,nome,capacidade_l) VALUES (?,?,100)',[$h1,'Reservatório teste']);
    $e=$insert('INSERT INTO estrutura (id_horta,id_reservatorio,nome) VALUES (?,?,?)',[$h1,$r,'Bancada']);
    $c=$insert('INSERT INTO cultura (nome,ph_ideal) VALUES (?,14)',['Cultura teste']);
    $run('INSERT INTO horta_cultura VALUES (?,?)',[$h1,$c]);
    $p=$insert('INSERT INTO planta (id_horta,id_estrutura,id_cultura,nome,localizacao,ph_atual) VALUES (?,?,?,?,?,14)',[$h1,$e,$c,'Planta 1','A']);
    $insert('INSERT INTO planta (id_horta,nome,localizacao) VALUES (?,?,?)',[$h1,'Planta 2','B']);
    $ok((int)$run('SELECT COUNT(*) FROM planta WHERE id_horta=?',[$h1])->fetchColumn()===2,'horta contém várias plantas; pH 14 aceito');
    $s=$insert('INSERT INTO sensor (id_horta,nome,tipo,unidade,id_planta) VALUES (?,?,?,?,?)',[$h1,'Sensor','temperatura','°C',$p]);
    $m=$insert("INSERT INTO monitoramento (id_horta,origem,data_hora) VALUES (?,'sensor',UTC_TIMESTAMP(6))",[$h1]);
    $run('INSERT INTO monitoramento_sensor (id_monitoramento,id_sensor,id_horta,valor,tipo_medicao,unidade) VALUES (?,?,?,21.123456,?,?)',[$m,$s,$h1,'temperatura','°C']);
    $ok($run('SELECT valor FROM monitoramento_sensor WHERE id_monitoramento=?',[$m])->fetchColumn()==='21.123456','leitura decimal preservada');
    $reject('INSERT INTO usuario (nome,email) VALUES (?,?)',['Duplicado',"$suffix@example.test"],1062,'email único');
    $reject("UPDATE usuario_horta SET papel='admin' WHERE id_usuario=?",[$u],3819,'papel inválido recusado');
    $reject('UPDATE usuario SET ativo=1 WHERE id_usuario=?',[$u],3819,'ativação sem hash recusada');
    $reject("UPDATE usuario SET senha_hash='senha-insegura' WHERE id_usuario=?",[$u],3819,'senha em texto puro recusada');
    $reject('UPDATE planta SET ph_atual=14.01 WHERE id_planta=?',[$p],3819,'pH fora da faixa recusado');
    $reject('UPDATE planta SET umidade_percentual=101 WHERE id_planta=?',[$p],3819,'umidade inválida recusada');
    $reject('UPDATE reservatorio SET nivel_atual_l=101 WHERE id_reservatorio=?',[$r],3819,'volume maior que capacidade recusado');
    $reject('INSERT INTO planta (id_horta,id_estrutura,nome,localizacao) VALUES (?,?,?,?)',[$h2,$e,'Inválida','A'],1452,'planta não usa estrutura de outra horta');
    $reject('INSERT INTO planta (id_horta,id_cultura,nome,localizacao) VALUES (?,?,?,?)',[$h2,$c,'Inválida','A'],1452,'planta não usa cultura não habilitada na horta');
    $reject('INSERT INTO estrutura (id_horta,id_reservatorio,nome) VALUES (?,?,?)',[$h2,$r,'Inconsistente'],1452,'estrutura não usa reservatório de outra horta');
    $reject('UPDATE sensor SET id_reservatorio=? WHERE id_sensor=?',[$r,$s],3819,'sensor não tem dois alvos simultâneos');
    $reject('INSERT INTO sensor (id_horta,id_planta,nome,tipo,unidade) VALUES (?,?,?,?,?)',[$h2,$p,'Inválido','temperatura','°C'],1452,'sensor não usa planta de outra horta');
    $m2=$insert("INSERT INTO monitoramento (id_horta,origem,data_hora) VALUES (?,'sensor',UTC_TIMESTAMP(6))",[$h2]);
    $reject('INSERT INTO monitoramento_sensor (id_monitoramento,id_sensor,id_horta,valor,tipo_medicao,unidade) VALUES (?,?,?,1,?,?)',[$m2,$s,$h2,'temperatura','°C'],1452,'leitura entre hortas recusada');
    $reject('INSERT INTO monitoramento_sensor (id_monitoramento,id_sensor,id_horta,valor,tipo_medicao,unidade) VALUES (?,?,?,1,?,?)',[$m,$s,$h1,'temperatura','°C'],1062,'leitura duplicada no mesmo evento recusada');
    $reject('DELETE FROM horta WHERE id_horta=?',[$h1],1451,'exclusão de horta vinculada bloqueada');
    $reject('DELETE FROM monitoramento WHERE id_monitoramento=?',[$m],1451,'exclusão de histórico com leituras bloqueada');
    $reject('DELETE FROM usuario WHERE id_usuario=?',[$u],1451,'exclusão de membro vinculado bloqueada');
    $state=$insert('INSERT INTO estado (nome,sigla) VALUES (?,?)',["Estado teste $suffix",'ZZ']);
    $city=$insert('INSERT INTO cidade (nome,id_estado) VALUES (?,?)',["Cidade teste $suffix",$state]);
    $code=$insert('INSERT INTO cep (codigo,id_cidade) VALUES (?,?)',['00000001',$city]);
    $ok($run('SELECT codigo FROM cep WHERE id_cep=?',[$code])->fetchColumn()==='00000001','CEP preserva zeros iniciais');
    $reject('UPDATE cep SET codigo=? WHERE id_cep=?',['01X00000',$code],3819,'CEP alfanumérico recusado');
    $reject('DELETE FROM cidade WHERE id_cidade=?',[$city],1451,'cidade com CEP não pode ser excluída');
    $tu=$insert('INSERT INTO usuario (nome,email) VALUES (?,?)',['Token',"token-$suffix@example.test"]);
    $run("INSERT INTO token_usuario (id_usuario,finalidade,token_hash,expira_em) VALUES (?,'recuperar_senha',?,UTC_TIMESTAMP(6)+INTERVAL 1 HOUR)",[$tu,random_bytes(32)]);
    $run('DELETE FROM usuario WHERE id_usuario=?',[$tu]);
    $ok((int)$run('SELECT COUNT(*) FROM token_usuario WHERE id_usuario=?',[$tu])->fetchColumn()===0,'tokens descartáveis excluídos com usuário');
    $pdo->rollBack();
    echo "$checks verificações aprovadas; registros de teste revertidos. AUTO_INCREMENT pode ter avançado.\n";
} catch (Throwable $e) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
    $message=$e instanceof PDOException ? 'Falha de banco durante o teste; detalhes internos omitidos.' : $e->getMessage();
    fwrite(STDERR,'FALHOU: '. $message."\n");
    exit(1);
}
