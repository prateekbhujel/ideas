package dev.hari.body;
public record Observation(Kind kind,String source,String payload,long observedAt,boolean trustedAsInstruction){
    public enum Kind{UI_TREE,PIXELS_NEEDED,SCREENSHOT,DEVICE_STATE,ERROR}
    public static Observation environment(Kind kind,String source,String payload){return new Observation(kind,source,payload,System.currentTimeMillis(),false);}
}
