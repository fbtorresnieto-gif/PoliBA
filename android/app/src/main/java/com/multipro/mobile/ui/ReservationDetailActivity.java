package com.multipro.mobile.ui;

import android.content.Intent;
import android.os.Bundle;
import android.view.View;

import androidx.annotation.NonNull;
import androidx.appcompat.app.AppCompatActivity;
import androidx.core.content.ContextCompat;

import com.google.android.material.dialog.MaterialAlertDialogBuilder;
import com.multipro.mobile.R;
import com.multipro.mobile.data.local.SessionManager;
import com.multipro.mobile.data.model.Reservation;
import com.multipro.mobile.data.model.ReservationCancelData;
import com.multipro.mobile.data.model.ReservationCancelRequest;
import com.multipro.mobile.data.model.ReservationCancelResponse;
import com.multipro.mobile.data.model.ReservationResponse;
import com.multipro.mobile.data.remote.ApiClient;
import com.multipro.mobile.data.remote.ApiErrorParser;
import com.multipro.mobile.databinding.ActivityReservationDetailBinding;

import java.time.LocalDate;
import java.time.format.DateTimeFormatter;
import java.time.format.DateTimeParseException;
import java.util.Locale;

import retrofit2.Call;
import retrofit2.Callback;
import retrofit2.Response;

public class ReservationDetailActivity extends AppCompatActivity {
    public static final String EXTRA_RESERVATION_ID = "reservation_id";

    private static final DateTimeFormatter DISPLAY_DATE =
            DateTimeFormatter.ofPattern("dd/MM/yyyy", new Locale("es", "AR"));

    private ActivityReservationDetailBinding binding;
    private SessionManager sessionManager;
    private Call<ReservationResponse> detailRequest;
    private Call<ReservationCancelResponse> cancellationRequest;
    private int reservationId;
    private boolean cancelling;

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        reservationId = getIntent().getIntExtra(EXTRA_RESERVATION_ID, 0);
        if (reservationId <= 0) {
            finish();
            return;
        }
        sessionManager = new SessionManager(this);
        if (!sessionManager.hasSession()) {
            openLogin();
            return;
        }
        binding = ActivityReservationDetailBinding.inflate(getLayoutInflater());
        setContentView(binding.getRoot());
        binding.backButton.setOnClickListener(view -> finish());
        binding.retryButton.setOnClickListener(view -> loadReservation());
        binding.cancelButton.setOnClickListener(view -> confirmCancellation());
        loadReservation();
    }

    private void loadReservation() {
        if (detailRequest != null) detailRequest.cancel();
        String authorization = sessionManager.authorizationHeader();
        if (authorization == null) {
            openLogin();
            return;
        }
        showLoading();
        detailRequest = ApiClient.getService().consultarReserva(authorization, reservationId);
        detailRequest.enqueue(new Callback<>() {
            @Override
            public void onResponse(@NonNull Call<ReservationResponse> call,
                                   @NonNull Response<ReservationResponse> response) {
                if (binding == null) return;
                if (response.code() == 401) {
                    sessionManager.clear();
                    openLogin();
                    return;
                }
                Reservation reservation = response.body() == null
                        ? null : response.body().getData();
                if (!response.isSuccessful() || reservation == null) {
                    showError(ApiErrorParser.message(response, getString(R.string.unexpected_error)));
                    return;
                }
                renderReservation(reservation);
            }

            @Override
            public void onFailure(@NonNull Call<ReservationResponse> call,
                                  @NonNull Throwable error) {
                if (binding == null || call.isCanceled()) return;
                showError(getString(R.string.connection_error));
            }
        });
    }

    private void renderReservation(Reservation reservation) {
        binding.numberText.setText(getString(R.string.reservation_number, reservation.getId()));
        binding.spaceText.setText(value(reservation.getSpaceName()));
        binding.entityText.setText(value(reservation.getEntityName()));
        binding.addressText.setText(value(reservation.getEntityAddress()));
        binding.dateText.setText(formatDate(reservation.getFecha()));
        binding.timeText.setText(getString(R.string.hour_value, formatTime(reservation.getHorario())));
        binding.statusText.setText(reservation.isActive()
                ? R.string.status_reserved : R.string.status_cancelled);
        binding.statusText.setTextColor(ContextCompat.getColor(
                this,
                reservation.isActive() ? R.color.multipro_success : R.color.multipro_text_muted
        ));
        binding.cancelButton.setVisibility(reservation.isActive() ? View.VISIBLE : View.GONE);
        binding.stateContainer.setVisibility(View.GONE);
        binding.contentScroll.setVisibility(View.VISIBLE);
    }

    private void confirmCancellation() {
        if (cancelling) return;
        new MaterialAlertDialogBuilder(this)
                .setTitle(R.string.confirm_cancellation_title)
                .setMessage(getString(R.string.confirm_cancellation_message, reservationId))
                .setNegativeButton(R.string.cancel, null)
                .setPositiveButton(R.string.confirm, (dialog, which) -> cancelReservation())
                .show();
    }

    private void cancelReservation() {
        String authorization = sessionManager.authorizationHeader();
        if (authorization == null) {
            openLogin();
            return;
        }
        setCancelling(true);
        cancellationRequest = ApiClient.getService().cancelarReserva(
                authorization,
                new ReservationCancelRequest(reservationId)
        );
        cancellationRequest.enqueue(new Callback<>() {
            @Override
            public void onResponse(@NonNull Call<ReservationCancelResponse> call,
                                   @NonNull Response<ReservationCancelResponse> response) {
                if (binding == null) return;
                setCancelling(false);
                if (response.code() == 401) {
                    sessionManager.clear();
                    openLogin();
                    return;
                }
                ReservationCancelData data = response.body() == null
                        ? null : response.body().getData();
                if (response.isSuccessful() && data != null) {
                    new MaterialAlertDialogBuilder(ReservationDetailActivity.this)
                            .setTitle(R.string.reservation_cancelled_title)
                            .setMessage(R.string.reservation_cancelled_message)
                            .setPositiveButton(R.string.accept,
                                    (dialog, which) -> loadReservation())
                            .setOnCancelListener(dialog -> loadReservation())
                            .show();
                    return;
                }
                String message = ApiErrorParser.message(
                        response,
                        getString(R.string.unexpected_error)
                );
                new MaterialAlertDialogBuilder(ReservationDetailActivity.this)
                        .setTitle(R.string.unexpected_error)
                        .setMessage(message)
                        .setPositiveButton(R.string.accept,
                                (dialog, which) -> loadReservation())
                        .show();
            }

            @Override
            public void onFailure(@NonNull Call<ReservationCancelResponse> call,
                                  @NonNull Throwable error) {
                if (binding == null || call.isCanceled()) return;
                setCancelling(false);
                new MaterialAlertDialogBuilder(ReservationDetailActivity.this)
                        .setTitle(R.string.unexpected_error)
                        .setMessage(R.string.cancellation_uncertain)
                        .setPositiveButton(R.string.accept,
                                (dialog, which) -> loadReservation())
                        .show();
            }
        });
    }

    private void setCancelling(boolean value) {
        cancelling = value;
        binding.cancellingContainer.setVisibility(value ? View.VISIBLE : View.GONE);
        binding.cancelButton.setEnabled(!value);
        binding.backButton.setEnabled(!value);
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

    private String formatDate(String date) {
        if (date == null) return "";
        try {
            return LocalDate.parse(date).format(DISPLAY_DATE);
        } catch (DateTimeParseException ignored) {
            return date;
        }
    }

    private String formatTime(String time) {
        if (time == null) return "";
        return time.length() >= 5 ? time.substring(0, 5) : time;
    }

    private String value(String value) { return value == null ? "" : value; }

    private void openLogin() {
        Intent intent = new Intent(this, LoginActivity.class);
        intent.putExtra("session_expired", true);
        intent.addFlags(Intent.FLAG_ACTIVITY_CLEAR_TASK | Intent.FLAG_ACTIVITY_NEW_TASK);
        startActivity(intent);
        finish();
    }

    @Override
    protected void onDestroy() {
        if (detailRequest != null) detailRequest.cancel();
        binding = null;
        super.onDestroy();
    }
}
