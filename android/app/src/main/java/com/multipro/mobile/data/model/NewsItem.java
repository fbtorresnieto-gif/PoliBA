package com.multipro.mobile.data.model;

import com.google.gson.annotations.SerializedName;

public class NewsItem {
    private int id;
    private String nombre;
    private String descripcion;
    @SerializedName("fecha_inicio") private String startDate;
    @SerializedName("fecha_fin") private String endDate;
    @SerializedName("entidad_nombre") private String entityName;
    @SerializedName("imagen_url") private String imageUrl;

    public int getId() { return id; }
    public String getNombre() { return nombre; }
    public String getDescripcion() { return descripcion; }
    public String getStartDate() { return startDate; }
    public String getEndDate() { return endDate; }
    public String getEntityName() { return entityName; }
    public String getImageUrl() { return imageUrl; }
}
