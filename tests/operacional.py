"""Painel operacional HTTP em uma base exclusiva sem usuários, com schema 1.6.0."""
import argparse
from contextlib import ExitStack
from datetime import datetime, timedelta, timezone
import json
import os
from pathlib import Path
import secrets
import shutil
import subprocess
import sys
import tempfile
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
        parser.error('Confirme o uso de uma base descartável com --executar.')
    env = os.environ.copy()
    bridge = """require 'public/php/config/database.php';
    $i=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
    $p=database_connection();$s=$p->prepare($i[0]);$s->execute($i[1]);
    echo json_encode($s->columnCount()?$s->fetchAll():[],JSON_THROW_ON_ERROR);"""

    def db(sql, *params):
        process = subprocess.run([args.php, '-r', bridge], cwd=ROOT, env=env, capture_output=True,
                                 input=json.dumps([sql, params]), text=True, encoding='utf-8')
        if process.returncode:
            raise RuntimeError('Falha SQL no banco descartável.')
        return json.loads(process.stdout)

    name = db('SELECT DATABASE() AS nome')[0]['nome']
    if not name.endswith(('_test', '_tests')) or db('SELECT 1 FROM usuario LIMIT 1'):
        parser.error('Use uma base exclusiva sem usuários com nome terminado em _test ou _tests.')
    assert db("SELECT 1 FROM schema_version WHERE versao='1.6.0'"), 'Importe o esquema 1.6.0.'
    checks = 0
    users = []
    gardens = []

    def ok(condition, label):
        nonlocal checks
        assert condition, label
        checks += 1
        print('OK:', label, flush=True)

    with ExitStack() as stack:
        directory = Path(stack.enter_context(tempfile.TemporaryDirectory(prefix='hx-operacional-')))
        app = directory / 'app'
        shutil.copytree(ROOT / 'public', app, ignore=shutil.ignore_patterns('installation.local.php'))
        http_port = port()
        output = stack.enter_context((directory / 'http.log').open('wb'))
        server = subprocess.Popen([args.php, '-S', f'127.0.0.1:{http_port}', 'server/router.php'],
                                  cwd=app, env=env, stdout=output, stderr=output)
        stack.callback(server.wait, 10)
        stack.callback(server.terminate)
        clients = [Client(f'http://127.0.0.1:{http_port}') for _ in range(5)]
        owner, farmer, outsider, empty, reader = clients
        for _ in range(50):
            try:
                empty.call('session.php')
                break
            except urllib.error.URLError:
                time.sleep(.1)
        else:
            raise RuntimeError('Servidor PHP indisponível.')

        def uid(email):
            return db('SELECT id_usuario FROM usuario WHERE email=?', email)[0]['id_usuario']

        def get(client, resource='resumo', **values):
            from urllib.parse import urlencode
            return client.call('operacional.php?' + urlencode(dict(recurso=resource, **values)))

        def data(client, resource='resumo', **values):
            status, result = get(client, resource, **values)
            assert status == 200, result
            return result['data']

        def create(client, resource, body, garden=None):
            route = resource + '.php' + (f'?horta={garden}' if garden else '')
            status, result = client.call(route, 'POST', body)
            assert status == 201, result
            return result['data']

        try:
            ok(get(empty)[0] == 401, 'visitante não consulta o painel')
            for index, client in enumerate(clients):
                email = f'op-{index}-{secrets.token_hex(4)}@example.test'
                client.register(email, 'administrador' if index in (0, 2) else 'agricultor')
                users.append(uid(email))
            owner_id, farmer_id, outsider_id, _, reader_id = users
            h1 = create(owner, 'hortas', {'nome': 'Horta A'})['id_horta']
            h2 = create(owner, 'hortas', {'nome': 'Horta B'})['id_horta']
            h3 = create(owner, 'hortas', {'nome': 'Vazia'})['id_horta']
            foreign = create(outsider, 'hortas', {'nome': 'Privada'})['id_horta']
            gardens.extend([h1, h2, h3, foreign])
            ok(data(empty) == dict(hortas=0, atencao=0, alerta=0, pendentes=0, em_andamento=0, concluidas_recentes=0),
               'agricultor sem hortas tem indicadores zerados')
            ok(data(owner)['hortas'] == 3, 'administrador trabalhando sozinho consulta as próprias hortas')
            ok(data(owner, 'hortas', horta=h3)[0]['plantas'] == 0, 'horta sem plantas tem contagem zero')
            db("INSERT INTO usuario_horta (id_usuario,id_horta,papel) VALUES (?,?,'agricultor')", farmer_id, h1)
            ok(data(farmer)['hortas'] == 1, 'agricultor com uma horta')
            db("INSERT INTO usuario_horta (id_usuario,id_horta,papel) VALUES (?,?,'agricultor')", farmer_id, h2)
            db("INSERT INTO usuario_horta (id_usuario,id_horta,papel) VALUES (?,?,'leitor')", reader_id, h1)
            ok(data(farmer)['hortas'] == 2, 'agricultor com várias hortas')
            p1 = create(owner, 'plantas', {'nome': 'Alface Atenção', 'bancada': 'A1', 'tipo': 'Folhosa', 'status': 'Atenção'}, h1)['id_planta']
            p2 = create(owner, 'plantas', {'nome': 'Rúcula Alerta', 'bancada': 'B1', 'tipo': 'Folhosa', 'status': 'Alerta'}, h2)['id_planta']
            normal = create(owner, 'plantas', {'nome': 'Normal sem leitura', 'bancada': 'A2', 'status': 'Normal'}, h1)['id_planta']
            private = create(outsider, 'plantas', {'nome': 'Privada', 'bancada': 'P', 'status': 'Alerta'}, foreign)['id_planta']
            summary = data(farmer)
            ok(summary['atencao'] == summary['alerta'] == 1, 'indicadores usam estados persistidos')
            overview = data(farmer, 'visao', horta=h1)
            ok(len(overview['hortas']) == 2 and overview['resumo']['hortas'] == 1
               and overview['resumo']['atencao'] == 1 and overview['resumo']['alerta'] == 0,
               'visão combinada reaproveita hortas e aplica o escopo dos indicadores')
            plants = data(farmer, 'plantas')['itens']
            ok([p['id_planta'] for p in plants] == [p2, p1], 'problemas começam pelo estado de maior gravidade')
            ok(len(data(farmer, 'plantas', horta=h1, estado='Atenção', tipo='Folhosa')['itens']) == 1, 'filtros por horta, estado e tipo combinados')
            ok(data(owner, 'plantas', horta=h3)['itens'] == [], 'horta sem problemas tem lista vazia')
            ok(len(data(farmer, 'plantas', estado='todas')['itens']) == 3, 'consulta de todas as plantas inclui Normal')
            for resource in ['resumo', 'hortas', 'plantas', 'tarefas', 'detalhes', 'registros']:
                ok(get(farmer, resource, horta=foreign, planta=private)[0] == 404, f'{resource} bloqueia horta alheia')
            ok(get(farmer, 'detalhes', horta=h1, planta=p2)[0] == 404, 'detalhes rejeitam planta de outra horta')
            ok(get(farmer, 'plantas', estado='inexistente')[0] == 422, 'estado inválido é rejeitado')
            ok(get(farmer, 'plantas', pagina='1 OR 1=1')[0] == 422, 'paginação não aceita expressão SQL')
            detail = data(farmer, 'detalhes', horta=h1, planta=normal)
            ok(detail['planta']['medicao_cadastro'] == 'ausente' and detail['leituras'] == [], 'planta Normal sem dados mantém ausência explícita')
            task = create(owner, 'tarefas', {'titulo': 'Inspecionar alface', 'id_responsavel': farmer_id, 'id_planta': p1}, h1)['id_tarefa']
            create(owner, 'tarefas', {'titulo': 'Trabalho individual', 'id_responsavel': owner_id, 'id_planta': p1}, h1)
            ok(data(farmer)['pendentes'] == 1 and data(owner)['pendentes'] == 1, 'cada painel conta tarefas da própria conta')
            ok(len(data(farmer, 'detalhes', horta=h1, planta=p1)['tarefas']['itens']) == 1, 'detalhes mostram apenas tarefas próprias relacionadas')
            ok(data(reader, 'tarefas')['itens'] == [], 'leitor não recebe tarefas operacionais')
            route = f'tarefas.php?horta={h1}'
            ok(farmer.call(route, 'PATCH', {'id_tarefa': task, 'versao': 1, 'acao': 'iniciar'})[0] == 200
               and data(farmer)['em_andamento'] == 1, 'tarefa em andamento é persistida')
            farmer.call(route, 'PATCH', {'id_tarefa': task, 'versao': 2, 'acao': 'verificar'})
            owner.call(route, 'PATCH', {'id_tarefa': task, 'versao': 3, 'acao': 'concluir'})
            completed = db('SELECT concluida_em FROM horta_tarefa WHERE id_tarefa=?', task)[0]['concluida_em']
            owner.call(route, 'PATCH', {'id_tarefa': task, 'versao': 3, 'acao': 'concluir'})
            ok(completed and db('SELECT concluida_em FROM horta_tarefa WHERE id_tarefa=?', task)[0]['concluida_em'] == completed,
               'repetir conclusão preserva a data')
            ok(data(farmer)['concluidas_recentes'] == 1 and data(farmer)['atencao'] == 1,
               'conclusão recente é contada sem resolver o estado da planta')
            ok(len(data(farmer, 'tarefas', estado='concluida')['itens']) == 1, 'filtro de tarefas concluídas')
            owner.call(route, 'PATCH', {'id_tarefa': task, 'versao': 4, 'acao': 'reabrir', 'justificativa': 'Nova inspeção'})
            ok(data(farmer)['concluidas_recentes'] == 0, 'reabrir tarefa remove a conclusão atual')
            ok(owner.call(route, 'POST', {'titulo': 'Inválida', 'id_responsavel': farmer_id, 'id_planta': p2})[0] == 422,
               'tarefa não associa planta de outra horta')
            record_route = f'registros.php?horta={h1}'
            ok(farmer.call(record_route, 'POST', {'tipo': 'observacao', 'descricao': 'Folhas observadas', 'id_planta': p1})[0] == 201,
               'observação vinculada à planta')
            ok(farmer.call(record_route, 'POST', {'tipo': 'intervencao', 'descricao': 'Inspeção', 'id_planta': p2})[0] == 422,
               'registro rejeita planta de outra horta')
            ok(reader.call(record_route, 'POST', {'tipo': 'observacao', 'descricao': 'Indevida', 'id_planta': p1})[0] == 403,
               'leitor não registra intervenção')
            ok(owner.call(f'plantas_transferir.php?horta={h1}', 'POST', {
                'id': p1, 'horta_destino': h2, 'confirmacao': 'TRANSFERIR'})[0] == 409, 'tarefas e registros impedem transferência mesmo sem sensores')
            sensor = create(owner, 'sensores', {'nome': 'pH', 'tipo': 'ph', 'unidade': 'pH', 'id_planta': p1,
                                              'limite_min': 5, 'limite_max': 7}, h1)['id_sensor']
            for origin, days, value in [('manual', 2, 8), ('simulacao', 0, 6)]:
                date = (datetime.now(timezone.utc) - timedelta(days=days)).isoformat()
                create(owner, 'monitoramentos', {'origem': origin, 'data_hora': date, 'leituras': [
                    {'id_sensor': sensor, 'valor': value, 'tipo_medicao': 'ph', 'unidade': 'pH'}]}, h1)
            detail = data(farmer, 'detalhes', horta=h1, planta=p1)
            ok(any(row['estado'] == 'alerta' and row['atualidade'] == 'desatualizada' for row in detail['leituras']),
               'limite excedido em leitura antiga permanece identificado')
            ok(detail['leituras'][0]['simulado'] and detail['planta']['status_planta'] == 'Atenção',
               'simulação explícita não altera o estado da planta')
            ok(detail['registros']['itens'][0]['descricao'] == 'Folhas observadas', 'detalhes recuperam registro associado')
            listed = data(farmer, 'plantas', horta=h1)['itens'][0]
            ok(listed['ultima_leitura']['origem'] == 'simulacao', 'lista mostra última leitura diretamente associada')
            for index in range(20):
                db("INSERT INTO horta_registro (id_horta,id_planta,id_autor,tipo,descricao) VALUES (?,?,?,'intervencao',?)",
                   h1, p1, farmer_id, f'Intervenção de teste {index}')
                db("INSERT INTO monitoramento (id_horta,id_usuario,origem,data_hora) VALUES (?,?,'manual',UTC_TIMESTAMP(6))",
                   h1, owner_id)
                mid = db('SELECT MAX(id_monitoramento) AS id FROM monitoramento WHERE id_horta=?', h1)[0]['id']
                db("INSERT INTO monitoramento_sensor (id_monitoramento,id_sensor,id_horta,valor,tipo_medicao,unidade,limite_min,limite_max) VALUES (?,?,?,6,'ph','pH',5,7)",
                   mid, sensor, h1)
            history = data(farmer, 'detalhes', horta=h1, planta=p1)
            ok(len(history['leituras']) == 20 and history['proximo_cursor'], 'detalhes limitam as leituras e oferecem cursor')
            from urllib.parse import urlencode
            status, next_readings = farmer.call('monitoramentos.php?' + urlencode({
                'horta': h1, 'planta': p1, 'cursor': history['proximo_cursor'], 'limite': 20}))
            ok(status == 200 and len(next_readings['data']) == 2, 'histórico continua pela API de monitoramento autorizada')
            records = data(farmer, 'registros', horta=h1, planta=p1, pagina=2)
            ok(history['registros']['mais'] and len(records['itens']) == 1, 'observações e intervenções têm paginação')
            for index in range(21):
                create(owner, 'plantas', {'nome': f'Extra {index}', 'bancada': 'A', 'status': 'Atenção'}, h1)
            page = data(farmer, 'plantas')
            next_page = data(farmer, 'plantas', pagina=2)
            ok(len(page['itens']) == 20 and page['mais'] and next_page['itens'], 'listagem de plantas é paginada')
            ok(not ({p['id_planta'] for p in page['itens']} & {p['id_planta'] for p in next_page['itens']}), 'páginas não repetem plantas')
            ok(owner.call(f'membros.php?horta={h1}', 'DELETE', {'id_usuario': farmer_id})[0] == 200
               and get(farmer, 'detalhes', horta=h1, planta=p1)[0] == 404, 'revogação vale na sessão atual')
            ok(data(farmer)['hortas'] == 1 and data(farmer)['atencao'] == 0, 'indicadores deixam de incluir a horta revogada')
        finally:
            for garden in gardens:
                for table in ('tarefa_intervencao', 'tarefa_historico', 'horta_tarefa', 'monitoramento_sensor', 'monitoramento', 'sensor', 'horta_registro', 'planta', 'usuario_horta', 'horta'):
                    db(f'DELETE FROM {table} WHERE id_horta=?', garden)
            for user in users:
                db('DELETE FROM usuario WHERE id_usuario=?', user)
            db('DELETE FROM auth_limite')
    print(f'{checks} verificações operacionais aprovadas.')


if __name__ == '__main__':
    main()
