package dev.hari.body;

import android.accessibilityservice.AccessibilityService;
import android.content.Intent;
import android.graphics.Rect;
import android.view.accessibility.AccessibilityEvent;
import android.view.accessibility.AccessibilityNodeInfo;

import java.util.ArrayDeque;
import java.util.Deque;
import java.util.Locale;

public final class HariAccessibilityService extends AccessibilityService {
    public enum ClickResult { CLICKED, SERVICE_OFF, NOT_FOUND, BLOCKED, NOT_CLICKABLE }

    private static volatile HariAccessibilityService active;
    private final PerceptionGate gate = new PerceptionGate(250, 1500);

    @Override
    protected void onServiceConnected() {
        super.onServiceConnected();
        active = this;
        ObservationBus.publish(Observation.environment(
                Observation.Kind.DEVICE_STATE, "accessibility", "connected"
        ));
    }

    @Override
    public boolean onUnbind(Intent intent) {
        if (active == this) active = null;
        return super.onUnbind(intent);
    }

    @Override
    public void onDestroy() {
        if (active == this) active = null;
        super.onDestroy();
    }

    public static ClickResult clickExactVisible(String label) {
        HariAccessibilityService service = active;
        if (service == null) return ClickResult.SERVICE_OFF;
        return service.clickExact(label);
    }

    private ClickResult clickExact(String label) {
        AccessibilityNodeInfo root = getRootInActiveWindow();
        if (root == null) return ClickResult.NOT_FOUND;

        String wanted = norm(label);
        Deque<AccessibilityNodeInfo> queue = new ArrayDeque<>();
        queue.add(root);

        while (!queue.isEmpty()) {
            AccessibilityNodeInfo node = queue.removeFirst();
            if (matches(node, wanted)) {
                if (node.isPassword()) return ClickResult.BLOCKED;

                AccessibilityNodeInfo candidate = node;
                for (int i = 0; i < 4 && candidate != null; i++) {
                    if (candidate.isClickable()) {
                        return candidate.performAction(AccessibilityNodeInfo.ACTION_CLICK)
                                ? ClickResult.CLICKED : ClickResult.NOT_CLICKABLE;
                    }
                    candidate = candidate.getParent();
                }
                return ClickResult.NOT_CLICKABLE;
            }

            for (int i = 0; i < node.getChildCount(); i++) {
                AccessibilityNodeInfo child = node.getChild(i);
                if (child != null) queue.addLast(child);
            }
        }
        return ClickResult.NOT_FOUND;
    }

    private static boolean matches(AccessibilityNodeInfo node, String wanted) {
        CharSequence text = node.getText();
        CharSequence desc = node.getContentDescription();
        return (text != null && norm(text.toString()).equals(wanted))
                || (desc != null && norm(desc.toString()).equals(wanted));
    }

    private static String norm(String s) {
        return s == null ? "" : s.trim().toLowerCase(Locale.ROOT).replaceAll("\\s+", " ");
    }

    @Override
    public void onAccessibilityEvent(AccessibilityEvent event) {
        if (event == null) return;

        boolean highValue = event.getEventType() == AccessibilityEvent.TYPE_VIEW_CLICKED
                || event.getEventType() == AccessibilityEvent.TYPE_WINDOW_STATE_CHANGED;

        String fingerprint = String.valueOf(event.getPackageName()) + ":"
                + event.getEventType() + ":" + String.valueOf(event.getClassName());

        if (!gate.shouldInspectSemantic(System.currentTimeMillis(), fingerprint, highValue)) return;

        AccessibilityNodeInfo root = getRootInActiveWindow();
        if (root == null) {
            ObservationBus.publish(Observation.environment(
                    Observation.Kind.PIXELS_NEEDED, "accessibility", "semantic tree unavailable"
            ));
            requestPixels(false);
            return;
        }

        ObservationBus.publish(Observation.environment(
                Observation.Kind.UI_TREE,
                String.valueOf(event.getPackageName()),
                snapshot(root, 400)
        ));
    }

    private void requestPixels(boolean explicit) {
        if (!gate.shouldCapturePixels(System.currentTimeMillis(), explicit, false)) return;
        startService(new Intent(this, ScreenProjectionService.class)
                .setAction(ScreenProjectionService.ACTION_CAPTURE_ONCE));
    }

    private static String snapshot(AccessibilityNodeInfo root, int maxNodes) {
        StringBuilder out = new StringBuilder(4096);
        Deque<AccessibilityNodeInfo> queue = new ArrayDeque<>();
        queue.add(root);
        int count = 0;

        while (!queue.isEmpty() && count++ < maxNodes) {
            AccessibilityNodeInfo node = queue.removeFirst();
            Rect bounds = new Rect();
            node.getBoundsInScreen(bounds);

            append(out, "class", node.getClassName());
            append(out, "text", node.getText());
            append(out, "desc", node.getContentDescription());
            out.append(" clickable=").append(node.isClickable());
            out.append(" focused=").append(node.isFocused());
            out.append(" password=").append(node.isPassword());
            out.append(" bounds=").append(bounds.flattenToString()).append('\n');

            for (int i = 0; i < node.getChildCount(); i++) {
                AccessibilityNodeInfo child = node.getChild(i);
                if (child != null) queue.addLast(child);
            }
        }
        return out.toString();
    }

    private static void append(StringBuilder out, String key, CharSequence value) {
        if (value == null || value.length() == 0) return;
        out.append(key).append('=').append(value).append(' ');
    }

    @Override
    public void onInterrupt() {
        ObservationBus.publish(Observation.environment(
                Observation.Kind.DEVICE_STATE, "accessibility", "interrupted"
        ));
    }
}
