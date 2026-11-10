<?php
declare(strict_types=1);

require_once __DIR__ . '/../../web/includes/VisibilidadEntidades.php';
require_once __DIR__ . '/../../web/includes/FiltroEntidades.php';

function verificar(bool $condicion, string $mensaje): void
{
    if (!$condicion) throw new RuntimeException($mensaje);
}

function verificarFalso(bool $condicion, string $mensaje): void
{
    if ($condicion) throw new RuntimeException($mensaje);
}

[$sqlInvitado, $parametrosInvitado] = VisibilidadEntidades::condicion(null, 'e');
verificar($sqlInvitado === "e.tipo_entidad = 'publica'" && $parametrosInvitado === [], 'El invitado debe quedar limitado a entidades públicas.');

[$sqlUsuario, $parametrosUsuario] = VisibilidadEntidades::condicion(['rol_nombre' => 'Alumno', 'fk_entidad' => 2], 'e');
verificar(str_contains($sqlUsuario, "e.tipo_entidad = 'publica'") && str_contains($sqlUsuario, 'e.id = ?') && $parametrosUsuario === [2], 'El usuario debe ver las públicas y su privada.');

[$sqlGestor, $parametrosGestor] = VisibilidadEntidades::condicion(['rol_nombre' => 'Gestor'], 'e');
verificar($sqlGestor === 'TRUE' && $parametrosGestor === [], 'El Gestor debe obtener alcance total.');

$opcionesVisibles = [
    ['id' => 2, 'nombre' => 'Privada propia', 'localidad_id' => 1, 'localidad' => 'La Plata', 'provincia_id' => 1, 'provincia' => 'Buenos Aires'],
    ['id' => 1, 'nombre' => 'Pública', 'localidad_id' => 1, 'localidad' => 'La Plata', 'provincia_id' => 1, 'provincia' => 'Buenos Aires'],
];
$vinculado = new FiltroEntidades($opcionesVisibles, []);
[$sqlVinculado, $parametrosVinculado] = $vinculado->condicion('a.fk_entidad');
verificar(str_contains($sqlVinculado, 'IN') && $parametrosVinculado === [2, 1], 'El filtro debe conservar el alcance visible aun sin selección explícita.');

verificar(VisibilidadEntidades::puedeVer(null, 1, 'publica'), 'Una entidad pública debe ser visible sin sesión.');
verificarFalso(VisibilidadEntidades::puedeVer(null, 2, 'privada'), 'Una entidad privada no debe ser visible sin sesión.');
verificar(VisibilidadEntidades::puedeVer(['rol_nombre' => 'Alumno', 'fk_entidad' => 2], 2, 'privada'), 'El usuario vinculado debe ver su entidad privada.');
verificarFalso(VisibilidadEntidades::puedeVer(['rol_nombre' => 'Alumno', 'fk_entidad' => 2], 3, 'privada'), 'El usuario no debe ver otra entidad privada.');
verificar(VisibilidadEntidades::puedeVer(['rol_nombre' => 'Gestor'], 3, 'privada'), 'El Gestor debe ver cualquier entidad privada.');

foreach (['perfil.php', 'estadisticas.php', 'espacios.php', 'novedades.php', 'reservas.php'] as $rutaPermitida) {
    verificar(VisibilidadEntidades::administradorPuedeAcceder('privada', $rutaPermitida), 'La entidad privada debe acceder a '.$rutaPermitida);
}
foreach (['actividades.php', 'modulos.php', 'profesores.php', 'subcategorias.php', 'alumnos.php'] as $rutaBloqueada) {
    verificarFalso(VisibilidadEntidades::administradorPuedeAcceder('privada', $rutaBloqueada), 'La entidad privada no debe acceder a '.$rutaBloqueada);
}
verificar(VisibilidadEntidades::administradorPuedeAcceder('publica', 'actividades.php'), 'La entidad pública debe conservar sus ABM.');

echo "VISIBILIDAD_ENTIDADES_OK invitado_vinculado_gestor_filtro_panel_privado\n";
