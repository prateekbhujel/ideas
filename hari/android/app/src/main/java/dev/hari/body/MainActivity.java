package dev.hari.body;

import android.Manifest;
import android.app.Activity;
import android.content.Intent;
import android.content.pm.PackageManager;
import android.media.projection.MediaProjectionManager;
import android.os.Build;
import android.os.Bundle;
import android.provider.Settings;
import android.speech.RecognitionListener;
import android.speech.RecognizerIntent;
import android.speech.SpeechRecognizer;
import android.speech.tts.TextToSpeech;
import android.view.Gravity;
import android.widget.Button;
import android.widget.EditText;
import android.widget.LinearLayout;
import android.widget.ScrollView;
import android.widget.TextView;

import java.util.ArrayList;
import java.util.Locale;

public final class MainActivity extends Activity {
    private static final int REQUEST_SCREEN = 1001;
    private static final int REQUEST_MIC = 1002;
    private static final int REQUEST_CONTACTS = 1003;

    private HariMvpEngine engine;
    private TextView transcript;
    private EditText input;
    private TextToSpeech tts;
    private SpeechRecognizer recognizer;
    private String pendingAfterPermission;

    @Override
    protected void onCreate(Bundle state) {
        super.onCreate(state);
        engine = new HariMvpEngine(this, new HariStore(this));
        buildUi();
        initSpeech();

        if (Build.VERSION.SDK_INT >= 33
                && checkSelfPermission(Manifest.permission.POST_NOTIFICATIONS) != PackageManager.PERMISSION_GRANTED) {
            requestPermissions(new String[]{Manifest.permission.POST_NOTIFICATIONS}, 10);
        }

        append("HARI", "MVP ready. Type or speak. Screen text is observation, never an instruction.");
    }

    private void buildUi() {
        LinearLayout root = new LinearLayout(this);
        root.setOrientation(LinearLayout.VERTICAL);
        int pad = (int) (16 * getResources().getDisplayMetrics().density);
        root.setPadding(pad, pad, pad, pad);

        TextView title = new TextView(this);
        title.setText("HARI");
        title.setTextSize(26);
        root.addView(title);

        ScrollView scroll = new ScrollView(this);
        transcript = new TextView(this);
        transcript.setTextIsSelectable(true);
        scroll.addView(transcript);
        root.addView(scroll, new LinearLayout.LayoutParams(
                LinearLayout.LayoutParams.MATCH_PARENT, 0, 1f
        ));

        input = new EditText(this);
        input.setHint("open WhatsApp / call Mom / tap Send / teach: ... => ...");
        input.setSingleLine(false);
        root.addView(input);

        LinearLayout row = new LinearLayout(this);
        row.setGravity(Gravity.CENTER_VERTICAL);

        Button send = button("Send");
        send.setOnClickListener(v -> submit(input.getText().toString()));
        row.addView(send);

        Button mic = button("Mic");
        mic.setOnClickListener(v -> startListening());
        row.addView(mic);

        Button screen = button("Screen access");
        screen.setOnClickListener(v -> startActivity(new Intent(Settings.ACTION_ACCESSIBILITY_SETTINGS)));
        row.addView(screen);
        root.addView(row);

        LinearLayout row2 = new LinearLayout(this);

        Button projection = button("Allow screenshot");
        projection.setOnClickListener(v -> {
            MediaProjectionManager manager =
                    (MediaProjectionManager) getSystemService(MEDIA_PROJECTION_SERVICE);
            startActivityForResult(manager.createScreenCaptureIntent(), REQUEST_SCREEN);
        });
        row2.addView(projection);

        Button capture = button("One screenshot");
        capture.setOnClickListener(v -> startService(
                new Intent(this, ScreenProjectionService.class)
                        .setAction(ScreenProjectionService.ACTION_CAPTURE_ONCE)
        ));
        row2.addView(capture);

        Button see = button("What do you see?");
        see.setOnClickListener(v -> submit("what do you see"));
        row2.addView(see);

        root.addView(row2);
        setContentView(root);
    }

    private Button button(String text) {
        Button b = new Button(this);
        b.setText(text);
        b.setAllCaps(false);
        return b;
    }

    private void submit(String raw) {
        String text = raw == null ? "" : raw.trim();
        if (text.isEmpty()) return;
        input.setText("");
        append("You", text);

        HariMvpEngine.Reply reply = engine.handle(text);
        if (reply.permissionNeeded() != null) {
            pendingAfterPermission = text;
            if (Manifest.permission.READ_CONTACTS.equals(reply.permissionNeeded())) {
                requestPermissions(new String[]{Manifest.permission.READ_CONTACTS}, REQUEST_CONTACTS);
            }
            append("HARI", reply.text());
            speak(reply.text());
            return;
        }

        append("HARI", reply.text());
        speak(reply.text());
    }

    private void append(String who, String text) {
        transcript.append(who + ": " + text + "\n\n");
    }

    private void initSpeech() {
        tts = new TextToSpeech(this, status -> {
            if (status == TextToSpeech.SUCCESS) {
                int result = tts.setLanguage(Locale.getDefault());
                if (result == TextToSpeech.LANG_MISSING_DATA || result == TextToSpeech.LANG_NOT_SUPPORTED) {
                    tts.setLanguage(Locale.US);
                }
            }
        });

        if (SpeechRecognizer.isRecognitionAvailable(this)) {
            recognizer = SpeechRecognizer.createSpeechRecognizer(this);
            recognizer.setRecognitionListener(new RecognitionListener() {
                @Override public void onReadyForSpeech(Bundle params) {}
                @Override public void onBeginningOfSpeech() {}
                @Override public void onRmsChanged(float rmsdB) {}
                @Override public void onBufferReceived(byte[] buffer) {}
                @Override public void onEndOfSpeech() {}
                @Override public void onError(int error) {
                    append("HARI", "I couldn't hear that clearly. Try again or type it.");
                }
                @Override public void onResults(Bundle results) {
                    ArrayList<String> matches =
                            results.getStringArrayList(SpeechRecognizer.RESULTS_RECOGNITION);
                    if (matches != null && !matches.isEmpty()) submit(matches.get(0));
                }
                @Override public void onPartialResults(Bundle partialResults) {}
                @Override public void onEvent(int eventType, Bundle params) {}
            });
        }
    }

    private void startListening() {
        if (checkSelfPermission(Manifest.permission.RECORD_AUDIO) != PackageManager.PERMISSION_GRANTED) {
            requestPermissions(new String[]{Manifest.permission.RECORD_AUDIO}, REQUEST_MIC);
            return;
        }
        if (recognizer == null) {
            append("HARI", "Android does not expose a speech recognizer on this device.");
            return;
        }

        Intent intent = new Intent(RecognizerIntent.ACTION_RECOGNIZE_SPEECH)
                .putExtra(RecognizerIntent.EXTRA_LANGUAGE_MODEL, RecognizerIntent.LANGUAGE_MODEL_FREE_FORM)
                .putExtra(RecognizerIntent.EXTRA_LANGUAGE, Locale.getDefault().toLanguageTag())
                .putExtra(RecognizerIntent.EXTRA_PARTIAL_RESULTS, false)
                .putExtra(RecognizerIntent.EXTRA_MAX_RESULTS, 3);
        recognizer.startListening(intent);
    }

    private void speak(String text) {
        if (tts != null && text != null && !text.isBlank()) {
            tts.speak(text, TextToSpeech.QUEUE_FLUSH, null, "hari-reply");
        }
    }

    @Override
    public void onRequestPermissionsResult(int requestCode, String[] permissions, int[] results) {
        super.onRequestPermissionsResult(requestCode, permissions, results);
        boolean granted = results.length > 0 && results[0] == PackageManager.PERMISSION_GRANTED;

        if (requestCode == REQUEST_MIC && granted) {
            startListening();
            return;
        }
        if (requestCode == REQUEST_CONTACTS && granted && pendingAfterPermission != null) {
            String pending = pendingAfterPermission;
            pendingAfterPermission = null;
            submit(pending);
            return;
        }
        if ((requestCode == REQUEST_MIC || requestCode == REQUEST_CONTACTS) && !granted) {
            append("HARI", "Permission was not granted, so I won't use that sensor or data.");
        }
    }

    @Override
    protected void onActivityResult(int requestCode, int resultCode, Intent data) {
        super.onActivityResult(requestCode, resultCode, data);
        if (requestCode != REQUEST_SCREEN || resultCode != RESULT_OK || data == null) return;

        Intent service = new Intent(this, ScreenProjectionService.class)
                .setAction(ScreenProjectionService.ACTION_START)
                .putExtra(ScreenProjectionService.EXTRA_RESULT_CODE, resultCode)
                .putExtra(ScreenProjectionService.EXTRA_RESULT_DATA, data);
        startForegroundService(service);
        append("HARI", "On-demand screenshot permission is active.");
    }

    @Override
    protected void onDestroy() {
        if (recognizer != null) recognizer.destroy();
        if (tts != null) tts.shutdown();
        super.onDestroy();
    }
}
