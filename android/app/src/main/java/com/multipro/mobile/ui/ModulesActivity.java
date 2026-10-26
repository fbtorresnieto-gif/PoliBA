package com.multipro.mobile.ui;

import android.content.Intent;
import android.os.Bundle;
import android.view.View;

import androidx.annotation.NonNull;
import androidx.appcompat.app.AppCompatActivity;

import com.multipro.mobile.R;
import com.multipro.mobile.data.local.SessionManager;
import com.multipro.mobile.data.model.Module;
import com.multipro.mobile.data.model.ModuleListResponse;
import com.multipro.mobile.data.remote.ApiClient;
import com.multipro.mobile.data.remote.ApiErrorParser;
import com.multipro.mobile.databinding.ActivityModulesBinding;
import com.multipro.mobile.databinding.ItemModuleBinding;

import java.util.Collections;
import java.util.List;

import retrofit2.Call;
import retrofit2.Callback;
import retrofit2.Response;

public class ModulesActivity extends AppCompatActivity {
    private ActivityModulesBinding binding;
    private SessionManager sessionManager;
    private Call<ModuleListResponse> request;

    @Override protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        sessionManager = new SessionManager(this);
        if (!sessionManager.hasSession()) { openLogin(); return; }
        binding = ActivityModulesBinding.inflate(getLayoutInflater());
        setContentView(binding.getRoot());
        binding.backButton.setOnClickListener(view -> finish());
        binding.retryButton.setOnClickListener(view -> loadModules());
        loadModules();
    }

    private void loadModules() {
        if (request != null) request.cancel();
        String authorization = sessionManager.authorizationHeader();
        if (authorization == null) { openLogin(); return; }
        showLoading();
        request = ApiClient.getService().listarModulos(authorization);
        request.enqueue(new Callback<>() {
            @Override public void onResponse(@NonNull Call<ModuleListResponse> call,
                    @NonNull Response<ModuleListResponse> response) {
                if (binding == null) return;
                if (response.code() == 401) { sessionManager.clear(); openLogin(); return; }
                if (response.code() == 403) { showError(getString(R.string.unsupported_role)); return; }
                if (!response.isSuccessful()) {
                    showError(ApiErrorParser.message(response, getString(R.string.unexpected_error)));
                    return;
                }
                List<Module> modules = response.body() == null || response.body().getData() == null
                        ? Collections.emptyList() : response.body().getData();
                renderModules(modules);
            }
            @Override public void onFailure(@NonNull Call<ModuleListResponse> call, @NonNull Throwable error) {
                if (binding == null || call.isCanceled()) return;
                showError(getString(R.string.connection_error));
            }
        });
    }

    private void renderModules(List<Module> modules) {
        binding.listContainer.removeAllViews();
        if (modules.isEmpty()) { showEmpty(); return; }
        binding.stateContainer.setVisibility(View.GONE);
        binding.contentScroll.setVisibility(View.VISIBLE);
        for (Module module : modules) {
            ItemModuleBinding item = ItemModuleBinding.inflate(getLayoutInflater(), binding.listContainer, false);
            item.nameText.setText(value(module.getNombre()));
            item.activityText.setText(value(module.getActivityName()));
            String location = value(module.getEntityName());
            if (module.getSpaceName() != null && !module.getSpaceName().isBlank())
                location += " · " + module.getSpaceName();
            item.locationText.setText(location);
            item.scheduleText.setText(getString(R.string.module_schedule,
                    formatTime(module.getStartTime()), formatTime(module.getEndTime())));
            item.capacityText.setText(getString(R.string.capacity_value, module.getCapacity()));
            item.getRoot().setOnClickListener(view -> {
                Intent intent = new Intent(this, AttendanceActivity.class);
                intent.putExtra("module_id", module.getId());
                intent.putExtra("module_name", module.getNombre());
                startActivity(intent);
            });
            binding.listContainer.addView(item.getRoot());
        }
    }

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
    private void showEmpty() {
        binding.contentScroll.setVisibility(View.GONE); binding.stateContainer.setVisibility(View.VISIBLE);
        binding.progressIndicator.setVisibility(View.GONE); binding.stateText.setText(R.string.no_modules);
        binding.retryButton.setVisibility(View.GONE);
    }
    private String formatTime(String time) { return time == null ? "" : time.substring(0, Math.min(5, time.length())); }
    private String value(String value) { return value == null ? "" : value; }
    private void openLogin() {
        Intent intent = new Intent(this, LoginActivity.class);
        intent.putExtra("session_expired", true);
        intent.addFlags(Intent.FLAG_ACTIVITY_CLEAR_TASK | Intent.FLAG_ACTIVITY_NEW_TASK);
        startActivity(intent); finish();
    }
    @Override protected void onDestroy() {
        if (request != null) request.cancel(); binding = null; super.onDestroy();
    }
}
