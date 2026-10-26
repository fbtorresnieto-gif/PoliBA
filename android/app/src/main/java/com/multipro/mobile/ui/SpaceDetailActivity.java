package com.multipro.mobile.ui;

import android.content.Intent;
import android.os.Bundle;
import android.view.View;

import androidx.annotation.NonNull;
import androidx.appcompat.app.AppCompatActivity;

import com.multipro.mobile.R;
import com.multipro.mobile.data.model.Space;
import com.multipro.mobile.data.model.SpaceResponse;
import com.multipro.mobile.data.remote.ApiClient;
import com.multipro.mobile.data.remote.ApiErrorParser;
import com.multipro.mobile.databinding.ActivitySpaceDetailBinding;

import retrofit2.Call;
import retrofit2.Callback;
import retrofit2.Response;

public class SpaceDetailActivity extends AppCompatActivity {
    public static final String EXTRA_SPACE_ID = "space_id";

    private ActivitySpaceDetailBinding binding;
    private Call<SpaceResponse> request;
    private int spaceId;

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        spaceId = getIntent().getIntExtra(EXTRA_SPACE_ID, 0);
        if (spaceId <= 0) {
            finish();
            return;
        }

        binding = ActivitySpaceDetailBinding.inflate(getLayoutInflater());
        setContentView(binding.getRoot());
        binding.backButton.setOnClickListener(view -> finish());
        binding.retryButton.setOnClickListener(view -> loadSpace());
        loadSpace();
    }

    private void loadSpace() {
        showLoading();
        request = ApiClient.getService().consultarEspacio(spaceId);
        request.enqueue(new Callback<>() {
            @Override
            public void onResponse(@NonNull Call<SpaceResponse> call,
                                   @NonNull Response<SpaceResponse> response) {
                if (binding == null) return;
                Space space = response.body() == null ? null : response.body().getData();
                if (!response.isSuccessful() || space == null) {
                    showError(ApiErrorParser.message(response, getString(R.string.unexpected_error)));
                    return;
                }
                renderSpace(space);
            }

            @Override
            public void onFailure(@NonNull Call<SpaceResponse> call, @NonNull Throwable error) {
                if (binding == null || call.isCanceled()) return;
                showError(getString(R.string.connection_error));
            }
        });
    }

    private void renderSpace(Space space) {
        binding.nameText.setText(value(space.getNombre()));
        binding.coveredText.setText(space.isTechado()
                ? R.string.covered_space : R.string.uncovered_space);
        binding.descriptionText.setText(value(space.getDescripcion()));
        binding.entityText.setText(value(space.getEntidadNombre()));
        binding.addressText.setText(value(space.getEntidadDireccion()));
        binding.daysText.setText(getString(
                R.string.open_days,
                value(space.getDiaApertura()),
                value(space.getDiaCierre())
        ));
        binding.hoursText.setText(getString(
                R.string.open_hours,
                formatTime(space.getHorarioApertura()),
                formatTime(space.getHorarioCierre())
        ));
        binding.availabilityButton.setOnClickListener(view -> {
            Intent intent = new Intent(this, AvailabilityActivity.class);
            intent.putExtra(AvailabilityActivity.EXTRA_SPACE_ID, space.getId());
            intent.putExtra(AvailabilityActivity.EXTRA_SPACE_NAME, space.getNombre());
            startActivity(intent);
        });
        binding.stateContainer.setVisibility(View.GONE);
        binding.contentScroll.setVisibility(View.VISIBLE);
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

    private String formatTime(String time) {
        if (time == null) return "";
        return time.length() >= 5 ? time.substring(0, 5) : time;
    }

    private String value(String value) { return value == null ? "" : value; }

    @Override
    protected void onDestroy() {
        if (request != null) request.cancel();
        binding = null;
        super.onDestroy();
    }
}
