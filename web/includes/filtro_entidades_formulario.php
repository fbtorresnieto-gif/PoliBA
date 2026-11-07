<?php
// Parcial para los tres catálogos. Requiere $filtroEntidades y $filtroCantidad.
if (!isset($filtroEntidades) || !$filtroEntidades instanceof FiltroEntidades) {
    http_response_code(404);
    exit;
}
$filtroRuta = basename($_SERVER['SCRIPT_NAME'] ?? 'index.php');
if (!in_array($filtroRuta, ['index.php','actividades.php','espacios.php'], true)) $filtroRuta = 'index.php';
$filtroEscape = static fn(mixed $v): string => htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$filtroResumen = $filtroEntidades->todas ? 'Todas las entidades activas' : 'Filtros de ubicación y entidad aplicados';
$filtroResultados = (int)$filtroCantidad === 1 ? '1 resultado' : (int)$filtroCantidad.' resultados';
?>
<form method="get" action="<?= $filtroEscape($filtroRuta); ?>" class="catalogo-filtros mb-4" aria-label="Filtrar por ubicación y entidad">
    <div class="catalogo-filtros-fila">
        <div class="catalogo-filtros-titulo"><i class="bi bi-funnel" aria-hidden="true"></i> Filtros</div>
        <?php foreach (['provincias'=>['Provincia','Todas',$filtroEntidades->provincias,$filtroEntidades->provinciasSeleccionadas], 'localidades'=>['Localidad','Todas',$filtroEntidades->localidades,$filtroEntidades->localidadesSeleccionadas], 'entidades'=>['Entidad','Todas',$filtroEntidades->opciones,$filtroEntidades->seleccionadas]] as $grupo=>[$titulo,$vacio,$opciones,$seleccion]):
            $elegidas=array_values(array_filter($opciones,static fn($o)=>in_array((int)$o['id'],$seleccion,true)));
            $valor=count($elegidas)===1?$elegidas[0]['nombre']:(count($elegidas)?count($elegidas).' seleccionadas':$vacio);
        ?>
            <details class="catalogo-filtro" data-vacio="<?= $vacio; ?>">
                <summary aria-label="<?= $titulo; ?>: <?= $filtroEscape($valor); ?>">
                    <span class="catalogo-filtro-texto"><span class="catalogo-filtro-etiqueta"><?= $titulo; ?></span><span class="catalogo-filtro-valor"><?= $filtroEscape($valor); ?></span></span>
                    <span class="catalogo-filtro-flecha" aria-hidden="true"></span>
                </summary>
                <div class="catalogo-filtro-menu">
                    <input type="search" class="form-control form-control-sm catalogo-filtro-buscar" aria-label="Buscar en <?= $titulo; ?>" placeholder="Buscar…" autocomplete="off">
                    <fieldset class="catalogo-filtro-opciones">
                        <legend class="visually-hidden"><?= $titulo; ?> — selección múltiple</legend>
                        <?php foreach ($opciones as $opcion): ?>
                            <div class="form-check catalogo-filtro-opcion">
                                <input class="form-check-input" type="checkbox" name="<?= $grupo; ?>[]" value="<?= (int)$opcion['id']; ?>" id="filtro-<?= $grupo; ?>-<?= (int)$opcion['id']; ?>" <?= in_array((int)$opcion['id'],$seleccion,true)?'checked':''; ?>>
                                <label class="form-check-label" for="filtro-<?= $grupo; ?>-<?= (int)$opcion['id']; ?>"><?= $filtroEscape($opcion['nombre']); ?></label>
                            </div>
                        <?php endforeach; ?>
                        <?php if (!$opciones): ?><p class="small text-muted mb-0">No hay opciones para esta selección.</p><?php endif; ?>
                    </fieldset>
                    <p class="catalogo-filtro-sin-coincidencias small text-muted mb-0" hidden>Sin coincidencias.</p>
                    <div class="d-flex align-items-center justify-content-between gap-2 mt-2">
                        <span class="small text-muted">Selección múltiple</span>
                        <button type="button" class="btn btn-sm btn-outline-dark rounded-pill px-3 catalogo-filtro-listo">Listo</button>
                    </div>
                </div>
            </details>
        <?php endforeach; ?>
    </div>
    <div class="catalogo-filtros-acciones">
        <span class="small text-muted" role="status"><?= $filtroEscape($filtroResultados); ?></span>
        <a class="small text-dark" href="<?= $filtroEscape($filtroRuta); ?>">Limpiar filtros</a>
        <button class="btn btn-dark rounded-pill px-4" type="submit">Aplicar filtros</button>
    </div>
</form>
<?php if ($filtroEntidades->invalido): ?>
    <div class="alert alert-warning" role="alert">Parte de la selección no es válida o no corresponde a la ubicación elegida. Revisá los filtros o limpiá la selección. Solo se muestran coincidencias válidas.</div>
<?php endif; ?>
