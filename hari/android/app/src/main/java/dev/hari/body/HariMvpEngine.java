package dev.hari.body;

import android.content.Context;

public final class HariMvpEngine {
    public record Reply(String text, String permissionNeeded) {}

    private final Context context;
    private final HariStore store;

    public HariMvpEngine(Context context, HariStore store) {
        this.context = context.getApplicationContext();
        this.store = store;
    }

    public Reply handle(String input) {
        return handle(input, 0);
    }

    private Reply handle(String input, int depth) {
        String normalized = HariStore.normalize(input);
        if (normalized.isEmpty()) return new Reply("Say or type something.", null);
        if (depth > 3) return new Reply("That learned phrase points in a loop, so I stopped.", null);

        String learned = store.resolveAlias(input);
        if (learned != null && !HariStore.normalize(learned).equals(normalized)) {
            return handle(learned, depth + 1);
        }

        CommandParser.Parsed parsed = CommandParser.parse(input);
        return switch (parsed.type()) {
            case TEACH -> {
                if (parsed.first().isBlank() || parsed.second().isBlank()) {
                    yield new Reply("I need both the phrase and what it should mean.", null);
                }
                store.teachAlias(parsed.first(), parsed.second());
                yield new Reply("Got it. When you say “" + parsed.first() + "”, I'll treat it as “" + parsed.second() + "”.", null);
            }
            case REMEMBER -> {
                store.remember(parsed.first(), parsed.second());
                yield new Reply("I'll remember that " + parsed.first() + " is " + parsed.second() + ".", null);
            }
            case RECALL -> {
                String value = store.recall(parsed.first());
                yield new Reply(value == null
                        ? "I don't remember anything reliable about " + parsed.first() + " yet."
                        : parsed.first() + " is " + value + ".", null);
            }
            case BODY_COMPUTER -> {
                store.setBody("computer");
                yield new Reply("I don't have a connected computer body yet, so I won't pretend I'm controlling it. This phone is the only live body in the MVP.", null);
            }
            case BODY_PHONE -> {
                store.setBody("phone");
                yield new Reply("Okay. I'm using this phone.", null);
            }
            case SEE_SCREEN -> new Reply(ObservationSummarizer.summarize(ObservationBus.latest()), null);
            case OPEN_APP -> from(AndroidActions.openApp(context, parsed.first()));
            case CALL_CONTACT -> from(AndroidActions.dialContact(context, parsed.first()));
            case TAP_VISIBLE -> from(AndroidActions.tapVisible(parsed.first()));
            case UNKNOWN -> new Reply(
                    "I don't know what “" + parsed.first() + "” means yet. Teach me with: teach: your phrase => open WhatsApp",
                    null
            );
        };
    }

    private static Reply from(AndroidActions.Result result) {
        return new Reply(result.message(), result.permissionNeeded());
    }
}
