package com.multipro.mobile.data.model;

import com.google.gson.annotations.SerializedName;

public final class Space {
    private int id;
    private String nombre;
    private String descripcion;
    private boolean techado;

    @SerializedName("entidad_id")
    private int entidadId;

    @SerializedName("entidad_nombre")
    private String entidadNombre;

    @SerializedName("entidad_direccion")
    private String entidadDireccion;

    @SerializedName("dia_apertura")
    private String diaApertura;

    @SerializedName("dia_cierre")
    private String diaCierre;

    @SerializedName("horario_apertura")
    private String horarioApertura;

    @SerializedName("horario_cierre")
    private String horarioCierre;

    @SerializedName("imagen_url")
    private String imageUrl;

    public int getId() { return id; }
    public String getNombre() { return nombre; }
    public String getDescripcion() { return descripcion; }
    public boolean isTechado() { return techado; }
    public int getEntidadId() { return entidadId; }
    public String getEntidadNombre() { return entidadNombre; }
    public String getEntidadDireccion() { return entidadDireccion; }
    public String getDiaApertura() { return diaApertura; }
    public String getDiaCierre() { return diaCierre; }
    public String getHorarioApertura() { return horarioApertura; }
    public String getHorarioCierre() { return horarioCierre; }
    public String getImageUrl() { return imageUrl; }
}
