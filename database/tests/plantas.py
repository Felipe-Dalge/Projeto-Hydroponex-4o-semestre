"""Integração do CRUD e migração de plantas com MySQL real, sem frameworks.
Python >=3.10. Banco dedicado vazio de usuários, nome _test/_tests, schema 1.1.0.
python database/tests/plantas.py --executar --php C:/xampp/php/php.exe
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
    with tempfile.TemporaryDirectory(prefix='hydroponex-plants-http-') as directory:
        temp=Path(directory).resolve(); assert temp.is_relative_to(Path(tempfile.gettempdir()).resolve())
        app=temp/'app'; shutil.copytree(source,app)
        with socket.socket() as sock: sock.bind(('127.0.0.1',0)); port=sock.getsockname()[1]
        base=f'http://127.0.0.1:{port}'
        with (temp/'http.log').open('wb') as log:
            server=subprocess.Popen([args.php,'-S',f'127.0.0.1:{port}','server/router.php'],cwd=app,env=env,stdout=log,stderr=log,creationflags=subprocess.CREATE_NO_WINDOW if os.name=='nt' else 0)
            try:
                a,b,guest=Client(base),Client(base),Client(base)
                for _ in range(50):
                    try: guest.request('/php/api/session.php'); break
                    except urllib.error.URLError: time.sleep(.1)
                else: raise RuntimeError('PHP não iniciou.')
                ua=a.register('Ana');ub=b.register('Bruno')
                h1=a.create({'nome':'Cultivo A '+run})[1]['data']['id_horta'];h2=a.create({'nome':'Cultivo B '+run})[1]['data']['id_horta'];hb=b.create({'nome':'Cultivo privado '+run})[1]['data']['id_horta']
                url=lambda h:'/php/api/plantas.php?horta='+str(h)
                ok(guest.request(url(h1))[0]==401,'visitante não acessa plantas')
                ok(a.request('/php/api/plantas.php')[0]==422,'horta é obrigatória, sem leitura de JSON')
                ok(a.request('/php/api/plantas.php','POST',{'nome':'Sem horta','bancada':'A'})[0]==422,'cadastro sem seleção é recusado')
                body={'nome':'Alface A','tipo':'Folhosa','localizacao':'Bancada A1','dias_crescimento':35,'ph_atual':6.25,'temperatura_agua_c':21.55,'umidade_percentual':72.15,'nivel_nutrientes_ppm':783.123,'nivel_irrigacao':3.001,'agua_l':1.125,'status_planta':'Atenção','ultima_medicao_em':'2026-10-28T12:34:56-03:00'}
                ok(a.request(url(h1),'POST',body,csrf=False)[0]==403,'CSRF obrigatório')
                created=a.request(url(h1),'POST',body);pid=created[1]['data']['id']
                ok(created[0]==201 and created[1]['data']['id_horta']==h1,'planta pertence à horta escolhida')
                full=a.request(url(h1)+'&id='+str(pid))[1]['data']
                ok(full['tipo']=='Folhosa' and full['dias_crescimento']==35 and full['ph_atual']==6.25 and full['nivel_irrigacao']==3.001,'detalhes preservam todos os campos do domínio')
                ok(full['bancada']==full['localizacao'] and full['temp']==full['temperatura_agua_c'] and full['nutrientes']==full['nivel_nutrientes_ppm'] and full['agua']==full['agua_l'],'aliases correspondem aos campos canônicos')
                ok(full['ultima_medicao_em']=='2026-10-28 15:34:56.000000','data absoluta convertida para UTC')
                ok(db('SELECT nivel_nutrientes_ppm FROM planta WHERE id_planta=?',[pid])['rows'][0]['nivel_nutrientes_ppm']=='783.123','precisão decimal preservada')
                other=a.request(url(h2),'POST',{'nome':'Rúcula B','bancada':'B1','temp':23,'nutrientes':620,'status':'Alerta'})[1]['data']
                ok(len(a.request(url(h1))[1]['data'])==1 and a.request(url(h2))[1]['data'][0]['id']==other['id'],'duas hortas têm plantas distintas sem mistura')
                ok(a.request(url(h2)+'&id='+str(pid))[0]==404,'detalhes de planta de outra horta são recusados')
                ok(b.request(url(h1))[0]==404,'terceiro não lista a horta')
                ok(b.request(url(h1),'PATCH',{'id':pid,'nome':'Invadida'})[0]==404,'terceiro não edita planta')
                ok(a.request(url(h1),'PATCH',{'id':pid,'id_horta':hb})[0]==422,'não permite transferência implícita por edição')
                ok(a.request(url(h1),'POST',dict(body,nome='Conflito',temp=22))[0]==422,'aliases duplicados são recusados')
                for field,bad in [('ph_atual',14.01),('ph_atual',-1),('umidade_percentual',101),('temperatura_agua_c',-273.16),('nivel_nutrientes_ppm',-1),('nivel_irrigacao',-1),('agua_l',-1),('dias_crescimento',65536),('dias_crescimento',1.5),('ph_atual',True),('ph_atual','NaN'),('ph_atual',6.123),('nivel_nutrientes_ppm',1000000000),('status_planta','Inexistente'),('ultima_medicao_em','2026-02-30 12:00:00'),('ultima_medicao_em','2026-10-28T12:00:00+99:99')]:
                    ok(a.request(url(h1),'PATCH',{'id':pid,field:bad})[0]==422,'valor inválido recusado: '+field+'='+str(bad))
                ok(a.request(url(h1),'PATCH',{'id':pid,'nome':'Mudança parcial inválida','ph_atual':99})[0]==422 and a.request(url(h1)+'&id='+str(pid))[1]['data']['nome']=='Alface A','validação não causa atualização parcial')
                ok(a.request(url(h1),'PATCH',{'id':pid,'ph_atual':None,'temp':None,'agua':None,'dias_crescimento':None})[0]==200,'campos opcionais podem voltar a nulo')
                ok(a.request(url(h1)+'&id='+str(pid))[1]['data']['temp'] is None,'valor nulo não é substituído por zero ou valor antigo')
                wildcard=a.request(url(h1),'POST',{'nome':'Alface %_','bancada':'A2','status':'Normal'})[1]['data']
                from urllib.parse import quote
                filtered=a.request(url(h1)+'&nome=Alface&status='+quote('Atenção'))
                ok(len(filtered[1]['data'])==1 and filtered[1]['data'][0]['id']==pid,'filtros nome e status são combinados')
                ok(filtered[1]['resumo']=={'total':2,'normal':1,'atencao':1,'alerta':0},'indicadores totais não são reduzidos pelo filtro')
                ok(filtered[1]['indicadores']['consumo_hoje_l'] is None,'consumo não é inventado nem derivado da água de uma planta')
                db('INSERT INTO consumo_agua (id_horta,data_consumo,volume_l) VALUES (?,?,?)',[h1,filtered[1]['indicadores']['data'],9.25])
                ok(a.request(url(h1))[1]['indicadores']['consumo_hoje_l']==9.25 and a.request(url(h2))[1]['indicadores']['consumo_hoje_l'] is None,'consumo diário do dashboard vem da horta correta')
                ok(len(a.request(url(h1)+'&nome=%25')[1]['data'])==1,'caractere percentual em nome é literal, não wildcard')
                ok(a.request(url(h1)+'&nome='+quote("' OR 1=1 --"))[1]['data']==[],'busca usa parâmetros, sem injeção SQL')
                ok(a.request(url(h1)+'&status=Outro')[0]==422,'filtro de status inválido é recusado')
                db("INSERT INTO usuario_horta (id_usuario,id_horta,papel) VALUES (?,?,'leitor')",[ub,h1])
                ok(b.request(url(h1))[0]==200 and b.request(url(h1),'POST',body)[0]==403,'leitor consulta sem cadastrar')
                db("UPDATE usuario_horta SET papel='gestor' WHERE id_usuario=? AND id_horta=?",[ub,h1])
                ok(b.request(url(h1),'PATCH',{'id':pid,'tipo':'Folhosa editada'})[0]==200,'gestor pode editar')
                ok(a.request(url(h1),'DELETE',{'id':wildcard['id']})[0]==422,'exclusão exige confirmação no servidor')
                ok(a.request(url(h1),'DELETE',{'id':wildcard['id'],'confirmacao':'EXCLUIR'})[0]==200,'exclusão confirmada retira planta da listagem')
                ok(db('SELECT arquivada_em FROM planta WHERE id_planta=?',[wildcard['id']])['rows'][0]['arquivada_em'] is not None and a.request(url(h1)+'&id='+str(wildcard['id']))[0]==404,'exclusão lógica conserva registro e bloqueia detalhes comuns')
                ok(a.request(url(h1))[1]['resumo']['total']==1,'indicadores descontam planta excluída')
                transfer='/php/api/plantas_transferir.php?horta='+str(h1)
                ok(a.request(transfer,'POST',{'id':pid,'horta_destino':hb,'confirmacao':'TRANSFERIR'})[0]==404,'transferência exige permissão no destino')
                ok(a.request(transfer,'POST',{'id':pid,'horta_destino':h2,'confirmacao':'sim'})[0]==422,'transferência exige confirmação')
                moved=a.request(transfer,'POST',{'id':pid,'horta_destino':h2,'confirmacao':'TRANSFERIR'})
                ok(moved[0]==200 and moved[1]['data']['id']==pid and moved[1]['data']['tipo']=='Folhosa editada','transferência preserva ID e dados')
                ok(a.request(url(h1))[1]['resumo']['total']==0 and a.request(url(h2))[1]['resumo']['total']==2,'contagens de origem e destino atualizadas')
                # Estruturas/culturas não podem ser silenciosamente descartadas ou cruzadas.
                s1=int(db("INSERT INTO estrutura (id_horta,nome) VALUES (?,'A')",[h1])['id']);s2=int(db("INSERT INTO estrutura (id_horta,nome) VALUES (?,'B')",[h2])['id'])
                linked=a.request(url(h1),'POST',{'nome':'Com estrutura','bancada':'A','id_estrutura':s1})[1]['data']['id']
                ok(a.request(transfer,'POST',{'id':linked,'horta_destino':h2,'confirmacao':'TRANSFERIR'})[0]==409,'transferência não descarta estrutura sem confirmação explícita')
                ok(a.request(transfer,'POST',{'id':linked,'horta_destino':h2,'id_estrutura':s1,'confirmacao':'TRANSFERIR'})[0]==422,'estrutura de origem não é usada no destino')
                ok(a.request(transfer,'POST',{'id':linked,'horta_destino':h2,'id_estrutura':s2,'confirmacao':'TRANSFERIR'})[0]==200,'transferência pode reassociar estrutura válida no destino')
                sensor=int(db("INSERT INTO sensor (id_horta,id_planta,nome,tipo,unidade) VALUES (?,?,'pH','ph','pH')",[h2,linked])['id'])
                ok(a.request(url(h2),'DELETE',{'id':linked,'confirmacao':'EXCLUIR'})[0]==409,'sensor ativo impede exclusão operacional')
                ok(a.request('/php/api/plantas_transferir.php?horta='+str(h2),'POST',{'id':linked,'horta_destino':h1,'id_estrutura':None,'confirmacao':'TRANSFERIR'})[0]==409,'sensores impedem transferência que perderia contexto histórico')
                db("UPDATE sensor SET status='inativo' WHERE id_sensor=?",[sensor])
                ok(a.request(url(h2),'DELETE',{'id':linked,'confirmacao':'EXCLUIR'})[0]==200,'sensor inativo e histórico são preservados por exclusão lógica')
                ok(db('SELECT id_planta FROM sensor WHERE id_sensor=?',[sensor])['rows'][0]['id_planta']==linked,'FK do sensor histórico continua válida')
                # Migração real do arquivo original, somente para uma horta explícita de teste.
                hm=a.create({'nome':'Migração '+run})[1]['data']['id_horta']
                def migrate(path,horta,apply=True,expected=0):
                    argv=[args.php,'database/migrations/001_importar_json.php','--horta='+str(horta),'--arquivo='+str(path)]+(['--aplicar'] if apply else [])
                    result=subprocess.run(argv,cwd=root,env=env,capture_output=True,text=True,encoding='utf-8')
                    assert result.returncode==expected,result.stdout+result.stderr
                    return result.stdout
                original=source/'php/storage/plantas.json'
                ok('nenhuma conexão' in migrate(original,hm,False),'simulação não grava no banco')
                ok('5 criados' in migrate(original,hm),'cinco registros originais migrados para horta explícita')
                ok('0 criados' in migrate(original,hm),'repetição idempotente, sem duplicação')
                imported=a.request(url(hm))[1]['data']
                ok(len(imported)==5 and all(p['id_horta']==hm for p in imported),'dados migrados aparecem só no destino')
                payloads=db('SELECT i.id_origem,i.payload FROM importacao_json i JOIN planta p ON p.id_planta=i.id_planta WHERE p.id_horta=? ORDER BY i.id_origem',[hm])['rows']
                raw=json.loads(original.read_text(encoding='utf-8'))
                ok([json.loads(r['payload']) for r in payloads]==raw,'payload integral de cada registro preservado')
                ok(all(p['ultima_medicao_em'] is None for p in imported),'lastUpdate relativo não vira data inventada')
                ok(imported[0]['umidade']==68 and imported[0]['agua']==1.2 and imported[0]['nivel_irrigacao'] is None,'água, umidade e irrigação não são confundidas')
                ipid=imported[0]['id']
                ok(a.request('/php/api/plantas_transferir.php?horta='+str(hm),'POST',{'id':ipid,'horta_destino':h1,'confirmacao':'TRANSFERIR'})[0]==409,'auditoria de importação impede transferência silenciosa')
                ok(a.request(url(hm),'DELETE',{'id':ipid,'confirmacao':'EXCLUIR'})[0]==200,'planta importada pode ser excluída logicamente sem perder auditoria')
                migrate(original,hm)
                ok(a.request(url(hm))[1]['resumo']['total']==4,'reimportação não ressuscita planta excluída')
                migrate(original,h1,expected=1)
                ok(a.request(url(h1))[1]['resumo']['total']==0,'mesmo snapshot não é duplicado em outra horta')
                changed=temp/'changed.json';changed.write_text(json.dumps([dict(r,nome='Mudança') for r in raw]),encoding='utf-8');migrate(changed,hm,expected=1)
                ok(a.request(url(hm))[1]['resumo']['total']==4,'snapshot alterado é recusado sem escritas parciais')
                # Falha após primeiro INSERT: segunda linha usa estrutura de outra horta.
                bad=temp/'bad.json';bad.write_text(json.dumps([{'id':201,'nome':'Primeira','bancada':'A'},{'id':202,'nome':'Inválida','bancada':'A','id_estrutura':s2}]),encoding='utf-8')
                before=db('SELECT COUNT(*) AS n FROM planta WHERE id_horta=?',[h1])['rows'][0]['n'];migrate(bad,h1,expected=1)
                ok(db('SELECT COUNT(*) AS n FROM planta WHERE id_horta=?',[h1])['rows'][0]['n']==before,'erro na segunda linha reverte toda a migração')
            finally:
                server.terminate();server.wait(timeout=10)
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
print(f'{checks} verificações de plantas/migração aprovadas; fixtures removidas.',flush=True)
