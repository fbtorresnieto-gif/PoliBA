package com.multipro.mobile.data.model;

import com.google.gson.annotations.SerializedName;

public class Module {
    private int id;
    private String nombre;
    private String descripcion;
    @SerializedName("horario_inicio") private String startTime;
    @SerializedName("horario_cierre") private String endTime;
    @SerializedName("cupo_maximo") private int capacity;
    @SerializedName("actividad_nombre") private String activityName;
    @SerializedName("entidad_nombre") private String entityName;
    @SerializedName("espacio_nombre") private String spaceName;

    public int getId() { return id; }
    public String getNombre() { return nombre; }
    public String getDescripcion() { return descripcion; }
    public String getStartTime() { return startTime; }
    public String getEndTime() { return endTime; }
    public int getCapacity() { return capacity; }
    public String getActivityName() { return activityName; }
    public String getEntityName() { return entityName; }
    public String getSpaceName() { return spaceName; }
}
