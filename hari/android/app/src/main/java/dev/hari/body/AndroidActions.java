package dev.hari.body;

import android.Manifest;
import android.content.Context;
import android.content.Intent;
import android.content.pm.PackageManager;
import android.content.pm.ResolveInfo;
import android.database.Cursor;
import android.net.Uri;
import android.provider.ContactsContract;

import java.util.ArrayList;
import java.util.List;
import java.util.Locale;

public final class AndroidActions {
    public record Result(boolean ok, String message, String permissionNeeded) {
        public static Result ok(String message) { return new Result(true, message, null); }
        public static Result fail(String message) { return new Result(false, message, null); }
        public static Result permission(String permission, String message) {
            return new Result(false, message, permission);
        }
    }

    private AndroidActions() {}

    public static Result openApp(Context context, String requested) {
        PackageManager pm = context.getPackageManager();
        Intent query = new Intent(Intent.ACTION_MAIN).addCategory(Intent.CATEGORY_LAUNCHER);
        List<ResolveInfo> apps = pm.queryIntentActivities(query, 0);

        String needle = norm(requested);
        ResolveInfo exact = null;
        List<ResolveInfo> partial = new ArrayList<>();

        for (ResolveInfo info : apps) {
            String label = String.valueOf(info.loadLabel(pm));
            String pkg = info.activityInfo.packageName;
            if (norm(label).equals(needle) || norm(pkg).equals(needle)) {
                exact = info;
                break;
            }
            if (norm(label).contains(needle)) partial.add(info);
        }

        ResolveInfo chosen = exact;
        if (chosen == null && partial.size() == 1) chosen = partial.get(0);
        if (chosen == null && partial.size() > 1) {
            return Result.fail("I found more than one app matching “" + requested + "”. Say the full app name.");
        }
        if (chosen == null) return Result.fail("I can't find an installed app named “" + requested + "”.");

        String pkg = chosen.activityInfo.packageName;
        Intent launch = pm.getLaunchIntentForPackage(pkg);
        if (launch == null) return Result.fail("Android knows the app, but it does not expose a launch activity.");
        launch.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK);
        context.startActivity(launch);
        return Result.ok("Opened " + String.valueOf(chosen.loadLabel(pm)) + ".");
    }

    public static Result dialContact(Context context, String requested) {
        if (context.checkSelfPermission(Manifest.permission.READ_CONTACTS) != PackageManager.PERMISSION_GRANTED) {
            return Result.permission(Manifest.permission.READ_CONTACTS,
                    "I need contact access to resolve “" + requested + "”.");
        }

        String[] projection = {
                ContactsContract.CommonDataKinds.Phone.DISPLAY_NAME,
                ContactsContract.CommonDataKinds.Phone.NUMBER
        };

        String bestName = null;
        String bestNumber = null;
        try (Cursor cursor = context.getContentResolver().query(
                ContactsContract.CommonDataKinds.Phone.CONTENT_URI,
                projection,
                ContactsContract.CommonDataKinds.Phone.DISPLAY_NAME + " LIKE ?",
                new String[]{requested + "%"},
                ContactsContract.CommonDataKinds.Phone.DISPLAY_NAME + " ASC"
        )) {
            if (cursor != null) {
                while (cursor.moveToNext()) {
                    String name = cursor.getString(0);
                    String number = cursor.getString(1);
                    if (name == null || number == null) continue;
                    if (norm(name).equals(norm(requested))) {
                        bestName = name;
                        bestNumber = number;
                        break;
                    }
                    if (bestNumber == null) {
                        bestName = name;
                        bestNumber = number;
                    }
                }
            }
        }

        if (bestNumber == null) return Result.fail("I couldn't find a contact matching “" + requested + "”.");

        Intent dial = new Intent(Intent.ACTION_DIAL, Uri.fromParts("tel", bestNumber, null));
        dial.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK);
        context.startActivity(dial);
        return Result.ok("Opened the dialer for " + bestName + ". You still choose whether to place the call.");
    }

    public static Result tapVisible(String label) {
        HariAccessibilityService.ClickResult result = HariAccessibilityService.clickExactVisible(label);
        if (result == HariAccessibilityService.ClickResult.SERVICE_OFF) {
            return Result.fail("Screen control is off. Enable HARI's accessibility service first.");
        }
        if (result == HariAccessibilityService.ClickResult.NOT_FOUND) {
            return Result.fail("I can't find an exact visible control labeled “" + label + "”.");
        }
        if (result == HariAccessibilityService.ClickResult.BLOCKED) {
            return Result.fail("I found that element, but I won't interact with a protected/password field.");
        }
        if (result == HariAccessibilityService.ClickResult.NOT_CLICKABLE) {
            return Result.fail("I found “" + label + "”, but Android does not expose it as clickable.");
        }
        return Result.ok("Pressed “" + label + "”.");
    }

    private static String norm(String text) {
        return text == null ? "" : text.trim().toLowerCase(Locale.ROOT).replaceAll("\\s+", " ");
    }
}
