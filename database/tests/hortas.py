"""Integração de hortas/plantas com MySQL real, sem frameworks.
Python >=3.10. Banco dedicado vazio de usuários, nome _test/_tests, schema 1.1.0.
python database/tests/hortas.py --executar --php C:/xampp/php/php.exe
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
root=Path(__file__).resolve().parents[2]
env=os.environ.copy()
bridge="""require 'Hydroponex_2/hydro/Hydroponexteste2/php/config/database.php';
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
users=[]; gardens=[]; emails=[]; postal=[]
checks=0
source=root/'Hydroponex_2/hydro/Hydroponexteste2'
legacy_hash=hashlib.sha256((source/'php/storage/plantas.json').read_bytes()).hexdigest()

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
    def register(self,label):
        self.request('/php/api/session.php')
        email=f'{label}-{run}@example.test'; emails.append(email)
        assert self.request('/php/api/auth/cadastro.php','POST',{'nome':label,'email':email,'senha':password})[0]==202
        uid=db('SELECT id_usuario FROM usuario WHERE email=?',[email])['rows'][0]['id_usuario']; users.append(uid)
        assert self.request('/php/api/auth/login.php','POST',{'email':email,'senha':password})[0]==200
        return uid
    def create(self,body):
        response=self.request('/php/api/hortas.php','POST',body)
        if response[0]==201: gardens.append(response[1]['data']['id_horta'])
        return response

try:
    with tempfile.TemporaryDirectory(prefix='hydroponex-gardens-http-') as directory:
        temp=Path(directory).resolve(); assert temp.is_relative_to(Path(tempfile.gettempdir()).resolve())
        app=temp/'app'; shutil.copytree(source,app)
        with socket.socket() as sock: sock.bind(('127.0.0.1',0)); port=sock.getsockname()[1]
        base=f'http://127.0.0.1:{port}'
        with (temp/'http.log').open('wb') as log:
            server=subprocess.Popen([args.php,'-S',f'127.0.0.1:{port}','server/router.php'],cwd=app,env=env,stdout=log,stderr=log,creationflags=subprocess.CREATE_NO_WINDOW if os.name=='nt' else 0)
            try:
                a,b,c,guest=Client(base),Client(base),Client(base),Client(base)
                for _ in range(50):
                    try: guest.request('/php/api/session.php'); break
                    except urllib.error.URLError: time.sleep(.1)
                else: raise RuntimeError('PHP não iniciou.')
                ok(guest.request('/php/api/hortas.php')[0]==401,'visitante não lista hortas')
                ua=a.register('Ana'); ub=b.register('Bruno'); uc=c.register('Carla')
                ok(a.request('/php/api/hortas.php')[1]['data']==[],'conta nova inicia sem hortas')
                ok(a.request('/php/api/hortas.php','POST',{'nome':'Sem CSRF'},csrf=False)[0]==403,'cadastro de horta exige CSRF')
                ok(a.create({'nome':'Invasão','id_usuario':ub})[0]==422,'responsável não pode ser escolhido por mass assignment')
                ok(a.create({'nome':'','tamanho_m2':1})[0]==422,'nome obrigatório')
                for invalid in [0,-1,'0.001','10000000000',True,[]]:
                    ok(a.create({'nome':'Inválida '+run,'tamanho_m2':invalid})[0]==422,'área inválida recusada: '+str(invalid))
                ok(a.create({'nome':'CEP inválido','cep':'abc'})[0]==422,'CEP malformado recusado')
                ok(a.create({'nome':'Estrutura inválida '+run,'estrutura':{'nome':'Bancada','qnt_unidade':0}})[0]==422,'estrutura inválida reverte criação')
                ok(db("SELECT COUNT(*) AS n FROM horta WHERE nome=?",['Estrutura inválida '+run])['rows'][0]['n']==0,'sem horta órfã após rollback')
                ha=a.create({'nome':'Horta A '+run,'tamanho_m2':'12.50','localizacao':'Terraço'})
                h1=ha[1]['data']['id_horta']; h2=a.create({'nome':'Horta A2 '+run})[1]['data']['id_horta']; hb=b.create({'nome':'Horta B '+run})[1]['data']['id_horta']
                ok(ha[0]==201 and len({h1,h2,hb})==3,'IDs únicos e várias hortas por usuário')
                ok(ha[1]['data']['responsaveis']==[{'id_usuario':ua,'nome':'Ana'}] and ha[1]['data']['papel']=='proprietario','criação associa proprietário da sessão')
                ok(a.request('/php/api/hortas.php')[1]['meta']['total']==2 and b.request('/php/api/hortas.php')[1]['meta']['total']==1,'listagem isolada entre usuários')
                ok(a.request(f'/php/api/hortas.php?id={h1}')[1]['data']['tamanho_m2']=='12.50','decimal de tamanho preservado')
                ok(b.request(f'/php/api/hortas.php?id={h1}')[0]==404 and c.request(f'/php/api/hortas.php?id={h1}')[0]==404,'terceiros não consultam horta')
                ok(b.request(f'/php/api/hortas.php?id={h1}','PATCH',{'nome':'Invadida'})[0]==404,'terceiro não edita horta')
                ok(b.request(f'/php/api/hortas.php?id={h1}','DELETE',{'confirmacao':'EXCLUIR'})[0]==404,'terceiro não exclui horta')
                ok(a.request(f'/php/api/hortas.php?id={h1}','PATCH',{'nome':'Horta atualizada '+run,'tamanho_m2':25})[0]==200,'proprietário edita informações')
                ok(a.request(f'/php/api/hortas.php?id={h1}','PATCH',{'nome':'Falha'},csrf=False)[0]==403,'edição exige CSRF')
                # Catálogo postal de fixture, sem depender de serviço externo.
                state=int(db('INSERT INTO estado (nome,sigla) VALUES (?,?)',['Estado teste '+run,'ZX'])['id']); postal.append(('estado','id_estado',state))
                city=int(db('INSERT INTO cidade (id_estado,nome) VALUES (?,?)',[state,'Cidade '+run])['id']); postal.append(('cidade','id_cidade',city))
                code='0'+str(secrets.randbelow(10000000)).zfill(7)
                cep=int(db('INSERT INTO cep (codigo,id_cidade) VALUES (?,?)',[code,city])['id']); postal.append(('cep','id_cep',cep))
                ok(a.request(f'/php/api/hortas.php?id={h1}','PATCH',{'cep':code[:5]+'-'+code[5:]})[0]==200,'CEP normalizado aponta ao catálogo')
                ok(a.request(f'/php/api/hortas.php?id={h1}')[1]['data']['cep']==code,'CEP mantém zeros iniciais')
                structure=a.request(f'/php/api/hortas.php?id={h1}','PATCH',{'estrutura':{'nome':'Bancada A','tipo':'NFT','qnt_unidade':8}})
                sid=structure[1]['data']['estruturas'][0]['id_estrutura']
                ok(structure[0]==200,'estrutura criada dentro da horta')
                ok(a.request(f'/php/api/hortas.php?id={h2}','PATCH',{'estrutura':{'id_estrutura':sid,'nome':'Roubo'}})[0]==422,'estrutura não pode ser movida de outra horta')
                ok(a.request(f'/php/api/hortas.php?id={h1}','PATCH',{'estrutura':{'id_estrutura':sid,'nome':'Bancada editada','qnt_unidade':10}})[0]==200,'estrutura própria pode ser editada')
                db("INSERT INTO usuario_horta (id_usuario,id_horta,papel) VALUES (?,?,'leitor')",[ub,h1])
                ok(b.request('/php/api/hortas.php')[1]['meta']['total']==2,'horta compartilhada aparece para membro autorizado')
                ok(b.request(f'/php/api/hortas.php?id={h1}')[0]==200,'leitor consulta horta compartilhada')
                ok(b.request(f'/php/api/hortas.php?id={h1}','PATCH',{'nome':'Leitor'})[0]==403,'leitor não edita')
                ok(b.request(f'/php/api/hortas.php?id={h1}','DELETE',{'confirmacao':'EXCLUIR'})[0]==403,'leitor não exclui')
                plant={'nome':'Alface','bancada':'A1','temp':22.5,'nutrientes':800,'id_estrutura':sid}
                created=a.request(f'/php/api/plantas.php?horta={h1}','POST',plant); pid=created[1]['data']['id']
                created2=a.request(f'/php/api/plantas.php?horta={h1}','POST',dict(plant,nome='Rúcula'))
                ok(created[0]==201 and created2[0]==201,'uma horta comporta várias plantas em MySQL')
                ok(created[1]['data']['umidade'] is None and created[1]['data']['agua'] is None,'cadastro não inventa umidade ou consumo de água')
                ok(len(a.request(f'/php/api/plantas.php?horta={h1}')[1]['data'])==2 and a.request(f'/php/api/plantas.php?horta={h2}')[1]['data']==[],'seleção de horta isola plantas')
                ok(b.request(f'/php/api/plantas.php?horta={h1}')[0]==200 and b.request(f'/php/api/plantas.php?horta={h1}','POST',plant)[0]==403,'leitor consulta mas não cadastra plantas')
                ok(c.request(f'/php/api/plantas.php?horta={h1}')[0]==404,'não membro não lista plantas')
                ok(a.request(f'/php/api/plantas.php?horta={h2}&id={pid}')[0]==404,'ID de planta é validado dentro da horta')
                ok(a.request(f'/php/api/plantas.php?horta={h2}','PATCH',{'id':pid,'nome':'Movida'})[0]==404,'planta não é editada por ID de outra horta')
                ok(a.request(f'/php/api/plantas.php?horta={h2}','POST',plant)[0]==422,'planta não usa estrutura de outra horta')
                db("UPDATE usuario_horta SET papel='gestor' WHERE id_usuario=? AND id_horta=?",[ub,h1])
                ok(b.request(f'/php/api/hortas.php?id={h1}','PATCH',{'localizacao':'Gestor editou'})[0]==200,'gestor pode editar horta')
                ok(b.request(f'/php/api/plantas.php?horta={h1}','PATCH',{'id':pid,'nome':'Alface editada'})[0]==200,'gestor pode editar planta')
                ok(b.request(f'/php/api/hortas.php?id={h1}','DELETE',{'confirmacao':'EXCLUIR'})[0]==403,'gestor não exclui horta')
                ok(a.request(f'/php/api/hortas.php?id={h1}','DELETE',{'confirmacao':'EXCLUIR'})[0]==409,'horta com plantas/estrutura tem exclusão bloqueada')
                # Testa separadamente cada dependência obrigatória.
                for table in ['planta','estrutura','reservatorio','sensor','monitoramento','consumo_agua','horta_cultura']:
                    h=a.create({'nome':'Dependência '+table+' '+run})[1]['data']['id_horta']
                    if table=='planta': db("INSERT INTO planta (id_horta,nome,localizacao) VALUES (?,'P','A')",[h])
                    elif table=='estrutura': db("INSERT INTO estrutura (id_horta,nome) VALUES (?,'E')",[h])
                    elif table=='reservatorio': db("INSERT INTO reservatorio (id_horta,nome,capacidade_l) VALUES (?,'R',100)",[h])
                    elif table=='sensor': db("INSERT INTO sensor (id_horta,nome,tipo,unidade) VALUES (?,'S','ph','pH')",[h])
                    elif table=='monitoramento': db("INSERT INTO monitoramento (id_horta,origem,data_hora) VALUES (?,'sensor',UTC_TIMESTAMP(6))",[h])
                    elif table=='consumo_agua': db('INSERT INTO consumo_agua (id_horta,data_consumo,volume_l) VALUES (?,CURRENT_DATE(),1)',[h])
                    else:
                        culture=int(db("INSERT INTO cultura (nome) VALUES (?)",['Cultura '+run])['id']); postal.append(('cultura','id_cultura',culture)); db('INSERT INTO horta_cultura VALUES (?,?)',[h,culture])
                    ok(a.request(f'/php/api/hortas.php?id={h}','DELETE',{'confirmacao':'EXCLUIR'})[0]==409,'exclusão preserva dependência: '+table)
                ok(a.request(f'/php/api/hortas.php?id={h2}','DELETE',{'confirmacao':'sim'})[0]==422,'exclusão exige confirmação textual')
                ok(a.request(f'/php/api/hortas.php?id={h2}','DELETE',{'confirmacao':'EXCLUIR'},csrf=False)[0]==403,'exclusão exige CSRF')
                db("INSERT INTO usuario_horta (id_usuario,id_horta,papel) VALUES (?,?,'leitor')",[ub,h2])
                ok(a.request(f'/php/api/hortas.php?id={h2}','DELETE',{'confirmacao':'EXCLUIR'})[0]==200,'proprietário exclui horta vazia com confirmação')
                ok(db('SELECT COUNT(*) AS n FROM usuario_horta WHERE id_horta=?',[h2])['rows'][0]['n']==0 and db('SELECT COUNT(*) AS n FROM usuario')['rows'][0]['n']==3,'exclusão remove só associações, não usuários')
                ok(b.request(f'/php/api/hortas.php?id={h2}')[0]==404,'membros não acessam horta excluída')
                for n in range(7): a.create({'nome':f'Página {n} {run}'})
                page1=a.request('/php/api/hortas.php?pagina=1'); page2=a.request('/php/api/hortas.php?pagina=2')
                ids1={h['id_horta'] for h in page1[1]['data']}; ids2={h['id_horta'] for h in page2[1]['data']}
                ok(len(ids1)==12 and ids2 and not ids1.intersection(ids2),'paginação não duplica nem perde hortas entre páginas')
                ok(len(a.request('/php/api/auth/me.php')[1]['data']['hortas'])==page1[1]['meta']['total'],'seletor recebe todas as hortas autorizadas')
                ok(a.request('/php/api/hortas.php?pagina=0')[0]==422,'página inválida recusada')
                db('DELETE FROM usuario_horta WHERE id_usuario=? AND id_horta=?',[ub,h1])
                ok(b.request(f'/php/api/hortas.php?id={h1}')[0]==404 and b.request(f'/php/api/plantas.php?horta={h1}')[0]==404,'revogação de acesso vale na próxima requisição')
                ok(a.request('/php/api/plantas.php')[0]==422,'API exige horta, sem fallback para JSON')
            finally:
                server.terminate(); server.wait(timeout=10)
finally:
    for h in gardens:
        for table in ['monitoramento_sensor','monitoramento','sensor','consumo_agua','horta_cultura']:
            db(f'DELETE FROM {table} WHERE id_horta=?',[h])
        db('DELETE FROM reservatorio_nutriente WHERE id_reservatorio IN (SELECT id_reservatorio FROM reservatorio WHERE id_horta=?)',[h])
        db('DELETE FROM importacao_json WHERE id_planta IN (SELECT id_planta FROM planta WHERE id_horta=?)',[h])
        for table in ['planta','estrutura','reservatorio','usuario_horta','horta']: db(f'DELETE FROM {table} WHERE id_horta=?',[h])
    for uid in users:
        db('DELETE FROM token_usuario WHERE id_usuario=?',[uid]); db('DELETE FROM usuario WHERE id_usuario=?',[uid])
    for table,column,value in reversed(postal): db(f'DELETE FROM {table} WHERE {column}=?',[value])
    scopes=[('login-ip','127.0.0.1'),('register-ip','127.0.0.1')]+[('login-email',e) for e in emails]
    for scope,identity in scopes:
        digest=hmac.new(key.encode(),(scope+':'+identity).encode(),hashlib.sha256).hexdigest(); db('DELETE FROM auth_limite WHERE chave=UNHEX(?)',[digest])
ok(legacy_hash==hashlib.sha256((source/'php/storage/plantas.json').read_bytes()).hexdigest(),'JSON legado original permanece intacto')
print(f'{checks} verificações de hortas/plantas aprovadas; fixtures removidas.',flush=True)
