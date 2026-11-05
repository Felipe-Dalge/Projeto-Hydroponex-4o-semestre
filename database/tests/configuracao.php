<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__, 2) . '/Hydroponex_2/hydro/Hydroponexteste2/php/config/database.php';

// Testes sem servidor: nenhuma chamada a database_connection(), nem criação de sessão HTTP.
$names = ['HYDROPONEX_DSN','HYDROPONEX_DB_HOST','HYDROPONEX_DB_PORT','HYDROPONEX_DB_NAME','HYDROPONEX_DB_USER','HYDROPONEX_DB_PASSWORD'];
$original = [];
foreach ($names as $name) $original[$name] = getenv($name);
$log = tempnam(sys_get_temp_dir(), 'hydroponex-config-');
if ($log === false) { fwrite(STDERR, "Não foi possível preparar o teste.\n"); exit(1); }
$oldLog = ini_get('error_log');
ini_set('error_log', $log);
$marker = 'SEGREDO_TESTE_NAO_DEVE_APARECER';
$base = ['HYDROPONEX_DB_HOST'=>'127.0.0.1','HYDROPONEX_DB_PORT'=>'3306','HYDROPONEX_DB_NAME'=>'hydroponex_teste','HYDROPONEX_DB_USER'=>'usuario_teste','HYDROPONEX_DB_PASSWORD'=>$marker];
$legacy = 'mysql:host=127.0.0.1;port=3306;dbname=hydroponex_teste;charset=utf8mb4';
$cases = [
    ['configuração por campos', [], true],
    ['DSN legado', ['HYDROPONEX_DSN'=>$legacy], true],
    ['DSN tem precedência sobre campos', ['HYDROPONEX_DSN'=>$legacy,'HYDROPONEX_DB_NAME'=>'outro_banco'], true],
    ['host e porta padrão', ['HYDROPONEX_DB_HOST'=>null,'HYDROPONEX_DB_PORT'=>null], true],
    ['banco obrigatório', ['HYDROPONEX_DB_NAME'=>null], false],
    ['usuário obrigatório', ['HYDROPONEX_DB_USER'=>null], false],
    ['senha deve ser configurada', ['HYDROPONEX_DB_PASSWORD'=>null], false],
    ['host vazio recusado', ['HYDROPONEX_DB_HOST'=>''], false],
    ['porta zero recusada', ['HYDROPONEX_DB_PORT'=>'0'], false],
    ['porta fora da faixa recusada', ['HYDROPONEX_DB_PORT'=>'65536'], false],
    ['porta não numérica recusada', ['HYDROPONEX_DB_PORT'=>'3306;dbname=x'], false],
    ['injeção no host recusada', ['HYDROPONEX_DB_HOST'=>'localhost;password='.$marker], false],
    ['injeção no nome recusada', ['HYDROPONEX_DB_NAME'=>'banco;charset=latin1'], false],
    ['driver alternativo recusado', ['HYDROPONEX_DSN'=>'sqlite:/'.$marker], false],
    ['charset incompatível recusado', ['HYDROPONEX_DSN'=>str_replace('utf8mb4','latin1',$legacy)], false],
    ['DSN com campo duplicado recusado', ['HYDROPONEX_DSN'=>$legacy.';host=outro'], false],
    ['DSN com opção extra recusado', ['HYDROPONEX_DSN'=>$legacy.';unix_socket=/'.$marker], false],
    ['DSN vazio recusado', ['HYDROPONEX_DSN'=>'mysql:'], false],
];
$failed = false;
try {
    foreach ($cases as [$label,$changes,$accepted]) {
        foreach ($names as $name) putenv($name);
        foreach (array_replace($base,$changes) as $name=>$value) {
            if ($value !== null) putenv($name.'='.$value);
        }
        try {
            $config = database_configuration();
            if (!$accepted || $config['dsn'] !== $legacy || $config['password'] !== $marker) throw new RuntimeException('Resultado inesperado.');
        } catch (DatabaseConnectionException $error) {
            if ($accepted || $error->getPrevious() !== null || str_contains((string)$error,$marker)) throw new RuntimeException('Falha no tratamento seguro.');
        }
        echo "OK: $label\n";
    }
    if (str_contains((string)file_get_contents($log),$marker)) throw new RuntimeException('Segredo apareceu no log.');
    echo count($cases)." casos aprovados; sem conexão, sem credenciais nos logs.\n";
} catch (Throwable) {
    fwrite(STDERR,"FALHA: validação de configuração divergente.\n");
    $failed = true;
} finally {
    foreach ($original as $name=>$value) {
        putenv($value === false ? $name : $name.'='.$value);
    }
    ini_set('error_log', $oldLog === false ? '' : $oldLog);
    unlink($log);
}
exit($failed ? 1 : 0);
