<?php
declare(strict_types=1);
require_once __DIR__.'/_bootstrap.php';
function apiCobroEspacio(array $e): array {
    $gratis=$e['precio_hora_ars']==='0.00';
    $presencial=!$gratis&&(bool)$e['pago_presencial_habilitado'];
    return ['precio_hora_ars'=>$e['precio_hora_ars'],'moneda'=>'ARS','duracion_minutos'=>60,
        'pago_presencial_habilitado'=>$presencial,'cobro_online_habilitado'=>false,
        'modalidades_disponibles'=>$gratis?['gratuito']:($presencial?['presencial']:[])];
}
if(realpath((string)($_SERVER['SCRIPT_FILENAME']??''))===__FILE__)apiResponder(['error'=>'Recurso no encontrado.'],404);
