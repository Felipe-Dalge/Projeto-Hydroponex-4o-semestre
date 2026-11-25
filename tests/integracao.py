"""Fluxos A-E com SMTP capturado, contas reais, isolamento e tarefas persistidas."""
import argparse
from contextlib import ExitStack
from datetime import datetime, timezone
from email import policy
from email.parser import BytesParser
import json
import os
from pathlib import Path
import re
import secrets
import shutil
import socketserver
import subprocess
import sys
import tempfile
import threading
import time
import urllib.error
import urllib.request
from equipe import CaptureSMTP, Client, port

ROOT = Path(__file__).resolve().parents[1]
if hasattr(sys.stdout, 'reconfigure'):
    sys.stdout.reconfigure(encoding='utf-8')


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--executar', action='store_true')
    parser.add_argument('--php', default='php')
    args = parser.parse_args()
    if not args.executar:
        parser.error('Use --executar apenas em uma base descartável sem usuários.')
    env = dict(os.environ, HYDROPONEX_MAIL_AUTOLOAD=str(ROOT / 'vendor/autoload.php'))
    if not Path(env['HYDROPONEX_MAIL_AUTOLOAD']).is_file():
        parser.error('Execute composer install antes de testar.')
    bridge = """require 'public/php/config/database.php';
    $i=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);$p=database_connection();
    $s=$p->prepare($i[0]);$s->execute($i[1]);echo json_encode($s->columnCount()?$s->fetchAll():[],JSON_THROW_ON_ERROR);"""

    def db(sql, *params):
        r = subprocess.run([args.php, '-r', bridge], cwd=ROOT, env=env, capture_output=True,
                           input=json.dumps([sql, params]), text=True, encoding='utf-8')
        if r.returncode:
            raise RuntimeError('Falha no banco de teste.')
        return json.loads(r.stdout)

    name = db('SELECT DATABASE() AS nome')[0]['nome']
    if not name.endswith(('_test', '_tests')) or db('SELECT 1 FROM usuario LIMIT 1'):
        parser.error('Use base exclusiva sem usuários terminada em _test ou _tests.')
    assert db("SELECT 1 FROM schema_version WHERE versao='1.7.0'")
    checks = 0
    gardens = []
    users = []

    def ok(condition, label):
        nonlocal checks
        assert condition, label
        checks += 1
        print('OK:', label, flush=True)

    with ExitStack() as stack:
        directory = Path(stack.enter_context(tempfile.TemporaryDirectory(prefix='hx-integracao-')))
        app = directory / 'app'
        shutil.copytree(ROOT / 'public', app, ignore=shutil.ignore_patterns('installation.local.php'))
        smtp = stack.enter_context(socketserver.ThreadingTCPServer(('127.0.0.1', 0), CaptureSMTP))
        smtp.messages = []
        smtp.reject = False
        threading.Thread(target=smtp.serve_forever, daemon=True).start()
        stack.callback(smtp.shutdown)
        http_port = port()
        url = f'http://127.0.0.1:{http_port}'
        env.update(HYDROPONEX_SMTP_HOST='127.0.0.1', HYDROPONEX_SMTP_PORT=str(smtp.server_address[1]),
                   HYDROPONEX_SMTP_SECURITY='none', HYDROPONEX_SMTP_USER='', HYDROPONEX_SMTP_PASSWORD='',
                   HYDROPONEX_SMTP_FROM='convites@example.test', HYDROPONEX_PUBLIC_URL=url)
        log_path = directory / 'http.log'
        log = stack.enter_context(log_path.open('wb'))
        server = subprocess.Popen([args.php, '-S', f'127.0.0.1:{http_port}', 'server/router.php'],
                                  cwd=app, env=env, stdout=log, stderr=log)
        stack.callback(server.wait, 10)
        stack.callback(server.terminate)
        owner, second, farmer, mixed = [Client(url) for _ in range(4)]
        for _ in range(50):
            try:
                owner.call('session.php')
                break
            except urllib.error.URLError:
                time.sleep(.1)
        else:
            raise RuntimeError('PHP indisponível.')

        def create(client, resource, body, garden=None):
            route = resource + '.php' + (f'?horta={garden}' if garden else '')
            status, result = client.call(route, 'POST', body)
            assert status == 201, result
            return result['data']

        def task_detail(client, garden, task):
            return client.call(f'tarefas.php?horta={garden}&id={task}')

        def change(client, garden, task, action):
            status, result = task_detail(client, garden, task)
            assert status == 200, result
            return client.call(f'tarefas.php?horta={garden}', 'PATCH',
                               dict(id_tarefa=task, versao=result['data']['versao'], acao=action))

        def invite(client, email, targets):
            result = create(client, 'equipe', dict(email=email, hortas=targets))
            message = BytesParser(policy=policy.default).parsebytes(smtp.messages[-1])
            assert message['To'].addresses[0].addr_spec == email
            token = re.search(r'/convite\.html#([a-f0-9]{64})', message.get_body().get_content()).group(1)
            assert token not in json.dumps(result)
            return token

        def summary(client):
            status, result = client.call('operacional.php?recurso=resumo')
            assert status == 200, result
            return result['data']

        def work(client, garden, task):
            status, result = task_detail(client, garden, task)
            assert status == 200
            return client.call(f'tarefa_intervencoes.php?horta={garden}', 'POST', {
                'id_tarefa': task, 'versao': result['data']['versao'], 'tipo': 'intervencao',
                'descricao': 'Inspeção registrada <img src=x onerror=alert(1)>',
                'realizada_em': datetime.now(timezone.utc).isoformat(),
            })

        try:
            emails = []
            for index, client in enumerate([owner, second, farmer, mixed]):
                email = f'integracao-{index}-{secrets.token_hex(4)}@example.test'
                emails.append(email)
                client.register(email, 'agricultor' if index == 2 else 'administrador')
                users.append(db('SELECT id_usuario FROM usuario WHERE email=?', email)[0]['id_usuario'])
            owner_id, _, farmer_id, mixed_id = users
            h1 = create(owner, 'hortas', dict(nome='Individual'))['id_horta']
            h2 = create(second, 'hortas', dict(nome='Outro administrador'))['id_horta']
            own = create(mixed, 'hortas', dict(nome='Horta própria'))['id_horta']
            gardens.extend([h1, h2, own])
            plant = create(owner, 'plantas', dict(nome='Alface', bancada='A1', status='Alerta'), h1)['id_planta']
            task = create(owner, 'tarefas', dict(titulo='Inspeção individual', id_planta=plant,
                                               id_responsavel=owner_id, origem_problema='estado_planta'), h1)['id_tarefa']
            ok(change(owner, h1, task, 'iniciar')[0] == 200 and work(owner, h1, task)[0] == 201,
               'A — administrador cria horta, planta, tarefa e registra trabalho sozinho')
            ok(change(owner, h1, task, 'verificar')[0] == 200 and change(owner, h1, task, 'concluir')[0] == 200,
               'A — administrador solicita verificação e conclui sua própria tarefa')
            ok(summary(owner)['concluidas_recentes'] == 1
               and owner.call(f'dashboard.php?horta={h1}')[1]['data']['plantas']['Alerta'] == 1,
               'A — dashboards concordam sem transformar conclusão em recuperação da planta')

            token = invite(owner, emails[2], [h1])
            ok(summary(farmer)['hortas'] == 0 and farmer.reply(token, 'consultar')[0] == 200,
               'B — convite entregue por SMTP permite prévia sem acesso automático')
            ok(farmer.reply(token)[0] == 200 and summary(farmer)['hortas'] == 1,
               'B — agricultor aceita e passa a acessar a horta')
            assigned = create(owner, 'tarefas', dict(titulo='Trabalho da equipe', id_responsavel=farmer_id, id_planta=plant), h1)['id_tarefa']
            ok(change(farmer, h1, assigned, 'iniciar')[0] == 200 and work(farmer, h1, assigned)[0] == 201,
               'B — agricultor executa tarefa atribuída e registra intervenção')
            ok(change(farmer, h1, assigned, 'verificar')[0] == 200
               and task_detail(owner, h1, assigned)[1]['intervencoes']['itens'][0]['id_autor'] == farmer_id,
               'B — administrador acompanha trabalho e autoria antes de aprovar')
            ok(change(owner, h1, assigned, 'concluir')[0] == 200, 'B — gestão aprova a intervenção da equipe')

            other_token = invite(second, emails[2], [h2])
            ok(farmer.reply(other_token)[0] == 200 and summary(farmer)['hortas'] == 2,
               'C — agricultor aceita convites de dois administradores diferentes')
            ok(farmer.call(f'dashboard.php?horta={own}')[0] == 404
               and {row['id_horta'] for row in farmer.call('operacional.php?recurso=hortas')[1]['data']} == {h1, h2},
               'C — consulta agregada contém somente as hortas autorizadas')
            mixed_token = invite(owner, emails[3], [h1])
            ok(mixed.reply(mixed_token)[0] == 200, 'E — proprietário de uma horta aceita papel operacional em outra')
            ok(mixed.call(f'hortas.php?id={own}', 'PATCH', dict(nome='Editada por seu dono'))[0] == 200
               and mixed.call(f'hortas.php?id={h1}', 'PATCH', dict(nome='Indevida'))[0] == 403,
               'E — administração em uma horta não eleva o papel em outra')
            ok(mixed.call(f'equipe.php', 'POST', dict(email=emails[1], hortas=[h1]))[0] == 403
               and mixed.call(f'tarefas.php?horta={h1}', 'POST', dict(titulo='Indevida'))[0] == 403,
               'E — perfil administrador não concede gestão de acessos ou tarefas alheias')
            ok(mixed.call('auth/area.php', 'PATCH', dict(area='agricultor'))[0] == 200
               and mixed.call('auth/area.php', 'PATCH', dict(area='administrativa'))[0] == 200,
               'E — alternar área conserva as permissões por vínculo')

            status, body = owner.call(f'membros.php?horta={h1}', 'DELETE', dict(id_usuario=farmer_id))
            ok(status == 200 and task_detail(farmer, h1, assigned)[0] == 404,
               'D — revogação bloqueia a tarefa na sessão já autenticada')
            for route in [f'plantas.php?horta={h1}', f'monitoramentos.php?horta={h1}', f'dashboard.php?horta={h1}',
                          f'registros.php?horta={h1}', f'tarefa_historico.php?horta={h1}&id_tarefa={assigned}',
                          f'tarefa_intervencoes.php?horta={h1}&id_tarefa={assigned}',
                          f'operacional.php?recurso=detalhes&horta={h1}&planta={plant}']:
                ok(farmer.call(route)[0] == 404, 'D — bloqueio após revogação em ' + route.split('?')[0])
            ok(summary(farmer)['hortas'] == 1 and farmer.call(f'dashboard.php?horta={h2}')[0] == 200,
               'D — outra horta continua acessível na mesma sessão')
            history = task_detail(owner, h1, assigned)[1]
            ok(history['intervencoes']['itens'][0]['id_autor'] == farmer_id
               and history['data']['responsavel_sem_acesso'], 'D — autoria e histórico preservados, responsável revogado sinalizado')

            for route in [f'plantas.php?horta={h2}&id={plant}', f'tarefas.php?horta={h2}&id={task}',
                          f'tarefa_historico.php?horta={h2}&id_tarefa={task}']:
                ok(farmer.call(route)[0] == 404, 'Identificadores cruzados não revelam dados em ' + route.split('?')[0])
            ok(mixed.call('perfil.php', 'PATCH', dict(id_usuario=owner_id, nome='Indevido'))[0] == 422,
               'Perfil rejeita tentativa de editar outro usuário')
            ok(mixed.call('perfil.php', 'PATCH', dict(perfil_entrada='administrador', papel='proprietario'))[0] == 422,
               'Perfil rejeita campos usados para elevar permissões')
            ok(farmer.call("plantas.php?horta=1%20OR%201=1")[0] == 422
               and owner.call(f'tarefas.php?horta={h1}&responsavel=1%20OR%201=1')[0] == 422,
               'Identificadores não aceitam expressões SQL')
            ok(mixed.call(f'registros.php?horta={h1}', 'POST', dict(tipo='observacao', descricao='Teste'), csrf=False)[0] == 403,
               'Operação autorizada ainda exige CSRF')
            text = 'Inspeção manual\nFolhas observadas <img src=x onerror=alert(1)>'
            ok(mixed.call(f'registros.php?horta={h1}', 'POST', dict(tipo='observacao', descricao=text, id_planta=plant))[0] == 201
               and mixed.call(f'registros.php?horta={h1}')[1]['data'][0]['descricao'] == text,
               'Formulário operacional aceita observação multilinha sem alterar seu conteúdo')
            ok(mixed.call(f'registros.php?horta={h1}', 'POST', dict(tipo='observacao', descricao='Texto\x00inválido'))[0] == 422,
               'Texto multilinha continua rejeitando caracteres de controle inválidos')
            for path in ['/php/config/database.php', '/php/services/tasks.php', '/php/storage/plantas.json',
                         '/php/config/installation.local.php', '/.env', '/.git/config', '/database/hydroponex.sql',
                         '/server/router.php']:
                try:
                    response = urllib.request.urlopen(url + path)
                except urllib.error.HTTPError as error:
                    response = error
                with response:
                    ok(response.status in (403, 404), 'Arquivo interno protegido em ' + path)
            ok(token.encode() not in log_path.read_bytes() and other_token.encode() not in log_path.read_bytes(),
               'Logs HTTP não contêm tokens de convite')
            ok(history['intervencoes']['itens'][0]['descricao'].endswith('<img src=x onerror=alert(1)>'),
               'Texto de intervenção mantém o conteúdo para apresentação segura pelo frontend')
        finally:
            for user in users:
                db('DELETE a FROM equipe_convite_horta a JOIN equipe_convite c ON c.id_convite=a.id_convite WHERE c.id_emissor=?', user)
                db('DELETE FROM equipe_convite WHERE id_emissor=?', user)
            for garden in gardens:
                for table in ['tarefa_intervencao', 'tarefa_historico', 'horta_tarefa', 'horta_registro', 'planta', 'usuario_horta', 'horta']:
                    db(f'DELETE FROM {table} WHERE id_horta=?', garden)
            for user in users:
                db('DELETE FROM usuario WHERE id_usuario=?', user)
            db('DELETE FROM auth_limite')
    print(f'{checks} verificações dos fluxos A-E e segurança integrados aprovadas.')


if __name__ == '__main__':
    main()
