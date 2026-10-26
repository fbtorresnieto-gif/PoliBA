package com.multipro.mobile.data.model;

import com.google.gson.annotations.SerializedName;

public class AttendanceRequest {
    @SerializedName("modulo_id") private final int moduleId;
    @SerializedName("inscripcion_id") private final int enrollmentId;
    private final String fecha;
    private final String estado;

    public AttendanceRequest(int moduleId, int enrollmentId, String fecha, String estado) {
        this.moduleId = moduleId;
        this.enrollmentId = enrollmentId;
        this.fecha = fecha;
        this.estado = estado;
    }
}
