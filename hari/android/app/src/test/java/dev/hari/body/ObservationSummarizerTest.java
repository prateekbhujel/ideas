package dev.hari.body;

import static org.junit.Assert.*;
import org.junit.Test;

public class ObservationSummarizerTest {
    @Test public void extractsHumanVisibleTextFromSemanticTree() {
        Observation o = new Observation(
                Observation.Kind.UI_TREE,
                "demo",
                "class=Button text=Send clickable=true focused=false bounds=0 0 10 10\n"
                        + "class=TextView desc=Profile photo clickable=false focused=false bounds=0 0 10 10\n",
                1,
                false
        );
        String s = ObservationSummarizer.summarize(o);
        assertTrue(s.contains("Send"));
        assertTrue(s.contains("Profile photo"));
    }

    @Test public void neverPretendsScreenshotWasUnderstood() {
        Observation o = new Observation(Observation.Kind.SCREENSHOT, "screen", "/tmp/x.png", 1, false);
        assertTrue(ObservationSummarizer.summarize(o).contains("does not have a vision model"));
    }
}
