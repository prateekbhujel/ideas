package dev.hari.body;import static org.junit.Assert.*;import org.junit.Test;
public class PerceptionGateTest{
 @Test public void semanticEventsAreDebouncedButImportantChangesPass(){PerceptionGate g=new PerceptionGate(250,1500);assertTrue(g.shouldInspectSemantic(1000,"a",false));assertFalse(g.shouldInspectSemantic(1050,"a",false));assertTrue(g.shouldInspectSemantic(1060,"b",false));assertTrue(g.shouldInspectSemantic(1070,"b",true));}
 @Test public void pixelsAreFallbackNotDefault(){PerceptionGate g=new PerceptionGate(250,1500);assertFalse(g.shouldCapturePixels(2000,false,true));assertTrue(g.shouldCapturePixels(2000,false,false));assertFalse(g.shouldCapturePixels(2200,true,false));assertTrue(g.shouldCapturePixels(4000,true,true));}
}