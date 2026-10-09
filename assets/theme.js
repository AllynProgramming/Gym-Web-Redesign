/* assets/theme.js - light/dark toggle shared by every GymTrack page.
   Load it in <head>, right after the viewport meta tag:
   <script src="assets/theme.js"></script>
   The choice is saved in localStorage ("gt-theme") and applies to all pages. */
(function () {
  var KEY = "gt-theme",
    root = document.documentElement;

  var LIGHT =
    "--bg:#ECEEEA;--surface:#F7F8F5;--ink:#1D2024;--muted:#5B6168;--rule:#C9CEC9;--accent:#1F4FCC;--on-accent:#fff;--focus:#1F4FCC;--err:#B3261E;--red:#D3302B;--blue:#1F4FCC;--green:#1F8A4D;";
  var DARK =
    "--bg:#16181B;--surface:#1E2125;--ink:#E8EAE6;--muted:#9AA0A6;--rule:#34383D;--accent:#6C93FF;--on-accent:#0F1216;--focus:#6C93FF;--err:#FF8A80;--red:#E5524C;--blue:#6C93FF;--green:#3DB070;";

  var css =
    ":root{color-scheme:light dark}" +
    'html[data-theme="light"]:root{color-scheme:light;' +
    LIGHT +
    "}" +
    'html[data-theme="dark"]:root{color-scheme:dark;' +
    DARK +
    "}" +
    ".theme-toggle{display:inline-flex;align-items:center;gap:.55rem;background:none;border:0;padding:.3rem 0;margin:0;cursor:pointer;color:var(--ink);font:600 .95rem var(--head,Arial,sans-serif)}" +
    ".theme-toggle:hover{text-decoration:underline;text-underline-offset:4px}" +
    ".theme-toggle .tt-track{position:relative;width:2.3rem;height:1.3rem;border-radius:1rem;background:var(--rule);box-shadow:inset 0 0 0 1px var(--muted);flex:none}" +
    ".theme-toggle .tt-knob{position:absolute;top:.2rem;left:.2rem;width:.9rem;height:.9rem;border-radius:50%;background:var(--ink)}" +
    '.theme-toggle[aria-pressed="true"] .tt-track{background:var(--accent);box-shadow:none}' +
    '.theme-toggle[aria-pressed="true"] .tt-knob{left:1.1rem;background:var(--on-accent)}' +
    "@media (prefers-reduced-motion:no-preference){.theme-toggle .tt-knob{transition:left .15s ease}}";
  var st = document.createElement("style");
  st.textContent = css;
  (document.head || root).appendChild(st);

  function stored() {
    try {
      var v = localStorage.getItem(KEY);
      return v === "light" || v === "dark" ? v : null;
    } catch (e) {
      return null;
    }
  }
  function systemDark() {
    return !!(
      window.matchMedia && matchMedia("(prefers-color-scheme: dark)").matches
    );
  }
  function current() {
    return root.getAttribute("data-theme") || (systemDark() ? "dark" : "light");
  }
  function apply(t) {
    if (t) root.setAttribute("data-theme", t);
    else root.removeAttribute("data-theme");
  }

  var saved = stored();
  if (saved) apply(saved); // runs before the page paints, so there is no flash

  var btn = null;
  function sync() {
    if (btn)
      btn.setAttribute("aria-pressed", current() === "dark" ? "true" : "false");
  }
  function announce() {
    sync();
    document.dispatchEvent(
      new CustomEvent("themechange", { detail: { theme: current() } }),
    );
  }

  document.addEventListener("DOMContentLoaded", function () {
    btn = document.createElement("button");
    btn.type = "button";
    btn.className = "theme-toggle";
    btn.setAttribute("aria-pressed", "false");
    btn.innerHTML =
      '<span class="tt-track" aria-hidden="true"><span class="tt-knob"></span></span><span>Dark mode</span>';
    btn.addEventListener("click", function () {
      var next = current() === "dark" ? "light" : "dark";
      apply(next);
      try {
        localStorage.setItem(KEY, next);
      } catch (e) {}
      announce();
    });

    var nav = document.querySelector("header nav");
    var header = document.querySelector("header");
    if (nav) nav.appendChild(btn);
    else if (header) {
      header.style.display = "flex";
      header.style.justifyContent = "space-between";
      header.style.alignItems = "center";
      header.appendChild(btn);
    } else {
      btn.style.cssText += ";position:fixed;top:.8rem;right:1rem;z-index:20";
      document.body.appendChild(btn);
    }
    sync();
  });

  // Follow other tabs, and the device setting while no choice has been saved.
  window.addEventListener("storage", function (e) {
    if (e.key === KEY) {
      apply(stored());
      announce();
    }
  });
  if (window.matchMedia) {
    var mq = matchMedia("(prefers-color-scheme: dark)");
    var onSys = function () {
      if (!stored()) announce();
    };
    if (mq.addEventListener) mq.addEventListener("change", onSys);
    else if (mq.addListener) mq.addListener(onSys);
  }
})();
