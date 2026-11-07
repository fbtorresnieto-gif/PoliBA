<?php
$ubicacionEscape=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
$ubicacionSufijo=isset($ubicacionActual['id'])?(int)$ubicacionActual['id']:'nueva';
?>
<fieldset class="ubicacion-entidad border rounded p-3 mb-3">
    <legend class="float-none w-auto fs-6 px-1">Ubicación de la entidad</legend>
    <div class="form-check mb-2">
        <input type="radio" class="form-check-input" name="ubicacion_modo" value="catalogo" id="modo-catalogo-<?= $ubicacionSufijo; ?>" checked>
        <label class="form-check-label" for="modo-catalogo-<?= $ubicacionSufijo; ?>">Seleccionar ubicación existente</label>
    </div>
    <label class="form-label fw-bold" for="provincia-<?= $ubicacionSufijo; ?>">Provincia / CABA</label>
    <select id="provincia-<?= $ubicacionSufijo; ?>" name="provincia_id" class="form-select ubicacion-provincia mb-2">
        <option value="">Sin ubicación cargada</option>
        <?php foreach ($provinciasCatalogo as $p): ?><option value="<?= (int)$p['id']; ?>" <?= (int)($ubicacionActual['provincia_id']??0)===(int)$p['id']?'selected':''; ?>><?= $ubicacionEscape($p['nombre']); ?></option><?php endforeach; ?>
    </select>
    <label class="form-label fw-bold" for="buscar-localidad-<?= $ubicacionSufijo; ?>">Buscar localidad</label>
    <input type="search" class="form-control ubicacion-busqueda mb-2" id="buscar-localidad-<?= $ubicacionSufijo; ?>" placeholder="Escribí parte del nombre o del partido / departamento" autocomplete="off">
    <label class="form-label fw-bold" for="localidad-<?= $ubicacionSufijo; ?>">Localidad</label>
    <select id="localidad-<?= $ubicacionSufijo; ?>" name="localidad_id" class="form-select ubicacion-localidad mb-2">
        <option value="">Seleccioná una provincia y luego una localidad</option>
        <?php if(!empty($ubicacionActual['fk_localidad'])): ?><option value="<?= (int)$ubicacionActual['fk_localidad']; ?>" selected><?= $ubicacionEscape(UbicacionEntidades::etiqueta(['nombre'=>$ubicacionActual['localidad'],'departamento'=>$ubicacionActual['localidad_departamento'],'categoria'=>$ubicacionActual['localidad_categoria']])); ?></option><?php endif; ?>
    </select>
    <p class="ubicacion-estado small text-muted" role="status">Ubicaciones disponibles en el catálogo local de MultiPro. Se conserva la dirección por separado.</p>
    <noscript><p class="small">Para buscar en el catálogo necesitás JavaScript. Podés conservar la ubicación actual o utilizar la carga manual.</p></noscript>
    <details>
        <summary class="small">¿No encontrás la ubicación?</summary>
        <div class="form-check my-2">
            <input type="radio" name="ubicacion_modo" value="manual" class="form-check-input" id="modo-manual-<?= $ubicacionSufijo; ?>">
            <label class="form-check-label" for="modo-manual-<?= $ubicacionSufijo; ?>">Cargar una ubicación no incluida en el catálogo</label>
        </div>
        <label class="form-label" for="provincia-manual-<?= $ubicacionSufijo; ?>">Provincia (carga manual)</label>
        <input id="provincia-manual-<?= $ubicacionSufijo; ?>" name="provincia" class="form-control mb-2" maxlength="100">
        <label class="form-label" for="localidad-manual-<?= $ubicacionSufijo; ?>">Localidad (carga manual)</label>
        <input id="localidad-manual-<?= $ubicacionSufijo; ?>" name="localidad" class="form-control mb-2" maxlength="100">
        <p class="small text-muted">Reservá esta opción para ubicaciones que realmente falten. Las altas manuales no tienen código oficial.</p>
    </details>
</fieldset>
