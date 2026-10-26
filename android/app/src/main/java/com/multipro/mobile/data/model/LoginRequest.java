package com.multipro.mobile.data.model;

import com.google.gson.annotations.SerializedName;

public final class LoginRequest {
    private final String email;

    @SerializedName("contrasena")
    private final String password;

    public LoginRequest(String email, String password) {
        this.email = email;
        this.password = password;
    }
}
