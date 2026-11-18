"""Gestão de tarefas em MariaDB 1.7.0 com duas instâncias PHP e base descartável."""
import argparse
from concurrent.futures import ThreadPoolExecutor
from contextlib import ExitStack
from datetime import datetime, timedelta, timezone
import json
import os
from pathlib import Path
import secrets
import shutil
import subprocess
import tempfile
import threading
import time
import urllib.error
from equipe import Client, port

ROOT = Path(__file__).resolve().parents[1]


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--executar', action='store_true')
    parser.add_argument('--php', default='php')
    args = parser.parse_args()
    if not args.executar:
        parser.error('Use --executar somente com uma base exclusiva de teste.')
    env = os.environ.copy()
    bridge = """require 'public/php/config/database.php';
    $i=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
    $p=database_connection();$s=$p->prepare($i[0]);$s->execute($i[1]);
    echo json_encode($s->columnCount()?$s->fetchAll():[],JSON_THROW_ON_ERROR);"""

    def db(sql, *params):
        result = subprocess.run([args.php, '-r', bridge], cwd=ROOT, env=env, capture_output=True,
                                input=json.dumps([sql, params]), text=True, encoding='utf-8')
        if result.returncode:
            raise RuntimeError('Falha no banco de teste.')
        return json.loads(result.stdout)

    name = db('SELECT DATABASE() AS nome')[0]['nome']
    if not name.endswith(('_test', '_tests')) or db('SELECT 1 FROM usuario LIMIT 1'):
        parser.error('Use uma base sem usuários, terminada em _test ou _tests.')
    assert db("SELECT 1 FROM schema_version WHERE versao='1.7.0'")
    checks = 0
    users = []
    gardens = []

    def ok(condition, label):
        nonlocal checks
        assert condition, label
        checks += 1
        print('OK:', label, flush=True)

    with ExitStack() as stack:
        directory = Path(stack.enter_context(tempfile.TemporaryDirectory(prefix='hx-tasks-')))
        app = directory / 'app'
        shutil.copytree(ROOT / 'public', app, ignore=shutil.ignore_patterns('installation.local.php'))
        clients = []
        for index in range(2):
            http_port = port()
            output = stack.enter_context((directory / f'http-{index}.log').open('wb'))
            server = subprocess.Popen([args.php, '-S', f'127.0.0.1:{http_port}', 'server/router.php'],
                                      cwd=app, env=env, stdout=output, stderr=output)
            stack.callback(server.wait, 10)
            stack.callback(server.terminate)
            client = Client(f'http://127.0.0.1:{http_port}')
            for _ in range(50):
                try:
                    client.call('session.php')
                    break
                except urllib.error.URLError:
                    time.sleep(.1)
            else:
                raise RuntimeError('PHP indisponível.')
            clients.append(client)
        owner, parallel = clients
        farmer, colleague, outsider, reader = [Client(owner.url) for _ in range(4)]

        def create(client, resource, body, garden=None):
            status, result = client.call(resource + '.php' + (f'?horta={garden}' if garden else ''), 'POST', body)
            assert status == 201, result
            return result['data']

        def detail(client, garden, task):
            return client.call(f'tarefas.php?horta={garden}&id={task}')

        def change(client, garden, task, action, **fields):
            status, current = detail(client, garden, task)
            if status != 200:
                return status, current
            return client.call(f'tarefas.php?horta={garden}', 'PATCH',
                               dict(id_tarefa=task, versao=current['data']['versao'], acao=action, **fields))

        try:
            emails = []
            for index, client in enumerate([owner, farmer, colleague, outsider, reader]):
                address = f'tarefa-{index}-{secrets.token_hex(4)}@example.test'
                emails.append(address)
                client.register(address, 'administrador' if index in (0, 3) else 'agricultor')
                users.append(db('SELECT id_usuario FROM usuario WHERE email=?', address)[0]['id_usuario'])
            owner_id, farmer_id, colleague_id, _, reader_id = users
            parallel.login(emails[0])
            garden = create(owner, 'hortas', {'nome': 'Equipe'})['id_horta']
            foreign = create(outsider, 'hortas', {'nome': 'Terceiros'})['id_horta']
            gardens.extend([garden, foreign])
            for user, role in [(farmer_id, 'agricultor'), (colleague_id, 'agricultor'), (reader_id, 'leitor')]:
                db('INSERT INTO usuario_horta (id_horta,id_usuario,papel) VALUES (?,?,?)', garden, user, role)
            plant = create(owner, 'plantas', {'nome': 'Em alerta', 'bancada': 'A', 'status': 'Alerta'}, garden)['id_planta']
            otherplant = create(outsider, 'plantas', {'nome': 'Privada', 'bancada': 'P'}, foreign)['id_planta']
            route = f'tarefas.php?horta={garden}'
            ok(farmer.call(route, 'POST', {'titulo': 'Indevida'})[0] == 403, 'agricultor não cria tarefas')
            ok(outsider.call(route, 'POST', {'titulo': 'Indevida'})[0] == 404, 'administrador não cria em horta alheia')
            ok(owner.call(route, 'POST', {'titulo': 'CSRF'}, csrf=False)[0] == 403, 'criação exige CSRF')
            ok(owner.call(route, 'POST', {'titulo': 'Inválida', 'id_planta': otherplant})[0] == 422, 'planta de outra horta é rejeitada')
            ok(owner.call(route, 'POST', {'titulo': 'Inválida', 'id_responsavel': reader_id})[0] == 422, 'leitor não recebe tarefa')
            ok(owner.call(route, 'POST', {'titulo': 'Inválida', 'id_responsavel': users[3]})[0] == 422, 'responsável sem vínculo na horta é rejeitado')
            ok(owner.call(route, 'POST', {'titulo': 'Inválida', 'prioridade': 'urgente'})[0] == 422, 'prioridades são validadas')
            task = create(owner, 'tarefas', {'titulo': 'Inspecionar', 'id_planta': plant, 'origem_problema': 'estado_planta',
                                           'chave_criacao': secrets.token_hex(16)}, garden)
            tid = task['id_tarefa']
            ok(task['id_responsavel'] is None and task['prioridade'] == 'media', 'criação aceita tarefa sem responsável e sem prioridade inferida')
            ok(owner.call(route, 'POST', {'titulo': 'Duplicada', 'id_planta': plant, 'origem_problema': 'estado_planta'})[0] == 409,
               'problema de planta com tarefa aberta não é duplicado')
            ok(change(owner, garden, tid, 'editar', id_responsavel=farmer_id, justificativa='Distribuição do trabalho')[0] == 200,
               'administrador atribui tarefa ao agricultor')
            ok(detail(colleague, garden, tid)[0] == 404, 'agricultor não consulta tarefa de outra pessoa')
            ok(change(farmer, garden, tid, 'concluir')[0] == 403, 'agricultor não conclui tarefa administrativamente')
            ok(change(owner, garden, tid, 'concluir')[0] == 403, 'conclusão não pode pular a verificação')
            ok(change(owner, garden, tid, 'iniciar')[0] == 403, 'gestão não executa silenciosamente como outro responsável')
            ok(change(farmer, garden, tid, 'iniciar')[0] == 200, 'responsável inicia sua tarefa')
            ok(farmer.call(route, 'PATCH', {'id_tarefa': tid, 'acao': 'verificar'})[0] == 422, 'atualização exige a versão consultada')
            current = detail(farmer, garden, tid)[1]['data']
            work = dict(id_tarefa=tid, versao=current['versao'], tipo='intervencao', descricao='Inspeção visual\nFolhas examinadas',
                        observacoes='Medição será conferida', realizada_em=datetime.now(timezone.utc).isoformat())
            ok(farmer.call(f'tarefa_intervencoes.php?horta={garden}', 'POST', work)[0] == 201, 'intervenção registra procedimento e autoria')
            ok(farmer.call(f'tarefa_intervencoes.php?horta={garden}', 'POST', work)[0] == 409, 'reenvio de intervenção não duplica o registro')
            large_work = dict(work, versao=detail(farmer, garden, tid)[1]['data']['versao'],
                              descricao='🌱' * 2000, observacoes='🌿' * 1000)
            ok(farmer.call(f'tarefa_intervencoes.php?horta={garden}', 'POST', large_work)[0] == 201,
               'campos completos em UTF-8 são aceitos também em JSON escapado')
            ok(farmer.call(f'tarefa_intervencoes.php?horta={garden}', 'POST', dict(large_work, descricao='x' * 50000))[0] == 413,
               'payload excedente mantém um limite de tamanho')
            ok(change(farmer, garden, tid, 'editar', prioridade='critica')[0] == 403, 'agricultor não edita prioridade')
            ok(change(farmer, garden, tid, 'verificar')[0] == 200, 'responsável solicita verificação')
            ok(change(owner, garden, tid, 'corrigir')[0] == 422, 'correção exige justificativa')
            ok(change(owner, garden, tid, 'corrigir', justificativa='Conferir também o reservatório')[0] == 200, 'gestão solicita correções')
            ok(change(farmer, garden, tid, 'verificar')[0] == 200 and change(owner, garden, tid, 'concluir')[0] == 200,
               'gestão aprova serviço em verificação')
            closed = detail(owner, garden, tid)[1]
            ok(closed['data']['concluida_em'] and db('SELECT status_planta FROM planta WHERE id_planta=?', plant)[0]['status_planta'] == 'Alerta',
               'conclusão persistida preserva o estado da planta')
            events = [row['evento'] for row in closed['historico']['itens']]
            ok(all(event in events for event in ['criacao', 'atribuicao', 'inicio', 'intervencao', 'verificacao', 'correcao', 'aprovacao']),
               'histórico registra o ciclo completo')
            ok(closed['intervencoes']['itens'][0]['id_autor'] == farmer_id, 'intervenção preserva autor autenticado')
            ok(change(owner, garden, tid, 'reabrir', justificativa='Inspeção complementar')[0] == 200,
               'conclusão pode ser reaberta com justificativa')
            ok(change(owner, garden, tid, 'cancelar')[0] == 422, 'cancelamento exige justificativa')
            ok(change(owner, garden, tid, 'cancelar', justificativa='Substituída por outro procedimento')[0] == 200,
               'cancelamento preserva histórico')
            ok(change(farmer, garden, tid, 'iniciar')[0] == 403, 'tarefa cancelada não reinicia')
            for resource in ['tarefa_historico', 'tarefa_intervencoes']:
                ok(owner.call(f'{resource}.php?horta={garden}&id_tarefa={tid}', 'DELETE', {})[0] == 405,
                   resource + ' não aceita exclusão')
                ok(owner.call(f'{resource}.php?horta={garden}&id_tarefa={tid}', 'PATCH', {'descricao': 'Alteração indevida'})[0] == 405,
                   resource + ' não permite reescrever registros')
            own = create(owner, 'tarefas', {'titulo': 'Trabalho individual', 'id_responsavel': owner_id}, garden)['id_tarefa']
            ok(all(change(owner, garden, own, action)[0] == 200 for action in ['iniciar', 'verificar', 'concluir']),
               'administrador sozinho executa e aprova sua tarefa')
            late = create(owner, 'tarefas', {'titulo': 'Prazo vencido', 'id_responsavel': farmer_id, 'prioridade': 'alta',
                                           'prazo_em': (datetime.now(timezone.utc) - timedelta(days=1)).isoformat()}, garden)['id_tarefa']
            ok(detail(owner, garden, late)[1]['data']['atrasada'], 'prazo vencido é sinalizado')
            ok(len(owner.call(route + '&atrasadas=1&prioridade=alta&responsavel=' + str(farmer_id))[1]['data']) == 1,
               'filtros de responsável, prioridade e atraso combinados')
            ok(owner.call(f'membros.php?horta={garden}', 'DELETE', {'id_usuario': farmer_id})[0] == 200,
               'vínculo pode ser removido sem apagar tarefas')
            ok(detail(owner, garden, late)[1]['data']['responsavel_sem_acesso'] and detail(farmer, garden, late)[0] == 404,
               'revogação sinaliza atribuição e bloqueia sessão existente')
            ok(change(owner, garden, late, 'editar', id_responsavel=colleague_id, justificativa='Novo responsável')[0] == 200,
               'gestão reatribui tarefa após remoção do vínculo')
            ok(change(colleague, garden, late, 'iniciar')[0] == 200, 'novo responsável executa tarefa reatribuída')

            race = create(owner, 'tarefas', {'titulo': 'Concorrência'}, garden)
            barrier = threading.Barrier(2)

            def update(client):
                barrier.wait(timeout=10)
                return client.call(route, 'PATCH', {'id_tarefa': race['id_tarefa'], 'versao': race['versao'],
                                                   'acao': 'editar', 'titulo': secrets.token_hex(8)})[0]

            with ThreadPoolExecutor(max_workers=2) as pool:
                outcomes = sorted(pool.map(update, [owner, parallel]))
            ok(outcomes == [200, 409], 'atualizações concorrentes preservam uma alteração e detectam conflito')
            payload = {'titulo': 'Requisição repetida', 'chave_criacao': secrets.token_hex(16)}
            with ThreadPoolExecutor(max_workers=2) as pool:
                outcomes = list(pool.map(lambda client: client.call(route, 'POST', payload), [owner, parallel]))
            ok(all(row[0] == 201 for row in outcomes) and outcomes[0][1]['data']['id_tarefa'] == outcomes[1][1]['data']['id_tarefa'],
               'criação concorrente com mesma chave é idempotente')
            ok(owner.call(route, 'POST', dict(payload, titulo='Outro conteúdo'))[0] == 409, 'chave de criação não aceita conteúdo diferente')
            for index in range(21):
                change(owner, garden, race['id_tarefa'], 'editar', descricao=f'Revisão {index}')
            status, history = owner.call(f'tarefa_historico.php?horta={garden}&id_tarefa={race["id_tarefa"]}&pagina=2')
            ok(status == 200 and len(history['data']['itens']) > 0, 'histórico é paginado sem perder eventos')
            sensor = create(owner, 'sensores', {'nome': 'pH', 'tipo': 'ph', 'unidade': 'pH', 'id_planta': plant,
                                               'limite_min': 5, 'limite_max': 7}, garden)['id_sensor']
            create(owner, 'monitoramentos', {'origem': 'manual', 'data_hora': datetime.now(timezone.utc).isoformat(),
                                            'leituras': [{'id_sensor': sensor, 'valor': 9, 'tipo_medicao': 'ph', 'unidade': 'pH'}]}, garden)
            reading = db('SELECT id_leitura FROM monitoramento_sensor WHERE id_sensor=?', sensor)[0]['id_leitura']
            reading_task = create(owner, 'tarefas', {'titulo': 'Conferir leitura', 'origem_problema': 'leitura', 'id_leitura': reading}, garden)
            ok(reading_task['id_planta'] == plant, 'leitura anormal identificada associa tarefa à planta correta')
            ok(owner.call(route, 'POST', {'titulo': 'Duplicada', 'origem_problema': 'leitura', 'id_leitura': reading})[0] == 409,
               'mesma leitura não gera duas tarefas abertas')
            ok(owner.call(f'tarefas.php?horta={foreign}', 'POST',
                          {'titulo': 'Indevida', 'origem_problema': 'leitura', 'id_leitura': reading})[0] == 404,
               'leitura não contorna autorização da horta')
        finally:
            for garden in gardens:
                for table in ['tarefa_intervencao', 'tarefa_historico', 'horta_tarefa', 'monitoramento_sensor',
                              'monitoramento', 'sensor', 'planta', 'usuario_horta', 'horta']:
                    db(f'DELETE FROM {table} WHERE id_horta=?', garden)
            for user in users:
                db('DELETE FROM usuario WHERE id_usuario=?', user)
            db('DELETE FROM auth_limite')
    print(f'{checks} verificações de gestão de tarefas aprovadas.')


if __name__ == '__main__':
    main()
