package com.multipro.mobile.data.model;

import com.google.gson.annotations.SerializedName;

public final class AvailabilitySlot {
    private String horario;
    private boolean disponible;

    @SerializedName("motivo_no_disponible")
    private String unavailableReason;

    private String modulo;

    public String getHorario() { return horario; }
    public boolean isDisponible() { return disponible; }
    public String getUnavailableReason() { return unavailableReason; }
    public String getModulo() { return modulo; }
}
