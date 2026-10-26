package com.multipro.mobile.ui;

import android.content.Intent;
import android.os.Bundle;
import android.view.View;

import androidx.annotation.NonNull;
import androidx.appcompat.app.AppCompatActivity;

import com.multipro.mobile.R;
import com.multipro.mobile.data.local.SessionManager;
import com.multipro.mobile.data.model.User;
import com.multipro.mobile.data.model.UserResponse;
import com.multipro.mobile.data.remote.ApiClient;
import com.multipro.mobile.data.remote.ApiErrorParser;
import com.multipro.mobile.databinding.ActivityProfileBinding;

import java.time.LocalDate;
import java.time.format.DateTimeFormatter;
import java.time.format.DateTimeParseException;
import java.util.Locale;

import retrofit2.Call;
import retrofit2.Callback;
import retrofit2.Response;

public class ProfileActivity extends AppCompatActivity {
    private static final DateTimeFormatter DISPLAY_DATE =
            DateTimeFormatter.ofPattern("dd/MM/yyyy", new Locale("es", "AR"));
    private ActivityProfileBinding binding;
    private SessionManager sessionManager;
    private Call<UserResponse> request;

    @Override protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        sessionManager = new SessionManager(this);
        if (!sessionManager.hasSession()) { openLogin(); return; }
        binding = ActivityProfileBinding.inflate(getLayoutInflater());
        setContentView(binding.getRoot());
        binding.backButton.setOnClickListener(view -> finish());
        binding.retryButton.setOnClickListener(view -> loadProfile());
        loadProfile();
    }

    private void loadProfile() {
        if (request != null) request.cancel();
        String authorization = sessionManager.authorizationHeader();
        if (authorization == null) { openLogin(); return; }
        showLoading();
        request = ApiClient.getService().consultarPerfil(authorization);
        request.enqueue(new Callback<>() {
            @Override public void onResponse(@NonNull Call<UserResponse> call,
                    @NonNull Response<UserResponse> response) {
                if (binding == null) return;
                if (response.code() == 401) { sessionManager.clear(); openLogin(); return; }
                User user = response.body() == null ? null : response.body().getData();
                if (!response.isSuccessful() || user == null) {
                    showError(ApiErrorParser.message(response, getString(R.string.unexpected_error))); return;
                }
                renderProfile(user);
            }
            @Override public void onFailure(@NonNull Call<UserResponse> call, @NonNull Throwable error) {
                if (binding == null || call.isCanceled()) return; showError(getString(R.string.connection_error));
            }
        });
    }

    private void renderProfile(User user) {
        binding.nameText.setText(display((value(user.getNombre()) + " " + value(user.getApellido())).trim()));
        binding.dniText.setText(display(user.getDni()));
        binding.emailText.setText(display(user.getEmail()));
        binding.phoneText.setText(display(user.getTelefono()));
        binding.addressText.setText(display(user.getDireccion()));
        binding.birthDateText.setText(display(formatDate(user.getFechaNacimiento())));
        binding.roleText.setText(display(user.getRol()));
        binding.entityText.setText(display(user.getEntidadNombre()));
        binding.stateContainer.setVisibility(View.GONE); binding.contentScroll.setVisibility(View.VISIBLE);
    }

    private String formatDate(String date) {
        if (date == null || date.isBlank()) return "";
        try { return LocalDate.parse(date).format(DISPLAY_DATE); }
        catch (DateTimeParseException ignored) { return date; }
    }
    private String value(String value) { return value == null ? "" : value; }
    private String display(String value) { return value == null || value.isBlank() ? getString(R.string.not_informed) : value; }
    private void showLoading() {
        binding.contentScroll.setVisibility(View.GONE); binding.stateContainer.setVisibility(View.VISIBLE);
        binding.progressIndicator.setVisibility(View.VISIBLE); binding.stateText.setText(R.string.loading_data);
        binding.retryButton.setVisibility(View.GONE);
    }
    private void showError(String message) {
        binding.contentScroll.setVisibility(View.GONE); binding.stateContainer.setVisibility(View.VISIBLE);
        binding.progressIndicator.setVisibility(View.GONE); binding.stateText.setText(message);
        binding.retryButton.setVisibility(View.VISIBLE);
    }
    private void openLogin() {
        Intent intent = new Intent(this, LoginActivity.class); intent.putExtra("session_expired", true);
        intent.addFlags(Intent.FLAG_ACTIVITY_CLEAR_TASK | Intent.FLAG_ACTIVITY_NEW_TASK);
        startActivity(intent); finish();
    }
    @Override protected void onDestroy() {
        if (request != null) request.cancel(); binding = null; super.onDestroy();
    }
}
