package com.multipro.mobile.ui;

import android.content.Context;
import com.multipro.mobile.R;
import com.multipro.mobile.data.model.ArsMoney;
import com.multipro.mobile.data.model.PaymentOptions;
import com.multipro.mobile.data.model.Reservation;

final class PaymentUi {
    private PaymentUi() {}
    static String price(Context c, PaymentOptions data) {
        String amount = data.hasValidPrice() ? ArsMoney.format(data.getHourlyPrice()) : null;
        return amount == null ? c.getString(R.string.mp_price_unknown) : c.getString(R.string.mp_hour_price, amount);
    }
    static String mode(Context c, PaymentOptions data) {
        String mode = data.getBookingMode();
        return c.getString(mode == null ? R.string.mp_no_mode : "gratuito".equals(mode)
                ? R.string.mp_free_mode : R.string.mp_presential_mode);
    }
    static int status(Reservation r) {
        if (r.getEstado() == null) return R.string.mp_unknown_status;
        return switch (r.getEstado()) {
            case "reservado" -> R.string.status_reserved;
            case "cancelado" -> R.string.status_cancelled;
            case "pendiente_presencial" -> R.string.mp_pending;
            case "pendiente_pago" -> R.string.mp_online_pending;
            case "vencido" -> R.string.mp_expired;
            default -> R.string.mp_unknown_status;
        };
    }
    static String total(Context c, Reservation r) {
        String amount = ArsMoney.format(r.getTotalArs());
        return amount == null ? c.getString(R.string.mp_price_unknown) : c.getString(R.string.mp_total, amount);
    }
    static String payment(Context c, Reservation r) {
        if (!"presencial".equals(r.getPaymentMode())) return "";
        String text = c.getString(r.isPresentialPaid() ? R.string.mp_paid :
                "cancelado".equals(r.getEstado()) ? R.string.mp_unpaid_cancelled : R.string.mp_pending);
        if ("pendiente".equals(r.getPresentialRefund())) text += "\n" + c.getString(R.string.mp_refund_pending);
        if ("realizada".equals(r.getPresentialRefund())) text += "\n" + c.getString(R.string.mp_refund_done);
        return text;
    }
}
