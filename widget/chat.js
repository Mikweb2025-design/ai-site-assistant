/* ai-site-assistant widget: a small floating bubble -> chat window with an animated portrait.
   Configuration comes from window.__aisCfg (see examples/loader.js):
     base     engine path on your own origin        default "/assistant"   (-> /assistant/status, /assistant/ws)
     faq      text-only fallback endpoint            default "/assistant-faq.php"
     assets   folder holding mic-worklet.js, chat.css  default "/assets/ais"
     owner    name used in the texts                 default "the site owner"
     name     name shown under the portrait          default = owner
     photo    portrait (square image)                default "/assets/ais/avatar.svg"
     landmarks  {mouth:{x,y}, eyes:[{x,y},{x,y}]} as fractions of the photo (0..1)  — tune for your photo
     voice    allow the voice call                   default true
     side     "left" | "right"
     color    accent colour (#rrggbb)
     v        cache-busting version for the files above
   Talks to the engine over WebSocket with a Twilio-Media-Streams-style protocol:
   8 kHz mu-law frames in both directions, "mark"/"clear" events, "text" events for typed text (docs/protocol.md). */
(function () {
  "use strict";
  var cfg = window.__aisCfg || {};
  var OWNER = String(cfg.owner || "the site owner"), ASSETS = String(cfg.assets || "/assets/ais").replace(/\/+$/, "");
  var BASE = String(cfg.base || "/assistant").replace(/\/+$/, "");
  var VOICE_ALLOWED = cfg.voice !== false;
  var L = (document.documentElement.lang || "de").slice(0, 2);
  if (L !== "en" && L !== "it") L = "de";

  var T = {
    de: {
      title: "Assistent", open: "Assistent öffnen", close: "Schließen", ph: "Frag mich etwas …", send: "Senden",
      call: "Anrufen", hang: "Auflegen", ai: "KI-Assistent von {owner} – keine echte Person.",
      hello: "Hallo! Ich beantworte Fragen zu {owner} und zu dieser Website. Schreib mir oder ruf mich direkt im Browser an.",
      idle: "bereit", thinking: "denkt nach …", hearing: "hört dich", nudge: "Frag mich etwas", connecting: "verbinde …", listening: "höre zu", speaking: "spricht", mic: "Mikrofon erlauben …",
      nomic: "Kein Mikrofon – du kannst schreiben.", busy: "Gerade führt jemand ein Gespräch. Bitte in ein paar Minuten noch einmal.",
      off: "Der Sprachanruf ist gerade nicht verfügbar – der Text-Chat funktioniert.", textOnly: "Gerade nur Text-Chat: der Sprachanruf ist nicht verfügbar.", sorry: "Das hat gerade nicht geklappt. Schreib {owner} bitte über das Kontaktformular.",
      blocked: "Der Browser blockiert den Ton – klick einmal ins Fenster.", ended: "Gespräch beendet.", limit: "Zu viele Gespräche – bitte später noch einmal."
    },
    en: {
      title: "Assistant", open: "Open assistant", close: "Close", ph: "Ask me something …", send: "Send",
      call: "Call", hang: "Hang up", ai: "{owner}'s AI assistant – not a real person.",
      hello: "Hi! I answer questions about {owner} and this website. Type to me, or call me right here in the browser.",
      idle: "ready", thinking: "thinking …", hearing: "hearing you", nudge: "Ask me anything", connecting: "connecting …", listening: "listening", speaking: "speaking", mic: "Allow the microphone …",
      nomic: "No microphone – you can type.", busy: "Someone is in a conversation right now. Please try again in a few minutes.",
      off: "The voice call is not available right now – the text chat works.", textOnly: "Text chat only right now: the voice call is not available.", sorry: "That did not work just now. Please write to {owner} through the contact form.",
      blocked: "The browser is blocking audio – click once inside the window.", ended: "Conversation ended.", limit: "Too many conversations – please try again later."
    },
    it: {
      title: "Assistente", open: "Apri l'assistente", close: "Chiudi", ph: "Chiedimi qualcosa …", send: "Invia",
      call: "Chiama", hang: "Chiudi chiamata", ai: "Assistente AI di {owner} – non è una persona vera.",
      hello: "Ciao! Rispondo a domande su {owner} e su questo sito. Scrivimi, oppure chiamami direttamente dal browser.",
      idle: "pronto", thinking: "sta pensando …", hearing: "ti ascolta", nudge: "Chiedimi qualcosa", connecting: "collegamento …", listening: "in ascolto", speaking: "parla", mic: "Consenti il microfono …",
      nomic: "Nessun microfono: puoi scrivere.", busy: "In questo momento c'è già una conversazione. Riprova tra qualche minuto.",
      off: "La chiamata vocale al momento non è disponibile: la chat scritta funziona.", textOnly: "Al momento solo chat scritta: la chiamata vocale non è disponibile.", sorry: "Non ha funzionato adesso. Scrivi a {owner} con il modulo di contatto.",
      blocked: "Il browser blocca l\u2019audio: fai un clic nella finestra.", ended: "Conversazione terminata.", limit: "Troppe conversazioni: riprova più tardi."
    }
  }[L];
  Object.keys(T).forEach(function (k) { if (typeof T[k] === "string") T[k] = T[k].replace(/\{owner\}/g, OWNER); });

  var reduced = window.matchMedia && matchMedia("(prefers-reduced-motion: reduce)").matches;
  var $ = function (tag, cls, txt) { var e = document.createElement(tag); if (cls) e.className = cls; if (txt) e.textContent = txt; return e; };

  /* ---------- DOM ---------- */
  var btn = $("button", "dmc-btn");
  btn.type = "button"; btn.setAttribute("aria-label", T.open);
  btn.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12a8 8 0 0 1-11.6 7.1L4 20l1-4.6A8 8 0 1 1 21 12z"/></svg>';

  btn.style.backgroundImage = "url(" + (cfg.photo || (ASSETS + "/avatar.svg")) + ")";
  var win = $("section", "dmc-win");
  win.hidden = true; win.setAttribute("role", "dialog"); win.setAttribute("aria-label", T.title);
  var head = $("div", "dmc-head");
  var close = $("button", "dmc-close", "×"); close.type = "button"; close.setAttribute("aria-label", T.close);
  var av = $("div", "dmc-avatar");
  var canvas = $("canvas"); canvas.width = 320; canvas.height = 320; av.appendChild(canvas);
  var nameEl = $("div", "dmc-name", cfg.name || OWNER);
  var stateEl = $("div", "dmc-state", T.idle);
  var aiEl = $("div", "dmc-ai", T.ai);
  head.appendChild(close); head.appendChild(av); head.appendChild(nameEl); head.appendChild(stateEl); head.appendChild(aiEl);
  var nudge = $("button", "dmc-nudge", T.nudge); nudge.type = "button"; nudge.hidden = true;
  var lvl = $("div", "dmc-lvl"); var lvlI = $("i"); lvl.appendChild(lvlI);
  var log = $("div", "dmc-log"); log.setAttribute("aria-live", "polite");
  var bar = $("form", "dmc-bar");
  var inp = $("input", "dmc-in"); inp.type = "text"; inp.maxLength = 500; inp.placeholder = T.ph; inp.setAttribute("aria-label", T.ph); inp.autocomplete = "off";
  var snd = $("button", "dmc-act", T.send); snd.type = "submit";
  var call = $("button", "dmc-act", T.call); call.type = "button"; call.hidden = !VOICE_ALLOWED;
  bar.appendChild(inp); bar.appendChild(snd); bar.appendChild(call);
  win.appendChild(head); win.appendChild(lvl); win.appendChild(log); win.appendChild(bar);

  var engineOff = false, hist = [], hadSession = false, ctxPending = false, lastSent = "";
  function msg(cls, text) {
    if ((cls === "me" || cls === "bot") && text) { hist.push({ r: cls, t: String(text).slice(0, 400) }); if (hist.length > 12) hist.shift(); } var m = $("div", "dmc-m " + cls, text); log.appendChild(m); log.scrollTop = log.scrollHeight; }
  function setState(s) { stateEl.textContent = s; }

  /* ---------- avatar: the photo, alive. Breathing, blinking (sometimes twice), small head movements and gaze changes,
     a jaw + mouth width driven by the voice (level and brightness of the sound), brows that lift on stress,
     and a different attitude when listening to you and when thinking. ---------- */
  var photo = new Image();
  photo.src = cfg.photo || (ASSETS + "/avatar.svg");
  var ctx2 = canvas.getContext("2d");
  var level = 0, wide = 0.5, micLvl = 0, talking = false, thinking = false, raf = 0, skin = "rgb(190,138,112)";
  var nextBlink = performance.now() + 2200, blinkT = -1, blinkTwice = false, nextLook = 0;
  var gx = 0, gy = 0, tgx = 0, tgy = 0, tilt = 0, ttilt = 0, brow = 0, nod = 0, lastLvl = 0, smile = 0;
  // landmarks as fractions of the (square) photo; drawn in a 440 x 440 space
  var LM = cfg.landmarks || {}, LMM = LM.mouth || { x: 0.5, y: 0.52 }, LME = LM.eyes || [{ x: 0.41, y: 0.38 }, { x: 0.59, y: 0.38 }];
  var MOUTH = { x: LMM.x * 440, y: LMM.y * 440 }, EYES = LME.map(function (e) { return { x: e.x * 440, y: e.y * 440 }; });
  var BROW = { x: EYES[0].x - 48, y: (EYES[0].y + EYES[1].y) / 2 - 32, w: EYES[1].x - EYES[0].x + 99 };
  photo.onload = function () {
    try {
      var c = document.createElement("canvas"); c.width = c.height = 1; var g = c.getContext("2d");
      g.drawImage(photo, photo.naturalWidth * (MOUTH.x - 20) / 440, photo.naturalHeight * (MOUTH.y - 36) / 440, 12, 12, 0, 0, 1, 1); var p = g.getImageData(0, 0, 1, 1).data;
      skin = "rgb(" + p[0] + "," + p[1] + "," + p[2] + ")";
    } catch (e) { /* tainted or blocked: keep the default tone */ }
    draw(performance.now());
  };
  function mode() { return talking ? "talk" : thinking ? "think" : micLvl > 0.07 ? "listen" : "idle"; }
  function draw(t) {
    if (!photo.complete || !photo.naturalWidth) return;
    var W = canvas.width, k = W / 440, m = reduced ? 0 : 1;
    var breathe = 1 + 0.006 * Math.sin(t / 1400) * m, sway = Math.sin(t / 2300) * 1.2 * m;
    ctx2.setTransform(1, 0, 0, 1, 0, 0);
    ctx2.clearRect(0, 0, W, W);
    ctx2.save();
    ctx2.translate(W / 2 + (sway + gx) * k, W / 2 + (6 + gy + nod) * k);
    ctx2.rotate(tilt);
    ctx2.scale(breathe * 1.12, breathe * 1.12);
    ctx2.translate(-W / 2, -W / 2 - 6 * k);
    ctx2.scale(k, k);
    ctx2.drawImage(photo, 0, 0, 440, 440);
    // brows lift a little on stressed syllables
    if (brow > 0.15) ctx2.drawImage(photo, BROW.x * photo.naturalWidth / 440, BROW.y * photo.naturalHeight / 440, BROW.w * photo.naturalWidth / 440, 30 * photo.naturalHeight / 440, BROW.x, BROW.y - brow * 2.2, BROW.w, 30);
    // mouth: jaw drops with the level, the opening gets wider on bright sounds (e, i, s) and rounder on dark ones (o, u)
    var open = level * 10;
    if (open > 0.5) {
      var rx = 12 + 11 * wide + level * 3;
      ctx2.fillStyle = "#2a1210";
      ctx2.beginPath(); ctx2.ellipse(MOUTH.x, MOUTH.y + 1, rx, Math.max(1, open * 0.72), 0, 0, Math.PI * 2); ctx2.fill();
      ctx2.drawImage(photo, (MOUTH.x - 60) * photo.naturalWidth / 440, (MOUTH.y + 4) * photo.naturalHeight / 440, 120 * photo.naturalWidth / 440, 56 * photo.naturalHeight / 440, MOUTH.x - 60, MOUTH.y + 4 + open * 0.8, 120, 56);
    }
    // blink: a skin-coloured lid closes over each eye (sometimes twice in a row)
    if (blinkT >= 0) {
      var p = (t - blinkT) / 130, f = p < 0.5 ? p * 2 : (1 - p) * 2;
      if (p >= 1) {
        if (blinkTwice) { blinkTwice = false; blinkT = t + 90; } else { blinkT = -1; nextBlink = t + 2200 + Math.random() * 3800; }
      } else if (p >= 0) {
        ctx2.fillStyle = skin;
        EYES.forEach(function (e) { ctx2.beginPath(); ctx2.ellipse(e.x + gx * 0.4, e.y - 1, 15, 3 + 6 * f, 0, 0, Math.PI * 2); ctx2.fill(); });
      }
    } else if (!reduced && t > nextBlink) { blinkT = t; blinkTwice = Math.random() < 0.18; }
    ctx2.restore();
  }
  var analyser = null, abuf = null, fbuf = null;
  function frame(t) {
    raf = requestAnimationFrame(frame);
    var md = mode(), target = 0, tw = 0.5;
    if (analyser && talking) {
      analyser.getByteTimeDomainData(abuf);
      var e = 0; for (var i = 0; i < abuf.length; i++) { var v = (abuf[i] - 128) / 128; e += v * v; }
      target = Math.min(1, Math.sqrt(e / abuf.length) * 5);
      if (!fbuf) fbuf = new Uint8Array(analyser.frequencyBinCount);
      analyser.getByteFrequencyData(fbuf);
      var hz = ac ? ac.sampleRate / 2 / fbuf.length : 8000 / fbuf.length, lo = 0, hi = 0;
      for (var j = 1; j < fbuf.length; j++) { var f = j * hz; if (f < 1000) lo += fbuf[j]; else if (f > 1800 && f < 4000) hi += fbuf[j]; }
      tw = hi / (lo + hi + 1);
    }
    level += (target - level) * (target > level ? 0.55 : 0.22);
    wide += (tw - wide) * 0.2;
    brow += ((target - lastLvl > 0.12 ? 1 : 0) - brow) * 0.25; lastLvl = target;      // stress = quick rise of the level
    nod += ((md === "talk" ? level * 1.6 : 0) - nod) * 0.2;
    // gaze / head: new small target every few seconds, depends on what is going on
    if (t > nextLook && !reduced) {
      nextLook = t + (md === "think" ? 1800 : 1600 + Math.random() * 2800);
      if (md === "think") { tgx = 2.4; tgy = -1.6; ttilt = 0.03; }
      else if (md === "listen") { tgx = (Math.random() - 0.5) * 1.2; tgy = 0.6; ttilt = -0.025; }
      else if (md === "talk") { tgx = (Math.random() - 0.5) * 2.4; tgy = (Math.random() - 0.5) * 1.4; ttilt = (Math.random() - 0.5) * 0.03; }
      else { tgx = (Math.random() - 0.5) * 3; tgy = (Math.random() - 0.5) * 1.6; ttilt = (Math.random() - 0.5) * 0.02; }
    }
    gx += (tgx - gx) * 0.05; gy += (tgy - gy) * 0.05; tilt += (ttilt - tilt) * 0.05;
    // the ring around the portrait follows what is happening
    av.style.setProperty("--lv", String(Math.min(1, md === "listen" ? micLvl * 5 : level)));
    var cls = "dmc-m-" + md;
    if (av.dataset.mode !== md) { av.dataset.mode = md; av.className = "dmc-avatar " + cls; setState(md === "think" ? T.thinking : md === "talk" ? T.speaking : md === "listen" && ws ? T.hearing : (ws ? T.listening : T.idle)); }
    draw(t);
  }
  function startAnim() { if (!raf) raf = requestAnimationFrame(frame); }
  function stopAnim() { if (raf) cancelAnimationFrame(raf); raf = 0; }

  /* ---------- call / chat session ---------- */
  var ws = null, ac = null, wideOut = null, filt = null, mic = null, nodeIn = null, monitor = null, srcIn = null, playAt = 0, srcs = [], marks = [];
  var hinted = false, resamp = { resto: 0, ultimo: null }, tick = 0, sid = "MZdm" + Math.random().toString(16).slice(2), voiceOn = false, ended = false;
  var DEC = new Float32Array(256);
  for (var i = 0; i < 256; i++) { var u = ~i & 255, tt = (((u & 15) << 3) + 132) << ((u & 112) >> 4); DEC[i] = (u & 128 ? 132 - tt : tt - 132) / 32768; }
  function enc(f) { var s = Math.max(-1, Math.min(1, f)) * 32767 | 0, sg = s < 0 ? 128 : 0; if (s < 0) s = -s; s = Math.min(s, 32635) + 132; var e = 7; for (var m = 16384; (s & m) === 0 && e > 0; m >>= 1) e--; return ~(sg | (e << 4) | ((s >> (e + 3)) & 15)) & 255; }
  function b64(u8) { var s = ""; for (var i = 0; i < u8.length; i++) s += String.fromCharCode(u8[i]); return btoa(s); }
  function send(o) { if (ws && ws.readyState === 1) ws.send(JSON.stringify(o)); }
  var ios = /iP(?:hone|ad|od)/.test(navigator.userAgent) || (navigator.platform === "MacIntel" && navigator.maxTouchPoints > 1);

  // linear resample of one 8 kHz packet to the device rate, phase kept across packets (no metallic buzz)
  function resample(vals, su, rate, st) {
    st = st || resamp;
    var n = vals.length, step = (rate || 8000) / su, out = [], x = st.resto;
    var at = function (i) { return i < 0 ? (st.ultimo == null ? vals[0] : st.ultimo) : (i < n ? vals[i] : vals[n - 1]); };
    while (x < n - 1) { var j = Math.floor(x), fr = x - j, a = at(j), b = at(j + 1); out.push(a + (b - a) * fr); x += step; }
    st.resto = x - n; st.ultimo = vals[n - 1];
    return out;
  }

  var resampW = { resto: 0, ultimo: null };
  // media may be 8 kHz mu-law (telephone-style engines) or 16-bit PCM at media.sampleRate (wideband, no telephone filtering)
  function play(payload, media) {
    if (!ac || !voiceOn) return;
    if (ac.state === "suspended") { ac.resume().catch(function () {}); if (!hinted) { hinted = true; setTimeout(function () { if (ac && ac.state === "suspended") msg("sys", T.blocked); }, 1500); } }
    var raw = atob(payload), n = raw.length, vals, out, wide = !!(media && media.encoding === "pcm16le");
    if (wide) {
      var cnt = n >> 1; vals = new Float32Array(cnt);
      for (var k = 0; k < cnt; k++) { var v = raw.charCodeAt(2 * k) | (raw.charCodeAt(2 * k + 1) << 8); vals[k] = (v > 32767 ? v - 65536 : v) / 32768; }
      out = cnt ? resample(vals, ac.sampleRate, media.sampleRate || 24000, resampW) : [];
    } else {
      vals = new Float32Array(n);
      for (var i = 0; i < n; i++) vals[i] = DEC[raw.charCodeAt(i)] || 0;
      out = resample(vals, ac.sampleRate);
    }
    if (!out.length) return;
    var buf = ac.createBuffer(1, out.length, ac.sampleRate); buf.getChannelData(0).set(out);
    var s = ac.createBufferSource(); s.buffer = buf; s.connect(wide ? wideOut : filt);
    var now = ac.currentTime, at = playAt > now + 0.02 ? playAt : now + (ios ? 0.25 : 0.15);
    s.start(at); playAt = at + buf.duration; srcs.push(s);
    s.onended = function () { srcs = srcs.filter(function (x) { return x !== s; }); };
    thinking = false; talking = true; win.classList.add("dmc-talk");
  }
  function mark(name) {
    var ms = voiceOn && ac && ac.state === "running" ? Math.max(0, (playAt - ac.currentTime) * 1000) : (voiceOn && ac ? 600 : 0);
    var t = setTimeout(function () {
      marks = marks.filter(function (m) { return m.t !== t; });
      send({ event: "mark", streamSid: sid, mark: { name: name } });
      if (!marks.length) { talking = false; win.classList.remove("dmc-talk"); }
    }, ms);
    marks.push({ t: t, n: name });
  }
  function clearAudio() {
    srcs.forEach(function (s) { try { s.stop(); } catch (e) {} }); srcs = []; playAt = 0;
    marks.forEach(function (m) { clearTimeout(m.t); send({ event: "mark", streamSid: sid, mark: { name: m.n } }); }); marks = [];
    talking = false; win.classList.remove("dmc-talk");
  }

  function status() {
    var ctl = new AbortController(), to = setTimeout(function () { ctl.abort(); }, 4000);
    return fetch(BASE + "/status", { signal: ctl.signal, cache: "no-store" })
      .then(function (r) { clearTimeout(to); return r.ok ? r.json() : null; })
      .catch(function () { clearTimeout(to); return null; });
  }

  function open() {
    return new Promise(function (resolve) {
      if (ws && ws.readyState === 1) return resolve(true);
      status().then(function (s) {
        if (!s || !s.ok || !(s.voice || s.text)) { engineOff = true; return resolve(false); }
        engineOff = false;
        if (s.busy) { return resolve(false); }
        setState(T.connecting);
        var proto = location.protocol === "https:" ? "wss://" : "ws://";
        var url = /^https?:/.test(BASE) ? BASE.replace(/^http/, "ws") : proto + location.host + BASE;
        var resume = hadSession; ctxPending = resume && hist.length > 0;
        var sock = new WebSocket(url + "/ws?lang=" + L + "&wb=1" + (resume ? "&resume=1" : "")), opened = false;
        ws = sock; ended = false; tick = 0;
        sock.onopen = function () {
          opened = true; hadSession = true;
          send({ event: "connected", protocol: "Call", version: "1.0.0" });
          send({ event: "start", streamSid: sid, start: { streamSid: sid, callSid: "CAwidget" + Date.now(), customParameters: { from: "browser", client: ios ? "ios" : "browser" }, mediaFormat: { encoding: "audio/x-mulaw", sampleRate: 8000, channels: 1 } } });
          setState(T.listening); resolve(true);
        };
        sock.onmessage = function (ev) {
          var m; try { m = JSON.parse(ev.data); } catch (e) { return; }
          if (m.event === "media") play(m.media.payload, m.media);
          else if (m.event === "mark") mark(m.mark.name);
          else if (m.event === "clear") clearAudio();
          else if (m.event === "text") {
            if (m.from === "visitor") { thinking = true; if (String(m.text).trim() === lastSent.trim()) { lastSent = ""; return; } } else thinking = false;
            msg(m.from === "visitor" ? "me" : "bot", m.text);
          }
        };
        sock.onclose = function () {
          if (!opened) { resolve(false); }
          var wait = voiceOn && ac ? Math.max(0, (playAt - ac.currentTime) * 1000) + 200 : 0;
          setTimeout(function () { if (ws === sock) finish(); }, wait);
        };
        sock.onerror = function () { /* onclose reports it */ };
      });
    });
  }

  function finish() {
    thinking = false; var had = !!ws; hangVoice(); try { if (ws) ws.close(); } catch (e) {} ws = null; clearAudio();
    call.textContent = T.call; call.classList.remove("on"); setState(T.idle); lvlI.style.width = "0";
  }

  function hangVoice() {
    voiceOn = false;
    try { if (nodeIn) { nodeIn.port.onmessage = null; nodeIn.disconnect(); } if (monitor) monitor.disconnect(); if (srcIn) srcIn.disconnect(); } catch (e) {}
    if (mic) mic.getTracks().forEach(function (t) { t.stop(); });
    if (ac) { try { ac.close(); } catch (e) {} }
    ac = filt = wideOut = mic = nodeIn = monitor = srcIn = analyser = null; playAt = 0;
  }

  // Must run synchronously inside the click: Safari / iOS (and Chrome without recent interaction) keep an
  // AudioContext created later "suspended", and then nothing is audible and the engine never gets its "mark" back.
  function prepAudio() {
    ac = new (window.AudioContext || window.webkitAudioContext)({ latencyHint: "interactive" });
    try { ac.resume().catch(function () {}); } catch (e) {}
    // silent buffer wakes the output (iOS / Bluetooth) so the first syllable is not lost
    try { var w = ac.createBufferSource(); w.buffer = ac.createBuffer(1, Math.max(128, Math.round(ac.sampleRate * 0.05)), ac.sampleRate); w.connect(ac.destination); w.start(); } catch (e) {}
    filt = ac.createBiquadFilter(); filt.type = "lowpass"; filt.frequency.value = Math.min(3400, ac.sampleRate * 0.45); filt.Q.value = 0.54;
    var f2 = ac.createBiquadFilter(); f2.type = "lowpass"; f2.frequency.value = filt.frequency.value; f2.Q.value = 1.3;
    analyser = ac.createAnalyser(); analyser.fftSize = 512; abuf = new Uint8Array(analyser.fftSize);
    filt.connect(f2); f2.connect(analyser); f2.connect(ac.destination);
    wideOut = ac.createGain(); wideOut.connect(analyser); wideOut.connect(ac.destination);   // wideband audio goes straight out: no 3.4 kHz telephone filters
    resampW = { resto: 0, ultimo: null };
    hinted = false; voiceOn = true; playAt = 0; resamp = { resto: 0, ultimo: null };
  }

  function startMic() {
    if (!ac) return Promise.resolve();
    setState(T.mic);
    return navigator.mediaDevices.getUserMedia({ audio: { echoCancellation: { ideal: true }, noiseSuppression: { ideal: true }, autoGainControl: { ideal: true }, channelCount: { ideal: 1 } } })
      .then(function (s) { return s; }, function () { msg("sys", T.nomic); return null; })
      .then(function (stream) {
        if (!ac) { if (stream) stream.getTracks().forEach(function (t) { t.stop(); }); return; }
        mic = stream;
        if (!stream) { setState(T.listening); return; }
        return ac.audioWorklet.addModule(ASSETS + "/mic-worklet.js?v=" + (cfg.v || 1)).then(function () {
          if (!ac) return;
          srcIn = ac.createMediaStreamSource(stream); nodeIn = new AudioWorkletNode(ac, "mic-capture"); srcIn.connect(nodeIn);
          monitor = ac.createGain(); monitor.gain.value = 0; nodeIn.connect(monitor); monitor.connect(ac.destination);
          nodeIn.port.onmessage = function (ev) {
            var d = ev.data, e = 0, u = new Uint8Array(160);
            for (var i = 0; i < 160; i++) { u[i] = enc(d[i]); e += d[i] * d[i]; }
            micLvl += (Math.sqrt(e / 160) - micLvl) * 0.3; lvlI.style.width = Math.min(100, Math.sqrt(e / 160) * 400) + "%";
            send({ event: "media", streamSid: sid, media: { track: "inbound", chunk: String(tick), timestamp: String(tick++ * 20), payload: b64(u) } });
          };
          setState(T.listening);
        });
      });
  }

  /* ---------- wiring ---------- */
  var greeted = false;
  function show() {
    win.hidden = false; btn.hidden = true; nudgeOff(); startAnim();
    if (!greeted) { greeted = true; msg("bot", T.hello); }
    status().then(function (s) { if (!s || !s.ok || !(s.voice || s.text)) { engineOff = true; call.hidden = true; msg("sys", T.textOnly); } else { call.hidden = !(s.voice && VOICE_ALLOWED); } });
    setTimeout(function () { inp.focus(); }, 50);
  }
  function hide() { finish(); win.hidden = true; btn.hidden = false; stopAnim(); }
  function nudgeOff() { nudge.hidden = true; try { sessionStorage.setItem("dmc-nudge", "1"); } catch (e) {} }
  btn.addEventListener("click", show);
  close.addEventListener("click", hide);
  document.addEventListener("keydown", function (e) { if (e.key === "Escape" && !win.hidden) hide(); });
  // Voice engine off (Mac down), busy or unreachable: the same chat keeps working from the site itself, text only.
  function askFallback(t) {
    thinking = true;
    return fetch(cfg.faq || "/assistant-faq.php", { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ lang: L, text: t }) })
      .then(function (r) { return r.ok ? r.json() : null; })
      .catch(function () { return null; })
      .then(function (d) {
        thinking = false;
        if (!d || !d.ok) { msg("sys", T.sorry); return; }
        msg("bot", d.reply);
        (d.links || []).forEach(function (l) {
          if (!l || typeof l.href !== "string" || l.href.charAt(0) !== "/") return;
          var a = $("a", "dmc-link", l.label); a.href = l.href; log.appendChild(a); log.scrollTop = log.scrollHeight;
        });
      });
  }
  // After a silence the engine hangs up; the next question reconnects on its own and carries the earlier talk as context.
  function context() {
    var past = hist.slice(0, -1).filter(function (h) { return h.t; }).slice(-8);
    if (!past.length) return "";
    return "[Earlier in this conversation \u2014 context only, do not answer it]\n" + past.map(function (h) { return (h.r === "me" ? "Visitor: " : "Assistant: ") + h.t; }).join("\n") + "\n[New message]\n";
  }
  bar.addEventListener("submit", function (e) {
    e.preventDefault();
    var t = inp.value.trim(); if (!t) return; inp.value = "";
    msg("me", t); thinking = true;
    open().then(function (ok) {
      if (!ok) { return askFallback(t); }
      var out = t;
      if (ctxPending) { ctxPending = false; out = context() + t; }
      lastSent = out;
      send({ event: "text", text: out.slice(0, 2400) });
    });
  });
  call.addEventListener("click", function () {
    if (voiceOn || (ws && call.classList.contains("on"))) return finish();
    prepAudio(); // first thing, inside the click (see prepAudio)
    call.disabled = true;
    open().then(function (ok) {
      if (!ok) { hangVoice(); call.disabled = false; msg("sys", T.off); return; }
      call.textContent = T.hang; call.classList.add("on");
      return startMic();
    }).then(function () { call.disabled = false; }, function () { call.disabled = false; msg("sys", T.nomic); });
  });
  // a blocked context wakes up on the next tap anywhere in the window
  win.addEventListener("click", function () { if (ac && ac.state === "suspended") ac.resume().catch(function () {}); });
  window.__aisDebug = function () { return { state: ac && ac.state, voiceOn: voiceOn, playAt: playAt, now: ac && ac.currentTime, ws: ws && ws.readyState }; };
  // leaving the page frees the engine at once (otherwise it waits for the timeout and blocks the next visitor)
  window.addEventListener("pagehide", function () { try { send({ event: "stop", streamSid: sid }); if (ws) ws.close(); } catch (e) {} });
  document.addEventListener("visibilitychange", function () { if (!document.hidden && ac && ac.state === "suspended") ac.resume().catch(function () {}); });

  var css = document.createElement("link"); css.rel = "stylesheet"; css.href = ASSETS + "/chat.css?v=" + (cfg.v || 1); document.head.appendChild(css);
  if (cfg.color) [btn, win, nudge].forEach(function (e) { e.style.setProperty("--accent", cfg.color); e.style.setProperty("--accent-soft", cfg.color + "22"); });
  if (cfg.side === "left") { btn.classList.add("dmc-l"); win.classList.add("dmc-l"); nudge.classList.add("dmc-l"); }
  document.body.appendChild(btn); document.body.appendChild(nudge); document.body.appendChild(win);
  // the bubble is always there; the dot shows whether the engine answers (green) or not (grey)
  var dot = $("i", "dmc-dot"); btn.appendChild(dot);
  nudge.addEventListener("click", show);
  var seen = false; try { seen = sessionStorage.getItem("dmc-nudge") === "1"; } catch (e) {}
  if (!seen) {
    setTimeout(function () { if (win.hidden) { nudge.hidden = false; setTimeout(nudgeOff, 14000); } }, 3500);
  }
  status().then(function (s) { if (s && s.ok && (s.voice || s.text)) dot.classList.add("on"); });
})();
