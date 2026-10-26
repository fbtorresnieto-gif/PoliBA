package com.multipro.mobile.data.model;

import com.google.gson.annotations.SerializedName;

public class StudentAttendance {
    @SerializedName("inscripcion_id") private int enrollmentId;
    @SerializedName("es_menor") private boolean minor;
    @SerializedName("persona_id") private int personId;
    private String nombre;
    private String apellido;
    private String dni;
    private String asistencia;

    public int getEnrollmentId() { return enrollmentId; }
    public boolean isMinor() { return minor; }
    public int getPersonId() { return personId; }
    public String getNombre() { return nombre; }
    public String getApellido() { return apellido; }
    public String getDni() { return dni; }
    public String getAsistencia() { return asistencia; }
    public void setAsistencia(String asistencia) { this.asistencia = asistencia; }
}
