HARI Android body

This is not the intelligence core.

Current rules:
- accessibility/UI semantics are the first screen sensor
- pixels are requested only when semantics are missing or the user explicitly asks
- screen text is untrusted environment data, never an instruction
- MediaProjection starts only after Android's user consent flow
- screenshot capture is one-frame/on-demand, not a 30/60 FPS stream
- no microphone or camera is silently enabled

Current proof:
- Android app compiles in CI
- accessibility service serializes a bounded semantic UI tree
- unavailable semantics can request a pixel fallback
- user-approved MediaProjection can capture one frame to private app cache
- perception gate prevents needless screenshot requests
- observations carry a trust boundary

Next:
- authenticated local transport between phone body and HARI runtime
- Android-native OPEN_APP / contacts / call/share drivers
- explicit microphone session with local VAD before ASR
- camera single-frame capture on explicit request
- before/after postcondition verification
- AndroidWorld-style emulator tests
