package com.multipro.mobile.data.remote;

import com.google.gson.JsonObject;
import com.multipro.mobile.data.model.AvailabilityResponse;
import com.multipro.mobile.data.model.EntityListResponse;
import com.multipro.mobile.data.model.LoginRequest;
import com.multipro.mobile.data.model.LoginResponse;
import com.multipro.mobile.data.model.ReservationCreateRequest;
import com.multipro.mobile.data.model.ReservationCreateResponse;
import com.multipro.mobile.data.model.ReservationCancelRequest;
import com.multipro.mobile.data.model.ReservationCancelResponse;
import com.multipro.mobile.data.model.ReservationListResponse;
import com.multipro.mobile.data.model.ReservationResponse;
import com.multipro.mobile.data.model.SpaceListResponse;
import com.multipro.mobile.data.model.SpaceResponse;
import com.multipro.mobile.data.model.UserResponse;
import com.multipro.mobile.data.model.AttendanceListResponse;
import com.multipro.mobile.data.model.AttendanceRequest;
import com.multipro.mobile.data.model.AttendanceUpdateResponse;
import com.multipro.mobile.data.model.ModuleListResponse;
import com.multipro.mobile.data.model.NewsListResponse;

import retrofit2.Call;
import retrofit2.http.Body;
import retrofit2.http.GET;
import retrofit2.http.Header;
import retrofit2.http.POST;
import retrofit2.http.Query;

public interface ApiService {
    @GET("entidades.php")
    Call<EntityListResponse> listarEntidades();

    @GET("novedades.php")
    Call<NewsListResponse> listarNovedades();

    @GET("espacios.php")
    Call<SpaceListResponse> listarEspacios(@Query("entidad_id") int entidadId);

    @GET("espacio.php")
    Call<SpaceResponse> consultarEspacio(@Query("id") int espacioId);

    @GET("disponibilidad.php")
    Call<AvailabilityResponse> consultarDisponibilidad(
            @Query("espacio_id") int espacioId,
            @Query("fecha") String fecha
    );

    @POST("auth/registro.php")
    Call<JsonObject> registrarAlumno(@Body JsonObject datos);

    @POST("auth/login.php")
    Call<LoginResponse> iniciarSesion(@Body LoginRequest credenciales);

    @POST("auth/google.php")
    Call<LoginResponse> autenticarConGoogle(@Body JsonObject datos);

    @GET("auth/yo.php")
    Call<UserResponse> consultarUsuario(@Header("Authorization") String authorization);

    @POST("auth/logout.php")
    Call<JsonObject> cerrarSesion(@Header("Authorization") String authorization);

    @GET("perfil/consultar.php")
    Call<UserResponse> consultarPerfil(@Header("Authorization") String authorization);

    @GET("reservas/listar.php")
    Call<ReservationListResponse> listarReservas(@Header("Authorization") String authorization);

    @GET("reservas/detalle.php")
    Call<ReservationResponse> consultarReserva(
            @Header("Authorization") String authorization,
            @Query("id") int reservaId
    );

    @POST("reservas/crear.php")
    Call<ReservationCreateResponse> crearReserva(
            @Header("Authorization") String authorization,
            @Body ReservationCreateRequest datos
    );

    @POST("reservas/cancelar.php")
    Call<ReservationCancelResponse> cancelarReserva(
            @Header("Authorization") String authorization,
            @Body ReservationCancelRequest datos
    );

    @GET("profesor/modulos.php")
    Call<ModuleListResponse> listarModulos(@Header("Authorization") String authorization);

    @GET("profesor/alumnos.php")
    Call<AttendanceListResponse> listarAlumnos(
            @Header("Authorization") String authorization,
            @Query("modulo_id") int moduloId,
            @Query("fecha") String fecha
    );

    @POST("profesor/asistencia.php")
    Call<AttendanceUpdateResponse> registrarAsistencia(
            @Header("Authorization") String authorization,
            @Body AttendanceRequest datos
    );
}
