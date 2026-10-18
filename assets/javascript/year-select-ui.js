/* Year filter custom dropdown (Kiosk Polish v113)
   Native <select> popup lists (the "Any / 1800 / 1900 ..." list that opens
   when you click the From Year / To Year fields) are rendered by the OS,
   not the page -- on Windows/Chrome that means a plain white list with a
   flat blue highlight, and there is no CSS that can round its corners, add
   a shadow, or theme its colors. To make that list actually look like the
   rest of the kiosk, this replaces it with a real styled dropdown built
   from HTML/CSS, while leaving the original <select> in the page as the
   single source of truth for its value.

   How it stays in sync with filters.js (which reads/writes
   #year-from-select / #year-to-select directly and never knows this UI
   exists):
   - Selecting a custom option sets select.value and dispatches a real
     "change" event, so filters.js's existing change listeners fire exactly
     as if the native control had been used.
   - filters.js also sets .value directly in a few places (Clear All, the
     "Year: X - Y" active-filter chip's remove button, and the from/to
     swap-if-reversed correction) without firing a change event. Those
     three spots call window.YearSelectUI.syncAll() right after, which is
     the only coupling in the other direction.
   - If this script fails to load or throws, the native <select> is simply
     left alone and fully usable -- it's only hidden after the custom UI
     for it has been built successfully. */
(function () {
  "use strict";

  function buildCustomSelect(select) {
    if (!select || select.dataset.customSelectReady === "1") return null;

    var wrap = document.createElement("div");
    wrap.className = "custom-select-wrap";

    var trigger = document.createElement("button");
    trigger.type = "button";
    trigger.className = "custom-select-trigger";
    trigger.setAttribute("aria-haspopup", "listbox");
    trigger.setAttribute("aria-expanded", "false");

    var valueSpan = document.createElement("span");
    valueSpan.className = "custom-select-value";
    trigger.appendChild(valueSpan);

    var chevron = document.createElementNS("http://www.w3.org/2000/svg", "svg");
    chevron.setAttribute("class", "custom-select-chevron");
    chevron.setAttribute("viewBox", "0 0 24 24");
    chevron.setAttribute("aria-hidden", "true");
    var poly = document.createElementNS("http://www.w3.org/2000/svg", "polyline");
    poly.setAttribute("points", "6 9 12 15 18 9");
    chevron.appendChild(poly);
    trigger.appendChild(chevron);

    var panel = document.createElement("ul");
    panel.className = "custom-select-panel";
    panel.setAttribute("role", "listbox");
    if (select.id) panel.setAttribute("aria-label", select.id);

    var optionEls = Array.prototype.map.call(select.options, function (opt) {
      var li = document.createElement("li");
      li.className = "custom-select-option";
      li.setAttribute("role", "option");
      li.tabIndex = -1;
      li.dataset.value = opt.value;
      li.textContent = opt.textContent;
      panel.appendChild(li);
      return li;
    });

    function labelFor(value) {
      for (var i = 0; i < select.options.length; i++) {
        if (select.options[i].value === value) return select.options[i].textContent;
      }
      return optionEls.length ? optionEls[0].textContent : "";
    }

    function syncFromSelect() {
      var val = select.value;
      valueSpan.textContent = labelFor(val);
      optionEls.forEach(function (li) {
        var isSel = li.dataset.value === val;
        li.classList.toggle("is-selected", isSel);
        li.setAttribute("aria-selected", isSel ? "true" : "false");
      });
    }

    function isOpen() {
      return wrap.classList.contains("is-open");
    }

    function closePanel() {
      if (!isOpen()) return;
      wrap.classList.remove("is-open");
      trigger.setAttribute("aria-expanded", "false");
    }

    function focusOption(li) {
      if (!li) return;
      optionEls.forEach(function (o) { o.tabIndex = -1; });
      li.tabIndex = 0;
      li.focus();
    }

    function openPanel() {
      if (isOpen()) return;
      document.querySelectorAll(".custom-select-wrap.is-open").forEach(function (w) {
        if (w !== wrap) {
          w.classList.remove("is-open");
          var t = w.querySelector(".custom-select-trigger");
          if (t) t.setAttribute("aria-expanded", "false");
        }
      });
      wrap.classList.add("is-open");
      trigger.setAttribute("aria-expanded", "true");
      var selected = optionEls.filter(function (o) { return o.classList.contains("is-selected"); })[0];
      focusOption(selected || optionEls[0]);
    }

    function choose(li) {
      select.value = li.dataset.value;
      syncFromSelect();
      closePanel();
      trigger.focus();
      select.dispatchEvent(new Event("change", { bubbles: true }));
    }

    trigger.addEventListener("click", function () {
      if (isOpen()) closePanel(); else openPanel();
    });

    trigger.addEventListener("keydown", function (e) {
      if (e.key === "ArrowDown" || e.key === "Enter" || e.key === " ") {
        e.preventDefault();
        openPanel();
      }
    });

    optionEls.forEach(function (li, idx) {
      li.addEventListener("click", function () { choose(li); });
      li.addEventListener("keydown", function (e) {
        if (e.key === "Enter" || e.key === " ") {
          e.preventDefault();
          choose(li);
        } else if (e.key === "Escape") {
          e.preventDefault();
          closePanel();
          trigger.focus();
        } else if (e.key === "ArrowDown") {
          e.preventDefault();
          focusOption(optionEls[Math.min(idx + 1, optionEls.length - 1)]);
        } else if (e.key === "ArrowUp") {
          e.preventDefault();
          focusOption(optionEls[Math.max(idx - 1, 0)]);
        } else if (e.key === "Home") {
          e.preventDefault();
          focusOption(optionEls[0]);
        } else if (e.key === "End") {
          e.preventDefault();
          focusOption(optionEls[optionEls.length - 1]);
        }
      });
    });

    document.addEventListener("click", function (e) {
      if (!wrap.contains(e.target)) closePanel();
    });

    document.addEventListener("keydown", function (e) {
      if (e.key === "Escape" && isOpen()) {
        closePanel();
        trigger.focus();
      }
    });

    select.insertAdjacentElement("afterend", wrap);
    wrap.appendChild(trigger);
    wrap.appendChild(panel);

    select.classList.add("custom-select-native-hidden");
    select.setAttribute("aria-hidden", "true");
    select.tabIndex = -1;
    select.dataset.customSelectReady = "1";

    syncFromSelect();

    return { syncFromSelect: syncFromSelect };
  }

  function init() {
    var selects = document.querySelectorAll(".year-range-select");
    var instances = [];
    selects.forEach(function (sel) {
      var inst = buildCustomSelect(sel);
      if (inst) instances.push(inst);
    });

    window.YearSelectUI = {
      syncAll: function () {
        instances.forEach(function (inst) { inst.syncFromSelect(); });
      }
    };
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
  } else {
    init();
  }
})();
