package com.multipro.mobile.data.model;

import java.math.BigDecimal;
import java.text.NumberFormat;
import java.util.Locale;

public final class ArsMoney {
    private ArsMoney() {}
    public static String format(String decimal) {
        if (decimal == null || !decimal.matches("(0|[1-9][0-9]{0,9})\\.[0-9]{2}")) return null;
        BigDecimal amount = new BigDecimal(decimal);
        if (amount.signum() == 0) return "Gratuito";
        NumberFormat format = NumberFormat.getNumberInstance(new Locale("es", "AR"));
        format.setMinimumFractionDigits(2);
        format.setMaximumFractionDigits(2);
        return "$ " + format.format(amount) + " ARS";
    }
}
