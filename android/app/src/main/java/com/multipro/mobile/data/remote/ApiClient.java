package com.multipro.mobile.data.remote;

import com.multipro.mobile.BuildConfig;

import java.util.concurrent.TimeUnit;

import okhttp3.OkHttpClient;
import okhttp3.logging.HttpLoggingInterceptor;
import retrofit2.Retrofit;
import retrofit2.converter.gson.GsonConverterFactory;

public final class ApiClient {
    private static final int CONNECT_TIMEOUT_SECONDS = 10;
    private static final int READ_TIMEOUT_SECONDS = 15;
    private static final int WRITE_TIMEOUT_SECONDS = 15;

    private static volatile ApiService service;

    private ApiClient() {
    }

    public static ApiService getService() {
        if (service == null) {
            synchronized (ApiClient.class) {
                if (service == null) {
                    HttpLoggingInterceptor logging = new HttpLoggingInterceptor();
                    logging.setLevel(BuildConfig.DEBUG
                            ? HttpLoggingInterceptor.Level.BASIC
                            : HttpLoggingInterceptor.Level.NONE);

                    OkHttpClient client = new OkHttpClient.Builder()
                            .connectTimeout(CONNECT_TIMEOUT_SECONDS, TimeUnit.SECONDS)
                            .readTimeout(READ_TIMEOUT_SECONDS, TimeUnit.SECONDS)
                            .writeTimeout(WRITE_TIMEOUT_SECONDS, TimeUnit.SECONDS)
                            .followRedirects(!"local".equals(BuildConfig.BUILD_TYPE))
                            .followSslRedirects(!"local".equals(BuildConfig.BUILD_TYPE))
                            .addInterceptor(logging)
                            .addInterceptor(chain -> {
                                if ("local".equals(BuildConfig.BUILD_TYPE) &&
                                        (!"127.0.0.1".equals(chain.request().url().host()) || chain.request().url().port() != 8080)) {
                                    throw new java.io.IOException("La variante local solo permite la API local.");
                                }
                                return chain.proceed(chain.request());
                            })
                            .build();

                    service = new Retrofit.Builder()
                            .baseUrl(BuildConfig.API_BASE_URL)
                            .client(client)
                            .addConverterFactory(GsonConverterFactory.create())
                            .build()
                            .create(ApiService.class);
                }
            }
        }
        return service;
    }
}
