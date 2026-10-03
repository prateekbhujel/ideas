package dev.hari.body;
public final class PerceptionGate{
    private final long semanticDebounceMs,pixelCooldownMs;private long lastSemanticAt=Long.MIN_VALUE/4,lastPixelAt=Long.MIN_VALUE/4;private String lastFingerprint="";
    public PerceptionGate(long semanticDebounceMs,long pixelCooldownMs){if(semanticDebounceMs<0||pixelCooldownMs<0)throw new IllegalArgumentException();this.semanticDebounceMs=semanticDebounceMs;this.pixelCooldownMs=pixelCooldownMs;}
    public synchronized boolean shouldInspectSemantic(long now,String fingerprint,boolean highValue){String fp=fingerprint==null?"":fingerprint;boolean changed=!fp.equals(lastFingerprint);boolean elapsed=now-lastSemanticAt>=semanticDebounceMs;if(highValue||changed||elapsed){lastSemanticAt=now;lastFingerprint=fp;return true;}return false;}
    public synchronized boolean shouldCapturePixels(long now,boolean explicitRequest,boolean semanticAvailable){if(!explicitRequest&&semanticAvailable)return false;if(now-lastPixelAt<pixelCooldownMs)return false;lastPixelAt=now;return true;}
}
