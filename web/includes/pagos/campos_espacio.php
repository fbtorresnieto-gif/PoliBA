<?php
// Parcial compartido: recibe $cobroEspacio y $sufijoCobro del formulario servidor.
$precioCobro = (string)($cobroEspacio['precio_hora_ars'] ?? '0.00');
?>
<fieldset class="border rounded-4 p-3 mb-3" data-configuracion-cobro>
    <legend class="float-none w-auto px-2 fs-6 fw-bold">Precio y formas de pago</legend>
    <label class="form-label fw-bold" for="precio<?= htmlspecialchars($sufijoCobro); ?>">Precio final por hora (ARS) *</label>
    <input type="text" inputmode="decimal" name="precio_hora_ars" id="precio<?= htmlspecialchars($sufijoCobro); ?>"
           class="form-control rounded-pill px-3" required maxlength="13" pattern="[0-9]{1,10}([.,][0-9]{1,2})?"
           value="<?= htmlspecialchars($precioCobro); ?>" aria-describedby="ayudaPrecio<?= htmlspecialchars($sufijoCobro); ?>">
    <div class="form-text mb-3" id="ayudaPrecio<?= htmlspecialchars($sufijoCobro); ?>">$0 significa gratuito. Usá coma o punto para los decimales, sin separadores de miles. El precio es final y se aplica a nuevas reservas.</div>
    <div class="form-check form-switch mb-2">
        <input class="form-check-input" type="checkbox" name="pago_presencial_habilitado" value="1"
               id="presencial<?= htmlspecialchars($sufijoCobro); ?>" data-pago-presencial
               <?= !empty($cobroEspacio['pago_presencial_habilitado']) ? 'checked' : ''; ?>
               <?= $precioCobro === '0.00' ? 'disabled' : ''; ?> aria-describedby="ayudaPresencial<?= htmlspecialchars($sufijoCobro); ?>">
        <label class="form-check-label" for="presencial<?= htmlspecialchars($sufijoCobro); ?>">Permitir pago presencial</label>
    </div>
    <div class="form-text mb-3" id="ayudaPresencial<?= htmlspecialchars($sufijoCobro); ?>">Permite reservar con pago pendiente en la entidad. El administrador registra el cobro. En espacios gratuitos no corresponde cobrar.</div>
    <div class="form-check form-switch">
        <input class="form-check-input" type="checkbox" id="online<?= htmlspecialchars($sufijoCobro); ?>" disabled
               aria-describedby="ayudaOnline<?= htmlspecialchars($sufijoCobro); ?>">
        <label class="form-check-label text-muted" for="online<?= htmlspecialchars($sufijoCobro); ?>">Permitir pago online</label>
    </div>
    <div class="form-text" id="ayudaOnline<?= htmlspecialchars($sufijoCobro); ?>">Disponible cuando se conecte Mercado Pago.</div>
</fieldset>
