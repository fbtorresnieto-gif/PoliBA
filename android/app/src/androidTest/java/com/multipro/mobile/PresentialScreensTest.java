package com.multipro.mobile;

import android.content.Context;
import android.content.Intent;
import android.graphics.Bitmap;
import android.view.View;
import android.widget.TextView;
import androidx.test.core.app.ActivityScenario;
import androidx.test.core.app.ApplicationProvider;
import androidx.test.espresso.UiController;
import androidx.test.espresso.ViewAction;
import androidx.test.ext.junit.runners.AndroidJUnit4;
import androidx.test.platform.app.InstrumentationRegistry;
import com.google.gson.Gson;
import com.google.gson.JsonObject;
import com.multipro.mobile.data.local.SessionManager;
import com.multipro.mobile.data.model.LoginData;
import com.multipro.mobile.ui.AvailabilityActivity;
import com.multipro.mobile.ui.ReservationDetailActivity;
import org.hamcrest.Matcher;
import org.junit.*;
import org.junit.runner.RunWith;
import java.io.*;
import java.net.*;
import java.nio.charset.StandardCharsets;
import java.util.UUID;
import java.util.concurrent.atomic.AtomicReference;
import static androidx.test.espresso.Espresso.onView;
import static androidx.test.espresso.action.ViewActions.click;
import static androidx.test.espresso.assertion.ViewAssertions.matches;
import static androidx.test.espresso.matcher.ViewMatchers.*;
import static org.hamcrest.Matchers.containsString;
import static org.junit.Assert.*;

/** Real emulator UI, isolated fixture HTTP on-device: never reaches staging or production. */
@RunWith(AndroidJUnit4.class)
public class PresentialScreensTest {
    private FixtureServer server;
    private ActivityScenario<?> activity;
    private Context context;
    private void launch(Intent intent) {
        activity=ActivityScenario.launch(intent);
        activity.onActivity(a -> {
            a.getWindow().addFlags(android.view.WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON
                    | android.view.WindowManager.LayoutParams.FLAG_TURN_SCREEN_ON
                    | android.view.WindowManager.LayoutParams.FLAG_SHOW_WHEN_LOCKED);
            ((android.app.KeyguardManager)a.getSystemService(Context.KEYGUARD_SERVICE)).requestDismissKeyguard(a,null);
        });
    }
    @Before public void setup() throws Exception {
        Assume.assumeTrue("local".equals(BuildConfig.BUILD_TYPE));
        context=ApplicationProvider.getApplicationContext();
        String token=UUID.randomUUID().toString().replace("-","");
        new SessionManager(context).save(new Gson().fromJson("{\"token\":\""+token+token+"\",\"vence_en\":\"2099-01-01\",\"usuario\":{\"nombre\":\"Prueba\",\"email\":\"fixture@multipro.invalid\",\"rol\":\"Alumno\"}}",LoginData.class));
        server=new FixtureServer();server.start();
    }
    @After public void cleanup() throws Exception {
        if(activity!=null)activity.close();
        if(server!=null)server.close();
        if(context!=null)new SessionManager(context).clear();
    }
    private void await(int id,String text) {
        onView(isRoot()).perform(new ViewAction() {
            public Matcher<View> getConstraints(){return isRoot();}
            public String getDescription(){return "Esperar texto de pantalla de prueba";}
            public void perform(UiController ui,View root){
                for(int i=0;i<100;i++){
                    View found=root.findViewById(id);
                    if(found instanceof TextView && ((TextView)found).getText().toString().contains(text))return;
                    ui.loopMainThreadForAtLeast(50);
                }
                throw new AssertionError("No se actualizo la pantalla esperada");
            }
        });
    }
    @Test public void createsPresentialWithExplicitPriceAndShowsPending() throws Exception {
        launch(new Intent(context,AvailabilityActivity.class)
                .putExtra(AvailabilityActivity.EXTRA_SPACE_ID,1).putExtra(AvailabilityActivity.EXTRA_SPACE_NAME,"Espacio de prueba"));
        await(R.id.priceText,"1.250,50");
        onView(withText("10:00 hs")).perform(click());
        onView(withText(R.string.mp_confirm_presential)).perform(click());
        // A dialog root has no screen-specific ID: wait through its message text.
        await(android.R.id.message,"Aboná en la entidad");
        onView(withText(R.string.mp_pending_title)).check(matches(isDisplayed()));
        assertEquals("presencial",server.created.get().get("modalidad_pago").getAsString());
        assertEquals("1250.50",server.created.get().get("precio_visto").getAsString());
        assertTrue(server.created.get().get("precio_visto").getAsJsonPrimitive().isString());
        File dir=context.getExternalFilesDir("evidence");assertNotNull(dir);dir.mkdirs();
        try(FileOutputStream out=new FileOutputStream(new File(dir,"reserva-presencial.png"))){
            InstrumentationRegistry.getInstrumentation().getUiAutomation().takeScreenshot().compress(Bitmap.CompressFormat.PNG,100,out);
        }
    }
    @Test public void paidReservationCancellationShowsPendingRefund() {
        launch(new Intent(context,ReservationDetailActivity.class)
                .putExtra(ReservationDetailActivity.EXTRA_RESERVATION_ID,21));
        await(R.id.amountText,"1.250,50");
        onView(withId(R.id.paymentText)).check(matches(withText(containsString("Cobro presencial registrado"))));
        onView(withId(R.id.cancelButton)).perform(click());
        onView(withText(R.string.confirm)).perform(click());
        await(android.R.id.message,"Devolución total pendiente");
        onView(withText(R.string.accept)).perform(click());
        await(R.id.paymentText,"Devolución total pendiente");
        onView(withId(R.id.cancelButton)).check(matches(withEffectiveVisibility(Visibility.GONE)));
    }
    @Test public void missingPriceContractNeverOffersFreeBooking() {
        server.missing=true;
        launch(new Intent(context,AvailabilityActivity.class)
                .putExtra(AvailabilityActivity.EXTRA_SPACE_ID,1));
        await(R.id.priceText,"Precio no informado");
        onView(withText(R.string.unavailable)).check(matches(isDisplayed()));
        assertNull(server.created.get());
    }

    private static class FixtureServer implements AutoCloseable {
        private final ServerSocket socket;
        private final Thread worker;
        volatile boolean running=true,missing=false,cancelled=false;
        final AtomicReference<JsonObject> created=new AtomicReference<>();
        FixtureServer() throws IOException {
            socket=new ServerSocket();socket.setReuseAddress(true);socket.bind(new InetSocketAddress("127.0.0.1",8080));
            worker=new Thread(()->{while(running){try(Socket client=socket.accept()){serve(client);}catch(IOException e){if(running)throw new RuntimeException("Fixture HTTP failure",e);}}});
        }
        void start(){worker.start();}
        private void serve(Socket client) throws IOException {
            client.setSoTimeout(5000);
            BufferedReader in=new BufferedReader(new InputStreamReader(client.getInputStream(),StandardCharsets.UTF_8));
            String line=in.readLine();if(line==null)return;String path=line.split(" ")[1];int length=0;
            while(!(line=in.readLine()).isEmpty())if(line.toLowerCase().startsWith("content-length:"))length=Integer.parseInt(line.substring(15).trim());
            char[] body=new char[length];int read=0;while(read<length){int n=in.read(body,read,length-read);if(n<0)break;read+=n;}
            String data;
            if(path.contains("disponibilidad.php")){
                String date=path.substring(path.indexOf("fecha=")+6).split("&")[0];
                data="{\"espacio_id\":1,\"fecha\":\""+date+"\",\"entidad_abierta\":true,"+
                    (missing?"":"\"precio_hora_ars\":\"1250.50\",\"moneda\":\"ARS\",\"duracion_minutos\":60,\"pago_presencial_habilitado\":true,\"modalidades_disponibles\":[\"presencial\"],")+
                    "\"slots\":[{\"horario\":\"10:00:00\",\"disponible\":true}]}";
            }else if(path.contains("crear.php")){
                created.set(new Gson().fromJson(new String(body),JsonObject.class));
                data="{\"id\":21,\"estado\":\"pendiente_presencial\",\"importe_total_ars\":\"1250.50\"}";
            }else{
                if(path.contains("cancelar.php"))cancelled=true;
                data="{\"id\":21,\"espacio_nombre\":\"Espacio de prueba\",\"entidad_nombre\":\"Entidad local\",\"fecha\":\"2099-01-01\",\"horario\":\"10:00:00\",\"estado\":\""+
                    (cancelled?"cancelado":"reservado")+"\",\"modalidad_pago\":\"presencial\",\"importe_total_ars\":\"1250.50\",\"presencial_cobrado\":true,\"devolucion_presencial\":\""+(cancelled?"pendiente":"no_solicitada")+"\"}";
            }
            byte[] response=("{\"data\":"+data+"}").getBytes(StandardCharsets.UTF_8);
            OutputStream out=client.getOutputStream();out.write(("HTTP/1.1 200 OK\r\nContent-Type: application/json; charset=utf-8\r\nConnection: close\r\nContent-Length: "+response.length+"\r\n\r\n").getBytes(StandardCharsets.UTF_8));out.write(response);out.flush();
        }
        public void close() throws Exception {running=false;socket.close();worker.join(2000);}
    }
}
