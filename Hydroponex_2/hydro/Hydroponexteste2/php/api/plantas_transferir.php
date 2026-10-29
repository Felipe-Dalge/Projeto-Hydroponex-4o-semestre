<?php
declare(strict_types=1);
require_once __DIR__.'/../services/garden_plants.php';
auth_api(['POST'],function():void {
    $user=require_authenticated_user();require_csrf();
    $source=garden_id($_GET['horta']??null);
    $data=auth_body(['id','horta_destino','confirmacao','id_estrutura','id_cultura']);
    send_json(['success'=>true,'data'=>plant_transfer($user,$source,$data),'message'=>'Planta transferida.']);
});
