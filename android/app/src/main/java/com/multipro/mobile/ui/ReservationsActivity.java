package com.multipro.mobile.ui;

import android.content.Intent;
import android.os.Bundle;
import android.view.View;

import androidx.annotation.NonNull;
import androidx.appcompat.app.AppCompatActivity;
import androidx.core.content.ContextCompat;

import com.multipro.mobile.R;
import com.multipro.mobile.data.local.SessionManager;
import com.multipro.mobile.data.model.Reservation;
import com.multipro.mobile.data.model.ReservationListResponse;
import com.multipro.mobile.data.remote.ApiClient;
import com.multipro.mobile.data.remote.ApiErrorParser;
import com.multipro.mobile.databinding.ActivityReservationsBinding;
import com.multipro.mobile.databinding.ItemReservationBinding;

import java.time.LocalDate;
import java.time.format.DateTimeFormatter;
import java.time.format.DateTimeParseException;
import java.util.Collections;
import java.util.List;
import java.util.Locale;

import retrofit2.Call;
import retrofit2.Callback;
import retrofit2.Response;

public class ReservationsActivity extends AppCompatActivity {
    private static final DateTimeFormatter API_DATE = DateTimeFormatter.ISO_LOCAL_DATE;
    private static final DateTimeFormatter DISPLAY_DATE =
            DateTimeFormatter.ofPattern("dd/MM/yyyy", new Locale("es", "AR"));

    private ActivityReservationsBinding binding;
    private SessionManager sessionManager;
    private Call<ReservationListResponse> request;

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        sessionManager = new SessionManager(this);
        if (!sessionManager.hasSession()) {
            openLogin();
            return;
        }
        binding = ActivityReservationsBinding.inflate(getLayoutInflater());
        setContentView(binding.getRoot());
        binding.backButton.setOnClickListener(view -> finish());
        binding.retryButton.setOnClickListener(view -> loadReservations());
    }

    @Override
    protected void onResume() {
        super.onResume();
        if (binding != null) loadReservations();
    }

    private void loadReservations() {
        if (request != null) request.cancel();
        String authorization = sessionManager.authorizationHeader();
        if (authorization == null) {
            openLogin();
            return;
        }
        showLoading();
        request = ApiClient.getService().listarReservas(authorization);
        request.enqueue(new Callback<>() {
            @Override
            public void onResponse(@NonNull Call<ReservationListResponse> call,
                                   @NonNull Response<ReservationListResponse> response) {
                if (binding == null) return;
                if (response.code() == 401) {
                    sessionManager.clear();
                    openLogin();
                    return;
                }
                if (!response.isSuccessful()) {
                    showError(ApiErrorParser.message(response, getString(R.string.unexpected_error)));
                    return;
                }
                List<Reservation> reservations = response.body() == null
                        || response.body().getData() == null
                        ? Collections.emptyList() : response.body().getData();
                renderReservations(reservations);
            }

            @Override
            public void onFailure(@NonNull Call<ReservationListResponse> call,
                                  @NonNull Throwable error) {
                if (binding == null || call.isCanceled()) return;
                showError(getString(R.string.connection_error));
            }
        });
    }

    private void renderReservations(List<Reservation> reservations) {
        binding.listContainer.removeAllViews();
        if (reservations.isEmpty()) {
            showEmpty();
            return;
        }
        binding.stateContainer.setVisibility(View.GONE);
        binding.contentScroll.setVisibility(View.VISIBLE);
        for (Reservation reservation : reservations) {
            ItemReservationBinding item = ItemReservationBinding.inflate(
                    getLayoutInflater(), binding.listContainer, false);
            item.numberText.setText(getString(R.string.reservation_number, reservation.getId()));
            item.spaceText.setText(value(reservation.getSpaceName()));
            item.entityText.setText(value(reservation.getEntityName()));
            item.dateTimeText.setText(getString(
                    R.string.reservation_datetime,
                    formatDate(reservation.getFecha()),
                    formatTime(reservation.getHorario())
            ));
            item.statusText.setText(PaymentUi.status(reservation));
            item.amountText.setText(PaymentUi.total(this, reservation));
            item.paymentText.setText(PaymentUi.payment(this, reservation));
            item.statusText.setTextColor(ContextCompat.getColor(
                    this,
                    reservation.isActive() ? R.color.multipro_success : R.color.multipro_text_muted
            ));
            item.getRoot().setOnClickListener(view -> {
                Intent intent = new Intent(this, ReservationDetailActivity.class);
                intent.putExtra(ReservationDetailActivity.EXTRA_RESERVATION_ID, reservation.getId());
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

    private void showEmpty() {
        binding.contentScroll.setVisibility(View.GONE);
        binding.stateContainer.setVisibility(View.VISIBLE);
        binding.progressIndicator.setVisibility(View.GONE);
        binding.stateText.setText(R.string.no_reservations);
        binding.retryButton.setVisibility(View.GONE);
    }

    private String formatDate(String date) {
        if (date == null) return "";
        try {
            return LocalDate.parse(date, API_DATE).format(DISPLAY_DATE);
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
        if (request != null) request.cancel();
        binding = null;
        super.onDestroy();
    }
}
