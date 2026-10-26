package com.multipro.mobile.ui;

import android.app.DatePickerDialog;
import android.content.Intent;
import android.os.Bundle;
import android.util.Patterns;
import android.view.View;
import android.widget.ArrayAdapter;

import androidx.annotation.NonNull;
import androidx.appcompat.app.AppCompatActivity;

import com.google.android.material.dialog.MaterialAlertDialogBuilder;
import com.google.gson.JsonObject;
import com.multipro.mobile.R;
import com.multipro.mobile.data.model.Entity;
import com.multipro.mobile.data.model.EntityListResponse;
import com.multipro.mobile.data.model.LoginData;
import com.multipro.mobile.data.model.LoginResponse;
import com.multipro.mobile.data.local.SessionManager;
import com.multipro.mobile.data.remote.ApiClient;
import com.multipro.mobile.data.remote.ApiErrorParser;
import com.multipro.mobile.databinding.ActivityRegisterBinding;

import java.time.LocalDate;
import java.time.Period;
import java.time.format.DateTimeFormatter;
import java.util.ArrayList;
import java.util.Collections;
import java.util.List;
import java.util.Locale;

import retrofit2.Call;
import retrofit2.Callback;
import retrofit2.Response;

public class RegisterActivity extends AppCompatActivity {
    public static final String EXTRA_GOOGLE_ID_TOKEN = "google_id_token";
    public static final String EXTRA_GOOGLE_FIRST_NAME = "google_first_name";
    public static final String EXTRA_GOOGLE_LAST_NAME = "google_last_name";
    public static final String EXTRA_GOOGLE_EMAIL = "google_email";
    private static final DateTimeFormatter API_DATE = DateTimeFormatter.ISO_LOCAL_DATE;
    private static final DateTimeFormatter DISPLAY_DATE =
            DateTimeFormatter.ofPattern("dd/MM/yyyy", new Locale("es", "AR"));
    private ActivityRegisterBinding binding;
    private Call<EntityListResponse> entitiesCall;
    private Call<JsonObject> registerCall;
    private Call<LoginResponse> googleRegisterCall;
    private List<Entity> entities = Collections.emptyList();
    private Integer selectedEntityId;
    private LocalDate selectedBirthDate;
    private String googleIdToken;

    @Override protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        binding = ActivityRegisterBinding.inflate(getLayoutInflater());
        setContentView(binding.getRoot());
        binding.backButton.setOnClickListener(view -> finish());
        binding.birthDateInput.setOnClickListener(view -> openDatePicker());
        binding.birthDateLayout.setEndIconOnClickListener(view -> openDatePicker());
        binding.registerButton.setOnClickListener(view -> submit());
        configureGoogleRegistration();
        configureEntitySelector(Collections.emptyList());
        loadEntities();
    }

    private void configureGoogleRegistration() {
        googleIdToken = getIntent().getStringExtra(EXTRA_GOOGLE_ID_TOKEN);
        if (googleIdToken == null || googleIdToken.isBlank()) return;
        binding.firstNameInput.setText(getIntent().getStringExtra(EXTRA_GOOGLE_FIRST_NAME));
        binding.lastNameInput.setText(getIntent().getStringExtra(EXTRA_GOOGLE_LAST_NAME));
        binding.emailInput.setText(getIntent().getStringExtra(EXTRA_GOOGLE_EMAIL));
        binding.emailInput.setEnabled(false);
        binding.passwordLayout.setVisibility(View.GONE);
        binding.confirmPasswordLayout.setVisibility(View.GONE);
        binding.registerTitle.setText(R.string.google_registration_title);
        binding.registerSubtitle.setText(R.string.google_registration_subtitle);
    }

    private void loadEntities() {
        entitiesCall = ApiClient.getService().listarEntidades();
        entitiesCall.enqueue(new Callback<>() {
            @Override public void onResponse(@NonNull Call<EntityListResponse> call,
                    @NonNull Response<EntityListResponse> response) {
                if (binding == null) return;
                if (response.isSuccessful() && response.body() != null && response.body().getData() != null) {
                    entities = response.body().getData();
                    configureEntitySelector(entities);
                } else showEntityWarning();
            }
            @Override public void onFailure(@NonNull Call<EntityListResponse> call, @NonNull Throwable error) {
                if (binding == null || call.isCanceled()) return; showEntityWarning();
            }
        });
    }

    private void configureEntitySelector(List<Entity> available) {
        List<String> labels = new ArrayList<>();
        labels.add(getString(R.string.without_entity));
        for (Entity entity : available) labels.add(entity.getNombre());
        ArrayAdapter<String> adapter = new ArrayAdapter<>(this,
                android.R.layout.simple_dropdown_item_1line, labels);
        binding.entityInput.setAdapter(adapter);
        binding.entityInput.setText(labels.get(0), false);
        selectedEntityId = null;
        binding.entityInput.setOnItemClickListener((parent, view, position, id) ->
                selectedEntityId = position == 0 ? null : available.get(position - 1).getId());
    }

    private void showEntityWarning() {
        binding.errorText.setText(R.string.entities_load_warning);
        binding.errorText.setVisibility(View.VISIBLE);
    }

    private void openDatePicker() {
        LocalDate initial = selectedBirthDate == null ? LocalDate.now().minusYears(18) : selectedBirthDate;
        DatePickerDialog dialog = new DatePickerDialog(this, (picker, year, month, day) -> {
            selectedBirthDate = LocalDate.of(year, month + 1, day);
            binding.birthDateInput.setText(selectedBirthDate.format(DISPLAY_DATE));
            binding.birthDateLayout.setError(null);
        }, initial.getYear(), initial.getMonthValue() - 1, initial.getDayOfMonth());
        long maximumBirthDate = LocalDate.now().minusYears(18)
                .atStartOfDay(java.time.ZoneId.systemDefault()).toInstant().toEpochMilli();
        dialog.getDatePicker().setMaxDate(maximumBirthDate);
        dialog.show();
    }

    private void submit() {
        clearErrors();
        String firstName = text(binding.firstNameInput);
        String lastName = text(binding.lastNameInput);
        String dni = text(binding.dniInput);
        String email = text(binding.emailInput);
        String password = rawText(binding.passwordInput);
        String confirmation = rawText(binding.confirmPasswordInput);
        boolean valid = true;
        if (firstName.isBlank()) { binding.firstNameLayout.setError(getString(R.string.required_field)); valid = false; }
        if (lastName.isBlank()) { binding.lastNameLayout.setError(getString(R.string.required_field)); valid = false; }
        if (dni.isBlank()) { binding.dniLayout.setError(getString(R.string.required_field)); valid = false; }
        if (email.isBlank()) { binding.emailLayout.setError(getString(R.string.required_email)); valid = false; }
        else if (!Patterns.EMAIL_ADDRESS.matcher(email).matches()) { binding.emailLayout.setError(getString(R.string.invalid_email)); valid = false; }
        if (selectedBirthDate == null) { binding.birthDateLayout.setError(getString(R.string.required_birth_date)); valid = false; }
        else if (Period.between(selectedBirthDate, LocalDate.now()).getYears() < 18) {
            binding.birthDateLayout.setError(getString(R.string.adult_required)); valid = false;
        }
        if (!isGoogleRegistration() && password.length() < 6) { binding.passwordLayout.setError(getString(R.string.password_minimum)); valid = false; }
        if (!isGoogleRegistration() && !password.equals(confirmation)) { binding.confirmPasswordLayout.setError(getString(R.string.password_mismatch)); valid = false; }
        if (!valid) return;

        JsonObject data = new JsonObject();
        data.addProperty("nombre", firstName); data.addProperty("apellido", lastName);
        data.addProperty("dni", dni); data.addProperty("email", email.toLowerCase(Locale.ROOT));
        if (isGoogleRegistration()) data.addProperty("id_token", googleIdToken);
        else {
            data.addProperty("contrasena", password);
            data.addProperty("confirmar_contrasena", confirmation);
        }
        data.addProperty("fecha_nacimiento", selectedBirthDate.format(API_DATE));
        data.addProperty("direccion", text(binding.addressInput));
        data.addProperty("telefono", text(binding.phoneInput));
        if (selectedEntityId != null) data.addProperty("entidad_id", selectedEntityId);
        if (isGoogleRegistration()) registerWithGoogle(data); else register(data);
    }

    private boolean isGoogleRegistration() {
        return googleIdToken != null && !googleIdToken.isBlank();
    }

    private void registerWithGoogle(JsonObject data) {
        setLoading(true);
        googleRegisterCall = ApiClient.getService().autenticarConGoogle(data);
        googleRegisterCall.enqueue(new Callback<>() {
            @Override public void onResponse(@NonNull Call<LoginResponse> call,
                    @NonNull Response<LoginResponse> response) {
                if (binding == null) return;
                setLoading(false);
                LoginData loginData = response.body() == null ? null : response.body().getData();
                if (!response.isSuccessful() || loginData == null || loginData.getUsuario() == null) {
                    showError(ApiErrorParser.message(response, getString(R.string.unexpected_error)));
                    return;
                }
                try {
                    new SessionManager(RegisterActivity.this).save(loginData);
                } catch (RuntimeException error) {
                    showError(getString(R.string.unexpected_error));
                    return;
                }
                Intent intent = new Intent(RegisterActivity.this, HomeActivity.class);
                intent.addFlags(Intent.FLAG_ACTIVITY_CLEAR_TASK | Intent.FLAG_ACTIVITY_NEW_TASK);
                startActivity(intent);
                finish();
            }

            @Override public void onFailure(@NonNull Call<LoginResponse> call,
                    @NonNull Throwable error) {
                if (binding == null || call.isCanceled()) return;
                setLoading(false);
                showError(getString(R.string.registration_uncertain));
            }
        });
    }

    private void register(JsonObject data) {
        setLoading(true);
        registerCall = ApiClient.getService().registrarAlumno(data);
        registerCall.enqueue(new Callback<>() {
            @Override public void onResponse(@NonNull Call<JsonObject> call, @NonNull Response<JsonObject> response) {
                if (binding == null) return;
                setLoading(false);
                if (response.isSuccessful() && response.body() != null) {
                    new MaterialAlertDialogBuilder(RegisterActivity.this)
                            .setTitle(R.string.registration_success_title)
                            .setMessage(R.string.registration_success_message)
                            .setCancelable(false)
                            .setPositiveButton(R.string.login, (dialog, which) -> finish())
                            .show();
                    return;
                }
                showError(ApiErrorParser.message(response, getString(R.string.unexpected_error)));
            }
            @Override public void onFailure(@NonNull Call<JsonObject> call, @NonNull Throwable error) {
                if (binding == null || call.isCanceled()) return;
                setLoading(false); showError(getString(R.string.registration_uncertain));
            }
        });
    }

    private void clearErrors() {
        binding.firstNameLayout.setError(null); binding.lastNameLayout.setError(null);
        binding.dniLayout.setError(null); binding.emailLayout.setError(null);
        binding.birthDateLayout.setError(null); binding.passwordLayout.setError(null);
        binding.confirmPasswordLayout.setError(null); binding.errorText.setVisibility(View.GONE);
    }
    private void showError(String message) { binding.errorText.setText(message); binding.errorText.setVisibility(View.VISIBLE); }
    private void setLoading(boolean loading) {
        binding.loadingContainer.setVisibility(loading ? View.VISIBLE : View.GONE);
        binding.registerButton.setEnabled(!loading); binding.backButton.setEnabled(!loading);
    }
    private String text(android.widget.EditText input) { return rawText(input).trim(); }
    private String rawText(android.widget.EditText input) { return input.getText() == null ? "" : input.getText().toString(); }

    @Override protected void onDestroy() {
        if (entitiesCall != null) entitiesCall.cancel();
        // El alta podría haber llegado al servidor; no cancelamos una solicitud ya confirmada.
        binding = null; super.onDestroy();
    }
}
