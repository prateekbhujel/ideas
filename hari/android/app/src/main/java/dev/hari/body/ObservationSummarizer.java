package dev.hari.body;

import java.util.LinkedHashSet;
import java.util.Set;

public final class ObservationSummarizer {
    private ObservationSummarizer() {}

    public static String summarize(Observation observation) {
        if (observation == null) {
            return "I don't have a screen observation yet. Enable semantic screen access first.";
        }

        if (observation.kind() != Observation.Kind.UI_TREE) {
            return switch (observation.kind()) {
                case SCREENSHOT -> "I captured one screen frame, but this MVP does not have a vision model attached yet.";
                case PIXELS_NEEDED -> "The app did not expose enough semantic screen information. A screenshot may be needed.";
                case DEVICE_STATE -> "Latest device observation: " + observation.payload();
                case ERROR -> "Screen observation failed: " + observation.payload();
                default -> "I have an observation, but I cannot summarize it safely.";
            };
        }

        Set<String> visible = new LinkedHashSet<>();
        String[] lines = observation.payload().split("\\R");
        for (String line : lines) {
            addField(visible, line, "text=");
            addField(visible, line, "desc=");
            if (visible.size() >= 8) break;
        }

        if (visible.isEmpty()) {
            return "I can read the screen structure, but I don't see useful visible text right now.";
        }

        return "I can see: " + String.join(" • ", visible);
    }

    private static void addField(Set<String> out, String line, String prefix) {
        int start = line.indexOf(prefix);
        if (start < 0 || out.size() >= 8) return;
        start += prefix.length();

        int end = line.length();
        String[] boundaries = {" text=", " desc=", " clickable=", " focused=", " password=", " bounds=", " class="};
        for (String boundary : boundaries) {
            int at = line.indexOf(boundary, start);
            if (at >= 0 && at < end) end = at;
        }

        String value = line.substring(start, end).trim();
        if (!value.isEmpty() && value.length() <= 160) out.add(value);
    }
}
