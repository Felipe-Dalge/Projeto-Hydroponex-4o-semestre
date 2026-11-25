"""Testa o migrador CLI em bancos temporários próprios, sem tocar na instalação."""
import argparse
import getpass
import json
import os
from pathlib import Path
import secrets
import subprocess
import tempfile
import sys

ROOT = Path(__file__).resolve().parents[1]
if hasattr(sys.stdout, 'reconfigure'):
    sys.stdout.reconfigure(encoding='utf-8')


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--executar', action='store_true')
    parser.add_argument('--php', default='php')
    parser.add_argument('--legacy-schema', type=Path, help='Opcional: SQL original MariaDB 1.3.0')
    args = parser.parse_args()
    if not args.executar:
        parser.error('Use --executar com MariaDB local descartável e conta administrativa de testes.')
    port = int(os.environ.get('HYDROPONEX_TEST_DB_PORT', '3306'))
    user = os.environ.get('HYDROPONEX_TEST_DB_USER', '')
    password = os.environ.get('HYDROPONEX_TEST_DB_PASSWORD')
    if not user:
        parser.error('Defina HYDROPONEX_TEST_DB_USER, HYDROPONEX_TEST_DB_PASSWORD e HYDROPONEX_TEST_DB_PORT.')
    if password is None:
        password = getpass.getpass('Senha do administrador do MariaDB de testes: ')
    bridge = r"""require 'scripts/lib/database_schema.php';
    $i=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
    $p=new PDO('mysql:host=127.0.0.1;port='.$i['port'].';charset=utf8mb4',$i['user'],$i['password'],
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    if($i['database'])$p->exec('USE '.$i['database']);
    $rows=[];foreach(install_sql($i['sql']) as $sql){$s=$p->query($sql);if($s->columnCount())$rows=$s->fetchAll();}
    echo json_encode($rows,JSON_THROW_ON_ERROR);"""
    databases = []
    checks = 0

    def db(sql, name=''):
        result = subprocess.run([args.php, '-r', bridge], cwd=ROOT, text=True, encoding='utf-8',
                                capture_output=True, input=json.dumps(dict(port=port, user=user,
                                password=password, database=name, sql=sql)))
        if result.returncode:
            raise RuntimeError('Falha ao preparar banco de testes (credenciais não exibidas).')
        return json.loads(result.stdout)

    def check(condition, label):
        nonlocal checks
        assert condition, label
        checks += 1
        print('OK:', label, flush=True)

    def create(schema):
        name = 'hx_updater_' + secrets.token_hex(8) + '_tests'
        db(f'CREATE DATABASE {name} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci')
        databases.append(name)
        db(schema, name)
        return name

    with tempfile.TemporaryDirectory(prefix='hx-updater-tests-') as directory:
        journal = Path(directory) / 'journal.json'

        def update(name, mode='inspect', **extra):
            settings = dict(HYDROPONEX_DB_HOST='127.0.0.1', HYDROPONEX_DB_PORT=str(port),
                            HYDROPONEX_DB_NAME=name, HYDROPONEX_DB_USER=user,
                            HYDROPONEX_DB_PASSWORD=password)
            payload = dict(mode=mode, settings=settings, source=str(ROOT), journal=str(journal), **extra)
            result = subprocess.run([args.php, 'scripts/update-database.php'], cwd=ROOT,
                                    input=json.dumps(payload), text=True, encoding='utf-8', capture_output=True)
            check(password == '' or password not in result.stdout + result.stderr, 'diagnóstico não expõe senha')
            return json.loads(result.stdout)

        try:
            current = create((ROOT / 'database/mariadb/hydroponex_mariadb.sql').read_text(encoding='utf-8-sig'))
            inspected = update(current)
            check(inspected['success'] and inspected['migrations'] == [], 'esquema atual reconhecido')
            db("CREATE TABLE custom_data(id INT PRIMARY KEY, valor VARCHAR(40)); INSERT INTO custom_data VALUES(1,'preservar')", current)
            check(update(current, 'validate')['success'], 'tabela adicional preservada')
            check(update(current, 'migrate')['success'], 'reexecução sem migrações')
            check(db('SELECT valor FROM custom_data', current)[0]['valor'] == 'preservar', 'dados adicionais preservados')
            journal.write_text('{"status":"executing"}', encoding='utf-8')
            check(not update(current, 'migrate')['success'], 'migração parcial bloqueia repetição')
            journal.unlink()
            db("INSERT INTO schema_version(versao) VALUES('99.0.0')", current)
            check(not update(current)['success'], 'downgrade de banco recusado')
            db("DELETE FROM schema_version WHERE versao='99.0.0'", current)
            db('ALTER TABLE usuario DROP CONSTRAINT ck_usuario_perfil_entrada', current)
            check(not update(current, 'validate')['success'], 'integridade incompleta recusada')
            if args.legacy_schema:
                legacy = create(args.legacy_schema.read_text(encoding='utf-8-sig'))
                db((ROOT / 'database/mariadb/seed_mariadb.sql').read_text(encoding='utf-8-sig'), legacy)
                hashed = subprocess.check_output([args.php, '-r',
                    "echo password_hash('Senha-apenas-do-teste-2026',PASSWORD_BCRYPT);"]).decode('ascii')
                db("UPDATE usuario SET senha_hash='" + hashed + "' WHERE id_usuario=1", legacy)
                tables = ['usuario', 'horta', 'usuario_horta', 'planta', 'monitoramento', 'monitoramento_sensor']
                before = {table: db(f'SELECT * FROM {table} ORDER BY 1', legacy) for table in tables}
                plan = update(legacy)
                check(plan['success'] and len(plan['migrations']) == 4, 'cadeia 1.3.0 até 1.7.0 identificada')
                check(update(legacy, 'migrate')['success'], 'quatro migrações executadas')
                for table, rows in before.items():
                    after = db(f'SELECT * FROM {table} ORDER BY 1', legacy)
                    check(len(rows) == len(after) and all(all(new[k] == v for k, v in old.items())
                          for old, new in zip(rows, after)), 'registros e campos antigos preservados: ' + table)
                check(update(legacy, 'migrate')['success'], 'migração antiga reexecutada sem duplicação')
        finally:
            # Somente os nomes aleatórios criados com sucesso por esta execução.
            for name in databases:
                assert name.startswith('hx_updater_') and name.endswith('_tests')
                db('DROP DATABASE ' + name)
    print(f'{checks} verificações aprovadas.')


if __name__ == '__main__':
    main()
