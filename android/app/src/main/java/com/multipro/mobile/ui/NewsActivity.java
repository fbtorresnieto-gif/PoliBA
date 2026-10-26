package com.multipro.mobile.ui;

import android.os.Bundle;
import android.view.View;

import androidx.annotation.NonNull;
import androidx.appcompat.app.AppCompatActivity;

import com.multipro.mobile.R;
import com.multipro.mobile.data.model.NewsItem;
import com.multipro.mobile.data.model.NewsListResponse;
import com.multipro.mobile.data.remote.ApiClient;
import com.multipro.mobile.data.remote.ApiErrorParser;
import com.multipro.mobile.databinding.ActivityNewsBinding;
import com.multipro.mobile.databinding.ItemNewsBinding;
import com.google.android.material.dialog.MaterialAlertDialogBuilder;

import java.time.LocalDate;
import java.time.format.DateTimeFormatter;
import java.time.format.DateTimeParseException;
import java.util.Collections;
import java.util.List;
import java.util.Locale;

import retrofit2.Call;
import retrofit2.Callback;
import retrofit2.Response;

public class NewsActivity extends AppCompatActivity {
    private static final DateTimeFormatter DISPLAY_DATE =
            DateTimeFormatter.ofPattern("dd/MM/yyyy", new Locale("es", "AR"));
    private ActivityNewsBinding binding;
    private Call<NewsListResponse> request;

    @Override protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        binding = ActivityNewsBinding.inflate(getLayoutInflater());
        setContentView(binding.getRoot());
        binding.backButton.setOnClickListener(view -> finish());
        binding.retryButton.setOnClickListener(view -> loadNews());
        loadNews();
    }

    private void loadNews() {
        if (request != null) request.cancel();
        showLoading();
        request = ApiClient.getService().listarNovedades();
        request.enqueue(new Callback<>() {
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
            item.titleText.setText(value(itemData.getNombre()));
            ImageLoader.load(item.imageView, itemData.getImageUrl());
            item.descriptionText.setText(value(itemData.getDescripcion()));
            String entity = value(itemData.getEntityName());
            item.entityText.setText(entity);
            item.entityText.setVisibility(entity.isBlank() ? View.GONE : View.VISIBLE);
            item.dateText.setText(dateText(itemData.getStartDate(), itemData.getEndDate()));
            item.getRoot().setOnClickListener(view -> new MaterialAlertDialogBuilder(this)
                    .setTitle(value(itemData.getNombre()))
                    .setMessage(value(itemData.getDescripcion()))
                    .setPositiveButton(R.string.accept, null)
                    .show());
            binding.listContainer.addView(item.getRoot());
        }
    }

    private String dateText(String start, String end) {
        String formattedStart = formatDate(start);
        String formattedEnd = formatDate(end);
        if (formattedEnd.isBlank() || formattedEnd.equals(formattedStart))
            return getString(R.string.news_date_single, formattedStart);
        return getString(R.string.news_date_range, formattedStart, formattedEnd);
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
    @Override protected void onDestroy() {
        if (request != null) request.cancel(); binding = null; super.onDestroy();
    }
}
