package dev.hari.body;
import android.accessibilityservice.AccessibilityService;import android.content.Intent;import android.graphics.Rect;import android.view.accessibility.AccessibilityEvent;import android.view.accessibility.AccessibilityNodeInfo;import java.util.ArrayDeque;import java.util.Deque;
public final class HariAccessibilityService extends AccessibilityService{
    private final PerceptionGate gate=new PerceptionGate(250,1500);
    @Override public void onAccessibilityEvent(AccessibilityEvent event){
        if(event==null)return;
        boolean high=event.getEventType()==AccessibilityEvent.TYPE_VIEW_CLICKED||event.getEventType()==AccessibilityEvent.TYPE_WINDOW_STATE_CHANGED;
        String fp=String.valueOf(event.getPackageName())+":"+event.getEventType()+":"+String.valueOf(event.getClassName());
        if(!gate.shouldInspectSemantic(System.currentTimeMillis(),fp,high))return;
        AccessibilityNodeInfo root=getRootInActiveWindow();
        if(root==null){ObservationBus.publish(Observation.environment(Observation.Kind.PIXELS_NEEDED,"accessibility","semantic tree unavailable"));requestPixels(false);return;}
        ObservationBus.publish(Observation.environment(Observation.Kind.UI_TREE,String.valueOf(event.getPackageName()),snapshot(root,400)));
    }
    private void requestPixels(boolean explicit){if(!gate.shouldCapturePixels(System.currentTimeMillis(),explicit,false))return;startService(new Intent(this,ScreenProjectionService.class).setAction(ScreenProjectionService.ACTION_CAPTURE_ONCE));}
    private static String snapshot(AccessibilityNodeInfo root,int maxNodes){StringBuilder out=new StringBuilder(4096);Deque<AccessibilityNodeInfo> q=new ArrayDeque<>();q.add(root);int count=0;while(!q.isEmpty()&&count++<maxNodes){AccessibilityNodeInfo n=q.removeFirst();Rect b=new Rect();n.getBoundsInScreen(b);append(out,"class",n.getClassName());append(out,"text",n.getText());append(out,"desc",n.getContentDescription());out.append(" clickable=").append(n.isClickable()).append(" focused=").append(n.isFocused()).append(" bounds=").append(b.flattenToString()).append('\n');for(int i=0;i<n.getChildCount();i++){AccessibilityNodeInfo c=n.getChild(i);if(c!=null)q.addLast(c);}}return out.toString();}
    private static void append(StringBuilder out,String key,CharSequence value){if(value!=null&&value.length()>0)out.append(key).append('=').append(value).append(' ');}
    @Override public void onInterrupt(){ObservationBus.publish(Observation.environment(Observation.Kind.DEVICE_STATE,"accessibility","interrupted"));}
}
