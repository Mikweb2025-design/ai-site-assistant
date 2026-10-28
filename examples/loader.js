/* Drop this into your site's main script (or a <script defer> on every page). It reads /site-config.json — written by the
   admin page — and only loads the widget when the assistant is switched on. Nothing is loaded when it is off. */
(function () {
  if (!window.fetch) return;
  fetch("/site-config.json", { cache: "no-cache" })
    .then(function (r) { return r.ok ? r.json() : {}; }).catch(function () { return {}; })
    .then(function (cfg) {
      cfg = cfg || {};
      if (cfg.chat !== true) return;
      // reveal privacy-policy paragraphs that only apply while the features are on
      [].forEach.call(document.querySelectorAll("[data-ais-privacy]"), function (e) { e.hidden = false; });
      [].forEach.call(document.querySelectorAll("[data-ais-booking]"), function (e) { e.hidden = cfg.chatBooking !== true; });
      [].forEach.call(document.querySelectorAll("[data-ais-messages]"), function (e) { e.hidden = cfg.chatMessages !== true; });
      // pages where it must not appear
      var home = /^\/(?:(?:en|de|it)\/)?(?:index\.html)?$/.test(location.pathname);
      if (home && cfg.chatHome === false) return;
      var hide = Array.isArray(cfg.chatHide) ? cfg.chatHide : [];
      for (var i = 0; i < hide.length; i++) {
        var p = String(hide[i]);
        if (p && (location.pathname === p || (p.slice(-1) === "/" && location.pathname.indexOf(p) === 0))) return;
      }
      if (cfg.chatMobile === false && matchMedia("(max-width: 700px)").matches) return;
      window.__aisCfg = {
        owner: cfg.chatOwner || "the site owner",
        name: cfg.chatName || cfg.chatOwner || "Assistant",
        base: cfg.chatBase || "/assistant",
        voice: cfg.chatVoice !== false,
        side: cfg.chatSide === "left" ? "left" : "right",
        color: /^#[0-9a-f]{6}$/i.test(cfg.chatColor || "") ? cfg.chatColor : "",
        photo: cfg.chatPhoto || "",                  // your portrait; empty = the placeholder avatar
        landmarks: cfg.chatLandmarks || null,        // {mouth:{x,y}, eyes:[{x,y},{x,y}]} as fractions of the photo
        assets: "/assets/ais",
        v: cfg.chatV || 1                            // bump in site-config.json to bust the browser cache of the widget files
      };
      var s = document.createElement("script");
      s.src = "/assets/ais/chat.js?v=" + (cfg.chatV || 1);
      s.defer = true;
      document.body.appendChild(s);
    });
})();
