package com.multipro.mobile.data.model;

import com.google.gson.annotations.SerializedName;

import java.util.List;

public final class AvailabilityData {
    @SerializedName("espacio_id")
    private int spaceId;

    private String fecha;

    @SerializedName("entidad_abierta")
    private boolean entityOpen;

    private List<AvailabilitySlot> slots;

    public int getSpaceId() { return spaceId; }
    public String getFecha() { return fecha; }
    public boolean isEntityOpen() { return entityOpen; }
    public List<AvailabilitySlot> getSlots() { return slots; }
}
