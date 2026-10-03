package dev.hari.body;
import java.util.concurrent.atomic.AtomicReference;
public final class ObservationBus{
    private static final AtomicReference<Observation> LAST=new AtomicReference<>();
    private ObservationBus(){}
    public static void publish(Observation x){LAST.set(x);}
    public static Observation latest(){return LAST.get();}
}
