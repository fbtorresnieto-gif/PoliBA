package com.multipro.mobile.data.model;

import com.google.gson.annotations.SerializedName;

public final class Reservation {
    private int id;

    @SerializedName("espacio_id")
    private int spaceId;

    @SerializedName("espacio_nombre")
    private String spaceName;

    @SerializedName("entidad_id")
    private int entityId;

    @SerializedName("entidad_nombre")
    private String entityName;

    @SerializedName("entidad_direccion")
    private String entityAddress;

    private String fecha;
    private String horario;
    private String estado;

    public int getId() { return id; }
    public int getSpaceId() { return spaceId; }
    public String getSpaceName() { return spaceName; }
    public int getEntityId() { return entityId; }
    public String getEntityName() { return entityName; }
    public String getEntityAddress() { return entityAddress; }
    public String getFecha() { return fecha; }
    public String getHorario() { return horario; }
    public String getEstado() { return estado; }
    public boolean isActive() { return "reservado".equalsIgnoreCase(estado); }
}
