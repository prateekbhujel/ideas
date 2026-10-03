package dev.hari.body;
import android.app.*;import android.content.*;import android.graphics.*;import android.hardware.display.*;import android.media.*;import android.media.projection.*;import android.os.IBinder;import android.util.DisplayMetrics;import java.io.*;import java.nio.ByteBuffer;
public final class ScreenProjectionService extends Service{
    public static final String ACTION_START="dev.hari.body.START_PROJECTION",ACTION_CAPTURE_ONCE="dev.hari.body.CAPTURE_ONCE",EXTRA_RESULT_CODE="resultCode",EXTRA_RESULT_DATA="resultData";
    private static final String CHANNEL="hari-screen";private static final int NOTIFICATION_ID=41;private MediaProjection projection;
    @Override public void onCreate(){super.onCreate();getSystemService(NotificationManager.class).createNotificationChannel(new NotificationChannel(CHANNEL,"HARI screen observation",NotificationManager.IMPORTANCE_LOW));}
    @Override public int onStartCommand(Intent intent,int flags,int startId){
        if(intent==null)return START_NOT_STICKY;String action=intent.getAction();
        if(ACTION_START.equals(action)){startForeground(NOTIFICATION_ID,notification("Screen observation ready"));int code=intent.getIntExtra(EXTRA_RESULT_CODE,0);Intent data=intent.getParcelableExtra(EXTRA_RESULT_DATA,Intent.class);if(data==null){stopSelf();return START_NOT_STICKY;}MediaProjectionManager m=(MediaProjectionManager)getSystemService(Context.MEDIA_PROJECTION_SERVICE);projection=m.getMediaProjection(code,data);if(projection==null){ObservationBus.publish(Observation.environment(Observation.Kind.ERROR,"screen","projection unavailable"));stopSelf();return START_NOT_STICKY;}projection.registerCallback(new MediaProjection.Callback(){@Override public void onStop(){ObservationBus.publish(Observation.environment(Observation.Kind.DEVICE_STATE,"screen","projection stopped"));projection=null;}},null);ObservationBus.publish(Observation.environment(Observation.Kind.DEVICE_STATE,"screen","projection ready"));}
        else if(ACTION_CAPTURE_ONCE.equals(action))captureOnce();
        return START_NOT_STICKY;
    }
    private void captureOnce(){
        if(projection==null){ObservationBus.publish(Observation.environment(Observation.Kind.ERROR,"screen","pixel capture requires active user-approved projection"));return;}
        DisplayMetrics dm=getResources().getDisplayMetrics();int w=dm.widthPixels,h=dm.heightPixels,d=dm.densityDpi;ImageReader r=ImageReader.newInstance(w,h,PixelFormat.RGBA_8888,2);VirtualDisplay vd=projection.createVirtualDisplay("hari-one-frame",w,h,d,DisplayManager.VIRTUAL_DISPLAY_FLAG_AUTO_MIRROR,r.getSurface(),null,null);
        r.setOnImageAvailableListener(reader->{Image image=null;try{image=reader.acquireLatestImage();if(image==null)return;Image.Plane p=image.getPlanes()[0];ByteBuffer buf=p.getBuffer();int pixel=p.getPixelStride(),row=p.getRowStride(),pad=row-pixel*w;Bitmap padded=Bitmap.createBitmap(w+pad/pixel,h,Bitmap.Config.ARGB_8888);padded.copyPixelsFromBuffer(buf);Bitmap cropped=Bitmap.createBitmap(padded,0,0,w,h);File file=new File(getCacheDir(),"hari-screen.png");try(FileOutputStream out=new FileOutputStream(file)){cropped.compress(Bitmap.CompressFormat.PNG,100,out);}padded.recycle();cropped.recycle();ObservationBus.publish(Observation.environment(Observation.Kind.SCREENSHOT,"mediaProjection",file.getAbsolutePath()));}catch(Exception e){ObservationBus.publish(Observation.environment(Observation.Kind.ERROR,"mediaProjection",e.getClass().getSimpleName()+": "+e.getMessage()));}finally{if(image!=null)image.close();reader.close();vd.release();}},null);
    }
    private Notification notification(String text){return new Notification.Builder(this,CHANNEL).setContentTitle("HARI").setContentText(text).setSmallIcon(android.R.drawable.ic_menu_view).build();}
    @Override public void onDestroy(){if(projection!=null)projection.stop();projection=null;super.onDestroy();}
    @Override public IBinder onBind(Intent intent){return null;}
}
