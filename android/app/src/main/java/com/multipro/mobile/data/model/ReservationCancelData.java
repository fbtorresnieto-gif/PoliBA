package com.multipro.mobile.data.model;

public final class ReservationCancelData {
    @com.google.gson.annotations.SerializedName("devolucion_presencial") private String refund;
    public boolean isRefundPending() { return "pendiente".equals(refund); }
    private int id;
    private String estado;

    public int getId() { return id; }
    public String getEstado() { return estado; }
}
