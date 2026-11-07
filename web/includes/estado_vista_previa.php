<?php if(isset($estadoVistaPrevia)):
$vp=$estadoVistaPrevia;$vpEscape=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
?>
<div class="container my-3"><section class="border border-warning rounded p-3 bg-light" aria-labelledby="titulo-impacto">
    <h2 class="h5" id="titulo-impacto">Revisar impacto: <?= $vpEscape($vp['nombre']); ?></h2>
    <p>Acción: <strong><?= $vpEscape(str_replace('_',' ',$vp['accion'])); ?></strong>. Motivo: <?= $vpEscape($vp['motivo']); ?></p>
    <ul>
        <li>Módulos activos vinculados: <?= $vp['modulos_activos']; ?>. Inscripciones y espera conservadas: <?= $vp['inscripciones']; ?>.</li>
        <li>Reservas futuras vinculadas: <?= $vp['reservas']; ?>. Al desactivar entidad o espacio se cancelarán.</li>
        <li>De esas reservas, presenciales cobradas: <?= $vp['devoluciones']; ?>. Devolución total a dejar pendiente al desactivar: $ <?= $vp['importe']; ?>.</li>
        <li>Cobros y devoluciones actualmente pendientes en la entidad: <?= $vp['pendientes']; ?>. Impiden cerrar la administración o desactivar al último administrador.</li>
    </ul>
    <p class="small">No se entregará dinero automáticamente. Reactivar no restaura reservas ni activa hijos. La baja individual de un espacio se bloqueará si conserva módulos activos. El impacto se verificará nuevamente al confirmar.</p>
    <form method="post" class="d-flex gap-2">
        <?php foreach(['estado_accion'=>$vp['accion'],'recurso_id'=>$vp['id'],'motivo'=>$vp['motivo'],'impacto_firma'=>$vp['firma'],'csrf_estados'=>$_SESSION['csrf_estados'],'csrf_entidades'=>$_SESSION['csrf_entidades']??'','csrf_espacios'=>$_SESSION['csrf_espacios']??''] as $nombre=>$valor): ?>
            <input type="hidden" name="<?= $nombre; ?>" value="<?= $vpEscape($valor); ?>">
        <?php endforeach; ?>
        <button class="btn btn-danger" type="submit">Confirmar operación</button>
        <a class="btn btn-outline-secondary" href="<?= $vpEscape(basename($_SERVER['SCRIPT_NAME'])); ?>">Volver sin cambios</a>
    </form>
</section></div>
<?php endif; ?>
