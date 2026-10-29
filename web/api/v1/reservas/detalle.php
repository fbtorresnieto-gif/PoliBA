<?php
declare(strict_types=1);
require_once __DIR__.'/_datos.php';
apiAceptarMetodo('GET');
$id=apiParametroEntero('id');
try {
    [$pdo,$usuario]=apiContextoAutenticado('Alumno');
    apiResponder(['data'=>reservaApiPropia($pdo,$id,(int)$usuario['id'])]);
} catch(Throwable $e) {
    error_log('MultiPro API detalle reserva: fallo interno');apiResponder(['error'=>'No se pudo consultar la reserva.'],500);
}
