<?php
declare(strict_types=1);
require_once __DIR__.'/../services/gardens.php';
auth_api(['GET','POST','PATCH','PUT','DELETE'],function():void {
    $user=require_authenticated_user(); $pdo=database_connection(); $method=$_SERVER['REQUEST_METHOD'];
    $id=isset($_GET['id'])?garden_id($_GET['id']):null;
    if($method==='GET') {
        if($id!==null) send_json(['success'=>true,'data'=>garden_read($pdo,$id,(int)$user['id_usuario'])]);
        $page=filter_var($_GET['pagina']??1,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>1000000]]);
        if($page===false) auth_error(422,'Página inválida.');
        $s=$pdo->prepare('SELECT COUNT(*) FROM usuario_horta uh JOIN horta h ON h.id_horta=uh.id_horta WHERE uh.id_usuario=? AND h.arquivada_em IS NULL'); $s->execute([$user['id_usuario']]); $total=(int)$s->fetchColumn();
        $s=$pdo->prepare(garden_select_sql().' ORDER BY h.id_horta DESC LIMIT 12 OFFSET ?');
        $s->bindValue(1,(int)$user['id_usuario'],PDO::PARAM_INT); $s->bindValue(2,($page-1)*12,PDO::PARAM_INT); $s->execute();
        send_json(['success'=>true,'data'=>garden_hydrate($pdo,$s->fetchAll()),'meta'=>['pagina'=>$page,'por_pagina'=>12,'total'=>$total]]);
    }
    require_csrf();
    if($method==='DELETE') {
        if($id===null)auth_error(422,'Informe a horta.');
        garden_delete($user,$id,auth_body(['confirmacao'])); send_json(['success'=>true,'message'=>'Horta excluída.']);
    }
    $data=auth_body(['nome','tamanho_m2','localizacao','cep','estrutura']);
    if($method==='POST' && $id!==null) auth_error(422,'Não envie um identificador ao criar uma horta.');
    if($method!=='POST' && $id===null) auth_error(422,'Informe a horta.');
    $result=garden_save($user,$data,$id);
    send_json(['success'=>true,'data'=>$result,'message'=>$method==='POST'?'Horta cadastrada.':'Horta atualizada.'],$method==='POST'?201:200);
});
