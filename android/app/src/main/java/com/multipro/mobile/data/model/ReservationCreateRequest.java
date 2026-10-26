package com.multipro.mobile.data.model;

import com.google.gson.annotations.SerializedName;

public final class ReservationCreateRequest {
    @SerializedName("espacio_id")
    private final int spaceId;

    private final String fecha;
    private final String horario;

    public ReservationCreateRequest(int spaceId, String date, String time) {
        this.spaceId = spaceId;
        this.fecha = date;
        this.horario = time;
    }
}
