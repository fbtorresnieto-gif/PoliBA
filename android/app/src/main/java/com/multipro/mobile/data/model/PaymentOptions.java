package com.multipro.mobile.data.model;

import com.google.gson.annotations.SerializedName;
import java.math.BigDecimal;
import java.util.List;

/** Prices are decimal text from the server; missing contracts never imply a free space. */
public class PaymentOptions {
    @SerializedName("precio_hora_ars") private String hourlyPrice;
    private String moneda;
    @SerializedName("duracion_minutos") private int duration;
    @SerializedName("pago_presencial_habilitado") private boolean presential;
    @SerializedName("modalidades_disponibles") private List<String> modes;

    public String getHourlyPrice() { return hourlyPrice; }
    public boolean hasValidPrice() {
        return hourlyPrice != null && hourlyPrice.matches("(0|[1-9][0-9]{0,9})\\.[0-9]{2}")
                && "ARS".equals(moneda) && duration == 60;
    }
    public String getBookingMode() {
        if (!hasValidPrice() || modes == null) return null;
        if (new BigDecimal(hourlyPrice).signum() == 0) return modes.contains("gratuito") ? "gratuito" : null;
        return presential && modes.contains("presencial") ? "presencial" : null;
    }
}
