/* Voice recorder for /admin/chatbot.php?t=voice: records from the microphone and puts the recording into the upload field. */
(function () {
  "use strict";
  var rec = document.getElementById("vrec"), stop = document.getElementById("vstop"), file = document.getElementById("vfile"),
      play = document.getElementById("vplay"), time = document.getElementById("vtime");
  if (!rec || !navigator.mediaDevices || !window.MediaRecorder) { if (rec) { rec.disabled = true; rec.title = "Recording is not supported by this browser: upload a file instead"; } return; }
  var mr = null, chunks = [], t0 = 0, tick = 0, stream = null;
  function mime() {
    var c = ["audio/webm;codecs=opus", "audio/webm", "audio/mp4", "audio/ogg;codecs=opus"];
    for (var i = 0; i < c.length; i++) { if (MediaRecorder.isTypeSupported(c[i])) return c[i]; }
    return "";
  }
  rec.addEventListener("click", function () {
    navigator.mediaDevices.getUserMedia({ audio: { echoCancellation: false, noiseSuppression: true, autoGainControl: false, channelCount: 1 } }).then(function (s) {
      stream = s; chunks = []; var m = mime(); mr = m ? new MediaRecorder(s, { mimeType: m }) : new MediaRecorder(s);
      mr.ondataavailable = function (e) { if (e.data && e.data.size) chunks.push(e.data); };
      mr.onstop = function () {
        stream.getTracks().forEach(function (t) { t.stop(); });
        clearInterval(tick);
        var type = mr.mimeType || "audio/webm", ext = type.indexOf("mp4") >= 0 ? "m4a" : type.indexOf("ogg") >= 0 ? "ogg" : "webm";
        var blob = new Blob(chunks, { type: type });
        try { var dt = new DataTransfer(); dt.items.add(new File([blob], "recording." + ext, { type: type })); file.files = dt.files; } catch (e) { time.textContent = "Could not attach the recording: upload a file instead."; return; }
        play.src = URL.createObjectURL(blob); play.hidden = false;
        time.textContent = "Recorded " + Math.round((Date.now() - t0) / 1000) + " s — listen, then send.";
        rec.disabled = false; stop.disabled = true;
      };
      mr.start(); t0 = Date.now(); rec.disabled = true; stop.disabled = false; play.hidden = true;
      tick = setInterval(function () { var s = Math.round((Date.now() - t0) / 1000); time.textContent = "Recording… " + s + " s" + (s >= 20 ? " (enough, you can stop)" : ""); if (s >= 60) stop.click(); }, 500);
    }).catch(function () { time.textContent = "Microphone not available (allow it in the browser)."; });
  });
  stop.addEventListener("click", function () { if (mr && mr.state !== "inactive") mr.stop(); });
})();
