package com.multipro.mobile.ui;

import android.widget.ImageView;

import com.bumptech.glide.Glide;
import com.multipro.mobile.R;

final class ImageLoader {
    private ImageLoader() {}

    static void load(ImageView view, String url) {
        Glide.with(view)
                .load(url == null || url.isBlank() ? null : url)
                .placeholder(R.drawable.card_image_placeholder)
                .error(R.drawable.card_image_placeholder)
                .centerCrop()
                .into(view);
    }
}
