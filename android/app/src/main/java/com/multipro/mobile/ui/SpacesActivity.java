package com.multipro.mobile.ui;

import android.content.Intent;
import android.os.Bundle;
import android.view.View;

import androidx.annotation.NonNull;
import androidx.appcompat.app.AppCompatActivity;

import com.multipro.mobile.R;
import com.multipro.mobile.data.model.Space;
import com.multipro.mobile.data.model.SpaceListResponse;
import com.multipro.mobile.data.remote.ApiClient;
import com.multipro.mobile.data.remote.ApiErrorParser;
import com.multipro.mobile.databinding.ActivitySpacesBinding;
import com.multipro.mobile.databinding.ItemSpaceBinding;

import java.util.Collections;
import java.util.List;

import retrofit2.Call;
import retrofit2.Callback;
import retrofit2.Response;

public class SpacesActivity extends AppCompatActivity {
    public static final String EXTRA_ENTITY_ID = "entity_id";
    public static final String EXTRA_ENTITY_NAME = "entity_name";

    private ActivitySpacesBinding binding;
    private Call<SpaceListResponse> request;
    private int entityId;

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        entityId = getIntent().getIntExtra(EXTRA_ENTITY_ID, 0);
        if (entityId <= 0) {
            finish();
            return;
        }

        binding = ActivitySpacesBinding.inflate(getLayoutInflater());
        setContentView(binding.getRoot());
        String entityName = getIntent().getStringExtra(EXTRA_ENTITY_NAME);
        binding.subtitleText.setText(getString(R.string.spaces_subtitle, value(entityName)));
        binding.backButton.setOnClickListener(view -> finish());
        binding.retryButton.setOnClickListener(view -> loadSpaces());
        loadSpaces();
    }

    private void loadSpaces() {
        showLoading();
        request = ApiClient.getService().listarEspacios(entityId);
        request.enqueue(new Callback<>() {
            @Override
            public void onResponse(@NonNull Call<SpaceListResponse> call,
                                   @NonNull Response<SpaceListResponse> response) {
                if (binding == null) return;
                if (!response.isSuccessful()) {
                    showError(ApiErrorParser.message(response, getString(R.string.unexpected_error)));
                    return;
                }
                List<Space> spaces = response.body() == null || response.body().getData() == null
                        ? Collections.emptyList() : response.body().getData();
                renderSpaces(spaces);
            }

            @Override
            public void onFailure(@NonNull Call<SpaceListResponse> call, @NonNull Throwable error) {
                if (binding == null || call.isCanceled()) return;
                showError(getString(R.string.connection_error));
            }
        });
    }

    private void renderSpaces(List<Space> spaces) {
        binding.listContainer.removeAllViews();
        if (spaces.isEmpty()) {
            showEmpty(getString(R.string.no_spaces));
            return;
        }

        binding.stateContainer.setVisibility(View.GONE);
        binding.contentScroll.setVisibility(View.VISIBLE);
        for (Space space : spaces) {
            ItemSpaceBinding item = ItemSpaceBinding.inflate(
                    getLayoutInflater(), binding.listContainer, false);
            item.nameText.setText(value(space.getNombre()));
            ImageLoader.load(item.imageView, space.getImageUrl());
            item.descriptionText.setText(value(space.getDescripcion()));
            item.coveredText.setText(space.isTechado()
                    ? R.string.covered_space : R.string.uncovered_space);
            item.getRoot().setOnClickListener(view -> {
                Intent intent = new Intent(this, SpaceDetailActivity.class);
                intent.putExtra(SpaceDetailActivity.EXTRA_SPACE_ID, space.getId());
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
