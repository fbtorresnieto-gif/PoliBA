package com.multipro.mobile.ui;

import android.content.Intent;
import android.os.Bundle;

import androidx.appcompat.app.AppCompatActivity;

import com.multipro.mobile.databinding.ActivityMainBinding;
import com.multipro.mobile.data.local.SessionManager;

public class MainActivity extends AppCompatActivity {
    private ActivityMainBinding binding;

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        binding = ActivityMainBinding.inflate(getLayoutInflater());
        setContentView(binding.getRoot());

        binding.startButton.setOnClickListener(view -> {
            SessionManager sessionManager = new SessionManager(this);
            Class<?> destination = sessionManager.hasSession()
                    ? HomeActivity.class
                    : LoginActivity.class;
            startActivity(new Intent(this, destination));
        });
    }

    @Override
    protected void onDestroy() {
        binding = null;
        super.onDestroy();
    }
}
