package com.multipro.mobile.data.remote;

import com.google.gson.Gson;
import com.multipro.mobile.data.model.ErrorResponse;

import java.io.IOException;

import retrofit2.Response;

public final class ApiErrorParser {
    private ApiErrorParser() {}

    public static String message(Response<?> response, String fallback) {
        if (response.errorBody() == null) {
            return fallback;
        }

        try {
            ErrorResponse error = new Gson().fromJson(response.errorBody().string(), ErrorResponse.class);
            if (error != null && error.getError() != null && !error.getError().isBlank()) {
                return error.getError();
            }
        } catch (IOException | RuntimeException ignored) {
            // La interfaz muestra un mensaje seguro si el servidor no devuelve el JSON esperado.
        }
        return fallback;
    }
}
