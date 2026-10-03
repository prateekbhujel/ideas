package dev.hari.body;

import java.util.Locale;
import java.util.regex.Matcher;
import java.util.regex.Pattern;

public final class CommandParser {
    public enum Type {
        OPEN_APP,
        CALL_CONTACT,
        TAP_VISIBLE,
        SEE_SCREEN,
        TEACH,
        REMEMBER,
        RECALL,
        BODY_PHONE,
        BODY_COMPUTER,
        UNKNOWN
    }

    public record Parsed(Type type, String first, String second) {}

    private static final Pattern TEACH =
            Pattern.compile("^teach\\s*:\\s*(.+?)\\s*=>\\s*(.+)$", Pattern.CASE_INSENSITIVE | Pattern.UNICODE_CASE);
    private static final Pattern WHEN =
            Pattern.compile("^when\\s+i\\s+say\\s+(.+?)(?:,|\\s+then\\s+)(.+)$", Pattern.CASE_INSENSITIVE | Pattern.UNICODE_CASE);
    private static final Pattern REMEMBER =
            Pattern.compile("^remember\\s+(.+?)\\s+is\\s+(.+)$", Pattern.CASE_INSENSITIVE | Pattern.UNICODE_CASE);
    private static final Pattern RECALL =
            Pattern.compile("^(?:what\\s+is|what\\s+do\\s+you\\s+remember\\s+about)\\s+(.+?)[?]?$", Pattern.CASE_INSENSITIVE | Pattern.UNICODE_CASE);
    private static final Pattern OPEN =
            Pattern.compile("^open\\s+(.+)$", Pattern.CASE_INSENSITIVE | Pattern.UNICODE_CASE);
    private static final Pattern CALL =
            Pattern.compile("^(?:call|dial)\\s+(.+)$", Pattern.CASE_INSENSITIVE | Pattern.UNICODE_CASE);
    private static final Pattern TAP =
            Pattern.compile("^(?:tap|press|click)\\s+(.+)$", Pattern.CASE_INSENSITIVE | Pattern.UNICODE_CASE);

    private CommandParser() {}

    public static Parsed parse(String raw) {
        String input = raw == null ? "" : raw.trim();
        if (input.isEmpty()) return new Parsed(Type.UNKNOWN, "", "");

        Matcher m = TEACH.matcher(input);
        if (m.matches()) return new Parsed(Type.TEACH, stripQuotes(m.group(1)), m.group(2).trim());

        m = WHEN.matcher(input);
        if (m.matches()) return new Parsed(Type.TEACH, stripQuotes(m.group(1)), m.group(2).trim());

        m = REMEMBER.matcher(input);
        if (m.matches()) return new Parsed(Type.REMEMBER, m.group(1).trim(), m.group(2).trim());

        m = RECALL.matcher(input);
        if (m.matches()) return new Parsed(Type.RECALL, m.group(1).trim(), "");

        String n = input.toLowerCase(Locale.ROOT);
        if (n.equals("what do you see") || n.equals("what can you see")
                || n.equals("what is on my screen") || n.equals("what's on my screen")) {
            return new Parsed(Type.SEE_SCREEN, "", "");
        }
        if (n.contains("on my computer") || n.equals("use my computer") || n.equals("go to my computer")) {
            return new Parsed(Type.BODY_COMPUTER, "", "");
        }
        if (n.contains("on my phone") || n.equals("use my phone") || n.equals("come back to my phone")) {
            return new Parsed(Type.BODY_PHONE, "", "");
        }

        m = OPEN.matcher(input);
        if (m.matches()) return new Parsed(Type.OPEN_APP, m.group(1).trim(), "");

        m = CALL.matcher(input);
        if (m.matches()) return new Parsed(Type.CALL_CONTACT, m.group(1).trim(), "");

        m = TAP.matcher(input);
        if (m.matches()) return new Parsed(Type.TAP_VISIBLE, m.group(1).trim(), "");

        return new Parsed(Type.UNKNOWN, input, "");
    }

    private static String stripQuotes(String x) {
        String s = x.trim();
        if (s.length() >= 2 && ((s.startsWith(""") && s.endsWith("""))
                || (s.startsWith("'") && s.endsWith("'")))) {
            return s.substring(1, s.length() - 1).trim();
        }
        return s;
    }
}
