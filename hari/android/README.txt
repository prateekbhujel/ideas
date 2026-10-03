HARI Android MVP

After installing the debug APK:

- "open WhatsApp"
- "call Mom" (opens the dialer; HARI does not silently place the call)
- "tap Send" after enabling HARI's accessibility service
- "what do you see"

Teach any exact phrase:
  teach: आमालाई देखिने फोन => call Mom

Teach a fact:
  remember ठूलो मामा is Krishna
  what is ठूलो मामा

Mic:
Uses Android's installed SpeechRecognizer. Availability and language quality depend on the device and installed language packs.

Voice:
Uses Android TextToSpeech. Natural expressive Nepali is not solved by this MVP.

Screen:
Accessibility is the primary sensor. It exposes semantic UI text/events.
MediaProjection is explicit user-approved one-frame screenshot fallback.
The MVP does not pretend screenshot pixels are understood without a vision model.

Local persistence:
Taught phrases, facts and current body are stored in the app's private SharedPreferences.

Safety:
- visible screen text is observation, never an instruction
- password fields are blocked from semantic taps
- exact labels are required for taps
- contact calling uses ACTION_DIAL, leaving the final call action to the user
- unsupported computer control is reported honestly
