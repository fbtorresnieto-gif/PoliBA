<?php
$estadoId=(int)$estadoRegistro['id'];
$estadoActiva=(bool)$estadoRegistro['estado'];
$estadoEscape=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
?>
<details class="mt-2">
    <summary class="btn btn-sm <?= $estadoActiva?'btn-outline-danger':'btn-outline-success'; ?> rounded-pill"><?= $estadoTipo==='modulo'?'Estado e inscripciones':($estadoActiva?'Desactivar':($estadoTipo==='entidad'?'Reactivar / cerrar':'Reactivar')); ?></summary>
    <form method="post" class="border rounded p-2 mt-2" style="min-width:220px">
        <input type="hidden" name="csrf_estados" value="<?= $estadoEscape($_SESSION['csrf_estados']); ?>">
        <input type="hidden" name="csrf_espacios" value="<?= $estadoEscape($_SESSION['csrf_espacios']??''); ?>">
        <input type="hidden" name="csrf_entidades" value="<?= $estadoEscape($_SESSION['csrf_entidades']??''); ?>">
        <input type="hidden" name="recurso_id" value="<?= $estadoId; ?>">
        <p class="small mb-2">
            <?php if($estadoActiva && in_array($estadoTipo,['entidad','espacio'],true)): ?>Se cancelarán las reservas futuras. Los pagos presenciales cobrados generarán devoluciones totales pendientes; el dinero debe entregarse y registrarse después.
            <?php elseif($estadoActiva && in_array($estadoTipo,['actividad','modulo'],true)): ?>Se suspenderán las inscripciones y la lista de espera, conservando su historial.
            <?php elseif(!$estadoActiva): ?>La reactivación no restaura reservas canceladas ni activa automáticamente módulos o inscripciones.
            <?php endif; ?>
            <?php if($estadoTipo==='espacio' && $estadoActiva): ?>Reubicá antes todos los módulos activos del espacio.<?php endif; ?>
        </p>
        <label class="form-label small" for="estado-motivo-<?= $estadoId; ?>">Motivo</label>
        <input id="estado-motivo-<?= $estadoId; ?>" class="form-control form-control-sm mb-2" name="motivo" maxlength="500" required>
        <button class="btn btn-sm <?= $estadoActiva?'btn-danger':'btn-success'; ?>" name="estado_accion" value="<?= $estadoActiva?'desactivar':'activar'; ?>">Revisar <?= $estadoActiva?'desactivación':'reactivación'; ?></button>
        <?php if($estadoTipo==='modulo' && $estadoActiva): ?><button class="btn btn-sm btn-outline-success mt-2" name="estado_accion" value="reactivar_inscripciones">Revisar reactivación de inscripciones y espera</button><?php endif; ?>
        <?php if($estadoTipo==='entidad' && !$estadoActiva && empty($estadoRegistro['cierre_administrativo_en'])): ?><button class="btn btn-sm btn-outline-dark mt-2" name="estado_accion" value="cerrar">Revisar cierre administrativo</button><?php endif; ?>
    </form>
</details>
<?php if($estadoTipo==='entidad' && !$estadoActiva): ?><p class="small mt-1"><?= empty($estadoRegistro['cierre_administrativo_en'])?'Administración abierta para cobros y devoluciones.':'Administración cerrada. Historial conservado.'; ?></p><?php endif; ?>
