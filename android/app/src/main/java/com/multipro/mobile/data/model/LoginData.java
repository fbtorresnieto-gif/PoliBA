package com.multipro.mobile.data.model;

import com.google.gson.annotations.SerializedName;

public final class LoginData {
    private String token;

    @SerializedName("tipo_token")
    private String tokenType;

    @SerializedName("vence_en")
    private String expiresAt;

    private User usuario;

    public String getToken() { return token; }
    public String getTokenType() { return tokenType; }
    public String getExpiresAt() { return expiresAt; }
    public User getUsuario() { return usuario; }
}
