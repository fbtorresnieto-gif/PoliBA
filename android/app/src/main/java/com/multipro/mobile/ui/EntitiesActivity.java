package com.multipro.mobile.ui;

import android.content.Intent;
import android.os.Bundle;
import android.view.View;

import androidx.annotation.NonNull;
import androidx.appcompat.app.AppCompatActivity;

import com.multipro.mobile.R;
import com.multipro.mobile.data.model.Entity;
import com.multipro.mobile.data.model.EntityListResponse;
import com.multipro.mobile.data.remote.ApiClient;
import com.multipro.mobile.data.remote.ApiErrorParser;
import com.multipro.mobile.databinding.ActivityEntitiesBinding;
import com.multipro.mobile.databinding.ItemEntityBinding;

import java.util.Collections;
import java.util.List;

import retrofit2.Call;
import retrofit2.Callback;
import retrofit2.Response;

public class EntitiesActivity extends AppCompatActivity {
    private ActivityEntitiesBinding binding;
    private Call<EntityListResponse> request;

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        binding = ActivityEntitiesBinding.inflate(getLayoutInflater());
        setContentView(binding.getRoot());
        binding.backButton.setOnClickListener(view -> finish());
        binding.retryButton.setOnClickListener(view -> loadEntities());
        loadEntities();
    }

    private void loadEntities() {
        showLoading();
        request = ApiClient.getService().listarEntidades();
        request.enqueue(new Callback<>() {
            @Override
            public void onResponse(@NonNull Call<EntityListResponse> call,
                                   @NonNull Response<EntityListResponse> response) {
                if (binding == null) return;
                if (!response.isSuccessful()) {
                    showError(ApiErrorParser.message(response, getString(R.string.unexpected_error)));
                    return;
                }
                List<Entity> entities = response.body() == null || response.body().getData() == null
                        ? Collections.emptyList() : response.body().getData();
                renderEntities(entities);
            }

            @Override
            public void onFailure(@NonNull Call<EntityListResponse> call, @NonNull Throwable error) {
                if (binding == null || call.isCanceled()) return;
                showError(getString(R.string.connection_error));
            }
        });
    }

    private void renderEntities(List<Entity> entities) {
        binding.listContainer.removeAllViews();
        if (entities.isEmpty()) {
            showEmpty(getString(R.string.no_entities));
            return;
        }

        binding.stateContainer.setVisibility(View.GONE);
        binding.contentScroll.setVisibility(View.VISIBLE);
        for (Entity entity : entities) {
            ItemEntityBinding item = ItemEntityBinding.inflate(
                    getLayoutInflater(), binding.listContainer, false);
            item.nameText.setText(value(entity.getNombre()));
            ImageLoader.load(item.imageView, entity.getImageUrl());
            item.addressText.setText(value(entity.getDireccion()));
            item.infoText.setText(value(entity.getInformacion()));
            item.scheduleText.setText(getString(
                    R.string.open_days,
                    value(entity.getDiaApertura()),
                    value(entity.getDiaCierre())
            ));
            item.getRoot().setOnClickListener(view -> {
                Intent intent = new Intent(this, SpacesActivity.class);
                intent.putExtra(SpacesActivity.EXTRA_ENTITY_ID, entity.getId());
                intent.putExtra(SpacesActivity.EXTRA_ENTITY_NAME, entity.getNombre());
                startActivity(intent);
            });
            binding.listContainer.addView(item.getRoot());
        }
    }

    private void showLoading() {
        binding.contentScroll.setVisibility(View.GONE);
        binding.stateContainer.setVisibility(View.VISIBLE);
        binding.progressIndicator.setVisibility(View.VISIBLE);
        binding.stateText.setText(R.string.loading_data);
        binding.retryButton.setVisibility(View.GONE);
    }

    private void showError(String message) {
        binding.contentScroll.setVisibility(View.GONE);
        binding.stateContainer.setVisibility(View.VISIBLE);
        binding.progressIndicator.setVisibility(View.GONE);
        binding.stateText.setText(message);
        binding.retryButton.setVisibility(View.VISIBLE);
    }

    private void showEmpty(String message) {
        binding.contentScroll.setVisibility(View.GONE);
        binding.stateContainer.setVisibility(View.VISIBLE);
        binding.progressIndicator.setVisibility(View.GONE);
        binding.stateText.setText(message);
        binding.retryButton.setVisibility(View.GONE);
    }

    private String value(String value) { return value == null ? "" : value; }

    @Override
    protected void onDestroy() {
        if (request != null) request.cancel();
        binding = null;
        super.onDestroy();
    }
}
