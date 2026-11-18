"""Integração de perfis, papéis e colaboração.
Use uma base descartável sem usuários com schema 1.5.0.
python tests/colaboracao.py --executar --php C:/xampp/php/php.exe
"""
import argparse
import http.cookiejar
import json
import os
from pathlib import Path
import secrets
import shutil
import socket
import subprocess
import sys
import tempfile
import time
import urllib.error
import urllib.request

if hasattr(sys.stdout, 'reconfigure'):
    sys.stdout.reconfigure(encoding='utf-8')
parser=argparse.ArgumentParser(description=__doc__)
parser.add_argument('--executar',action='store_true')
parser.add_argument('--php',default='php')
args=parser.parse_args()
if not args.executar: parser.error('Confirme uso do banco descartável com --executar.')
root=Path(__file__).resolve().parents[1]
env=os.environ.copy()
bridge="""require 'public/php/config/database.php';
$i=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);$p=database_connection();
$s=$p->prepare($i['sql']);$s->execute($i['params']);echo json_encode(['rows'=>$s->columnCount()?$s->fetchAll():[],'id'=>$p->lastInsertId()],JSON_THROW_ON_ERROR);"""

def db(sql,params=None):
    p=subprocess.run([args.php,'-r',bridge],input=json.dumps({'sql':sql,'params':params or []}),env=env,cwd=root,capture_output=True,text=True,encoding='utf-8')
    if p.returncode: raise RuntimeError('Falha de acesso ao banco de testes; detalhes internos omitidos.')
    return json.loads(p.stdout)

name=db('SELECT DATABASE() AS nome')['rows'][0]['nome']
if not name.endswith(('_test','_tests')) or db('SELECT COUNT(*) AS n FROM usuario')['rows'][0]['n']!=0:
    raise RuntimeError('Use banco exclusivo de teste, sem usuários, com nome _test/_tests.')
key=env.get('HYDROPONEX_AUTH_KEY','')
if len(key)!=64 or any(c not in '0123456789abcdefABCDEF' for c in key): raise RuntimeError('Chave de autenticação inválida.')
run=secrets.token_hex(8)
password='Horta-'+secrets.token_hex(12)
users=[]; gardens=[]; emails=[]
checks=0
source=root/'public'

def ok(value,label):
    global checks
    if not value: raise AssertionError(label)
    checks+=1; print('OK:',label,flush=True)

class Client:
    def __init__(self,base):
        self.base=base; self.jar=http.cookiejar.CookieJar(); self.csrf=None
        self.opener=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar))
    def request(self,path,method='GET',data=None,csrf=True):
        headers={'Accept':'application/json'}
        if data is not None: headers['Content-Type']='application/json'
        if csrf and self.csrf: headers['X-CSRF-Token']=self.csrf
        req=urllib.request.Request(self.base+path,headers=headers,method=method,data=None if data is None else json.dumps(data).encode())
        try: response=self.opener.open(req,timeout=15)
        except urllib.error.HTTPError as error: response=error
        raw=response.read().decode('utf-8')
        assert 'SQLSTATE' not in raw and 'Stack trace' not in raw and env['HYDROPONEX_DB_PASSWORD'] not in raw
        body=json.loads(raw)
        if body.get('csrfToken'): self.csrf=body['csrfToken']
        return response.status,body
    def register(self, label, profile):
        self.request('/php/api/session.php')
        email = f'{label}-{run}@example.test'
        emails.append(email)
        status, _ = self.request('/php/api/auth/cadastro.php', 'POST', {
            'nome': label, 'email': email, 'senha': password, 'perfil_entrada': profile,
        })
        assert status == 202
        uid = db('SELECT id_usuario FROM usuario WHERE email=?', [email])['rows'][0]['id_usuario']
        users.append(uid)
        return uid, email

    def login(self, email, area):
        return self.request('/php/api/auth/login.php', 'POST', {'email': email, 'senha': password, 'area': area})

    def create(self,body):
        response=self.request('/php/api/hortas.php','POST',body)
        if response[0]==201: gardens.append(response[1]['data']['id_horta'])
        return response

try:
    with tempfile.TemporaryDirectory(prefix='hydroponex-collaboration-http-') as directory:
        temp = Path(directory).resolve()
        assert temp.is_relative_to(Path(tempfile.gettempdir()).resolve())
        app = temp / 'app'
        shutil.copytree(source, app, ignore=shutil.ignore_patterns('installation.local.php'))
        with socket.socket() as sock:
            sock.bind(('127.0.0.1', 0))
            port = sock.getsockname()[1]
        base = f'http://127.0.0.1:{port}'
        with (temp / 'http.log').open('wb') as log:
            server = subprocess.Popen([args.php, '-S', f'127.0.0.1:{port}', 'server/router.php'], cwd=app,
                                      env=env, stdout=log, stderr=log,
                                      creationflags=subprocess.CREATE_NO_WINDOW if os.name == 'nt' else 0)
            try:
                owner, farmer, outsider, reader = [Client(base) for _ in range(4)]
                for _ in range(50):
                    try:
                        outsider.request('/php/api/session.php')
                        break
                    except urllib.error.URLError:
                        time.sleep(.1)
                else:
                    raise RuntimeError('PHP não iniciou.')

                owner_id, owner_email = owner.register('Dono', 'administrador')
                farmer_id, farmer_email = farmer.register('Agricultora', 'agricultor')
                _, outsider_email = outsider.register('Terceiro', 'administrador')
                _, reader_email = reader.register('Leitor', 'agricultor')
                ok(farmer.login(farmer_email, 'administrativa')[0] == 403, 'agricultor operacional não abre área administrativa')
                ok(farmer.request('/php/api/auth/me.php')[0] == 401, 'área negada não cria sessão autenticada')
                ok(farmer.login(farmer_email, 'agricultor')[1]['data']['hortas'] == [], 'agricultor começa sem hortas')
                ok(farmer.create({'nome': 'Indevida'})[0] == 403, 'perfil operacional não cria horta')
                ok(owner.login(owner_email, 'administrativa')[0] == 200, 'administrador entra sem vínculos prévios')
                ok(outsider.login(outsider_email, 'administrativa')[0] == 200, 'outro administrador pode abrir sua própria área')
                ok(reader.login(reader_email, 'agricultor')[0] == 200, 'leitor entra na área operacional')
                first = owner.create({'nome': 'Operacional'})
                second = owner.create({'nome': 'Gestão compartilhada'})
                ok(first[0] == second[0] == 201, 'proprietário cria hortas sem cadastrar agricultores')
                garden, other = [response[1]['data']['id_horta'] for response in (first, second)]
                def route(resource, horta=garden):
                    return f'/php/api/{resource}.php?horta={horta}'
                ok(outsider.request(route('dashboard'))[0] == 404, 'administrador sem vínculo não acessa dados de terceiros')
                ok(outsider.request(route('convites'))[0] == 404, 'administrador sem vínculo não consulta convites antigos')
                ok(owner.request(route('convites'), 'POST', {'email': farmer_email, 'papel': 'agricultor'})[0] == 405, 'emissão de novos códigos manuais foi encerrada')
                ok(owner.request('/php/api/equipe.php', 'POST', {'email': farmer_email, 'hortas': [garden]}, csrf=False)[0] == 403, 'emissão de convite por e-mail exige CSRF')
                def invite(horta, email, role):
                    code = secrets.token_hex(32)
                    stored = db('INSERT INTO horta_convite (id_horta,id_criador,email,papel,codigo_hash,expira_em) '
                                'VALUES (?,?,?,?,UNHEX(SHA2(?,256)),UTC_TIMESTAMP(6)+INTERVAL 7 DAY)',
                                [horta, owner_id, email, role, code])
                    return {'id_convite': int(stored['id']), 'codigo': code}
                def accept(client, code, csrf=True):
                    return client.request('/php/api/convites.php', 'PATCH', {'codigo': code}, csrf=csrf)[0]
                pending = invite(garden, farmer_email, 'agricultor')
                code = pending['codigo']
                ok(len(code) == 64 and code not in json.dumps(owner.request(route('convites'))[1]), 'código legado não é exposto na listagem')
                ok(accept(outsider, code) == 404, 'código de outra conta é recusado')
                ok(accept(farmer, code, csrf=False) == 403, 'aceitação exige CSRF')
                ok(accept(farmer, code) == 200 and accept(farmer, code) == 404, 'convite é aceito uma única vez')
                ok(farmer.request(route('plantas'))[0] == 200 and farmer.request(route('dashboard'))[0] == 200, 'agricultor consulta plantas e alertas da horta')
                ok(farmer.request(route('dashboard', other))[0] == 404, 'vínculo não libera outra horta')
                ok(farmer.request(route('convites'))[0] == 403 and farmer.request(route('membros'))[0] == 403, 'agricultor não gerencia convites ou membros')
                for resource, payload in [('plantas', {'nome': 'Indevida', 'bancada': 'A'}), ('reservatorios', {'nome': 'Indevido'}), ('sensores', {'nome': 'Indevido'}), ('monitoramentos', {'leituras': [{'id_sensor': 1, 'valor': 6.5}], 'origem': 'manual', 'data_hora': '2025-01-01T12:00:00Z'}), ('culturas_horta', {'id_cultura': 1})]:
                    status = farmer.request(route(resource), 'POST', payload)[0]
                    ok(status == 403, f'papel operacional não altera {resource}')
                ok(farmer.request(f'/php/api/hortas.php?id={garden}', 'DELETE', {'confirmacao': 'EXCLUIR'})[0] == 403, 'agricultor não exclui a horta')
                ok(farmer.request('/php/api/auth/area.php', 'PATCH', {'area': 'administrativa'})[0] == 403, 'troca de área também verifica autorização')
                status, task = owner.request(route('tarefas'), 'POST', {'titulo': 'Revisar plantas', 'id_responsavel': farmer_id})
                ok(status == 201, 'proprietário atribui tarefa a membro operacional')
                task_id = task['data']['id_tarefa']
                own_task = owner.request(route('tarefas'), 'POST', {'titulo': 'Revisar estrutura', 'id_responsavel': owner_id})[1]['data']['id_tarefa']
                ok([x['id_tarefa'] for x in farmer.request(route('tarefas'))[1]['data']] == [task_id], 'agricultor lista apenas suas tarefas')
                ok(farmer.request(route('tarefas'), 'PATCH', {'id_tarefa': own_task, 'estado': 'concluida'})[0] == 404, 'agricultor não altera tarefa de terceiro')
                ok(farmer.request(route('tarefas'), 'PATCH', {'id_tarefa': task_id, 'versao': 1, 'acao': 'iniciar'})[0] == 200, 'agricultor inicia tarefa própria')
                ok(farmer.request(route('registros'), 'POST', {'tipo': 'intervencao', 'descricao': 'Revisão visual realizada'})[0] == 201, 'agricultor registra intervenção')
                ok(farmer.request(route('registros'), 'DELETE', {'id_registro': 1})[0] == 405, 'histórico de registros não é apagável pela API')
                ok(farmer.request(route('registros'), 'POST', {'tipo': 'observacao', 'descricao': 'Texto', 'id_autor': owner_id})[0] == 422, 'autor de registro vem da sessão')
                ok(accept(farmer, invite(other, farmer_email, 'gestor')['codigo']) == 200, 'mesma conta aceita outro papel em outra horta')
                ok(farmer.request('/php/api/auth/area.php', 'PATCH', {'area': 'administrativa'})[0] == 200, 'gestor alterna para área administrativa sem novo login')
                ok(farmer.request('/php/api/auth/me.php')[1]['data']['area'] == 'administrativa', 'área selecionada persiste na sessão')
                ok(farmer.request(f'/php/api/hortas.php?id={other}', 'PATCH', {'nome': 'Editada pelo gestor'})[0] == 200, 'gestor administra horta autorizada')
                ok(farmer.request(f'/php/api/hortas.php?id={garden}', 'PATCH', {'nome': 'Indevida'})[0] == 403, 'área administrativa não eleva papel operacional')
                ok(farmer.request(route('membros', other))[0] == 403, 'gestor não gerencia permissões')
                ok(farmer.request(route('tarefas', other), 'PATCH', {'id_tarefa': task_id, 'estado': 'pendente'})[0] == 404, 'gestor não altera tarefa de outra horta')
                cancelled = invite(garden, reader_email, 'leitor')
                ok(owner.request(route('convites'), 'DELETE', {'id_convite': cancelled['id_convite']})[0] == 200 and accept(reader, cancelled['codigo']) == 404, 'convite revogado não cria vínculo')
                expired = invite(garden, reader_email, 'leitor')
                db('UPDATE horta_convite SET expira_em=UTC_TIMESTAMP(6)-INTERVAL 1 SECOND WHERE id_convite=?', [expired['id_convite']])
                ok(accept(reader, expired['codigo']) == 404, 'convite expirado não cria vínculo')
                ok(accept(reader, invite(garden, reader_email, 'leitor')['codigo']) == 200, 'convite de leitor mantém o papel legado')
                ok(reader.request(route('registros'))[0] == 200 and reader.request(route('tarefas'))[0] == 403, 'leitor consulta registros, com tarefas restritas')
                ok(reader.request(route('registros'), 'POST', {'tipo': 'observacao', 'descricao': 'Indevida'})[0] == 403, 'leitor não escreve registros')
                ok(owner.request(route('membros'), 'DELETE', {'id_usuario': owner_id})[0] == 403, 'proprietário permanece protegido')
                ok(owner.request(route('membros', other), 'PATCH', {'id_usuario': farmer_id, 'papel': 'agricultor'})[0] == 200, 'proprietário altera papel na horta selecionada')
                ok(farmer.request('/php/api/auth/me.php')[1]['data']['area'] == 'agricultor', 'sessão volta à área disponível após mudança de papel')
                ok(owner.request(route('membros'), 'DELETE', {'id_usuario': farmer_id})[0] == 200, 'proprietário revoga acesso por horta')
                ok(farmer.request(route('registros'))[0] == 404 and farmer.request(route('registros', other))[0] == 200, 'revogação respeita a horta e a sessão atual')
            finally:
                server.terminate()
                server.wait(timeout=10)
finally:
    for garden in gardens:
        for table in ('horta_registro', 'tarefa_intervencao', 'tarefa_historico', 'horta_tarefa', 'horta_convite', 'usuario_horta', 'horta'):
            db(f'DELETE FROM {table} WHERE id_horta=?', [garden])
    for user in users:
        db('DELETE FROM usuario WHERE id_usuario=?', [user])
    db('DELETE FROM auth_limite')
print(f'{checks} verificações de colaboração aprovadas; fixtures removidas.')
