<?php
declare(strict_types=1);
require_once __DIR__.'/../services/garden_plants.php';
auth_api(['GET','POST','PUT','PATCH','DELETE'],function():void {
    $user=require_authenticated_user();
    if(!array_key_exists('horta',$_GET)) auth_error(422,'Selecione uma horta para gerenciar suas plantas.');
    garden_plants_api($user,garden_id($_GET['horta']));
});
