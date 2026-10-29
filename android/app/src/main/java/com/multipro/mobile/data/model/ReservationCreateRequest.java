package com.multipro.mobile.data.model;

import com.google.gson.annotations.SerializedName;

public final class ReservationCreateRequest {
    @SerializedName("espacio_id")
    private final int spaceId;

    private final String fecha;
    private final String horario;
    @SerializedName("modalidad_pago") private final String paymentMode;
    @SerializedName("precio_visto") private final String seenPrice;

    public ReservationCreateRequest(int spaceId, String date, String time) {
        this(spaceId, date, time, null, null);
    }

    public ReservationCreateRequest(int spaceId, String date, String time, String mode, String price) {
        this.spaceId = spaceId;
        this.fecha = date;
        this.horario = time;
        this.paymentMode = mode;
        this.seenPrice = price;
    }
}
