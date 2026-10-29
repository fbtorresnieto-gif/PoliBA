<?php
declare(strict_types=1);
require_once __DIR__.'/_datos.php';
apiAceptarMetodo('GET');
try {
    [$pdo,$usuario]=apiContextoAutenticado('Alumno');
    $s=$pdo->prepare(reservaApiSeleccion()." WHERE r.fk_usuario=? AND r.estado<>'vencido' ORDER BY r.fecha_de_asistencia DESC,r.horario DESC");
    $s->execute([$usuario['id']]);
    apiResponder(['data'=>array_map('reservaApiDatos',$s->fetchAll(PDO::FETCH_ASSOC))]);
} catch(Throwable $e) {
    error_log('MultiPro API listar reservas: fallo interno');apiResponder(['error'=>'No se pudieron consultar las reservas.'],500);
}
