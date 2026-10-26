package com.multipro.mobile.data.model;

import com.google.gson.annotations.SerializedName;

public final class ReservationCreateData {
    private int id;

    @SerializedName("espacio_id")
    private int spaceId;

    private String fecha;
    private String horario;
    private String estado;

    public int getId() { return id; }
    public int getSpaceId() { return spaceId; }
    public String getFecha() { return fecha; }
    public String getHorario() { return horario; }
    public String getEstado() { return estado; }
}
