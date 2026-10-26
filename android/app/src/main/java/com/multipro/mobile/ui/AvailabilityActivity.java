package com.multipro.mobile.ui;

import android.app.DatePickerDialog;
import android.content.Intent;
import android.os.Bundle;
import android.view.View;

import androidx.annotation.NonNull;
import androidx.appcompat.app.AppCompatActivity;
import androidx.core.content.ContextCompat;

import com.google.android.material.dialog.MaterialAlertDialogBuilder;
import com.multipro.mobile.R;
import com.multipro.mobile.data.local.SessionManager;
import com.multipro.mobile.data.model.AvailabilityData;
import com.multipro.mobile.data.model.AvailabilityResponse;
import com.multipro.mobile.data.model.AvailabilitySlot;
import com.multipro.mobile.data.model.ReservationCreateData;
import com.multipro.mobile.data.model.ReservationCreateRequest;
import com.multipro.mobile.data.model.ReservationCreateResponse;
import com.multipro.mobile.data.remote.ApiClient;
import com.multipro.mobile.data.remote.ApiErrorParser;
import com.multipro.mobile.databinding.ActivityAvailabilityBinding;
import com.multipro.mobile.databinding.ItemAvailabilitySlotBinding;

import java.time.LocalDate;
import java.time.format.DateTimeFormatter;
import java.util.Collections;
import java.util.List;
import java.util.Locale;

import retrofit2.Call;
import retrofit2.Callback;
import retrofit2.Response;

public class AvailabilityActivity extends AppCompatActivity {
    public static final String EXTRA_SPACE_ID = "space_id";
    public static final String EXTRA_SPACE_NAME = "space_name";

    private static final DateTimeFormatter API_DATE = DateTimeFormatter.ISO_LOCAL_DATE;
    private static final DateTimeFormatter DISPLAY_DATE =
            DateTimeFormatter.ofPattern("dd/MM/yyyy", new Locale("es", "AR"));

    private ActivityAvailabilityBinding binding;
    private Call<AvailabilityResponse> request;
    private Call<ReservationCreateResponse> reservationRequest;
    private SessionManager sessionManager;
    private int spaceId;
    private LocalDate selectedDate;
    private String spaceName;
    private boolean bookingInProgress;

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        spaceId = getIntent().getIntExtra(EXTRA_SPACE_ID, 0);
        if (spaceId <= 0) {
            finish();
            return;
        }

        binding = ActivityAvailabilityBinding.inflate(getLayoutInflater());
        setContentView(binding.getRoot());
        sessionManager = new SessionManager(this);
        spaceName = getIntent().getStringExtra(EXTRA_SPACE_NAME);
        binding.subtitleText.setText(getString(R.string.availability_subtitle, value(spaceName)));
        binding.backButton.setOnClickListener(view -> finish());
        binding.dateButton.setOnClickListener(view -> openDatePicker());
        binding.retryButton.setOnClickListener(view -> loadAvailability());

        selectedDate = LocalDate.now();
        updateDateLabel();
        loadAvailability();
    }

    private void openDatePicker() {
        DatePickerDialog dialog = new DatePickerDialog(
                this,
                (picker, year, month, day) -> {
                    selectedDate = LocalDate.of(year, month + 1, day);
                    updateDateLabel();
                    loadAvailability();
                },
                selectedDate.getYear(),
                selectedDate.getMonthValue() - 1,
                selectedDate.getDayOfMonth()
        );
        dialog.getDatePicker().setMinDate(System.currentTimeMillis() - 1000L);
        dialog.show();
    }

    private void updateDateLabel() {
        binding.dateButton.setText(getString(
                R.string.selected_date,
                selectedDate.format(DISPLAY_DATE)
        ));
    }

    private void loadAvailability() {
        if (request != null) request.cancel();
        showLoading();
        request = ApiClient.getService().consultarDisponibilidad(
                spaceId,
                selectedDate.format(API_DATE)
        );
        request.enqueue(new Callback<>() {
            @Override
            public void onResponse(@NonNull Call<AvailabilityResponse> call,
                                   @NonNull Response<AvailabilityResponse> response) {
                if (binding == null) return;
                AvailabilityData data = response.body() == null ? null : response.body().getData();
                if (!response.isSuccessful() || data == null) {
                    showError(ApiErrorParser.message(response, getString(R.string.unexpected_error)));
                    return;
                }
                renderAvailability(data);
            }

            @Override
            public void onFailure(@NonNull Call<AvailabilityResponse> call,
                                  @NonNull Throwable error) {
                if (binding == null || call.isCanceled()) return;
                showError(getString(R.string.connection_error));
            }
        });
    }

    private void renderAvailability(AvailabilityData data) {
        List<AvailabilitySlot> slots = data.getSlots() == null
                ? Collections.emptyList() : data.getSlots();
        binding.listContainer.removeAllViews();
        binding.entityStatusText.setVisibility(data.isEntityOpen() ? View.GONE : View.VISIBLE);
        if (slots.isEmpty()) {
            showEmpty(getString(R.string.no_slots));
            return;
        }

        binding.stateContainer.setVisibility(View.GONE);
        binding.contentScroll.setVisibility(View.VISIBLE);
        for (AvailabilitySlot slot : slots) {
            ItemAvailabilitySlotBinding item = ItemAvailabilitySlotBinding.inflate(
                    getLayoutInflater(), binding.listContainer, false);
            item.timeText.setText(getString(R.string.hour_value, formatTime(slot.getHorario())));
            item.statusText.setText(slot.isDisponible() ? R.string.available : R.string.unavailable);
            item.statusText.setTextColor(ContextCompat.getColor(
                    this,
                    slot.isDisponible() ? R.color.multipro_success : R.color.multipro_error
            ));
            item.getRoot().setCardBackgroundColor(ContextCompat.getColor(
                    this,
                    slot.isDisponible()
                            ? R.color.multipro_success_background
                            : R.color.multipro_unavailable_background
            ));

            String reason = unavailableReason(slot);
            item.reasonText.setText(reason);
            item.reasonText.setVisibility(reason.isBlank() ? View.GONE : View.VISIBLE);
            item.actionText.setVisibility(slot.isDisponible() ? View.VISIBLE : View.GONE);
            item.getRoot().setClickable(slot.isDisponible());
            item.getRoot().setFocusable(slot.isDisponible());
            if (slot.isDisponible()) {
                item.getRoot().setOnClickListener(view -> confirmReservation(slot));
            }
            binding.listContainer.addView(item.getRoot());
        }
    }

    private void confirmReservation(AvailabilitySlot slot) {
        if (bookingInProgress) return;
        String displayDate = selectedDate.format(DISPLAY_DATE);
        String displayTime = formatTime(slot.getHorario());
        new MaterialAlertDialogBuilder(this)
                .setTitle(R.string.confirm_reservation_title)
                .setMessage(getString(
                        R.string.confirm_reservation_message,
                        value(spaceName),
                        displayDate,
                        displayTime
                ))
                .setNegativeButton(R.string.cancel, null)
                .setPositiveButton(R.string.confirm,
                        (dialog, which) -> createReservation(slot.getHorario()))
                .show();
    }

    private void createReservation(String time) {
        String authorization = sessionManager.authorizationHeader();
        if (authorization == null) {
            openLogin();
            return;
        }

        setBookingInProgress(true);
        reservationRequest = ApiClient.getService().crearReserva(
                authorization,
                new ReservationCreateRequest(
                        spaceId,
                        selectedDate.format(API_DATE),
                        time
                )
        );
        reservationRequest.enqueue(new Callback<>() {
            @Override
            public void onResponse(@NonNull Call<ReservationCreateResponse> call,
                                   @NonNull Response<ReservationCreateResponse> response) {
                if (binding == null) return;
                setBookingInProgress(false);
                if (response.code() == 401) {
                    sessionManager.clear();
                    openLogin();
                    return;
                }

                ReservationCreateData data = response.body() == null
                        ? null : response.body().getData();
                if (response.isSuccessful() && data != null) {
                    new MaterialAlertDialogBuilder(AvailabilityActivity.this)
                            .setTitle(R.string.reservation_created_title)
                            .setMessage(getString(R.string.reservation_created_message, data.getId()))
                            .setPositiveButton(R.string.accept,
                                    (dialog, which) -> loadAvailability())
                            .setOnCancelListener(dialog -> loadAvailability())
                            .show();
                    return;
                }

                String message = ApiErrorParser.message(
                        response,
                        getString(R.string.unexpected_error)
                );
                new MaterialAlertDialogBuilder(AvailabilityActivity.this)
                        .setTitle(response.code() == 409
                                ? R.string.unavailable : R.string.unexpected_error)
                        .setMessage(message)
                        .setPositiveButton(R.string.accept,
                                (dialog, which) -> loadAvailability())
                        .show();
            }

            @Override
            public void onFailure(@NonNull Call<ReservationCreateResponse> call,
                                  @NonNull Throwable error) {
                if (binding == null || call.isCanceled()) return;
                setBookingInProgress(false);
                new MaterialAlertDialogBuilder(AvailabilityActivity.this)
                        .setTitle(R.string.unexpected_error)
                        .setMessage(R.string.reservation_uncertain)
                        .setPositiveButton(R.string.accept,
                                (dialog, which) -> loadAvailability())
                        .show();
            }
        });
    }

    private void setBookingInProgress(boolean inProgress) {
        bookingInProgress = inProgress;
        binding.bookingContainer.setVisibility(inProgress ? View.VISIBLE : View.GONE);
        binding.dateButton.setEnabled(!inProgress);
        binding.backButton.setEnabled(!inProgress);
    }

    private void openLogin() {
        Intent intent = new Intent(this, LoginActivity.class);
        intent.putExtra("session_expired", true);
        intent.addFlags(Intent.FLAG_ACTIVITY_CLEAR_TASK | Intent.FLAG_ACTIVITY_NEW_TASK);
        startActivity(intent);
        finish();
    }

    private String unavailableReason(AvailabilitySlot slot) {
        if (slot.isDisponible() || slot.getUnavailableReason() == null) return "";
        return switch (slot.getUnavailableReason()) {
            case "reservado" -> getString(R.string.reserved);
            case "modulo" -> getString(R.string.occupied_module, value(slot.getModulo()));
            case "horario_pasado" -> getString(R.string.past_time);
            case "entidad_cerrada" -> getString(R.string.entity_closed);
            default -> getString(R.string.unavailable);
        };
    }

    private void showLoading() {
        binding.contentScroll.setVisibility(View.GONE);
        binding.stateContainer.setVisibility(View.VISIBLE);
        binding.progressIndicator.setVisibility(View.VISIBLE);
        binding.stateText.setText(R.string.loading_data);
        binding.retryButton.setVisibility(View.GONE);
        binding.entityStatusText.setVisibility(View.GONE);
    }

    private void showError(String message) {
        binding.contentScroll.setVisibility(View.GONE);
        binding.stateContainer.setVisibility(View.VISIBLE);
        binding.progressIndicator.setVisibility(View.GONE);
        binding.stateText.setText(message);
        binding.retryButton.setVisibility(View.VISIBLE);
        binding.entityStatusText.setVisibility(View.GONE);
    }

    private void showEmpty(String message) {
        binding.contentScroll.setVisibility(View.GONE);
        binding.stateContainer.setVisibility(View.VISIBLE);
        binding.progressIndicator.setVisibility(View.GONE);
        binding.stateText.setText(message);
        binding.retryButton.setVisibility(View.GONE);
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
