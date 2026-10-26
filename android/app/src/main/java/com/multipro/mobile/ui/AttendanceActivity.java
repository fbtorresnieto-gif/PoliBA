package com.multipro.mobile.ui;

import android.app.DatePickerDialog;
import android.content.Intent;
import android.os.Bundle;
import android.view.View;
import android.widget.Toast;

import androidx.annotation.NonNull;
import androidx.appcompat.app.AppCompatActivity;

import com.multipro.mobile.R;
import com.multipro.mobile.data.local.SessionManager;
import com.multipro.mobile.data.model.AttendanceData;
import com.multipro.mobile.data.model.AttendanceListResponse;
import com.multipro.mobile.data.model.AttendanceRequest;
import com.multipro.mobile.data.model.AttendanceUpdateResponse;
import com.multipro.mobile.data.model.StudentAttendance;
import com.multipro.mobile.data.remote.ApiClient;
import com.multipro.mobile.data.remote.ApiErrorParser;
import com.multipro.mobile.databinding.ActivityAttendanceBinding;
import com.multipro.mobile.databinding.ItemStudentAttendanceBinding;

import java.time.LocalDate;
import java.time.format.DateTimeFormatter;
import java.util.Collections;
import java.util.List;
import java.util.Locale;

import retrofit2.Call;
import retrofit2.Callback;
import retrofit2.Response;

public class AttendanceActivity extends AppCompatActivity {
    private static final DateTimeFormatter API_DATE = DateTimeFormatter.ISO_LOCAL_DATE;
    private static final DateTimeFormatter DISPLAY_DATE = DateTimeFormatter.ofPattern("dd/MM/yyyy", new Locale("es", "AR"));
    private ActivityAttendanceBinding binding;
    private SessionManager sessionManager;
    private int moduleId;
    private String moduleName;
    private LocalDate selectedDate = LocalDate.now();
    private Call<AttendanceListResponse> listRequest;
    private Call<AttendanceUpdateResponse> saveRequest;

    @Override protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        sessionManager = new SessionManager(this);
        moduleId = getIntent().getIntExtra("module_id", 0);
        moduleName = getIntent().getStringExtra("module_name");
        if (!sessionManager.hasSession()) { openLogin(); return; }
        if (moduleId < 1) { finish(); return; }
        binding = ActivityAttendanceBinding.inflate(getLayoutInflater());
        setContentView(binding.getRoot());
        binding.moduleText.setText(value(moduleName));
        binding.backButton.setOnClickListener(view -> finish());
        binding.retryButton.setOnClickListener(view -> loadStudents());
        binding.dateButton.setOnClickListener(view -> selectDate());
        updateDateLabel();
        loadStudents();
    }

    private void selectDate() {
        DatePickerDialog dialog = new DatePickerDialog(this, (picker, year, month, day) -> {
            selectedDate = LocalDate.of(year, month + 1, day);
            updateDateLabel(); loadStudents();
        }, selectedDate.getYear(), selectedDate.getMonthValue() - 1, selectedDate.getDayOfMonth());
        dialog.show();
    }

    private void updateDateLabel() {
        binding.dateButton.setText(getString(R.string.attendance_date, selectedDate.format(DISPLAY_DATE)));
    }

    private void loadStudents() {
        if (listRequest != null) listRequest.cancel();
        String authorization = sessionManager.authorizationHeader();
        if (authorization == null) { openLogin(); return; }
        showLoading();
        listRequest = ApiClient.getService().listarAlumnos(authorization, moduleId, selectedDate.format(API_DATE));
        listRequest.enqueue(new Callback<>() {
            @Override public void onResponse(@NonNull Call<AttendanceListResponse> call,
                    @NonNull Response<AttendanceListResponse> response) {
                if (binding == null) return;
                if (response.code() == 401) { sessionManager.clear(); openLogin(); return; }
                if (!response.isSuccessful() || response.body() == null || response.body().getData() == null) {
                    showError(ApiErrorParser.message(response, getString(R.string.unexpected_error))); return;
                }
                renderStudents(response.body().getData());
            }
            @Override public void onFailure(@NonNull Call<AttendanceListResponse> call, @NonNull Throwable error) {
                if (binding == null || call.isCanceled()) return; showError(getString(R.string.connection_error));
            }
        });
    }

    private void renderStudents(AttendanceData data) {
        List<StudentAttendance> students = data.getAlumnos() == null ? Collections.emptyList() : data.getAlumnos();
        binding.listContainer.removeAllViews();
        binding.statusText.setVisibility(View.GONE);
        if (students.isEmpty()) { showEmpty(); return; }
        binding.stateContainer.setVisibility(View.GONE); binding.contentScroll.setVisibility(View.VISIBLE);
        for (StudentAttendance student : students) {
            ItemStudentAttendanceBinding item = ItemStudentAttendanceBinding.inflate(getLayoutInflater(), binding.listContainer, false);
            item.nameText.setText((value(student.getApellido()) + ", " + value(student.getNombre())).replaceFirst("^, ", ""));
            String details = getString(R.string.dni_value, value(student.getDni()));
            if (student.isMinor()) details += " · " + getString(R.string.minor_student);
            item.detailText.setText(details);
            renderStatus(item, student.getAsistencia());
            item.statusGroup.addOnButtonCheckedListener((group, checkedId, isChecked) -> {
                if (!isChecked || saveRequest != null) return;
                String status = checkedId == R.id.presentButton ? "presente"
                        : checkedId == R.id.absentButton ? "ausente" : "lluvia";
                if (status.equals(student.getAsistencia())) return;
                saveAttendance(student, status);
            });
            binding.listContainer.addView(item.getRoot());
        }
    }

    private void renderStatus(ItemStudentAttendanceBinding item, String status) {
        int checked = View.NO_ID;
        int label = R.string.attendance_pending;
        if ("presente".equals(status)) { checked = R.id.presentButton; label = R.string.attendance_present; }
        else if ("ausente".equals(status)) { checked = R.id.absentButton; label = R.string.attendance_absent; }
        else if ("lluvia".equals(status)) { checked = R.id.rainButton; label = R.string.attendance_rain; }
        item.currentStatusText.setText(label);
        if (checked != View.NO_ID) item.statusGroup.check(checked);
    }

    private void saveAttendance(StudentAttendance student, String status) {
        String authorization = sessionManager.authorizationHeader();
        if (authorization == null) { openLogin(); return; }
        setSaving(true);
        saveRequest = ApiClient.getService().registrarAsistencia(authorization,
                new AttendanceRequest(moduleId, student.getEnrollmentId(), selectedDate.format(API_DATE), status));
        saveRequest.enqueue(new Callback<>() {
            @Override public void onResponse(@NonNull Call<AttendanceUpdateResponse> call,
                    @NonNull Response<AttendanceUpdateResponse> response) {
                if (binding == null) return;
                saveRequest = null; setSaving(false);
                if (response.code() == 401) { sessionManager.clear(); openLogin(); return; }
                if (response.isSuccessful() && response.body() != null && response.body().getData() != null) {
                    Toast.makeText(AttendanceActivity.this, R.string.attendance_saved, Toast.LENGTH_SHORT).show();
                    loadStudents(); return;
                }
                Toast.makeText(AttendanceActivity.this,
                        ApiErrorParser.message(response, getString(R.string.unexpected_error)), Toast.LENGTH_LONG).show();
                loadStudents();
            }
            @Override public void onFailure(@NonNull Call<AttendanceUpdateResponse> call, @NonNull Throwable error) {
                if (binding == null || call.isCanceled()) return;
                saveRequest = null; setSaving(false);
                Toast.makeText(AttendanceActivity.this, R.string.attendance_uncertain, Toast.LENGTH_LONG).show();
                loadStudents();
            }
        });
    }

    private void setSaving(boolean saving) {
        binding.savingContainer.setVisibility(saving ? View.VISIBLE : View.GONE);
        binding.dateButton.setEnabled(!saving); binding.backButton.setEnabled(!saving);
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
        binding.progressIndicator.setVisibility(View.GONE); binding.stateText.setText(R.string.no_students);
        binding.retryButton.setVisibility(View.GONE);
    }
    private String value(String value) { return value == null ? "" : value; }
    private void openLogin() {
        Intent intent = new Intent(this, LoginActivity.class); intent.putExtra("session_expired", true);
        intent.addFlags(Intent.FLAG_ACTIVITY_CLEAR_TASK | Intent.FLAG_ACTIVITY_NEW_TASK);
        startActivity(intent); finish();
    }
    @Override protected void onDestroy() {
        if (listRequest != null) listRequest.cancel();
        // A confirmed write may already have reached the server, so its callback is allowed to complete.
        binding = null; super.onDestroy();
    }
}
