// HARI Desktop Real-Time Interaction Controller

let recognition = null;
let isSpeaking = false;
let currentAudio = null;
let lastPendingPlan = null;

document.addEventListener("DOMContentLoaded", () => {
  initCamera();
  initSpeechRecognition();
  initEventListeners();
  startStatusPolling();
});

// 1. Camera Initialization
async function initCamera() {
  const video = document.getElementById("camVideo");
  try {
    const stream = await navigator.mediaDevices.getUserMedia({ video: true, audio: false });
    video.srcObject = stream;
  } catch (err) {
    console.warn("Camera init error (using mock/offline):", err);
    document.getElementById("camTag").innerText = "Camera: Inactive / Not Permitted";
  }
}

function captureCamFrameB64() {
  const video = document.getElementById("camVideo");
  const canvas = document.getElementById("camCanvas");
  if (!video || !video.videoWidth) return null;

  canvas.width = video.videoWidth;
  canvas.height = video.videoHeight;
  const ctx = canvas.getContext("2d");
  ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
  return canvas.toDataURL("image/jpeg", 0.7);
}

// 2. Speech Recognition (Continuous EARS)
function initSpeechRecognition() {
  const SpeechRec = window.SpeechRecognition || window.webkitSpeechRecognition;
  if (!SpeechRec) {
    console.warn("Web Speech API not supported in this browser. Use text input.");
    return;
  }

  recognition = new SpeechRec();
  recognition.continuous = true;
  recognition.interimResults = true;
  // Automatically support multilingual / code-switching or en-US
  recognition.lang = "en-US";

  recognition.onstart = () => {
    document.getElementById("turnBadge").innerText = "Listening...";
    document.getElementById("turnBadge").style.color = "var(--accent-green)";
  };

  recognition.onresult = (event) => {
    let interim = "";
    let final = "";

    for (let i = event.resultIndex; i < event.results.length; ++i) {
      if (event.results[i].isFinal) {
        final += event.results[i][0].transcript;
      } else {
        interim += event.results[i][0].transcript;
      }
    }

    if (interim) {
      document.getElementById("liveTranscription").innerText = interim;
      // Barge-in check: If HARI is speaking, cut it off instantly!
      if (isSpeaking) {
        triggerBargeIn();
      }
    }

    if (final && final.trim().length > 0) {
      document.getElementById("liveTranscription").innerText = final;
      submitUserUtterance(final.trim());
    }
  };

  recognition.onerror = (err) => {
    console.log("Speech recognition notice:", err.error);
  };

  recognition.onend = () => {
    // Keep continuous listening alive
    const micChecked = document.getElementById("micCheck").checked;
    if (micChecked) {
      try { recognition.start(); } catch (e) {}
    }
  };

  try {
    recognition.start();
  } catch (e) {
    console.log("Recognition auto-start notice:", e);
  }
}

// 3. User Interaction & API Dispatch
async function submitUserUtterance(text, isCorrection = false) {
  if (!text) return;
  appendMessage("user", text);
  document.getElementById("textInput").value = "";
  document.getElementById("statusBadge").innerText = "THINKING";
  document.getElementById("statusBadge").style.color = "var(--accent-cyan)";

  const camB64 = captureCamFrameB64();

  try {
    const res = await fetch("/api/chat", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        text: text,
        camera_b64: camB64,
        is_correction: isCorrection
      })
    });
    const data = await res.json();
    handleChatResponse(data);
  } catch (err) {
    console.error("Chat error:", err);
    appendMessage("hari", "Connection error communicating with HARI core.");
  }
}

function handleChatResponse(data) {
  document.getElementById("statusBadge").innerText = "ALIVE";
  document.getElementById("statusBadge").style.color = "var(--accent-green)";

  if (data.reply) {
    appendMessage("hari", data.reply);
  }

  // Handle plan and confirmation
  if (data.plan) {
    renderPlan(data.plan);
  }

  // Handle epistemic evaluation
  if (data.epistemic) {
    renderEpistemic(data.epistemic);
  }

  // Handle audio playback aloud
  if (data.audio_url) {
    playHariVoice(data.audio_url);
  }
}

function playHariVoice(url) {
  stopHariVoice();
  isSpeaking = true;
  document.getElementById("turnBadge").innerText = "HARI Speaking...";
  document.getElementById("turnBadge").style.color = "var(--accent-cyan)";

  currentAudio = new Audio(url);
  currentAudio.play().catch(e => console.log("Audio play error:", e));

  currentAudio.onended = () => {
    isSpeaking = false;
    document.getElementById("turnBadge").innerText = "Listening...";
    document.getElementById("turnBadge").style.color = "var(--accent-green)";
  };
}

function stopHariVoice() {
  if (currentAudio) {
    currentAudio.pause();
    currentAudio.currentTime = 0;
    currentAudio = null;
  }
  isSpeaking = false;
}

async function triggerBargeIn() {
  stopHariVoice();
  document.getElementById("turnBadge").innerText = "Interrupted!";
  document.getElementById("turnBadge").style.color = "var(--accent-red)";
  try {
    await fetch("/api/voice/interrupt", { method: "POST" });
  } catch (e) {}
}

function renderPlan(plan) {
  lastPendingPlan = plan;
  document.getElementById("actionTitle").innerText = `${plan.action_type}: ${plan.reason || ""}`;
  document.getElementById("actionDesc").innerText = `Risk Level: ${plan.risk_level} | Target: ${JSON.stringify(plan.params)}`;
  document.getElementById("riskBadge").innerText = plan.risk_level;
  document.getElementById("riskBadge").style.color = plan.risk_level === "CONSEQUENTIAL" ? "var(--accent-amber)" : "var(--accent-green)";

  const confirmBox = document.getElementById("confirmBox");
  if (plan.needs_confirmation) {
    confirmBox.style.display = "flex";
  } else {
    confirmBox.style.display = "none";
  }
}

function renderEpistemic(ep) {
  document.getElementById("epistemicDecision").innerText = ep.action;
  document.getElementById("epistemicDecision").style.color = ep.action === "ACT" ? "var(--accent-green)" : "var(--accent-amber)";
  document.getElementById("confVal").innerText = ep.confidence;
  document.getElementById("confMeter").style.width = `${Math.min(100, Math.round(ep.confidence * 100))}%`;

  const list = document.getElementById("competingList");
  list.innerHTML = "";
  if (ep.competing_hypotheses) {
    ep.competing_hypotheses.forEach((h, idx) => {
      const div = document.createElement("div");
      div.className = "hypo-item";
      div.innerText = `${idx + 1}. ${h.intent} (${h.score})`;
      list.appendChild(div);
    });
  }
}

function appendMessage(author, text) {
  const scroll = document.getElementById("dialogueScroll");
  const msg = document.createElement("div");
  msg.className = `msg ${author}`;
  msg.innerHTML = `
    <div class="msg-author">${author.toUpperCase()}</div>
    <div class="msg-bubble">${escapeHtml(text)}</div>
  `;
  scroll.appendChild(msg);
  scroll.scrollTop = scroll.scrollHeight;
}

function escapeHtml(str) {
  return str.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;");
}

// 4. Polling Status from HARI Core
async function startStatusPolling() {
  setInterval(async () => {
    try {
      const res = await fetch("/api/status");
      if (!res.ok) return;
      const data = await res.json();
      updateDashboard(data);
    } catch (e) {}
  }, 2500);
}

function updateDashboard(data) {
  if (data.mind) {
    const stats = data.mind.resource_stats || {};
    document.getElementById("tokensPill").innerText = `Tokens: ${stats.tokens || 0}`;
    document.getElementById("conceptCount").innerText = `${stats.pairs || 0} associations`;

    if (data.screen && data.screen.frontmost_app) {
      document.getElementById("frontAppText").innerText = `Active: ${data.screen.frontmost_app}`;
    }

    // Windows
    if (data.screen && data.screen.windows) {
      const wList = document.getElementById("windowList");
      wList.innerHTML = "";
      data.screen.windows.slice(0, 4).forEach((w, idx) => {
        const li = document.createElement("li");
        li.className = "win-item";
        li.innerText = `${idx + 1}. ${w.app}: ${w.title.slice(0, 30)}`;
        wList.appendChild(li);
      });
    }
  }
}

// 5. Event Listeners
function initEventListeners() {
  document.getElementById("btnSend").addEventListener("click", () => {
    const val = document.getElementById("textInput").value.trim();
    if (val) submitUserUtterance(val);
  });

  document.getElementById("textInput").addEventListener("keydown", (e) => {
    if (e.key === "Enter") {
      const val = document.getElementById("textInput").value.trim();
      if (val) submitUserUtterance(val);
    }
  });

  document.getElementById("btnBargeIn").addEventListener("click", triggerBargeIn);

  document.getElementById("btnConfirm").addEventListener("click", async () => {
    if (!lastPendingPlan) return;
    try {
      const res = await fetch("/api/action/confirm", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ plan: lastPendingPlan })
      });
      const data = await res.json();
      document.getElementById("confirmBox").style.display = "none";
      appendMessage("hari", `Action confirmed and executed: ${JSON.stringify(data.action_result)}`);
    } catch (e) {
      console.error(e);
    }
  });

  document.getElementById("btnCancel").addEventListener("click", () => {
    document.getElementById("confirmBox").style.display = "none";
    appendMessage("hari", "Action cancelled by user.");
  });
}
