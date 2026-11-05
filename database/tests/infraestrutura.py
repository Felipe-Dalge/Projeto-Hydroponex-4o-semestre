"""Integração de infraestrutura e catálogos com MySQL real, sem frameworks.
Python >=3.10. Banco dedicado vazio de usuários, nome _test/_tests, schema 1.2.0.
python database/tests/infraestrutura.py --executar --php C:/xampp/php/php.exe
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


catalogs=[]
try:
    with tempfile.TemporaryDirectory(prefix='hydroponex-infra-http-') as directory:
        temp=Path(directory).resolve();assert temp.is_relative_to(Path(tempfile.gettempdir()).resolve());app=temp/'app';shutil.copytree(source,app)
        with socket.socket() as sock:sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
        base=f'http://127.0.0.1:{port}'
        with (temp/'http.log').open('wb') as log:
            server=subprocess.Popen([args.php,'-S',f'127.0.0.1:{port}','server/router.php'],cwd=app,env=env,stdout=log,stderr=log,creationflags=subprocess.CREATE_NO_WINDOW if os.name=='nt' else 0)
            try:
                a,b,c,guest=Client(base),Client(base),Client(base),Client(base)
                for _ in range(50):
                    try:guest.request('/php/api/session.php');break
                    except urllib.error.URLError:time.sleep(.1)
                else:raise RuntimeError('PHP não iniciou.')
                ua=a.register('Ana');ub=b.register('Bruno');uc=c.register('Carla')
                h1=a.create({'nome':'Infra A '+run})[1]['data']['id_horta'];h2=a.create({'nome':'Infra A2 '+run})[1]['data']['id_horta'];hb=b.create({'nome':'Infra B '+run})[1]['data']['id_horta']
                url=lambda resource,id=None,h=None:'/php/api/'+resource+'.php'+(('?'+('&'.join(([f'horta={h}']if h else[])+([f'id={id}']if id else[]))))if(h or id)else'')
                def create(resource,data,h=None,client=a):
                    response=client.request(url(resource,h=h),'POST',data)
                    if response[0]==201 and resource in ['culturas','nutrientes']:
                        table,pk=('cultura','id_cultura')if resource=='culturas'else('nutriente','id_nutriente');catalogs.append((table,pk,response[1]['data']['id']))
                    return response
                for resource in ['culturas','nutrientes','reservatorios','estruturas']:
                    ok(guest.request(url(resource,h=h1))[0]==401,'visitante não consulta '+resource)
                ok(a.request(url('reservatorios'))[0]==422,'reservatório exige horta')
                ok(a.request(url('estruturas'))[0]==422,'estrutura exige horta')
                ok(a.request(url('culturas'),'POST',{'nome':'CSRF'},csrf=False)[0]==403,'catálogo exige CSRF')
                ok(create('culturas',{'nome':'Invasão','id_criador':ub})[0]==422,'criador vem da sessão, não do corpo')
                global_c=int(db("INSERT INTO cultura (nome) VALUES (?)",['Cultura global '+run])['id']);catalogs.append(('cultura','id_cultura',global_c))
                global_n=int(db("INSERT INTO nutriente (nome) VALUES (?)",['Nutriente global '+run])['id']);catalogs.append(('nutriente','id_nutriente',global_n))
                ok(a.request(url('culturas',global_c))[1]['data']['escopo']=='global','dados preexistentes globais são consultáveis')
                ok(a.request(url('culturas',global_c),'PATCH',{'nome':'Tomada'})[0]==403,'usuário comum não edita catálogo global')
                ok(a.request(url('nutrientes',global_n),'DELETE',{'confirmacao':'EXCLUIR'})[0]==403,'usuário comum não exclui nutriente global')
                culture=create('culturas',{'nome':'Alface pessoal','categoria':'Folhosa','tempo_crescimento_dias':35,'temperatura_ideal_c':22.5,'ph_ideal':6.25})
                cid=culture[1]['data']['id']
                ok(culture[0]==201 and culture[1]['data']['categoria']=='Folhosa' and culture[1]['data']['escopo']=='pessoal','cadastro de cultura pessoal com dados ideais')
                ok(db('SELECT id_criador FROM cultura WHERE id_cultura=?',[cid])['rows'][0]['id_criador']==ua,'dono do catálogo confirmado no banco')
                ok(b.request(url('culturas',cid))[0]==404,'catálogo pessoal não aparece para terceiro')
                ok(a.request(url('culturas',cid),'PATCH',{'ph_ideal':6.5})[0]==200,'criador edita cultura livre')
                for data in [{'ph_ideal':14.01},{'tempo_crescimento_dias':-1},{'temperatura_ideal_c':-273.16},{'nome':[]},{'tempo_crescimento_dias':1.5}]:
                    ok(a.request(url('culturas',cid),'PATCH',data)[0]==422,'cultura valida entrada '+str(data))
                link=url('culturas_horta',h=h1)
                ok(a.request(link,'POST',{'id_cultura':cid})[0]==200,'cultura é habilitada na horta')
                a.request(link,'POST',{'id_cultura':cid})
                ok(db('SELECT COUNT(*) AS n FROM horta_cultura WHERE id_horta=? AND id_cultura=?',[h1,cid])['rows'][0]['n']==1,'associação de cultura é idempotente')
                ok(a.request(url('culturas',cid),'PATCH',{'nome':'Alteração compartilhada'})[0]==409,'cultura em uso não sofre alteração retroativa')
                plant=a.request('/php/api/plantas.php?horta='+str(h1),'POST',{'nome':'Alface plantada','bancada':'A','id_cultura':cid})
                pid=plant[1]['data']['id']
                ok(plant[0]==201,'planta aceita cultura habilitada')
                ok(a.request('/php/api/plantas.php?horta='+str(h2),'POST',{'nome':'Inválida','bancada':'B','id_cultura':cid})[0]==422,'planta não usa cultura não habilitada na horta')
                ok(a.request(link,'DELETE',{'id_cultura':cid,'confirmacao':'DESVINCULAR'})[0]==409,'cultura de planta não pode ser desvinculada')
                ok(a.request(url('culturas',cid),'DELETE',{'confirmacao':'EXCLUIR'})[0]==409,'cultura em uso não pode ser excluída')
                db("INSERT INTO usuario_horta (id_usuario,id_horta,papel) VALUES (?,?,'leitor')",[ub,h1])
                ok(b.request(url('culturas',cid))[0]==200 and not b.request(url('culturas',cid))[1]['data']['pode_editar'],'membro consulta catálogo compartilhado sem assumir propriedade')
                ok(b.request(link,'POST',{'id_cultura':global_c})[0]==403,'leitor não associa culturas')
                ok(c.request(url('culturas',cid))[0]==404,'não membro continua sem ver item pessoal vinculado')
                ok(a.request('/php/api/plantas.php?horta='+str(h1),'PATCH',{'id':pid,'id_cultura':None})[0]==200,'vínculo de planta pode ser removido explicitamente')
                ok(a.request(link,'DELETE',{'id_cultura':cid,'confirmacao':'DESVINCULAR'})[0]==200,'desvinculação livre preserva catálogo')
                ok(a.request(url('culturas',cid),'PATCH',{'nome':'Alface revisada'})[0]==200,'catálogo volta a ser editável sem uso')
                ok(b.request(url('culturas',cid))[0]==404,'sem vínculo não há acesso herdado ao catálogo privado')
                spare=create('culturas',{'nome':'Descartável'})[1]['data']['id']
                ok(a.request(url('culturas',spare),'DELETE',{'confirmacao':'EXCLUIR'})[0]==200,'exclusão de cultura pessoal livre')
                rr=create('reservatorios',{'nome':'Reservatório A','capacidade_l':100,'nivel_atual_l':50,'temperatura_c':22,'ph_atual':6.2},h1)
                rid=rr[1]['data']['id'];r2=create('reservatorios',{'nome':'Reservatório A2','capacidade_l':80},h2)[1]['data']['id']
                ok(rr[0]==201 and rr[1]['data']['id_horta']==h1,'reservatório é criado na horta')
                ok(b.request(url('reservatorios',rid,h2))[0]==404 and c.request(url('reservatorios',rid,h1))[0]==404,'não consulta reservatório através de horta indevida')
                ok(b.request(url('reservatorios',rid,h1))[0]==200,'leitor consulta reservatório autorizado')
                ok(b.request(url('reservatorios',rid,h1),'PATCH',{'nivel_atual_l':20})[0]==403,'leitor não altera nível')
                for data in [{'capacidade_l':0},{'nivel_atual_l':101},{'nivel_atual_l':-1},{'ph_atual':15},{'capacidade_l':True},{'temperatura_c':22.123},{'id_horta':h2}]:
                    ok(a.request(url('reservatorios',rid,h1),'PATCH',data)[0]==422,'reservatório valida entrada '+str(data))
                ok(a.request(url('reservatorios',rid,h1),'PATCH',{'capacidade_l':40})[0]==422,'reduzir capacidade abaixo do nível é recusado')
                stored=a.request(url('reservatorios',rid,h1))[1]['data']
                ok(stored['capacidade_l']=='100.000' and stored['nivel_atual_l']=='50.000','falha não grava capacidade parcial')
                ok(a.request(url('reservatorios',rid,h1),'PATCH',{'capacidade_l':40,'nivel_atual_l':30})[0]==200,'capacidade e nível podem ser ajustados atomicamente')
                es=create('estruturas',{'nome':'Bancada A','tipo':'NFT','qnt_unidade':12,'id_reservatorio':rid},h1);eid=es[1]['data']['id']
                ok(es[0]==201,'estrutura associa reservatório da horta')
                ok(create('estruturas',{'nome':'Inválida','id_reservatorio':r2},h1)[0]==422,'estrutura não usa reservatório de outra horta')
                ok(a.request(url('estruturas',eid,h1),'PATCH',{'qnt_unidade':0})[0]==422,'estrutura não aceita zero unidades')
                ok(a.request(url('reservatorios',rid,h1),'DELETE',{'confirmacao':'EXCLUIR'})[0]==409,'reservatório usado por estrutura não é excluído')
                db('UPDATE planta SET id_estrutura=? WHERE id_planta=?',[eid,pid])
                ok(a.request(url('estruturas',eid,h1),'DELETE',{'confirmacao':'EXCLUIR'})[0]==409,'estrutura com planta não é excluída')
                db('UPDATE planta SET id_estrutura=NULL WHERE id_planta=?',[pid])
                ok(a.request(url('estruturas',eid,h1),'PATCH',{'id_reservatorio':None,'qnt_unidade':8})[0]==200,'estrutura permite desvinculação explícita')
                ok(a.request(url('reservatorios',rid,h1),'DELETE',{'confirmacao':'EXCLUIR'})[0]==200,'reservatório livre pode ser excluído')
                ok(a.request(url('estruturas',eid,h1),'DELETE',{'confirmacao':'EXCLUIR'})[0]==200,'estrutura livre pode ser excluída')
                nutrient=create('nutrientes',{'classificacao':'Solução A','descricao':'Mistura de teste','tipo':'Solução'})
                nid=nutrient[1]['data']['id']
                ok(nutrient[0]==201 and nutrient[1]['data']['nome']=='Solução A','nutriente aceita classificação como nome quando ausente')
                ok(a.request(url('nutrientes',nid),'PATCH',{'descricao':'Descrição revisada'})[0]==200,'criador edita nutriente não utilizado')
                ok(a.request(url('nutrientes',nid),'PATCH',{'descricao':'Linha 1\nLinha 2'})[0]==200 and a.request(url('nutrientes',nid))[1]['data']['descricao']=='Linha 1\nLinha 2','descrição multilinha é preservada')
                ok(b.request(url('nutrientes',nid))[0]==404,'nutriente pessoal isolado')
                rd=create('reservatorios',{'nome':'Dosagem','capacidade_l':60},h1)[1]['data']['id']
                sensor=int(db("INSERT INTO sensor (id_horta,id_reservatorio,nome,tipo,unidade) VALUES (?,?,'Nutrientes A','nutrientes','ppm')",[h1,rd])['id'])
                links=url('nutrientes_vinculos',h=h1)
                ok(a.request(links,'PATCH',{'id_sensor':sensor,'id_nutriente':nid})[0]==200,'nutriente pode ser vinculado a sensor da horta')
                ok(b.request(url('nutrientes',nid))[0]==200,'nutriente vinculado torna-se consultável por membro')
                ok(b.request(links,'PATCH',{'id_sensor':sensor,'id_nutriente':global_n})[0]==403,'leitor não altera sensor')
                ok(a.request(url('nutrientes_vinculos',h=h2),'PATCH',{'id_sensor':sensor,'id_nutriente':nid})[0]==404,'não associa sensor usando horta diferente')
                mid=int(db("INSERT INTO monitoramento (id_horta,origem,data_hora) VALUES (?,'sensor',UTC_TIMESTAMP(6))",[h1])['id'])
                db("INSERT INTO monitoramento_sensor (id_monitoramento,id_sensor,id_horta,valor,tipo_medicao,unidade) VALUES (?,?,?,750.125,'nutrientes','ppm')",[mid,sensor,h1])
                ok(a.request(links,'PATCH',{'id_sensor':sensor,'id_nutriente':None})[0]==409,'sensor com leituras não perde contexto do nutriente')
                ok(a.request(links,'PATCH',{'id_sensor':sensor,'id_nutriente':nid})[0]==200,'salvar mesmo vínculo com histórico é seguro')
                records=a.request(links)[1]['data']
                ok(len(records['leituras'])==1 and records['leituras'][0]['nutriente']=='Solução A','monitoramento mostra nutriente e leitura da horta')
                ok(c.request(links)[0]==404,'não membro não acessa monitoramento nutricional')
                ok(a.request(url('nutrientes',nid),'DELETE',{'confirmacao':'EXCLUIR'})[0]==409,'sensor impede exclusão de nutriente')
                dose={'id_reservatorio':rd,'id_nutriente':nid,'quantidade':'2.125','unidade':'mL','aplicado_em':'2026-10-28T12:00:00Z'}
                ok(a.request(links,'POST',dose)[0]==201,'aplicação de nutriente registrada com quantidade/unidade')
                application=db('SELECT quantidade,id_usuario FROM reservatorio_nutriente WHERE id_reservatorio=?',[rd])['rows'][0]
                ok(application['quantidade']=='2.125' and application['id_usuario']==ua,'aplicação guarda precisão e autor da sessão')
                ok(a.request(links,'POST',dict(dose,unidade='kg'))[0]==422,'unidade de dosagem inválida é recusada')
                ok(a.request(links,'POST',dict(dose,quantidade=0))[0]==422,'dosagem zero é recusada')
                ok(a.request(links,'POST',dict(dose,id_usuario=ub))[0]==422,'autor de aplicação não vem do frontend')
                ok(a.request(url('reservatorios',rd,h1),'DELETE',{'confirmacao':'EXCLUIR'})[0]==409,'aplicações/sensores protegem reservatório')
                ok(a.request(url('nutrientes',nid),'PATCH',{'nome':'Reinterpretada'})[0]==409,'nutriente com histórico não é reinterpretado por edição')
                db("UPDATE usuario_horta SET papel='gestor' WHERE id_usuario=? AND id_horta=?",[ub,h1])
                ok(b.request(url('reservatorios',rd,h1),'PATCH',{'nivel_atual_l':25})[0]==200,'gestor gerencia infraestrutura autorizada')
                ok(b.request(url('nutrientes',nid),'PATCH',{'nome':'Tomada por gestor'})[0]==403,'gestor de horta não assume propriedade de catálogo de outro criador')
                db('DELETE FROM usuario_horta WHERE id_usuario=? AND id_horta=?',[ub,h1])
                ok(b.request(url('nutrientes',nid))[0]==404 and b.request(url('reservatorios',rd,h1))[0]==404,'revogação remove acesso ao catálogo compartilhado e infraestrutura')
                free=create('nutrientes',{'nome':'Nutriente livre'})[1]['data']['id']
                ok(a.request(url('nutrientes',free),'DELETE',{'confirmacao':'EXCLUIR'})[0]==200,'nutriente pessoal sem uso pode ser excluído')
                ok(a.request(url('reservatorios',rd,h1),'DELETE',{'confirmacao':'sim'})[0]==422,'exclusão exige confirmação')
            finally:server.terminate();server.wait(timeout=10)
finally:
    for h in gardens:
        for table in ['monitoramento_sensor','monitoramento','sensor','consumo_agua','horta_cultura']:
            db(f'DELETE FROM {table} WHERE id_horta=?',[h])
        db('DELETE FROM reservatorio_nutriente WHERE id_reservatorio IN (SELECT id_reservatorio FROM reservatorio WHERE id_horta=?)',[h])
        db('DELETE FROM importacao_json WHERE id_planta IN (SELECT id_planta FROM planta WHERE id_horta=?)',[h])
        for table in ['planta','estrutura','reservatorio','usuario_horta','horta']: db(f'DELETE FROM {table} WHERE id_horta=?',[h])
    for table,pk,id in catalogs: db(f'DELETE FROM {table} WHERE {pk}=?',[id])
    for uid in users:
        db('DELETE FROM token_usuario WHERE id_usuario=?',[uid]); db('DELETE FROM usuario WHERE id_usuario=?',[uid])
    for table,column,value in reversed(postal): db(f'DELETE FROM {table} WHERE {column}=?',[value])
    scopes=[('login-ip','127.0.0.1'),('register-ip','127.0.0.1')]+[('login-email',e) for e in emails]
    for scope,identity in scopes:
        digest=hmac.new(key.encode(),(scope+':'+identity).encode(),hashlib.sha256).hexdigest(); db('DELETE FROM auth_limite WHERE chave=UNHEX(?)',[digest])
ok(legacy_hash==hashlib.sha256((source/'php/storage/plantas.json').read_bytes()).hexdigest(),'JSON legado original permanece intacto')
print(f'{checks} verificações de infraestrutura aprovadas; fixtures removidas.',flush=True)
