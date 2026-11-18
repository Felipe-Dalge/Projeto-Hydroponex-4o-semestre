"""Convites HTTP e SMTP locais, com dois processos PHP para testar concorrência."""
import argparse
from concurrent.futures import ThreadPoolExecutor
from contextlib import ExitStack
from email import policy
from email.parser import BytesParser
import http.cookiejar
import json
import os
from pathlib import Path
import re
import secrets
import shutil
import socket
import socketserver
import subprocess
import tempfile
import threading
import time
import urllib.error
import urllib.request


ROOT = Path(__file__).resolve().parents[1]
TEAM = 'equipe.php'
REPLY = 'convite_email.php'
PASSWORD = 'Horta-' + secrets.token_hex(16)
CHECKS = 0


def ok(condition, label):
    global CHECKS
    assert condition, label
    CHECKS += 1
    print('OK:', label, flush=True)


class CaptureSMTP(socketserver.StreamRequestHandler):
    def handle(self):
        self.wfile.write(b'220 capture ESMTP\r\n')
        while line := self.rfile.readline():
            command = line.split(maxsplit=1)[0].upper()
            if command == b'DATA':
                self.wfile.write(b'354 End with dot\r\n')
                body = []
                while chunk := self.rfile.readline():
                    if chunk == b'.\r\n':
                        break
                    body.append(chunk)
                if self.server.reject:
                    self.wfile.write(b'550 Message rejected\r\n')
                else:
                    self.server.messages.append(b''.join(body))
                    self.wfile.write(b'250 Queued\r\n')
            elif command == b'QUIT':
                self.wfile.write(b'221 Goodbye\r\n')
                return
            else:
                self.wfile.write(b'250 OK\r\n')


class Client:
    def __init__(self, url):
        self.url = url
        self.csrf = ''
        self.http = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))

    def call(self, path, method='GET', data=None, csrf=True):
        headers = {'Accept': 'application/json', 'Content-Type': 'application/json'}
        if csrf:
            headers['X-CSRF-Token'] = self.csrf
        request = urllib.request.Request(self.url + '/php/api/' + path,
                                         data=None if data is None else json.dumps(data).encode(),
                                         headers=headers, method=method)
        try:
            response = self.http.open(request, timeout=20)
        except urllib.error.HTTPError as exc:
            response = exc
        with response:
            body = json.load(response)
            assert 'SQLSTATE' not in json.dumps(body), body
            self.csrf = body.get('csrfToken', self.csrf)
            return response.status, body

    def login(self, address):
        self.call('session.php')
        assert self.call('auth/login.php', 'POST', {'email': address, 'senha': PASSWORD})[0] == 200

    def register(self, address, profile='agricultor'):
        self.call('session.php')
        assert self.call('auth/cadastro.php', 'POST', {
            'email': address, 'nome': address.split('@')[0], 'senha': PASSWORD, 'perfil_entrada': profile,
        })[0] == 202
        self.login(address)

    def reply(self, token, action='aceitar', csrf=True):
        return self.call(REPLY, 'POST', {'token': token, 'acao': action}, csrf)


def port():
    with socket.socket() as sock:
        sock.bind(('127.0.0.1', 0))
        return sock.getsockname()[1]


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--executar', action='store_true')
    parser.add_argument('--php', default='php')
    args = parser.parse_args()
    if not args.executar:
        parser.error('Use --executar somente com uma base de teste descartável.')
    env = dict(os.environ, HYDROPONEX_MAIL_AUTOLOAD=str(ROOT / 'vendor/autoload.php'))
    if not Path(env['HYDROPONEX_MAIL_AUTOLOAD']).is_file():
        parser.error('Execute composer install antes de testar.')
    bridge = """require 'public/php/config/database.php';
    $i=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
    $p=database_connection();$s=$p->prepare($i[0]);$s->execute($i[1]);
    echo json_encode($s->columnCount()?$s->fetchAll():[],JSON_THROW_ON_ERROR);"""

    def db(sql, *params):
        response = subprocess.run([args.php, '-r', bridge], input=json.dumps([sql, params]), text=True,
                                  encoding='utf-8', cwd=ROOT, env=env, capture_output=True, check=True)
        return json.loads(response.stdout)

    db_name = db('SELECT DATABASE() AS nome')[0]['nome']
    if not db_name.endswith(('_test', '_tests')) or db('SELECT 1 FROM usuario LIMIT 1'):
        parser.error('Use uma base descartável sem usuários, terminada em _test ou _tests.')
    assert db("SELECT 1 FROM schema_version WHERE versao='1.5.0'"), 'Importe o esquema 1.5.0.'
    addresses = []
    gardens = []
    tokens = []
    tag = secrets.token_hex(5)

    def address(name):
        value = f'{name}-{tag}@example.test'
        addresses.append(value)
        return value

    def user_id(email):
        return db('SELECT id_usuario FROM usuario WHERE email=?', email)[0]['id_usuario']

    with ExitStack() as stack:
        directory = Path(stack.enter_context(tempfile.TemporaryDirectory(prefix='hx-team-test-')))
        app = directory / 'app'
        shutil.copytree(ROOT / 'public', app, ignore=shutil.ignore_patterns('installation.local.php'))
        smtp = stack.enter_context(socketserver.ThreadingTCPServer(('127.0.0.1', 0), CaptureSMTP))
        smtp.reject = False
        smtp.messages = []
        threading.Thread(target=smtp.serve_forever, daemon=True).start()
        stack.callback(smtp.shutdown)
        ports = [port(), port()]
        urls = [f'http://127.0.0.1:{value}' for value in ports]
        env.update(HYDROPONEX_SMTP_HOST='127.0.0.1', HYDROPONEX_SMTP_PORT=str(smtp.server_address[1]),
                   HYDROPONEX_SMTP_SECURITY='none', HYDROPONEX_SMTP_USER='', HYDROPONEX_SMTP_PASSWORD='',
                   HYDROPONEX_SMTP_FROM='convites@example.test', HYDROPONEX_PUBLIC_URL=urls[0])
        logs = []
        for index, value in enumerate(ports):
            log = directory / f'http-{index}.log'
            logs.append(log)
            output = stack.enter_context(log.open('wb'))
            server = subprocess.Popen([args.php, '-S', f'127.0.0.1:{value}', 'server/router.php'],
                                      env=env, cwd=app, stdout=output, stderr=output)
            stack.callback(server.wait, 10)
            stack.callback(server.terminate)
            client = Client(urls[index])
            for _ in range(60):
                try:
                    if client.call('session.php')[0] == 200:
                        break
                except urllib.error.URLError:
                    time.sleep(.1)
            else:
                raise RuntimeError('Servidor PHP indisponível.')

        owner, farmer, stranger, new = [Client(urls[0]) for _ in range(4)]

        def create(client, name):
            status, result = client.call('hortas.php', 'POST', {'nome': name})
            assert status == 201, result
            value = result['data']['id_horta']
            gardens.append(value)
            return value

        def read_token(email):
            message = BytesParser(policy=policy.default).parsebytes(smtp.messages[-1])
            assert message['To'].addresses[0].addr_spec == email
            body = message.get_body().get_content()
            match = re.search(r'/convite\.html#([a-f0-9]{64})', body)
            assert match, 'O SMTP não recebeu um link de convite válido.'
            value = match.group(1)
            tokens.append(value)
            return value

        def invite(email, targets):
            status, result = owner.call(TEAM, 'POST', {'email': email, 'hortas': targets})
            assert status == 201, result
            value = read_token(email)
            assert value not in json.dumps(result)
            return result['data']['id_convite'], value

        def views(client, garden):
            return client.call(f'dashboard.php?horta={garden}')[0]

        try:
            owner_email = address('proprietario')
            existing = address('agricultor')
            newcomer = address('novo')
            stranger_email = address('terceiro')
            owner.register(owner_email, 'administrador')
            farmer.register(existing)
            stranger.register(stranger_email, 'administrador')
            first, second = create(owner, 'NFT'), create(owner, 'Vertical')
            private = create(stranger, 'Privada')
            payload = {'email': existing, 'hortas': [first]}
            ok(farmer.call(TEAM)[0] == 403, 'agricultor sem gestão não lista a equipe')
            ok(stranger.call(TEAM, 'POST', payload)[0] == 404, 'outro proprietário não pode convidar para horta alheia')
            ok(owner.call(TEAM, 'POST', {**payload, 'hortas': [first, private]})[0] == 404, 'escopo misto rejeitado inteiro')
            ok(owner.call(TEAM, 'POST', payload, csrf=False)[0] == 403, 'emissão exige CSRF')
            first_id, first_token = invite(existing, [second, first, first])
            ok(owner.call(TEAM, 'POST', payload)[0] == 409, 'convites sobrepostos são recusados')
            ok(new.reply(first_token)[0] == 401, 'convite exige sessão autenticada')
            ok(stranger.reply(first_token, 'consultar')[0] == 404, 'outra conta não recebe a oferta')
            ok(farmer.reply(first_token, csrf=False)[0] == 403, 'resposta exige CSRF')
            offer = farmer.reply(first_token, 'consultar')[1]['data']['hortas']
            ok([row['id_horta'] for row in offer] == [first, second], 'prévia mostra todas as hortas sem duplicar')
            ok(views(farmer, first) == views(farmer, second) == 404, 'prévia não concede acesso')

            concurrent = Client(urls[1])
            concurrent.login(existing)
            ready = threading.Barrier(2)

            def accept(client):
                ready.wait(timeout=10)
                return client.reply(first_token)[0]

            with ThreadPoolExecutor(max_workers=2) as pool:
                outcomes = sorted(pool.map(accept, [farmer, concurrent]))
            ok(outcomes == [200, 404], f'aceitação concorrente concede acesso uma vez ({outcomes})')
            ok(farmer.reply(first_token)[0] == 404, 'token aceito não pode ser reutilizado')
            ok(views(farmer, first) == views(farmer, second) == 200 and views(farmer, private) == 404,
               'vínculos criados nas hortas autorizadas e isolamento preservado')
            ok(owner.call(TEAM, 'POST', payload)[0] == 409, 'convite para vínculo existente é recusado')
            ok(db('SELECT email_verificado_em AS verificado FROM usuario WHERE email=?', existing)[0]['verificado'],
               'aceitação comprova o e-mail da conta existente')
            team = owner.call(TEAM)[1]['data']
            ok(first_token not in json.dumps(team) and len(team['agricultores'][0]['hortas']) == 2
               and team['convites'][0]['estado'] == 'aceito', 'equipe agrupa os vínculos e não expõe o token')
            ok(farmer.call(f'registros.php?horta={first}', 'POST', {'tipo': 'observacao', 'descricao': 'Registro de cultivo'})[0] == 201,
               'agricultor registra trabalho antes da revogação')
            ok(owner.call(f'membros.php?horta={first}', 'DELETE', {'id_usuario': user_id(existing)})[0] == 200,
               'proprietário remove um vínculo')
            ok(views(farmer, first) == 404 and views(farmer, second) == 200, 'sessão atual perde apenas a horta removida')
            ok(owner.call(f'registros.php?horta={first}')[1]['data'], 'remoção preserva histórico de trabalho')

            _, newcomer_token = invite(newcomer, [first])
            ok(not db('SELECT 1 FROM usuario WHERE email=?', newcomer), 'convite para e-mail novo não cria conta')
            new.register(newcomer)
            ok(new.reply(newcomer_token, 'consultar')[0] == 200 and new.reply(newcomer_token)[0] == 200,
               'conta criada depois do convite pode consultar e aceitar')
            ok(db('SELECT email_verificado_em AS verificado FROM usuario WHERE email=?', newcomer)[0]['verificado'],
               'conta nova tem e-mail verificado na aceitação')

            expired_id, expired = invite(existing, [first])
            db('UPDATE equipe_convite SET expira_em=UTC_TIMESTAMP(6)-INTERVAL 1 SECOND, tentativa_em=UTC_TIMESTAMP(6)-INTERVAL 2 MINUTE WHERE id_convite=?', expired_id)
            ok(farmer.reply(expired)[0] == 404, 'convite expirado é recusado')
            ok(owner.call(TEAM, 'PATCH', {'id_convite': expired_id})[0] == 200, 'convite expirado pode ser reenviado')
            replacement = read_token(existing)
            ok(replacement != expired and farmer.reply(expired)[0] == 404, 'reenvio invalida o token anterior')
            ok(owner.call(TEAM, 'PATCH', {'id_convite': expired_id})[0] == 429, 'intervalo mínimo limita reenvios')
            ok(owner.call(TEAM, 'DELETE', {'id_convite': expired_id})[0] == 200 and farmer.reply(replacement)[0] == 404,
               'cancelamento invalida link reenviado')

            _, decline = invite(existing, [first])
            ok(farmer.reply(decline, 'recusar')[0] == 200 and farmer.reply(decline)[0] == 404 and views(farmer, first) == 404,
               'recusa encerra o convite sem criar vínculo')
            third = create(owner, 'Terceira')
            partial_id, partial_token = invite(existing, [first, third])
            db("INSERT INTO usuario_horta (id_horta,id_usuario,papel) VALUES (?,?,'leitor')", third, user_id(existing))
            ok(farmer.reply(partial_token)[0] == 409 and views(farmer, first) == 404,
               'conflito na última horta reverte os vínculos anteriores da mesma aceitação')
            membership = db('SELECT papel FROM usuario_horta WHERE id_horta=? AND id_usuario=?', third, user_id(existing))
            ok(membership[0]['papel'] == 'leitor'
               and db('SELECT aceito_em FROM equipe_convite WHERE id_convite=?', partial_id)[0]['aceito_em'] is None,
               'aceitação revertida preserva papel existente e não consome o convite')
            assert owner.call(TEAM, 'DELETE', {'id_convite': partial_id})[0] == 200
            db('DELETE FROM usuario_horta WHERE id_horta=? AND id_usuario=?', third, user_id(existing))
            _, invalid = invite(stranger_email, [first, third])
            db("UPDATE usuario_horta SET papel='gestor' WHERE id_horta=? AND id_usuario=?", third, user_id(owner_email))
            ok(stranger.reply(invalid)[0] == 404 and views(stranger, first) == 404 and views(stranger, third) == 404,
               'perda de permissão em uma horta impede toda a concessão')
            erased = create(owner, 'Horta a excluir')
            erased_id, erased_token = invite(stranger_email, [erased])
            ok(owner.call(f'hortas.php?id={erased}', 'DELETE', {'confirmacao': 'EXCLUIR'})[0] == 200,
               'convite não impede exclusão de horta sem histórico')
            ok(stranger.reply(erased_token)[0] == 404
               and db('SELECT cancelado_em FROM equipe_convite WHERE id_convite=?', erased_id)[0]['cancelado_em'],
               'exclusão da horta cancela seu convite')

            smtp.reject = True
            before = len(smtp.messages)
            status, body = owner.call(TEAM, 'POST', payload)
            ok(status == 502 and not body['success'] and len(smtp.messages) == before, 'SMTP rejeitado é reportado como falha')
            failed = owner.call(TEAM)[1]['data']['convites'][0]
            ok(failed['entrega'] == 'falhou' and failed['estado'] == 'pendente', 'a tentativa falha permanece visível')
            db('UPDATE equipe_convite SET tentativa_em=UTC_TIMESTAMP(6)-INTERVAL 2 MINUTE WHERE id_convite=?', failed['id_convite'])
            smtp.reject = False
            ok(owner.call(TEAM, 'PATCH', {'id_convite': failed['id_convite']})[0] == 200, 'envio recuperado após rejeição SMTP')
            recovered = read_token(existing)
            ok(farmer.reply(recovered)[0] == 200, 'link de envio recuperado funciona')
            ok(all(token.encode() not in log.read_bytes() for token in tokens for log in logs), 'tokens não aparecem no log PHP')
            ok(first_id != expired_id, 'convites encerrados preservam sua identificação')
        finally:
            users = [row['id_usuario'] for email in addresses
                     for row in db('SELECT id_usuario FROM usuario WHERE email=?', email)]
            for user in users:
                db('DELETE a FROM equipe_convite_horta a JOIN equipe_convite c ON c.id_convite=a.id_convite WHERE c.id_emissor=?', user)
                db('DELETE FROM equipe_convite WHERE id_emissor=?', user)
            for garden in gardens:
                for table in ('horta_registro', 'usuario_horta', 'horta'):
                    db(f'DELETE FROM {table} WHERE id_horta=?', garden)
            for user in users:
                db('DELETE FROM usuario WHERE id_usuario=?', user)
            db('DELETE FROM auth_limite')
    print(f'{CHECKS} verificações de equipe aprovadas.')


if __name__ == '__main__':
    main()
