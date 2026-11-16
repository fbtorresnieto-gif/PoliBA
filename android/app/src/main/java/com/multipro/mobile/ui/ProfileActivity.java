package com.multipro.mobile.ui;

import android.content.Intent;
import android.os.Bundle;
import android.view.View;
import android.widget.Toast;

import androidx.annotation.NonNull;
import androidx.appcompat.app.AlertDialog;
import androidx.appcompat.app.AppCompatActivity;

import com.google.android.material.dialog.MaterialAlertDialogBuilder;
import com.google.gson.JsonObject;
import com.multipro.mobile.R;
import com.multipro.mobile.data.local.SessionManager;
import com.multipro.mobile.data.model.User;
import com.multipro.mobile.data.model.UserResponse;
import com.multipro.mobile.data.remote.ApiClient;
import com.multipro.mobile.data.remote.ApiErrorParser;
import com.multipro.mobile.databinding.ActivityProfileBinding;
import com.multipro.mobile.databinding.DialogEditProfileBinding;

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
    private Call<UserResponse> updateRequest;
    private User currentUser;
    private AlertDialog editDialog;

    @Override protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        sessionManager = new SessionManager(this);
        if (!sessionManager.hasSession()) { openLogin(); return; }
        binding = ActivityProfileBinding.inflate(getLayoutInflater());
        setContentView(binding.getRoot());
        binding.backButton.setOnClickListener(view -> finish());
        binding.retryButton.setOnClickListener(view -> loadProfile());
        binding.modifyProfileButton.setOnClickListener(view -> showEditProfile());
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
        currentUser = user;
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

    private void showEditProfile() {
        if (currentUser == null || editDialog != null) return;
        DialogEditProfileBinding fields = DialogEditProfileBinding.inflate(getLayoutInflater());
        fields.firstNameInput.setText(value(currentUser.getNombre()));
        fields.lastNameInput.setText(value(currentUser.getApellido()));
        fields.addressInput.setText(value(currentUser.getDireccion()));
        fields.phoneInput.setText(value(currentUser.getTelefono()));

        editDialog = new MaterialAlertDialogBuilder(this)
                .setTitle(R.string.modify_profile)
                .setView(fields.getRoot())
                .setNegativeButton(R.string.cancel, null)
                .setPositiveButton(R.string.save_changes, null)
                .create();
        editDialog.setOnShowListener(ignored -> editDialog.getButton(AlertDialog.BUTTON_POSITIVE)
                .setOnClickListener(view -> submitProfile(fields)));
        editDialog.setOnDismissListener(ignored -> editDialog = null);
        editDialog.show();
    }

    private void submitProfile(DialogEditProfileBinding fields) {
        String firstName = text(fields.firstNameInput.getText());
        String lastName = text(fields.lastNameInput.getText());
        fields.firstNameLayout.setError(firstName.isEmpty() ? getString(R.string.required_field) : null);
        fields.lastNameLayout.setError(lastName.isEmpty() ? getString(R.string.required_field) : null);
        if (firstName.isEmpty() || lastName.isEmpty()) return;

        String authorization = sessionManager.authorizationHeader();
        if (authorization == null) { openLogin(); return; }
        JsonObject data = new JsonObject();
        data.addProperty("nombre", firstName);
        data.addProperty("apellido", lastName);
        data.addProperty("direccion", text(fields.addressInput.getText()));
        data.addProperty("telefono", text(fields.phoneInput.getText()));
        setEditEnabled(fields, false);
        updateRequest = ApiClient.getService().actualizarPerfil(authorization, data);
        updateRequest.enqueue(new Callback<>() {
            @Override public void onResponse(@NonNull Call<UserResponse> call,
                    @NonNull Response<UserResponse> response) {
                if (binding == null || call != updateRequest) return;
                if (response.code() == 401) { sessionManager.clear(); openLogin(); return; }
                User updated = response.body() == null ? null : response.body().getData();
                if (!response.isSuccessful() || updated == null) {
                    setEditEnabled(fields, true);
                    fields.firstNameLayout.setError(ApiErrorParser.message(
                            response, getString(R.string.unexpected_error)));
                    return;
                }
                sessionManager.updateName(updated.getNombre());
                renderProfile(updated);
                AlertDialog dialog = editDialog;
                if (dialog != null) dialog.dismiss();
                Toast.makeText(ProfileActivity.this, R.string.profile_updated, Toast.LENGTH_LONG).show();
            }

            @Override public void onFailure(@NonNull Call<UserResponse> call, @NonNull Throwable error) {
                if (binding == null || call.isCanceled() || call != updateRequest) return;
                setEditEnabled(fields, true);
                fields.firstNameLayout.setError(getString(R.string.connection_error));
            }
        });
    }

    private void setEditEnabled(DialogEditProfileBinding fields, boolean enabled) {
        fields.firstNameInput.setEnabled(enabled);
        fields.lastNameInput.setEnabled(enabled);
        fields.addressInput.setEnabled(enabled);
        fields.phoneInput.setEnabled(enabled);
        if (editDialog != null) {
            editDialog.getButton(AlertDialog.BUTTON_POSITIVE).setEnabled(enabled);
            editDialog.getButton(AlertDialog.BUTTON_NEGATIVE).setEnabled(enabled);
        }
    }

    private String text(CharSequence value) { return value == null ? "" : value.toString().trim(); }

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
        if (request != null) request.cancel();
        if (updateRequest != null) updateRequest.cancel();
        if (editDialog != null) editDialog.dismiss();
        binding = null; super.onDestroy();
    }
}
