package com.multipro.mobile.ui;

import android.content.Intent;
import android.os.Bundle;
import android.view.View;

import androidx.annotation.NonNull;
import androidx.appcompat.app.AppCompatActivity;
import androidx.activity.OnBackPressedCallback;
import androidx.core.view.GravityCompat;

import com.google.android.material.dialog.MaterialAlertDialogBuilder;
import com.google.gson.JsonObject;
import com.multipro.mobile.R;
import com.multipro.mobile.data.local.SessionManager;
import com.multipro.mobile.data.model.NewsItem;
import com.multipro.mobile.data.model.NewsListResponse;
import com.multipro.mobile.data.model.User;
import com.multipro.mobile.data.model.UserResponse;
import com.multipro.mobile.data.remote.ApiClient;
import com.multipro.mobile.data.remote.ApiErrorParser;
import com.multipro.mobile.databinding.ActivityHomeBinding;
import com.multipro.mobile.databinding.ItemNewsBinding;

import java.time.LocalDate;
import java.time.format.DateTimeFormatter;
import java.time.format.DateTimeParseException;
import java.util.Collections;
import java.util.List;
import java.util.Locale;

import retrofit2.Call;
import retrofit2.Callback;
import retrofit2.Response;

public class HomeActivity extends AppCompatActivity {
    private static final DateTimeFormatter DISPLAY_DATE =
            DateTimeFormatter.ofPattern("dd/MM/yyyy", new Locale("es", "AR"));
    private ActivityHomeBinding binding;
    private SessionManager sessionManager;
    private Call<UserResponse> profileCall;
    private Call<NewsListResponse> newsCall;
    private Call<JsonObject> logoutCall;

    @Override protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        sessionManager = new SessionManager(this);
        if (!sessionManager.hasSession()) { openLogin(false); return; }
        binding = ActivityHomeBinding.inflate(getLayoutInflater());
        setContentView(binding.getRoot());
        getOnBackPressedDispatcher().addCallback(this, new OnBackPressedCallback(true) {
            @Override public void handleOnBackPressed() {
                if (binding != null && binding.drawerLayout.isDrawerOpen(GravityCompat.START))
                    binding.drawerLayout.closeDrawer(GravityCompat.START);
                else finish();
            }
        });
        configureNavigation();
        renderUser(sessionManager.getName(), sessionManager.getRole(), sessionManager.getEmail());
        verifySession();
        loadNews();
    }

    private void configureNavigation() {
        binding.menuButton.setOnClickListener(view -> binding.drawerLayout.openDrawer(GravityCompat.START));
        binding.retryButton.setOnClickListener(view -> loadNews());
        binding.drawerExploreButton.setOnClickListener(view -> openScreen(EntitiesActivity.class));
        binding.drawerReservationsButton.setOnClickListener(view -> openScreen(ReservationsActivity.class));
        binding.drawerModulesButton.setOnClickListener(view -> openScreen(ModulesActivity.class));
        binding.drawerProfileButton.setOnClickListener(view -> openScreen(ProfileActivity.class));
        binding.drawerLogoutButton.setOnClickListener(view -> logout());
    }

    private void openScreen(Class<?> destination) {
        binding.drawerLayout.closeDrawer(GravityCompat.START);
        startActivity(new Intent(this, destination));
    }

    private void renderUser(String name, String role, String email) {
        String safeName = value(name);
        String safeRole = value(role);
        binding.greetingText.setText(getString(R.string.home_news_greeting, safeName));
        binding.drawerNameText.setText(safeName);
        binding.drawerRoleText.setText(getString(R.string.connected_as, safeRole));
        binding.drawerEmailText.setText(value(email));
        boolean professor = safeRole.equalsIgnoreCase("Profesor");
        binding.drawerExploreButton.setVisibility(professor ? View.GONE : View.VISIBLE);
        binding.drawerReservationsButton.setVisibility(professor ? View.GONE : View.VISIBLE);
        binding.drawerModulesButton.setVisibility(professor ? View.VISIBLE : View.GONE);
    }

    private void verifySession() {
        String authorization = sessionManager.authorizationHeader();
        if (authorization == null) { openLogin(true); return; }
        profileCall = ApiClient.getService().consultarUsuario(authorization);
        profileCall.enqueue(new Callback<>() {
            @Override public void onResponse(@NonNull Call<UserResponse> call,
                    @NonNull Response<UserResponse> response) {
                if (binding == null) return;
                if (response.code() == 401) { sessionManager.clear(); openLogin(true); return; }
                User user = response.body() == null ? null : response.body().getData();
                if (response.isSuccessful() && user != null)
                    renderUser(user.getNombre(), user.getRol(), user.getEmail());
            }
            @Override public void onFailure(@NonNull Call<UserResponse> call, @NonNull Throwable error) {
                // Las novedades públicas siguen disponibles si la verificación temporalmente falla.
            }
        });
    }

    private void loadNews() {
        if (newsCall != null) newsCall.cancel();
        showLoading();
        newsCall = ApiClient.getService().listarNovedades();
        newsCall.enqueue(new Callback<>() {
            @Override public void onResponse(@NonNull Call<NewsListResponse> call,
                    @NonNull Response<NewsListResponse> response) {
                if (binding == null) return;
                if (!response.isSuccessful()) {
                    showError(ApiErrorParser.message(response, getString(R.string.unexpected_error))); return;
                }
                List<NewsItem> news = response.body() == null || response.body().getData() == null
                        ? Collections.emptyList() : response.body().getData();
                renderNews(news);
            }
            @Override public void onFailure(@NonNull Call<NewsListResponse> call, @NonNull Throwable error) {
                if (binding == null || call.isCanceled()) return; showError(getString(R.string.connection_error));
            }
        });
    }

    private void renderNews(List<NewsItem> news) {
        binding.listContainer.removeAllViews();
        if (news.isEmpty()) { showEmpty(); return; }
        binding.stateContainer.setVisibility(View.GONE); binding.contentScroll.setVisibility(View.VISIBLE);
        for (NewsItem itemData : news) {
            ItemNewsBinding item = ItemNewsBinding.inflate(getLayoutInflater(), binding.listContainer, false);
            ImageLoader.load(item.imageView, itemData.getImageUrl());
            item.titleText.setText(value(itemData.getNombre()));
            item.descriptionText.setText(value(itemData.getDescripcion()));
            String entity = value(itemData.getEntityName());
            item.entityText.setText(entity);
            item.entityText.setVisibility(entity.isBlank() ? View.GONE : View.VISIBLE);
            item.dateText.setText(dateText(itemData.getStartDate(), itemData.getEndDate()));
            item.getRoot().setOnClickListener(view -> new MaterialAlertDialogBuilder(this)
                    .setTitle(value(itemData.getNombre())).setMessage(value(itemData.getDescripcion()))
                    .setPositiveButton(R.string.accept, null).show());
            binding.listContainer.addView(item.getRoot());
        }
    }

    private void logout() {
        String authorization = sessionManager.authorizationHeader();
        if (authorization == null) { sessionManager.clear(); openLogin(false); return; }
        binding.drawerLogoutButton.setEnabled(false);
        binding.drawerLogoutButton.setText(R.string.logging_out);
        logoutCall = ApiClient.getService().cerrarSesion(authorization);
        logoutCall.enqueue(new Callback<>() {
            @Override public void onResponse(@NonNull Call<JsonObject> call, @NonNull Response<JsonObject> response) {
                if (binding == null) return;
                if (response.isSuccessful() || response.code() == 401) {
                    sessionManager.clear(); openLogin(false); return;
                }
                binding.drawerLogoutButton.setEnabled(true);
                binding.drawerLogoutButton.setText(R.string.logout);
            }
            @Override public void onFailure(@NonNull Call<JsonObject> call, @NonNull Throwable error) {
                if (binding == null || call.isCanceled()) return;
                binding.drawerLogoutButton.setEnabled(true);
                binding.drawerLogoutButton.setText(R.string.logout);
            }
        });
    }

    private String dateText(String start, String end) {
        String first = formatDate(start); String last = formatDate(end);
        if (last.isBlank() || last.equals(first)) return getString(R.string.news_date_single, first);
        return getString(R.string.news_date_range, first, last);
    }
    private String formatDate(String date) {
        if (date == null || date.isBlank()) return "";
        try { return LocalDate.parse(date).format(DISPLAY_DATE); }
        catch (DateTimeParseException ignored) { return date; }
    }
    private String value(String value) { return value == null ? "" : value; }
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
        binding.progressIndicator.setVisibility(View.GONE); binding.stateText.setText(R.string.no_news);
        binding.retryButton.setVisibility(View.GONE);
    }
    private void openLogin(boolean expired) {
        Intent intent = new Intent(this, LoginActivity.class);
        if (expired) intent.putExtra("session_expired", true);
        intent.addFlags(Intent.FLAG_ACTIVITY_CLEAR_TASK | Intent.FLAG_ACTIVITY_NEW_TASK);
        startActivity(intent); finish();
    }
    @Override protected void onDestroy() {
        if (profileCall != null) profileCall.cancel();
        if (newsCall != null) newsCall.cancel();
        if (logoutCall != null) logoutCall.cancel();
        binding = null; super.onDestroy();
    }
}
