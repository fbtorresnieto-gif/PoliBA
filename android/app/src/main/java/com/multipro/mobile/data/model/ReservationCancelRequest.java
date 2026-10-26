package com.multipro.mobile.data.model;

import com.google.gson.annotations.SerializedName;

public final class ReservationCancelRequest {
    @SerializedName("reserva_id")
    private final int reservationId;

    public ReservationCancelRequest(int reservationId) {
        this.reservationId = reservationId;
    }
}
