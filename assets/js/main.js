/* Nova Booth Studio — main.js
   Progressive enhancement only: the site is fully readable without JS. */
(function () {
  "use strict";

  var reducedMotion = window.matchMedia("(prefers-reduced-motion: reduce)");

  document.documentElement.classList.remove("no-js");

  /* ---------- Shared form helpers ---------- */

  // Stamp every form's timestamp field on load so the server can reject
  // instant (bot) submissions. Uses ms since epoch.
  Array.prototype.forEach.call(document.querySelectorAll("[data-form-time]"), function (node) {
    node.value = String(Date.now());
  });

  function setSubmitting(formEl, busy) {
    var button = formEl.querySelector("[data-submit]");
    if (!button) return;
    if (busy) {
      button.dataset.label = button.dataset.label || button.textContent;
      button.setAttribute("aria-busy", "true");
      button.disabled = true;
      button.textContent = "Sending…";
    } else {
      button.removeAttribute("aria-busy");
      button.disabled = false;
      if (button.dataset.label) button.textContent = button.dataset.label;
    }
  }

  // POST a form to its action via fetch and resolve with a normalised result.
  function deliverForm(formEl) {
    var action = formEl.getAttribute("action") || "/form-handler.php";
    var data = new FormData(formEl);
    return fetch(action, {
      method: "POST",
      body: data,
      headers: { Accept: "application/json" },
      credentials: "same-origin"
    })
      .then(function (response) {
        return response
          .json()
          .catch(function () { return {}; })
          .then(function (json) {
            return { ok: response.ok && json && json.ok === true, status: response.status, json: json || {} };
          });
      })
      .catch(function () {
        return { ok: false, status: 0, json: {} };
      });
  }

  /* ---------- Header scroll state ---------- */
  var header = document.querySelector("[data-header]");
  function onScroll() {
    if (!header) return;
    header.classList.toggle("is-scrolled", window.scrollY > 12);
  }
  window.addEventListener("scroll", onScroll, { passive: true });
  onScroll();

  /* ---------- Mobile navigation ---------- */
  var navToggle = document.querySelector("[data-nav-toggle]");
  var nav = document.getElementById("site-nav");

  function setNav(open) {
    document.body.classList.toggle("nav-open", open);
    if (navToggle) navToggle.setAttribute("aria-expanded", open ? "true" : "false");
  }

  if (navToggle && nav) {
    navToggle.addEventListener("click", function () {
      setNav(!document.body.classList.contains("nav-open"));
    });
    nav.addEventListener("click", function (event) {
      if (event.target.closest("a")) setNav(false);
    });
    document.addEventListener("keydown", function (event) {
      if (event.key === "Escape" && document.body.classList.contains("nav-open")) {
        setNav(false);
        navToggle.focus();
      }
    });
    window.matchMedia("(min-width: 900px)").addEventListener("change", function (mq) {
      if (mq.matches) setNav(false);
    });
  }

  /* ---------- Reveal on scroll ---------- */
  var revealNodes = document.querySelectorAll("[data-reveal]");
  if (revealNodes.length) {
    if (reducedMotion.matches || !("IntersectionObserver" in window)) {
      revealNodes.forEach(function (node) { node.classList.add("is-visible"); });
    } else {
      var observer = new IntersectionObserver(
        function (entries) {
          entries.forEach(function (entry) {
            if (entry.isIntersecting) {
              entry.target.classList.add("is-visible");
              observer.unobserve(entry.target);
            }
          });
        },
        { rootMargin: "0px 0px -8% 0px", threshold: 0.08 }
      );
      revealNodes.forEach(function (node) { observer.observe(node); });
    }
  }

  /* ---------- Hero parallax tilt (desktop pointers only) ---------- */
  var stage = document.querySelector("[data-stage]");
  var finePointer = window.matchMedia("(hover: hover) and (pointer: fine)");
  if (stage && finePointer.matches && !reducedMotion.matches) {
    var layers = stage.querySelectorAll("[data-depth]");
    var targetX = 0;
    var targetY = 0;
    var currentX = 0;
    var currentY = 0;
    var rafId = null;

    function animate() {
      currentX += (targetX - currentX) * 0.06;
      currentY += (targetY - currentY) * 0.06;
      layers.forEach(function (layer) {
        var depth = parseFloat(layer.getAttribute("data-depth")) || 0;
        layer.style.transform =
          "translate3d(" + (currentX * depth).toFixed(2) + "px, " + (currentY * depth).toFixed(2) + "px, 0)";
      });
      if (Math.abs(targetX - currentX) > 0.001 || Math.abs(targetY - currentY) > 0.001) {
        rafId = requestAnimationFrame(animate);
      } else {
        rafId = null;
      }
    }

    var hero = stage.closest(".hero") || stage;
    hero.addEventListener("pointermove", function (event) {
      var rect = hero.getBoundingClientRect();
      targetX = (event.clientX - rect.left) / rect.width - 0.5;
      targetY = (event.clientY - rect.top) / rect.height - 0.5;
      if (rafId === null) rafId = requestAnimationFrame(animate);
    });
    hero.addEventListener("pointerleave", function () {
      targetX = 0;
      targetY = 0;
      if (rafId === null) rafId = requestAnimationFrame(animate);
    });
  }

  /* ---------- Footer year ---------- */
  var yearNode = document.querySelector("[data-year]");
  if (yearNode) yearNode.textContent = String(new Date().getFullYear());

  /* ---------- Availability form ---------- */
  var form = document.getElementById("availability-form");
  if (form) initAvailabilityForm(form);

  function initAvailabilityForm(formEl) {
    var successPanel = document.getElementById("form-success");
    var summaryList = document.getElementById("success-summary");
    var alertBox = formEl.querySelector("[data-form-alert]");
    var copyButton = document.getElementById("copy-summary");
    var copyStatus = document.getElementById("copy-status");
    var fallbackBox = formEl.querySelector("[data-form-fallback]");
    var lastSummaryText = "";

    // Earliest selectable event date is today.
    var dateInput = formEl.querySelector("#event-date");
    if (dateInput) {
      var today = new Date();
      var iso =
        today.getFullYear() +
        "-" +
        String(today.getMonth() + 1).padStart(2, "0") +
        "-" +
        String(today.getDate()).padStart(2, "0");
      dateInput.min = iso;
    }

    var validators = {
      name: function (v) { return v.trim().length >= 2 || "Please enter your name."; },
      email: function (v) {
        return /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(v.trim()) || "Please enter a valid email address.";
      },
      phone: function (v) {
        var digits = v.replace(/\D/g, "");
        return (digits.length >= 10 && digits.length <= 11) || "Please enter a 10-digit phone number.";
      },
      "event-date": function (v) {
        if (!v) return "Please choose your event date.";
        var chosen = new Date(v + "T23:59:59");
        return chosen >= new Date() || "Please choose a future date.";
      },
      "event-type": function (v) { return v !== "" || "Please choose an event type."; },
      venue: function (v) { return v.trim().length >= 2 || "Please tell us the venue or city."; },
      experience: function (v) { return v !== "" || "Please choose an experience."; },
      consent: function (v) { return v === "on" || "Please confirm you're happy to be contacted."; }
    };

    function fieldWrap(name) {
      var input = formEl.querySelector('[name="' + name + '"]');
      return input ? input.closest(".field") : null;
    }

    function setError(name, message) {
      var wrap = fieldWrap(name);
      if (!wrap) return;
      var errorNode = wrap.querySelector(".field-error");
      if (message) {
        wrap.classList.add("has-error");
        if (errorNode) errorNode.textContent = message;
        wrap.querySelectorAll("input, select, textarea").forEach(function (el) {
          el.setAttribute("aria-invalid", "true");
        });
      } else {
        wrap.classList.remove("has-error");
        wrap.querySelectorAll("input, select, textarea").forEach(function (el) {
          el.removeAttribute("aria-invalid");
        });
      }
    }

    function getValue(name) {
      var field = formEl.elements[name];
      if (!field) return "";
      if (field instanceof RadioNodeList) return field.value || "";
      if (field.type === "checkbox") return field.checked ? "on" : "";
      return field.value || "";
    }

    formEl.addEventListener("submit", function (event) {
      event.preventDefault();

      var firstInvalid = null;
      Object.keys(validators).forEach(function (name) {
        var result = validators[name](getValue(name));
        if (result !== true) {
          setError(name, result);
          if (!firstInvalid) firstInvalid = name;
        } else {
          setError(name, null);
        }
      });

      if (firstInvalid) {
        if (alertBox) {
          alertBox.textContent = "A few details need attention before we can prepare your request.";
          alertBox.classList.add("is-visible");
        }
        var wrap = fieldWrap(firstInvalid);
        var focusTarget = wrap && wrap.querySelector("input, select, textarea");
        if (focusTarget) focusTarget.focus();
        return;
      }

      if (alertBox) alertBox.classList.remove("is-visible");
      if (fallbackBox) fallbackBox.hidden = true;

      // Only reveal the success screen once the server confirms delivery.
      var data = collectData();
      setSubmitting(formEl, true);
      deliverForm(formEl).then(function (result) {
        if (result.ok) {
          renderSuccess(data);
          return;
        }
        setSubmitting(formEl, false);
        if (result.json && result.json.fields) {
          Object.keys(result.json.fields).forEach(function (name) {
            setError(name, result.json.fields[name]);
          });
        }
        if (fallbackBox) {
          fallbackBox.hidden = false;
          fallbackBox.setAttribute("tabindex", "-1");
          fallbackBox.scrollIntoView({ behavior: reducedMotion.matches ? "auto" : "smooth", block: "center" });
        }
      });
    });

    // Clear a field's error as soon as it is corrected.
    formEl.addEventListener("input", function (event) {
      var name = event.target.name;
      if (name && validators[name] && validators[name](getValue(name)) === true) {
        setError(name, null);
      }
    });
    formEl.addEventListener("change", function (event) {
      var name = event.target.name;
      if (name && validators[name] && validators[name](getValue(name)) === true) {
        setError(name, null);
      }
    });

    function collectData() {
      var labels = [
        ["name", "Name"],
        ["email", "Email"],
        ["phone", "Phone"],
        ["event-date", "Event date"],
        ["start-time", "Start time"],
        ["event-type", "Event type"],
        ["venue", "Venue / city"],
        ["setting", "Indoor or outdoor"],
        ["guests", "Expected guests"],
        ["experience", "Experience"],
        ["duration", "Duration"],
        ["prints", "Prints required"],
        ["branding", "Backdrop / branding"],
        ["contact-method", "Preferred contact"],
        ["message", "Message"]
      ];
      var entries = [];
      labels.forEach(function (pair) {
        var value = getValue(pair[0]).trim();
        if (value) entries.push({ label: pair[1], value: value });
      });
      return entries;
    }

    function renderSuccess(entries) {
      if (summaryList) {
        summaryList.innerHTML = "";
        entries.forEach(function (entry) {
          var row = document.createElement("div");
          var dt = document.createElement("dt");
          dt.textContent = entry.label;
          var dd = document.createElement("dd");
          dd.textContent = entry.value;
          row.appendChild(dt);
          row.appendChild(dd);
          summaryList.appendChild(row);
        });
      }
      lastSummaryText =
        "Availability request — Nova Booth Studio\n" +
        entries
          .map(function (entry) { return entry.label + ": " + entry.value; })
          .join("\n");

      formEl.hidden = true;
      if (successPanel) {
        successPanel.hidden = false;
        var heading = successPanel.querySelector("h2");
        if (heading) {
          heading.setAttribute("tabindex", "-1");
          heading.focus();
        }
        successPanel.scrollIntoView({
          behavior: reducedMotion.matches ? "auto" : "smooth",
          block: "start"
        });
      }
    }

    if (copyButton) {
      copyButton.addEventListener("click", function () {
        if (!lastSummaryText || !navigator.clipboard) return;
        navigator.clipboard.writeText(lastSummaryText).then(
          function () {
            if (copyStatus) copyStatus.textContent = "Copied — paste it into a text or email to us.";
          },
          function () {
            if (copyStatus) copyStatus.textContent = "Copy failed — you can select the summary above instead.";
          }
        );
      });
    }
  }
})();
