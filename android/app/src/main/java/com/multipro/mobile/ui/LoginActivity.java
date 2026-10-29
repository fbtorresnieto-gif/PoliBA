package com.multipro.mobile.ui;

import android.content.Intent;
import android.os.Bundle;
import android.os.CancellationSignal;
import android.util.Patterns;
import android.view.View;

import androidx.annotation.NonNull;
import androidx.appcompat.app.AppCompatActivity;
import androidx.core.content.ContextCompat;
import androidx.credentials.Credential;
import androidx.credentials.CredentialManager;
import androidx.credentials.CredentialManagerCallback;
import androidx.credentials.CustomCredential;
import androidx.credentials.GetCredentialRequest;
import androidx.credentials.GetCredentialResponse;
import androidx.credentials.exceptions.GetCredentialCancellationException;
import androidx.credentials.exceptions.GetCredentialException;

import com.google.android.libraries.identity.googleid.GetSignInWithGoogleOption;
import com.google.android.libraries.identity.googleid.GoogleIdTokenCredential;
import com.google.gson.JsonObject;
import com.google.gson.JsonParser;
import com.multipro.mobile.BuildConfig;
import com.multipro.mobile.R;
import com.multipro.mobile.data.local.SessionManager;
import com.multipro.mobile.data.model.LoginData;
import com.multipro.mobile.data.model.LoginRequest;
import com.multipro.mobile.data.model.LoginResponse;
import com.multipro.mobile.data.remote.ApiClient;
import com.multipro.mobile.data.remote.ApiErrorParser;
import com.multipro.mobile.databinding.ActivityLoginBinding;

import java.util.Locale;

import retrofit2.Call;
import retrofit2.Callback;
import retrofit2.Response;

public class LoginActivity extends AppCompatActivity {
    private ActivityLoginBinding binding;
    private Call<LoginResponse> loginCall;
    private Call<LoginResponse> googleLoginCall;
    private CancellationSignal googleCancellationSignal;
    private CredentialManager credentialManager;

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        binding = ActivityLoginBinding.inflate(getLayoutInflater());
        setContentView(binding.getRoot());

        binding.backButton.setOnClickListener(view -> finish());
        binding.loginButton.setOnClickListener(view -> submitLogin());
        credentialManager = CredentialManager.create(this);
        binding.googleLoginButton.setOnClickListener(view -> startGoogleSignIn());
        binding.googleLoginButton.setEnabled(!BuildConfig.GOOGLE_WEB_CLIENT_ID.isBlank());
        if (BuildConfig.GOOGLE_WEB_CLIENT_ID.isBlank()) binding.googleLoginButton.setVisibility(View.GONE);
        binding.registerButton.setOnClickListener(view ->
                startActivity(new Intent(this, RegisterActivity.class)));
        binding.passwordInput.setOnEditorActionListener((view, actionId, event) -> {
            submitLogin();
            return true;
        });
        if (getIntent().getBooleanExtra("session_expired", false)) {
            showError(getString(R.string.session_expired));
        }
    }

    private void startGoogleSignIn() {
        if (BuildConfig.GOOGLE_WEB_CLIENT_ID.isBlank()) return;
        clearErrors();
        setLoading(true, true);
        googleCancellationSignal = new CancellationSignal();
        GetSignInWithGoogleOption option = new GetSignInWithGoogleOption.Builder(
                BuildConfig.GOOGLE_WEB_CLIENT_ID
        ).build();
        GetCredentialRequest request = new GetCredentialRequest.Builder()
                .addCredentialOption(option)
                .build();
        credentialManager.getCredentialAsync(
                this,
                request,
                googleCancellationSignal,
                ContextCompat.getMainExecutor(this),
                new CredentialManagerCallback<>() {
                    @Override public void onResult(GetCredentialResponse result) {
                        handleGoogleCredential(result.getCredential());
                    }

                    @Override public void onError(@NonNull GetCredentialException error) {
                        if (binding == null) return;
                        setLoading(false, true);
                        if (!(error instanceof GetCredentialCancellationException)) {
                            showError(getString(R.string.google_login_error));
                        }
                    }
                }
        );
    }

    private void handleGoogleCredential(Credential credential) {
        if (!(credential instanceof CustomCredential customCredential)) {
            setLoading(false, true);
            showError(getString(R.string.google_login_error));
            return;
        }
        try {
            GoogleIdTokenCredential googleCredential =
                    GoogleIdTokenCredential.createFrom(customCredential.getData());
            submitGoogleToken(googleCredential.getIdToken());
        } catch (RuntimeException error) {
            setLoading(false, true);
            showError(getString(R.string.google_login_error));
        }
    }

    private void submitGoogleToken(String idToken) {
        JsonObject payload = new JsonObject();
        payload.addProperty("id_token", idToken);
        googleLoginCall = ApiClient.getService().autenticarConGoogle(payload);
        googleLoginCall.enqueue(new Callback<>() {
            @Override public void onResponse(@NonNull Call<LoginResponse> call,
                    @NonNull Response<LoginResponse> response) {
                if (binding == null) return;
                setLoading(false, true);
                if (response.code() == 409 && openGoogleRegistration(response, idToken)) return;
                LoginData data = response.body() == null ? null : response.body().getData();
                if (!response.isSuccessful() || !saveSessionAndOpenHome(data)) {
                    showError(ApiErrorParser.message(response, getString(R.string.google_login_error)));
                }
            }

            @Override public void onFailure(@NonNull Call<LoginResponse> call,
                    @NonNull Throwable error) {
                if (binding == null || call.isCanceled()) return;
                setLoading(false, true);
                showError(getString(R.string.connection_error));
            }
        });
    }

    private boolean openGoogleRegistration(Response<LoginResponse> response, String idToken) {
        try {
            if (response.errorBody() == null) return false;
            JsonObject body = JsonParser.parseString(response.errorBody().string()).getAsJsonObject();
            if (!body.has("codigo") || !"registro_requerido".equals(body.get("codigo").getAsString()))
                return false;
            JsonObject profile = body.getAsJsonObject("data");
            Intent intent = new Intent(this, RegisterActivity.class);
            intent.putExtra(RegisterActivity.EXTRA_GOOGLE_ID_TOKEN, idToken);
            intent.putExtra(RegisterActivity.EXTRA_GOOGLE_FIRST_NAME, stringValue(profile, "nombre"));
            intent.putExtra(RegisterActivity.EXTRA_GOOGLE_LAST_NAME, stringValue(profile, "apellido"));
            intent.putExtra(RegisterActivity.EXTRA_GOOGLE_EMAIL, stringValue(profile, "email"));
            startActivity(intent);
            return true;
        } catch (Exception ignored) {
            return false;
        }
    }

    private String stringValue(JsonObject object, String name) {
        return object != null && object.has(name) && !object.get(name).isJsonNull()
                ? object.get(name).getAsString() : "";
    }

    private void submitLogin() {
        clearErrors();
        String email = binding.emailInput.getText() == null
                ? "" : binding.emailInput.getText().toString().trim();
        String password = binding.passwordInput.getText() == null
                ? "" : binding.passwordInput.getText().toString();

        boolean valid = true;
        if (email.isEmpty()) {
            binding.emailLayout.setError(getString(R.string.required_email));
            valid = false;
        } else if (!Patterns.EMAIL_ADDRESS.matcher(email).matches()) {
            binding.emailLayout.setError(getString(R.string.invalid_email));
            valid = false;
        }
        if (password.isEmpty()) {
            binding.passwordLayout.setError(getString(R.string.required_password));
            valid = false;
        }
        if (!valid) {
            return;
        }

        setLoading(true, false);
        loginCall = ApiClient.getService().iniciarSesion(
                new LoginRequest(email.toLowerCase(Locale.ROOT), password)
        );
        loginCall.enqueue(new Callback<>() {
            @Override
            public void onResponse(@NonNull Call<LoginResponse> call,
                                   @NonNull Response<LoginResponse> response) {
                if (binding == null) return;
                setLoading(false, false);
                LoginData data = response.body() == null ? null : response.body().getData();
                if (!response.isSuccessful() || data == null || data.getUsuario() == null
                        || data.getToken() == null || data.getToken().isBlank()) {
                    showError(ApiErrorParser.message(response, getString(R.string.unexpected_error)));
                    return;
                }

                saveSessionAndOpenHome(data);
            }

            @Override
            public void onFailure(@NonNull Call<LoginResponse> call, @NonNull Throwable error) {
                if (binding == null || call.isCanceled()) return;
                setLoading(false, false);
                showError(getString(R.string.connection_error));
            }
        });
    }

    private boolean saveSessionAndOpenHome(LoginData data) {
        if (data == null || data.getUsuario() == null || data.getToken() == null
                || data.getToken().isBlank()) return false;
        if (!isMobileRole(data.getUsuario().getRol())) {
            showError(getString(R.string.unsupported_role));
            return false;
        }
        try {
            new SessionManager(this).save(data);
        } catch (RuntimeException error) {
            showError(getString(R.string.unexpected_error));
            return false;
        }
        Intent intent = new Intent(this, HomeActivity.class);
        intent.addFlags(Intent.FLAG_ACTIVITY_CLEAR_TOP | Intent.FLAG_ACTIVITY_NEW_TASK);
        startActivity(intent);
        finish();
        return true;
    }

    private boolean isMobileRole(String role) {
        return role != null && (role.equalsIgnoreCase("Alumno")
                || role.equalsIgnoreCase("Profesor"));
    }

    private void clearErrors() {
        binding.emailLayout.setError(null);
        binding.passwordLayout.setError(null);
        binding.errorText.setVisibility(View.GONE);
    }

    private void showError(String message) {
        binding.errorText.setText(message);
        binding.errorText.setVisibility(View.VISIBLE);
    }

    private void setLoading(boolean loading, boolean google) {
        binding.progressIndicator.setVisibility(loading ? View.VISIBLE : View.GONE);
        binding.loginButton.setEnabled(!loading);
        binding.googleLoginButton.setEnabled(!loading && !BuildConfig.GOOGLE_WEB_CLIENT_ID.isBlank());
        binding.registerButton.setEnabled(!loading);
        binding.emailInput.setEnabled(!loading);
        binding.passwordInput.setEnabled(!loading);
        binding.loginButton.setText(loading && !google ? R.string.logging_in : R.string.login);
        binding.googleLoginButton.setText(loading && google
                ? R.string.google_signing_in : R.string.login_with_google);
    }

    @Override
    protected void onDestroy() {
        if (loginCall != null) loginCall.cancel();
        if (googleLoginCall != null) googleLoginCall.cancel();
        if (googleCancellationSignal != null) googleCancellationSignal.cancel();
        binding = null;
        super.onDestroy();
    }
}
