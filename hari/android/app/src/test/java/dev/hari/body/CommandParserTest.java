package dev.hari.body;

import static org.junit.Assert.*;
import org.junit.Test;

public class CommandParserTest {
    @Test public void parsesUsefulPhoneCommands() {
        assertEquals(CommandParser.Type.OPEN_APP, CommandParser.parse("open WhatsApp").type());
        assertEquals("Mom", CommandParser.parse("call Mom").first());
        assertEquals(CommandParser.Type.TAP_VISIBLE, CommandParser.parse("tap Send").type());
        assertEquals(CommandParser.Type.SEE_SCREEN, CommandParser.parse("what do you see").type());
    }

    @Test public void learnsArbitraryNativePhraseWithoutUnderstandingIt() {
        CommandParser.Parsed p = CommandParser.parse("teach: आमालाई देखिने फोन => call Mom");
        assertEquals(CommandParser.Type.TEACH, p.type());
        assertEquals("आमालाई देखिने फोन", p.first());
        assertEquals("call Mom", p.second());
    }

    @Test public void parsesNaturalEnglishTeachingForm() {
        CommandParser.Parsed p = CommandParser.parse("when I say hello there, open WhatsApp");
        assertEquals(CommandParser.Type.TEACH, p.type());
        assertEquals("hello there", p.first());
        assertEquals("open WhatsApp", p.second());
    }

    @Test public void bodySwitchIsExplicit() {
        assertEquals(CommandParser.Type.BODY_COMPUTER, CommandParser.parse("go to my computer").type());
        assertEquals(CommandParser.Type.BODY_PHONE, CommandParser.parse("use my phone").type());
    }
}
