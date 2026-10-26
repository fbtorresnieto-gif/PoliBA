package com.multipro.mobile.data.model;

import com.google.gson.annotations.SerializedName;

public final class User {
    private int id;
    private String nombre;
    private String apellido;
    private String email;
    private String rol;
    private String dni;
    private String direccion;
    private String telefono;
    @SerializedName("fecha_nacimiento") private String fechaNacimiento;

    @SerializedName("entidad_id")
    private Integer entidadId;

    @SerializedName("entidad_nombre")
    private String entidadNombre;

    public int getId() { return id; }
    public String getNombre() { return nombre; }
    public String getApellido() { return apellido; }
    public String getEmail() { return email; }
    public String getRol() { return rol; }
    public String getDni() { return dni; }
    public String getDireccion() { return direccion; }
    public String getTelefono() { return telefono; }
    public String getFechaNacimiento() { return fechaNacimiento; }
    public Integer getEntidadId() { return entidadId; }
    public String getEntidadNombre() { return entidadNombre; }
}
