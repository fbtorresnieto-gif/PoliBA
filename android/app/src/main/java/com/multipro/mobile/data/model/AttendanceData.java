package com.multipro.mobile.data.model;

import java.util.List;

public class AttendanceData {
    private AttendanceModule modulo;
    private String fecha;
    private List<StudentAttendance> alumnos;
    public AttendanceModule getModulo() { return modulo; }
    public String getFecha() { return fecha; }
    public List<StudentAttendance> getAlumnos() { return alumnos; }
}
