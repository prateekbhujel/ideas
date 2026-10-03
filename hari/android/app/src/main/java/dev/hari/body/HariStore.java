package dev.hari.body;

import android.content.Context;
import android.content.SharedPreferences;

import java.util.Locale;

public final class HariStore {
    private static final String PREFS = "hari-life";
    private static final String ALIAS = "alias:";
    private static final String FACT = "fact:";
    private static final String BODY = "body";

    private final SharedPreferences prefs;

    public HariStore(Context context) {
        prefs = context.getSharedPreferences(PREFS, Context.MODE_PRIVATE);
    }

    public void teachAlias(String phrase, String command) {
        prefs.edit().putString(ALIAS + normalize(phrase), command.trim()).apply();
    }

    public String resolveAlias(String phrase) {
        return prefs.getString(ALIAS + normalize(phrase), null);
    }

    public void remember(String key, String value) {
        prefs.edit().putString(FACT + normalize(key), value.trim()).apply();
    }

    public String recall(String key) {
        return prefs.getString(FACT + normalize(key), null);
    }

    public void setBody(String body) {
        prefs.edit().putString(BODY, normalize(body)).apply();
    }

    public String body() {
        return prefs.getString(BODY, "phone");
    }

    public static String normalize(String text) {
        if (text == null) return "";
        return text.trim().toLowerCase(Locale.ROOT).replaceAll("\\s+", " ");
    }
}
