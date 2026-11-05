"""Integração HTTP real, stdlib Python 3.10+, em cópia temporária do aplicativo.

Exige banco MySQL 8 dedicado, vazio de usuários, nome terminado em _test/_tests,
schema 1.1.0 e conta SELECT/INSERT/UPDATE/DELETE. Não usa o JSON do aplicativo real.
Execute da raiz: python database/tests/autenticacao.py --executar --php C:/xampp/php/php.exe
"""
import argparse
import hashlib
import hmac
import http.cookiejar
import json
import os
from pathlib import Path
import secrets
import shutil
import socket
import subprocess
import tempfile
import time
import urllib.error
import urllib.request
import sys

if hasattr(sys.stdout, 'reconfigure'):
    sys.stdout.reconfigure(encoding='utf-8')

parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--executar', action='store_true')
parser.add_argument('--php', default='php')
args = parser.parse_args()
if not args.executar:
    parser.error('Confirme o uso do banco descartável com --executar.')
root = Path(__file__).resolve().parents[2]
env = os.environ.copy()
php = args.php
bridge = """require 'Hydroponex_2/hydro/Hydroponexteste2/php/config/database.php';
$i=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
$p=database_connection();$s=$p->prepare($i['sql']);$s->execute($i['params']);
echo json_encode(['rows'=>$s->columnCount()?$s->fetchAll():[],'id'=>$p->lastInsertId()],JSON_THROW_ON_ERROR);"""


def db(sql, params=None):
    result = subprocess.run([php, '-r', bridge], input=json.dumps({'sql': sql, 'params': params or []}),
                            env=env, cwd=root, capture_output=True, text=True, encoding='utf-8')
    if result.returncode:
        raise RuntimeError('Falha no acesso administrativo de teste; confira a configuração.')
    return json.loads(result.stdout)


db_name = db('SELECT DATABASE() AS nome')['rows'][0]['nome']
if not db_name.endswith(('_test', '_tests')):
    raise RuntimeError('Use banco dedicado com nome terminado em _test ou _tests.')
if db('SELECT COUNT(*) AS n FROM usuario')['rows'][0]['n'] != 0:
    raise RuntimeError('O teste requer banco sem usuários; não execute sobre dados existentes.')
if len(env.get('HYDROPONEX_AUTH_KEY', '')) != 64 or any(c not in '0123456789abcdefABCDEF' for c in env['HYDROPONEX_AUTH_KEY']):
    raise RuntimeError('Defina HYDROPONEX_AUTH_KEY com 64 caracteres hexadecimais aleatórios.')
assert db("SELECT versao FROM schema_version WHERE versao='1.1.0'")['rows']

checks = 0
user_ids = []
garden_ids = []
monitor_ids = []
emails = []
run_id = secrets.token_hex(8)
password = 'Hydro-' + secrets.token_hex(12)
new_password = 'Nova-' + secrets.token_hex(12)
app_source = root / 'Hydroponex_2/hydro/Hydroponexteste2'
legacy_hash = hashlib.sha256((app_source / 'php/storage/plantas.json').read_bytes()).hexdigest()


def ok(condition, label):
    global checks
    if not condition:
        raise AssertionError(label)
    checks += 1
    print('OK:', label, flush=True)


def expire_limit(scope, identity):
    key = hmac.new(env['HYDROPONEX_AUTH_KEY'].encode(), (scope + ':' + identity).encode(), hashlib.sha256).hexdigest()
    db('UPDATE auth_limite SET janela_em=UTC_TIMESTAMP(6)-INTERVAL 2 HOUR WHERE chave=UNHEX(?)', [key])


class Client:
    def __init__(self, base):
        self.base = base
        self.jar = http.cookiejar.CookieJar()
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar))
        self.csrf = None

    def request(self, path, method='GET', data=None, token=True):
        headers = {'Accept': 'application/json'}
        if data is not None:
            headers['Content-Type'] = 'application/json'
        if token and self.csrf:
            headers['X-CSRF-Token'] = self.csrf
        request = urllib.request.Request(self.base + path, method=method, headers=headers,
                                         data=None if data is None else json.dumps(data).encode())
        try:
            response = self.opener.open(request, timeout=15)
        except urllib.error.HTTPError as error:
            response = error
        text = response.read().decode('utf-8')
        for private in ('SQLSTATE', 'Stack trace', env.get('HYDROPONEX_DB_PASSWORD', '\0')):
            assert private not in text
        try:
            body = json.loads(text)
        except json.JSONDecodeError:
            body = text
        if isinstance(body, dict) and body.get('csrfToken'):
            self.csrf = body['csrfToken']
        return response.status, body, response.headers

    def token(self):
        result = self.request('/php/api/session.php')
        assert result[0] == 200
        return result

    def login(self, email, senha=password):
        self.token()
        return self.request('/php/api/auth/login.php', 'POST', {'email': email, 'senha': senha})

    def sid(self):
        return next((c.value for c in self.jar if c.name == 'HYDROPONEXSESSID'), None)


try:
    with tempfile.TemporaryDirectory(prefix='hydroponex-auth-http-') as directory:
        temp = Path(directory).resolve()
        assert temp.is_relative_to(Path(tempfile.gettempdir()).resolve())
        app = temp / 'app'
        shutil.copytree(app_source, app)
        # Endpoint de fixture só na cópia; permite verificar a regra de papéis sem expor nova API.
        (app / 'php/api/_permission.php').write_text("""<?php
require __DIR__.'/../config/auth.php'; auth_api(['GET'],function(){
require_horta_permission(validate_id($_GET['horta']??null),isset($_GET['write']),isset($_GET['manage']));
send_json(['success'=>true]);});""", encoding='utf-8')
        (app / 'php/api/_expire.php').write_text("""<?php
require __DIR__.'/../config/auth.php'; auth_api(['POST'],function(){
require_authenticated_user();require_csrf();
if(isset($_GET['absolute']))$_SESSION['inicio']=time()-43201;
else $_SESSION['atividade']=time()-1801;
send_json(['success'=>true]);});""", encoding='utf-8')
        with socket.socket() as sock:
            sock.bind(('127.0.0.1', 0))
            port = sock.getsockname()[1]
        base = f'http://127.0.0.1:{port}'
        log_path = temp / 'http.log'
        with log_path.open('wb') as log:
            server = subprocess.Popen([php, '-d', 'display_errors=1', '-S', f'127.0.0.1:{port}', 'server/router.php'],
                                      cwd=app, env=env, stdout=log, stderr=log,
                                      creationflags=subprocess.CREATE_NO_WINDOW if os.name == 'nt' else 0)
            try:
                a, b, other = Client(base), Client(base), Client(base)
                for _ in range(50):
                    try:
                        a.token()
                        break
                    except urllib.error.URLError:
                        time.sleep(.1)
                else:
                    raise RuntimeError('Servidor PHP de teste não iniciou.')
                ok(a.request('/php/api/auth/me.php')[0] == 401, 'visitante não obtém perfil')
                ok(a.request('/php/api/plantas.php')[0] == 401, 'visitante não acessa plantas')
                for path in ('/php/storage/plantas.json', '/php/config/database.php', '/php/api/../storage/plantas.json', '/php/%73torage/plantas.json'):
                    ok(a.request(path)[0] == 404, 'diretório privado bloqueado: ' + path)
                email_a, email_b = f'a-{run_id}@example.test', f'b-{run_id}@example.test'
                emails.extend([email_a, email_b])
                body_a = {'nome': 'Ana Teste', 'email': email_a, 'senha': password}
                ok(a.request('/php/api/auth/cadastro.php', 'POST', body_a, token=False)[0] == 403, 'cadastro exige CSRF')
                ok(a.request('/php/api/auth/cadastro.php', 'POST', dict(body_a, tipo='admin'))[0] == 422, 'cadastro recusa elevação de privilégios')
                ok(a.request('/php/api/auth/cadastro.php', 'POST', dict(body_a, senha='curta'))[0] == 422, 'senha curta recusada')
                created = a.request('/php/api/auth/cadastro.php', 'POST', body_a)
                repeated = a.request('/php/api/auth/cadastro.php', 'POST', body_a)
                ok(created[0] == 202 and created[:2] == repeated[:2], 'cadastro e duplicata têm resposta pública idêntica')
                rows = db('SELECT id_usuario,senha_hash FROM usuario WHERE email=?', [email_a])['rows']
                uid_a = rows[0]['id_usuario']; user_ids.append(uid_a)
                ok(len(rows) == 1 and rows[0]['senha_hash'].startswith('$2y$12$') and rows[0]['senha_hash'] != password, 'um usuário, hash bcrypt sem senha em texto puro')
                b.token()
                b.request('/php/api/auth/cadastro.php', 'POST', {'nome': 'Bruno Teste', 'email': email_b, 'senha': password})
                uid_b = db('SELECT id_usuario FROM usuario WHERE email=?', [email_b])['rows'][0]['id_usuario']; user_ids.append(uid_b)
                before = a.sid()
                login = a.login(email_a)
                ok(login[0] == 200 and a.sid() != before, 'login real regenera ID da sessão')
                old_session = Client(base)
                cookie = http.cookiejar.Cookie(0,'HYDROPONEXSESSID',before,None,False,'127.0.0.1',False,False,'/',True,False,None,True,None,None,{})
                old_session.jar.set_cookie(cookie)
                ok(old_session.request('/php/api/auth/me.php')[0] == 401, 'ID pré-login não autentica após regeneração')
                serialized = json.dumps(login[1])
                ok(not any(x in serialized for x in ('senha_hash', 'auth_versao', 'csrf_token', 'PHPSESSID')), 'resposta não expõe credenciais internas')
                ok(b.login(email_b)[0] == 200, 'segunda conta autentica separadamente')
                email_c = f'c-{run_id}@example.test'; emails.append(email_c)
                other.token()
                other.request('/php/api/auth/cadastro.php','POST',{'nome':'Carla Teste','email':email_c,'senha':password})
                uid_c = db('SELECT id_usuario FROM usuario WHERE email=?',[email_c])['rows'][0]['id_usuario']; user_ids.append(uid_c)
                db('UPDATE usuario SET ativo=0 WHERE id_usuario=?',[uid_c])
                disabled = other.login(email_c)
                ok(disabled[0] == 401 and disabled[1]['message'] == 'E-mail ou senha inválidos.', 'conta inativa recebe erro genérico')
                db('UPDATE usuario SET ativo=1 WHERE id_usuario=?',[uid_c])
                ok(other.request('/php/api/perfil.php')[0] == 401, 'perfil privado exige sessão')
                wrong = other.login(email_a, 'senha-invalida')
                unknown_email = f'unknown-{run_id}@example.test'; emails.append(unknown_email)
                missing = other.login(unknown_email, 'senha-invalida')
                ok(wrong[:2] == missing[:2] and wrong[0] == 401, 'login não distingue senha incorreta e e-mail inexistente')
                ok(a.request('/php/api/perfil.php', 'PATCH', {'nome': 'Teste'}, token=False)[0] == 403, 'alteração sem CSRF recusada')
                ok(a.request('/php/api/perfil.php', 'PATCH', {'id_usuario': uid_b, 'nome': 'Invadido'})[0] == 422, 'não permite editar terceiro')
                ok(a.request('/php/api/perfil.php', 'DELETE', {'id_usuario': uid_b, 'senha_atual': password, 'confirmacao': 'EXCLUIR'})[0] == 422, 'não permite excluir terceiro')
                ok(a.request('/php/api/perfil.php', 'PATCH', {'ativo': 1, 'tipo': 'admin'})[0] == 422, 'perfil recusa campos de privilégio')
                ok(a.request('/php/api/perfil.php', 'PATCH', {'avatar_url': 'javascript:alert(1)'})[0] == 422, 'URL de avatar insegura recusada')
                ok(a.request('/php/api/perfil.php', 'PATCH', {'nome': 'Ana Atualizada'})[0] == 200, 'nome atualizado no MySQL')
                ok(a.request('/php/api/perfil.php?id='+str(uid_b))[1]['data']['id'] == uid_a, 'parâmetro de URL nunca seleciona conta de terceiros')
                ok(a.request('/php/api/perfil.php', 'PATCH', {'email': email_b, 'senha_atual': password})[0] == 409, 'e-mail duplicado não sobrescreve conta')
                changed_email = f'a-new-{run_id}@example.test'; emails.append(changed_email)
                ok(a.request('/php/api/perfil.php', 'PATCH', {'email': changed_email, 'senha_atual': 'errada'})[0] == 403, 'troca de e-mail exige senha atual')
                second_session = Client(base)
                ok(second_session.login(email_a)[0] == 200, 'sessão adicional criada para teste de revogação')
                ok(a.request('/php/api/perfil.php', 'PATCH', {'email': changed_email, 'senha_atual': password})[0] == 200, 'troca de e-mail confirmada')
                ok(second_session.request('/php/api/auth/me.php')[0] == 401, 'troca de e-mail revoga outra sessão')
                ok(second_session.login(changed_email)[0] == 200, 'login pelo e-mail atualizado')
                ok(a.request('/php/api/auth/senha.php', 'POST', {'senha_atual': 'errada', 'nova_senha': new_password, 'confirmacao_senha': new_password})[0] == 403, 'troca de senha exige senha atual')
                db("INSERT INTO token_usuario (id_usuario,finalidade,token_hash,expira_em) VALUES (?,'recuperar_senha',UNHEX(?),UTC_TIMESTAMP(6)+INTERVAL 1 HOUR)",[uid_a,secrets.token_hex(32)])
                ok(a.request('/php/api/auth/senha.php', 'POST', {'senha_atual': password, 'nova_senha': new_password, 'confirmacao_senha': new_password})[0] == 200, 'senha alterada com verificação')
                ok(db('SELECT COUNT(*) AS n FROM token_usuario WHERE id_usuario=?',[uid_a])['rows'][0]['n'] == 0, 'troca de senha invalida tokens anteriores')
                ok(second_session.request('/php/api/auth/me.php')[0] == 401, 'troca de senha revoga outras sessões')
                ok(other.login(changed_email, password)[0] == 401, 'senha anterior deixa de funcionar')
                ok(other.login(changed_email, new_password)[0] == 200, 'nova senha funciona')
                personal_garden = a.request('/php/api/hortas.php','POST',{'nome':'Horta privada A '+run_id})[1]['data']['id_horta']
                bob_garden = b.request('/php/api/hortas.php','POST',{'nome':'Horta privada B '+run_id})[1]['data']['id_horta']
                garden_ids.extend([personal_garden,bob_garden])
                ok(a.request(f'/php/api/plantas.php?horta={personal_garden}')[1]['data'] == [], 'nova horta inicia sem plantas')
                plant = a.request(f'/php/api/plantas.php?horta={personal_garden}', 'POST', {'nome': 'Alface privada', 'bancada': 'A', 'temp': 22, 'nutrientes': 800})
                pid = plant[1]['data']['id']
                ok(plant[0] == 201 and b.request(f'/php/api/plantas.php?horta={bob_garden}')[1]['data'] == [], 'plantas MySQL isoladas por usuário/horta')
                ok(b.request(f'/php/api/plantas.php?horta={bob_garden}', 'PATCH', {'id': pid, 'nome': 'Invadida'})[0] == 404, 'terceiro não altera planta de outro usuário')
                ok(a.request(f'/php/api/plantas.php?horta={personal_garden}', 'PUT', {'id': pid, 'nome': 'Alface atualizada'})[0] == 200, 'CRUD preserva atualização própria')
                db("INSERT INTO usuario_horta (id_usuario,id_horta,papel) VALUES (?,?,'proprietario')",[uid_b,personal_garden])
                garden = int(db('INSERT INTO horta (nome) VALUES (?)', ['Horta de teste ' + run_id])['id']); garden_ids.append(garden)
                db("INSERT INTO usuario_horta (id_usuario,id_horta,papel) VALUES (?,?,'proprietario'),(?,?,'leitor')", [uid_a,garden,uid_b,garden])
                permission = f'/php/api/_permission.php?horta={garden}'
                ok(a.request(permission + '&manage=1')[0] == 200 and b.request(permission)[0] == 200, 'proprietário gerencia; leitor consulta')
                nonmember = Client(base)
                ok(nonmember.login(email_c)[0] == 200 and nonmember.request(permission)[0] == 403, 'não membro não acessa a horta')
                nonmember.request('/php/api/_expire.php','POST',{})
                ok(nonmember.request('/php/api/auth/me.php')[0] == 401, 'sessão expira após 30 minutos sem atividade')
                nonmember.login(email_c)
                nonmember.request('/php/api/_expire.php?absolute=1','POST',{})
                ok(nonmember.request('/php/api/auth/me.php')[0] == 401, 'sessão expira no limite absoluto de 12 horas')
                ok(b.request(permission + '&write=1')[0] == 403 and b.request(permission + '&manage=1')[0] == 403, 'leitor não escreve nem gerencia membros')
                db("UPDATE usuario_horta SET papel='gestor' WHERE id_usuario=? AND id_horta=?", [uid_b,garden])
                ok(b.request(permission + '&write=1')[0] == 200 and b.request(permission + '&manage=1')[0] == 403, 'gestor escreve sem gerenciar membros')
                expire_limit('sensitive-user', str(uid_a))
                ok(a.request('/php/api/perfil.php', 'DELETE', {'senha_atual': new_password, 'confirmacao': 'sim'})[0] == 422, 'exclusão exige confirmação textual')
                ok(a.request('/php/api/perfil.php', 'DELETE', {'senha_atual': new_password, 'confirmacao': 'EXCLUIR'})[0] == 409, 'último proprietário não abandona horta ativa')
                db("UPDATE usuario_horta SET papel='proprietario' WHERE id_usuario=? AND id_horta=?", [uid_b,garden])
                monitor = int(db("INSERT INTO monitoramento (id_horta,id_usuario,origem,data_hora) VALUES (?,?,'manual',UTC_TIMESTAMP(6))", [garden,uid_a])['id']); monitor_ids.append(monitor)
                ok(a.request('/php/api/perfil.php', 'DELETE', {'senha_atual': new_password, 'confirmacao': 'EXCLUIR'})[0] == 200, 'conta excluída sem apagar horta compartilhada')
                tombstone = db('SELECT nome,email,senha_hash,ativo,excluido_em FROM usuario WHERE id_usuario=?', [uid_a])['rows'][0]
                ok(tombstone['senha_hash'] is None and tombstone['ativo'] == 0 and tombstone['excluido_em'] and tombstone['email'] != changed_email, 'exclusão remove credenciais e anonimiza perfil')
                ok(db('SELECT id_usuario FROM monitoramento WHERE id_monitoramento=?', [monitor])['rows'][0]['id_usuario'] == uid_a, 'histórico preserva FK do autor anonimizado')
                ok(other.request('/php/api/auth/me.php')[0] == 401, 'exclusão revoga sessão em outro dispositivo')
                ok(a.login(changed_email, new_password)[0] == 401, 'conta excluída não autentica')
                ok(a.request('/php/api/auth/cadastro.php','POST',{'nome':'Nova Ana','email':changed_email,'senha':password})[0] == 202, 'e-mail liberado após anonimização da conta')
                reused = db('SELECT id_usuario FROM usuario WHERE email=?',[changed_email])['rows'][0]['id_usuario']; user_ids.append(reused)
                ok(reused != uid_a, 'novo cadastro não herda identidade ou recursos da conta excluída')
                sid_before_logout = b.sid()
                ok(b.request('/php/api/auth/logout.php','POST',{},token=False)[0] == 403, 'logout também exige CSRF')
                ok(b.request('/php/api/auth/logout.php', 'POST')[0] == 200 and b.sid() is None, 'logout destrói sessão e cookie')
                ok(b.request('/php/api/auth/me.php')[0] == 401, 'sessão encerrada não acessa perfil')
                # Limites não podem ser burlados abrindo outro cookie de sessão.
                expire_limit('login-ip', '127.0.0.1')
                blocked_email = f'blocked-{run_id}@example.test'; emails.append(blocked_email)
                attempts = []
                for _ in range(6):
                    fresh = Client(base)
                    attempts.append(fresh.login(blocked_email, 'senha-invalida')[0])
                ok(attempts == [401]*5 + [429], 'limite por e-mail persiste entre sessões novas')
                expire_limit('login-email', blocked_email)
                ok(Client(base).login(blocked_email, 'senha-invalida')[0] == 401, 'janela expirada libera nova tentativa')
                # Bloqueio por IP também limita distribuição entre e-mails.
                key = hmac.new(env['HYDROPONEX_AUTH_KEY'].encode(), b'login-ip:127.0.0.1', hashlib.sha256).hexdigest()
                db('UPDATE auth_limite SET tentativas=30 WHERE chave=UNHEX(?)', [key])
                ok(Client(base).login(email_b)[0] == 429, 'limite por IP bloqueia tentativas distribuídas')
            finally:
                server.terminate()
                server.wait(timeout=10)
        log_text = log_path.read_text(encoding='utf-8', errors='replace')
        ok(env['HYDROPONEX_DB_PASSWORD'] not in log_text and password not in log_text and new_password not in log_text, 'logs HTTP não contêm senhas')
finally:
    # Remoção só de fixtures desta execução; nunca DROP, TRUNCATE ou dados de usuários reais.
    for mid in monitor_ids:
        db('DELETE FROM monitoramento WHERE id_monitoramento=?', [mid])
    for garden in garden_ids:
        db('DELETE FROM planta WHERE id_horta=?',[garden])
        db('DELETE FROM usuario_horta WHERE id_horta=?', [garden])
        db('DELETE FROM horta WHERE id_horta=?', [garden])
    for uid in user_ids:
        db('DELETE FROM token_usuario WHERE id_usuario=?', [uid])
        db('DELETE FROM usuario WHERE id_usuario=?', [uid])
    keys = [('login-ip','127.0.0.1'),('register-ip','127.0.0.1')]
    keys += [('login-email', e) for e in emails] + [('sensitive-user',str(uid)) for uid in user_ids]
    for scope, identity in keys:
        key = hmac.new(env['HYDROPONEX_AUTH_KEY'].encode(), (scope+':'+identity).encode(), hashlib.sha256).hexdigest()
        db('DELETE FROM auth_limite WHERE chave=UNHEX(?)', [key])
ok(legacy_hash == hashlib.sha256((app_source/'php/storage/plantas.json').read_bytes()).hexdigest(), 'JSON legado original preservado')
print(f'{checks} verificações HTTP/SQL aprovadas; fixtures removidas.', flush=True)
