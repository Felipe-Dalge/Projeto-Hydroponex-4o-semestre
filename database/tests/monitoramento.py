"""Integração de sensores, monitoramentos e dashboard com MySQL real, sem frameworks.
Python >=3.10. Banco dedicado vazio de usuários, nome _test/_tests, schema 1.3.0.
python database/tests/monitoramento.py --executar --php C:/xampp/php/php.exe
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
import urllib.parse
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
if not db("SELECT versao FROM schema_version WHERE versao='1.3.0'")['rows']: raise RuntimeError('Instale o schema 1.3.0 no banco descartável antes de testar.')
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



nutrients=[]
try:
    with tempfile.TemporaryDirectory(prefix='hydroponex-monitor-http-') as directory:
        temp=Path(directory).resolve();app=temp/'app';shutil.copytree(source,app)
        with socket.socket() as sock:sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
        base=f'http://127.0.0.1:{port}'
        with (temp/'http.log').open('wb') as log:
            server=subprocess.Popen([args.php,'-S',f'127.0.0.1:{port}','server/router.php'],cwd=app,env=env,stdout=log,stderr=log,creationflags=subprocess.CREATE_NO_WINDOW if os.name=='nt' else 0)
            try:
                a,b,c,guest=Client(base),Client(base),Client(base),Client(base)
                for _ in range(50):
                    try:guest.request('/php/api/session.php');break
                    except urllib.error.URLError:time.sleep(.1)
                ua=a.register('Ana');ub=b.register('Bruno');uc=c.register('Carla')
                h1=a.create({'nome':'Monitor A '+run})[1]['data']['id_horta'];h2=a.create({'nome':'Monitor A2 '+run})[1]['data']['id_horta'];hb=b.create({'nome':'Monitor B '+run})[1]['data']['id_horta']
                def url(resource,h=h1,**query):return '/php/api/'+resource+'.php?horta='+str(h)+''.join('&'+k+'='+urllib.parse.quote(str(v),safe='') for k,v in query.items())
                def create(data,h=h1,client=a):return client.request(url('sensores',h),'POST',data)
                def sensor(name='pH A',**fields):return {'nome':name,'tipo':'ph','unidade':'pH','limite_min':5.5,'limite_max':6.5,**fields}
                from datetime import datetime,timezone,timedelta
                now=datetime.now(timezone.utc);date=(now-timedelta(minutes=3)).isoformat()
                def reading(sid,value,origin='manual',**fields):return {'origem':origin,'data_hora':date,'leituras':[{'id_sensor':sid,'valor':value,'tipo_medicao':'ph','unidade':'pH'}],**fields}
                for resource in ['sensores','monitoramentos','dashboard']:ok(guest.request(url(resource))[0]==401,'visitante bloqueado em '+resource)
                ok(c.request(url('sensores'))[0]==404,'outra conta não lista sensores')
                ok(a.request('/php/api/sensores.php')[0]==422,'sensor exige horta')
                ok(a.request(url('sensores'),'POST',sensor(),csrf=False)[0]==403,'sensor exige CSRF')
                for change in [{'tipo':'sql'},{'unidade':'ppm'},{'status':'removido'},{'limite_min':15},{'limite_min':7,'limite_max':6},{'limite_max':True},{'nome':[]},{'id_usuario':ub}]:
                    ok(create(sensor(**change))[0]==422,'sensor rejeita '+str(list(change)))
                sid=create(sensor())[1]['data']['id_sensor'];sid2=create(sensor('pH A2'),h2)[1]['data']['id_sensor'];sidb=create(sensor('pH B'),hb,b)[1]['data']['id_sensor']
                ok(a.request(url('sensores',id=sid))[1]['data']['nome']=='pH A','consulta detalhe de sensor')
                ok(a.request(url('sensores',h2,id=sid))[0]==404,'sensor não mistura hortas do proprietário')
                ok(c.request(url('sensores',id=sid),'PATCH',{'nome':'Invasão'})[0]==404,'terceiro não edita sensor')
                db("INSERT INTO usuario_horta (id_usuario,id_horta,papel) VALUES (?,?,'leitor')",[ub,h1])
                ok(b.request(url('sensores'))[0]==200,'leitor consulta sensores')
                ok(create(sensor('Leitor'),client=b)[0]==403,'leitor não cria sensores')
                ok(b.request(url('monitoramentos'),'POST',reading(sid,6))[0]==403,'leitor não registra leituras')
                db("UPDATE usuario_horta SET papel='gestor' WHERE id_usuario=? AND id_horta=?",[ub,h1])
                ok(b.request(url('sensores',id=sid),'PATCH',{'nome':'pH gestor'})[0]==200,'gestor edita sensor da horta')
                r1=a.request(url('reservatorios'),'POST',{'nome':'R1','capacidade_l':100})[1]['data']['id'];r2=a.request(url('reservatorios',h2),'POST',{'nome':'R2','capacidade_l':100})[1]['data']['id']
                e1=a.request(url('estruturas'),'POST',{'nome':'E1','id_reservatorio':r1})[1]['data']['id']
                p1=a.request(url('plantas'),'POST',{'nome':'Planta monitorada','localizacao':'Bancada'})[1]['data']['id']
                for fields in [{'id_reservatorio':r2},{'id_estrutura':e1,'id_planta':p1},{'id_planta':99999999}]:ok(create(sensor(**fields))[0]==422,'sensor valida alvo da mesma horta e exclusividade')
                sp=create(sensor('pH planta',id_planta=p1))[1]['data']['id_sensor']
                sr=create(sensor('pH reservatório',id_reservatorio=r1))[1]['data']['id_sensor']
                se=create(sensor('pH estrutura',id_estrutura=e1))[1]['data']['id_sensor']
                ok(a.request(url('reservatorios',id=r1),'DELETE',{'confirmacao':'EXCLUIR'})[0]==409,'sensor/estrutura preserva reservatório')
                ok(a.request(url('estruturas',id=e1),'DELETE',{'confirmacao':'EXCLUIR'})[0]==409,'sensor preserva estrutura')
                ok(a.request(url('monitoramentos'),'POST',reading(sid,8))[0]==201,'registro manual autenticado')
                ok(db('SELECT id_usuario FROM monitoramento WHERE id_horta=?',[h1])['rows'][0]['id_usuario']==ua,'autor vem da sessão')
                hist=a.request(url('monitoramentos'))[1]['data'];ok(hist[0]['estado']=='alerta' and not hist[0]['simulado'],'alerta com limites da coleta')
                ok(hist[0]['registrado_em'] is not None,'instante de recebimento armazenado')
                ok(a.request(url('sensores',id=sid),'PATCH',{'limite_min':0,'limite_max':14})[0]==200,'pode ajustar limites para próximas leituras')
                ok(a.request(url('monitoramentos'))[1]['data'][0]['estado']=='alerta','alterar limites não reescreve alerta histórico')
                for fields in [{'tipo':'temperatura','unidade':'°C'},{'id_planta':p1},{'unidade':'mg/L','tipo':'nutrientes'}]:ok(a.request(url('sensores',id=sid),'PATCH',fields)[0]==409,'contexto com histórico fica protegido')
                ok(a.request(url('sensores',id=sid),'DELETE',{'confirmacao':'EXCLUIR'})[0]==409,'não exclui sensor com histórico')
                for data in [reading(sid,15),reading(sid,True),reading(sid,'NaN'),reading(sid,'1.1234567'),reading(sid,4,'sensor'),reading(sid,4,'importacao'),reading(sid,4,data_hora='2026-02-30T12:00:00Z'),reading(sid,4,data_hora=(now+timedelta(days=1)).isoformat()),reading(sid,4,id_usuario=ub)]:
                    ok(a.request(url('monitoramentos'),'POST',data)[0]==422,'leitura inválida/proveniência não autorizada rejeitada')
                ok(a.request(url('monitoramentos'),'POST',reading(sid,6),csrf=False)[0]==403,'leituras exigem CSRF')
                data=reading(sid,6);data['leituras'][0]['unidade']='°C';ok(a.request(url('monitoramentos'),'POST',data)[0]==422,'unidade deve coincidir com sensor')
                data=reading(sid,6);data['leituras']*=2;ok(a.request(url('monitoramentos'),'POST',data)[0]==422,'sensor duplicado no evento rejeitado')
                before=db('SELECT COUNT(*) n FROM monitoramento WHERE id_horta=?',[h1])['rows'][0]['n']
                data=reading(sid,6);data['leituras'].append({'id_sensor':sid2,'valor':6,'tipo_medicao':'ph','unidade':'pH'})
                ok(a.request(url('monitoramentos'),'POST',data)[0]==404,'lote não associa sensor de outra horta')
                ok(db('SELECT COUNT(*) n FROM monitoramento WHERE id_horta=?',[h1])['rows'][0]['n']==before,'lote inválido não grava evento parcial')
                ok(a.request(url('monitoramentos'),'POST',reading(sid,6,'simulacao',data_hora=(now-timedelta(minutes=1)).isoformat()))[0]==201,'simulação explícita aceita')
                dash=a.request(url('dashboard'))[1]['data'];s=next(x for x in dash['sensores'] if x['id_sensor']==sid)
                ok(s['ultima_medicao']['valor']=='8.000000' and s['ultima_simulacao']['valor']=='6.000000','dashboard separa simulação de medição')
                ok(dash['alertas_medicoes']==1,'simulação normal não oculta alerta manual')
                ok(dash['consumo_hoje_l'] is None,'ausência de consumo não vira zero')
                ok(next(x for x in dash['sensores'] if x['id_sensor']==sr)['ultima_medicao'] is None,'sensor sem dados permanece indisponível')
                ok(a.request(url('dashboard',h2))[1]['data']['alertas_medicoes']==0,'dashboard não mistura leituras de duas hortas')
                ok(c.request(url('dashboard'))[0]==404,'dashboard privado')
                ok(a.request(url('monitoramentos'),'POST',reading(sp,0))[0]==201,'zero é leitura válida, não ausência')
                ok(a.request(url('monitoramentos',planta=p1))[1]['data'][0]['id_sensor']==sp,'filtro por planta usa alvo direto')
                ok(len(a.request(url('monitoramentos',sensor=sid))[1]['data'])==2,'filtro por sensor')
                ok(all(r['origem']=='simulacao' for r in a.request(url('monitoramentos',origem='simulacao'))[1]['data']),'filtro por origem')
                for query in [{'sensor':sid2},{'planta':99999999}]:ok(a.request(url('monitoramentos',**query))[0]==404,'filtros não ultrapassam horta')
                for query in [{'inicio':'2026-10-28T12:00:00Z','fim':'2026-10-27T12:00:00Z'},{'cursor':'invalid'},{'limite':101},{'origem':'qualquer'},{'inicio':'x'}]:ok(a.request(url('monitoramentos',**query))[0]==422,'filtro/período/cursor inválido rejeitado')
                page=a.request(url('monitoramentos',limite=1))[1];ids=[page['data'][0]['id_leitura']]
                while page['proximo_cursor']:
                    page=a.request(url('monitoramentos',limite=1,cursor=page['proximo_cursor']))[1];ids.extend(r['id_leitura'] for r in page['data'])
                ok(len(ids)==3 and len(set(ids))==3,'paginação estável sem duplicar empates de horário')
                ok(a.request(url('monitoramentos',inicio=(now+timedelta(days=2)).isoformat()))[1]['data']==[],'período sem leituras fica vazio')
                ok(a.request(url('sensores',id=sid),'PATCH',{'status':'inativo'})[0]==200,'inativa sensor preservando leituras')
                ok(a.request(url('monitoramentos'),'POST',reading(sid,6))[0]==409,'sensor inativo não recebe leituras')
                ok(a.request(url('dashboard'))[1]['data']['alertas_medicoes']==1,'somente sensor ativo com alerta contribui (planta pH zero)')
                fresh=create(sensor('Sem histórico'))[1]['data']['id_sensor'];ok(a.request(url('sensores',id=fresh),'DELETE',{'confirmacao':'EXCLUIR'})[0]==200,'sensor sem leituras pode ser excluído')
                ok(a.request(url('sensores',id=se),'DELETE',{})[0]==422,'exclusão exige confirmação')
                for typ,unit,value in [('temperatura','°C',22.25),('nutrientes','ppm',700),('umidade','%',50),('nivel_agua','L',20),('condutividade','mS/cm',1.2),('luminosidade','lux',1000)]:
                    x=create({'nome':typ,'tipo':typ,'unidade':unit})[1]['data']['id_sensor'];d=reading(x,value);d['leituras'][0].update(tipo_medicao=typ,unidade=unit)
                    ok(a.request(url('monitoramentos'),'POST',d)[0]==201,'tipo/unidade suportado: '+typ)
                legacy=int(db("INSERT INTO sensor(id_horta,nome,tipo,unidade) VALUES (?,'Sensor legado','parametro_legado','u')",[h1])['id'])
                ok(a.request(url('sensores',id=legacy),'PATCH',{'status':'inativo'})[0]==200,'tipo legado pode ser inativado sem conversão arbitrária')
                ok(a.request(url('sensores',id=legacy),'PATCH',{'nome':'Legado preservado'})[0]==200,'tipo legado permite manutenção de nome/status')
                nutrient=a.request(url('nutrientes'),'POST',{'nome':'Nutriente monitor'})[1]['data']['id'];nutrients.append(nutrient)
                ok(a.request(url('nutrientes_vinculos'),'PATCH',{'id_sensor':sr,'id_nutriente':nutrient})[0]==422,'rota alternativa não associa nutriente a sensor pH')
                ok(a.request(url('monitoramentos'),'DELETE',{'confirmacao':'EXCLUIR'})[0]==405,'histórico não pode ser apagado pela API')
                ok(a.request(url('sensores',id=sp),'PATCH',{'status':'inativo'})[0]==200,'sensor de planta pode ser inativado')
                ok(a.request(url('plantas'),'DELETE',{'id':p1,'confirmacao':'EXCLUIR'})[0]==200,'planta pode ser arquivada com sensor inativo')
                ok(a.request(url('sensores',id=sp),'PATCH',{'nome':'Histórico da planta'})[0]==200,'sensor inativo de planta arquivada continua gerenciável')
                ok(a.request(url('sensores',id=sp),'PATCH',{'status':'ativo'})[0]==422,'sensor não pode ser reativado sobre planta arquivada')
                db("INSERT INTO consumo_agua (id_horta,data_consumo,volume_l,origem) VALUES (?,DATE(CONVERT_TZ(UTC_TIMESTAMP(),'+00:00','-03:00')),10,'simulacao')",[h1])
                ok(a.request(url('dashboard'))[1]['data']['consumo_hoje_l'] is None,'consumo simulado não aparece como real')
                db("UPDATE consumo_agua SET origem='manual' WHERE id_horta=?",[h1])
                ok(float(a.request(url('dashboard'))[1]['data']['consumo_hoje_l'])==10,'consumo cadastrado alimenta dashboard')
                ok(hashlib.sha256((source/'php/storage/plantas.json').read_bytes()).hexdigest()==legacy_hash,'JSON original preservado')
            finally:server.terminate();server.wait(timeout=10)
finally:
    if gardens:
        marks=','.join('?' for _ in gardens)
        for table in ['monitoramento_sensor','monitoramento','sensor','planta','estrutura','reservatorio','consumo_agua','horta_cultura','usuario_horta','horta']:
            db(f'DELETE FROM {table} WHERE id_horta IN ({marks})',gardens)
    for nid in nutrients:db('DELETE FROM nutriente WHERE id_nutriente=?',[nid])
    for uid in users:db('DELETE FROM usuario WHERE id_usuario=?',[uid])
    scopes=[('login-ip','127.0.0.1'),('register-ip','127.0.0.1')]+[('login-email',e) for e in emails]
    for scope,identity in scopes:
        digest=hmac.new(key.encode(),(scope+':'+identity).encode(),hashlib.sha256).hexdigest();db('DELETE FROM auth_limite WHERE chave=UNHEX(?)',[digest])

print(f'{checks} verificações aprovadas; fixtures removidas.',flush=True)
