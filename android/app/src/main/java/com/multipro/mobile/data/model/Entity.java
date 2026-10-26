package com.multipro.mobile.data.model;

import com.google.gson.annotations.SerializedName;

public final class Entity {
    private int id;
    private String nombre;
    private String direccion;
    private String informacion;

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
    public String getDireccion() { return direccion; }
    public String getInformacion() { return informacion; }
    public String getDiaApertura() { return diaApertura; }
    public String getDiaCierre() { return diaCierre; }
    public String getHorarioApertura() { return horarioApertura; }
    public String getHorarioCierre() { return horarioCierre; }
    public String getImageUrl() { return imageUrl; }
}
