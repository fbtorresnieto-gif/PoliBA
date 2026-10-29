package com.multipro.mobile.data.model;

import org.junit.Test;
import com.google.gson.Gson;
import com.google.gson.JsonObject;
import com.multipro.mobile.BuildConfig;
import static org.junit.Assert.*;

public class PaymentsContractTest {
    private final Gson gson = new Gson();
    private PaymentOptions options(String price, String modes) {
        return gson.fromJson("{\"precio_hora_ars\":\""+price+"\",\"moneda\":\"ARS\",\"duracion_minutos\":60,"
                +"\"pago_presencial_habilitado\":true,\"modalidades_disponibles\":"+modes+"}", PaymentOptions.class);
    }
    @Test public void freeContractRequiresZeroAndFreeMode() {
        assertEquals("gratuito", options("0.00","[\"gratuito\"]").getBookingMode());
        assertNull(options("1250.50","[\"gratuito\"]").getBookingMode());
    }
    @Test public void onlyPresentialCanBeChosenForPositivePrice() {
        assertEquals("presencial", options("1250.50","[\"presencial\"]").getBookingMode());
        assertNull(options("1250.50","[\"online\"]").getBookingMode());
        assertNull(options("1250.50","[]").getBookingMode());
    }
    @Test public void missingAndInvalidContractsDoNotImplyFree() {
        assertNull(gson.fromJson("{}",Space.class).getBookingMode());
        for(String price : new String[]{"-1.00","NaN","1e2","100.001","0","99999999999.00"})
            assertNull(options(price,"[\"presencial\"]").getBookingMode());
        assertNull(gson.fromJson("{\"precio_hora_ars\":\"0.00\",\"moneda\":\"USD\",\"duracion_minutos\":60,\"modalidades_disponibles\":[\"gratuito\"]}",Space.class).getBookingMode());
    }
    @Test public void inheritedGsonFieldsSurviveSpaceAndAvailabilityParsing() {
        String json=gson.toJson(options("100.10","[\"presencial\"]"));
        assertEquals("100.10",gson.fromJson(json,Space.class).getHourlyPrice());
        assertEquals("presencial",gson.fromJson(json,AvailabilityData.class).getBookingMode());
    }
    @Test public void amountsNeverUseFloatingPoint() {
        assertEquals("$ 9.999.999.999,99 ARS", ArsMoney.format("9999999999.99"));
        assertEquals("$ 1.250,50 ARS",ArsMoney.format("1250.50"));
        assertEquals("Gratuito",ArsMoney.format("0.00"));
        assertNull(ArsMoney.format(null));
        assertNull(ArsMoney.format("1e2"));
    }
    @Test public void requestCarriesExplicitSelectionAndDecimalText() {
        JsonObject body=gson.toJsonTree(new ReservationCreateRequest(1,"2099-01-01","10:00","presencial","100.10")).getAsJsonObject();
        assertEquals("100.10",body.get("precio_visto").getAsString());
        assertTrue(body.get("precio_visto").getAsJsonPrimitive().isString());
        assertEquals("presencial",body.get("modalidad_pago").getAsString());
        assertFalse(body.has("importe_total_ars"));
        assertFalse(body.has("fk_usuario"));
        assertFalse(gson.toJsonTree(new ReservationCreateRequest(1,"2099-01-01","10:00")).getAsJsonObject().has("precio_visto"));
    }
    @Test public void pendingIsCancellableAndRefundStatesArePreserved() {
        Reservation r=gson.fromJson("{\"estado\":\"pendiente_presencial\",\"modalidad_pago\":\"presencial\",\"importe_total_ars\":\"100.10\"}",Reservation.class);
        assertTrue(r.canCancel()); assertFalse(r.isActive()); assertEquals("100.10",r.getTotalArs());
        r=gson.fromJson("{\"estado\":\"cancelado\",\"presencial_cobrado\":true,\"devolucion_presencial\":\"pendiente\"}",Reservation.class);
        assertFalse(r.canCancel()); assertTrue(r.isPresentialPaid()); assertEquals("pendiente",r.getPresentialRefund());
        assertFalse(gson.fromJson("{\"estado\":\"reservado\",\"modalidad_pago\":\"online\"}",Reservation.class).canCancel());
        assertTrue(gson.fromJson("{\"devolucion_presencial\":\"pendiente\"}",ReservationCancelData.class).isRefundPending());
    }
    @Test public void localBuildCannotTargetProduction() {
        if("local".equals(BuildConfig.BUILD_TYPE)) {
            assertEquals("http://127.0.0.1:8080/api/v1/",BuildConfig.API_BASE_URL);
            assertEquals("com.multipro.mobile.local",BuildConfig.APPLICATION_ID);
            assertEquals("",BuildConfig.GOOGLE_WEB_CLIENT_ID);
        } else assertEquals("https://multipro-production.up.railway.app/api/v1/",BuildConfig.API_BASE_URL);
    }
}
