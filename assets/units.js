/* assets/units.js - kg / lb preference shared by the pages that show weights.
   Weights are ALWAYS stored in kilograms in the database. This file only changes what you
   type and see, so switching units never changes saved data.
   Load it in <head> after theme.js:  <script src="assets/units.js"></script>
   Pages listen for the "unitchange" event and redraw their numbers. */
(function () {
  var KEY = "gt-unit",
    KG_PER_LB = 0.45359237;

  var css =
    ".unit-switch{display:inline-flex;border:2px solid var(--ink);border-radius:6px;overflow:hidden}" +
    ".unit-switch button{font:700 .9rem var(--head,Arial,sans-serif);padding:.35rem .95rem;min-height:2.4rem;background:transparent;color:var(--ink);border:0;cursor:pointer}" +
    '.unit-switch button[aria-pressed="true"]{background:var(--ink);color:var(--bg)}' +
    ".unit-switch button:focus-visible{outline:3px solid var(--accent);outline-offset:-3px}";
  var st = document.createElement("style");
  st.textContent = css;
  (document.head || document.documentElement).appendChild(st);

  function get() {
    try {
      return localStorage.getItem(KEY) === "lb" ? "lb" : "kg";
    } catch (e) {
      return "kg";
    }
  }
  function announce() {
    document.dispatchEvent(
      new CustomEvent("unitchange", { detail: { unit: get() } }),
    );
  }
  function set(u) {
    try {
      localStorage.setItem(KEY, u === "lb" ? "lb" : "kg");
    } catch (e) {}
    announce();
  }
  function fromKg(kg, u) {
    var n = parseFloat(kg);
    if (isNaN(n)) return NaN;
    return (u || get()) === "lb" ? n / KG_PER_LB : n;
  }
  function toKg(v, u) {
    var n = parseFloat(v);
    if (isNaN(n)) return NaN;
    return (u || get()) === "lb" ? n * KG_PER_LB : n;
  }
  // kg shown to 2 decimals, lb to 1 decimal; trailing zeros are dropped (225, not 225.0)
  function round(n, u) {
    var f = (u || get()) === "lb" ? 10 : 100;
    return Math.round(n * f) / f;
  }
  function fmt(kg, u) {
    var n = fromKg(kg, u);
    return isNaN(n) ? "" : String(round(n, u));
  }

  function control(el) {
    if (!el) return;
    el.innerHTML =
      '<div class="unit-switch" role="group" aria-label="Weight unit">' +
      '<button type="button" data-u="kg">kg</button><button type="button" data-u="lb">lb</button></div>';
    function sync() {
      var u = get();
      el.querySelectorAll("button").forEach(function (b) {
        b.setAttribute("aria-pressed", b.dataset.u === u ? "true" : "false");
      });
    }
    el.addEventListener("click", function (e) {
      var b = e.target.closest("button[data-u]");
      if (b && b.dataset.u !== get()) set(b.dataset.u);
    });
    document.addEventListener("unitchange", sync);
    sync();
  }

  window.addEventListener("storage", function (e) {
    if (e.key === KEY) announce();
  });

  window.GT = window.GT || {};
  window.GT.units = {
    get: get,
    set: set,
    fromKg: fromKg,
    toKg: toKg,
    round: round,
    fmt: fmt,
    control: control,
    KG_PER_LB: KG_PER_LB,
  };
})();
