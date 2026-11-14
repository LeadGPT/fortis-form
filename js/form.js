(function () {
  "use strict";

  var card = document.querySelector(".card");
  var form = document.getElementById("leadForm");
  var steps = Array.prototype.slice.call(form.querySelectorAll(".step"));
  var stemPath = document.getElementById("stemPath");
  var nodes = Array.prototype.slice.call(document.querySelectorAll(".node"));

  var order = ["0", "1", "2", "3"];
  var current = "0";

  var NODE_TOP = { "0": 0, "1": 25, "2": 50, "3": 75, "end": 100 };

  // ---- Session id, for correlating progress pings with the final submission ----
  var sessionId = (function () {
    if (window.crypto && crypto.randomUUID) return crypto.randomUUID();
    return "s-" + Date.now() + "-" + Math.random().toString(16).slice(2);
  })();

  var STEP_NAMES = { "1": "details", "2": "breakdown", "3": "objectives" };

  function collectFieldsSnapshot() {
    var interests = Array.prototype.slice
      .call(form.querySelectorAll('input[name="interest"]:checked'))
      .map(function (i) { return i.value; })
      .filter(function (v) { return v !== "Other"; });
    if (otherBox && otherBox.checked && otherText.value.trim()) {
      interests.push("Other: " + otherText.value.trim());
    }
    return {
      name: form.name.value.trim(),
      email: form.email.value.trim(),
      phone: form.phone.value.trim() ? ("+44 " + normalizeUkPhone(form.phone.value)) : "",
      city: form.city.value.trim(),
      income: form.income.value,
      assets: (form.querySelector('input[name="assets"]:checked') || {}).value || "",
      pensionsCount: form.pensionsCount.value,
      pensionsValue: form.pensionsValue.value,
      isasValue: form.isasValue.value,
      cashValue: form.cashValue.value,
      otherValue: form.otherValue.value,
      interests: interests,
      notes: form.notes.value.trim()
    };
  }

  function sendProgress(stepName, useBeacon) {
    var body = JSON.stringify({ sessionId: sessionId, step: stepName, fields: collectFieldsSnapshot() });
    try {
      if (useBeacon && navigator.sendBeacon) {
        navigator.sendBeacon("php/progress.php", new Blob([body], { type: "application/json" }));
      } else {
        fetch("php/progress.php", {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: body,
          keepalive: true
        });
      }
    } catch (e) {
      // Progress tracking should never block or break the actual form.
    }
  }

  // ---- Debounced autosave: fires ~700ms after the person stops typing/selecting ----
  var autosaveTimer = null;
  function scheduleAutosave() {
    var stepName = STEP_NAMES[current];
    if (!stepName) return; // nothing to save on the welcome/ending screens
    clearTimeout(autosaveTimer);
    autosaveTimer = setTimeout(function () {
      sendProgress(stepName, false);
    }, 700);
  }

  // ---- Immediate flush the instant the person leaves, switches tab, or closes the page ----
  function flushProgress() {
    var stepName = STEP_NAMES[current];
    if (!stepName) return;
    clearTimeout(autosaveTimer);
    sendProgress(stepName, true);
  }
  document.addEventListener("visibilitychange", function () {
    if (document.visibilityState === "hidden") flushProgress();
  });
  window.addEventListener("pagehide", flushProgress);

  var STEP_LABELS = { "1": "Step 1 of 3", "2": "Step 2 of 3", "3": "Step 3 of 3" };
  var progressLabel = document.getElementById("progressLabel");

  function showStep(key) {
    steps.forEach(function (s) {
      s.classList.toggle("active", s.getAttribute("data-step") === key);
    });
    current = key;
    updateStem(key);
    progressLabel.textContent = STEP_LABELS[key] || "";
    window.scrollTo({ top: 0, behavior: "smooth" });
  }

  function updateStem(key) {
    var pct = NODE_TOP[key] !== undefined ? NODE_TOP[key] : (key === "qualified" || key === "notqualified" ? 100 : 0);
    var h = 400 * (pct / 100);
    stemPath.setAttribute("d", "M4,0 L4," + h);
    stemPath.classList.add("grown");
    nodes.forEach(function (n) {
      var step = n.getAttribute("data-step");
      n.classList.toggle("on", NODE_TOP[step] !== undefined && NODE_TOP[step] <= pct);
    });
  }

  function goBack() {
    var idx = order.indexOf(current);
    if (idx > 0) showStep(order[idx - 1]);
  }
  form.querySelectorAll("[data-back]").forEach(function (btn) {
    btn.addEventListener("click", goBack);
  });

  // ---- Step 0 -> Step 1 ----
  form.querySelector('[data-step="0"] [data-next]').addEventListener("click", function () {
    showStep("1");
    sendProgress("details", false);
  });

  // ---- Step 1 -> qualification branch ----
  form.querySelector('[data-step="1"] [data-next]').addEventListener("click", function () {
    if (!validateStep1()) return;
    var assets = (form.querySelector('input[name="assets"]:checked') || {}).value || "";
    if (assets === "Under £100,000") {
      // Not enough to qualify: submit immediately, no breakdown/objectives needed.
      submitForm(false);
    } else {
      showStep("2");
      sendProgress("breakdown", false);
      updateGoalBar();
    }
  });

  // ---- Step 2 -> Step 3 (breakdown sum must match the declared bracket) ----
  form.querySelector('[data-step="2"] [data-next]').addEventListener("click", function () {
    if (!validateBreakdown()) return;
    showStep("3");
    sendProgress("objectives", false);
  });

  // ---- Running total + goal bar on the breakdown step ----
  var breakdownInputs = Array.prototype.slice.call(form.querySelectorAll("[data-breakdown-input]"));
  var runningTotalEl = document.getElementById("runningTotal");
  var runningTotalLabel = document.getElementById("runningTotalLabel");
  var goalFill = document.getElementById("goalFill");

  function currentSum() {
    return breakdownInputs.reduce(function (sum, input) {
      var v = parseFloat(input.value);
      return sum + (isNaN(v) ? 0 : v);
    }, 0);
  }
  function formatGBP(n) {
    return "£" + Math.round(n).toLocaleString("en-GB");
  }
  function updateGoalBar() {
    var threshold = requiredThreshold();
    var sum = currentSum();
    var pct = threshold > 0 ? Math.min(100, (sum / threshold) * 100) : 0;
    var met = threshold > 0 && sum >= threshold;
    goalFill.style.width = pct + "%";
    goalFill.classList.toggle("met", met);
    runningTotalLabel.classList.toggle("met", met);
    runningTotalEl.textContent = formatGBP(sum);
  }
  breakdownInputs.forEach(function (input) {
    input.addEventListener("input", updateGoalBar);
  });

  function requiredThreshold() {
    var assets = (form.querySelector('input[name="assets"]:checked') || {}).value || "";
    if (assets === "Over £250,000") return 250000;
    if (assets === "£200,000 to £250,000") return 200000;
    if (assets === "£100,000 to £200,000") return 100000;
    return 0;
  }

  // Same underlying form for everyone £100k+, but tracked as three separate
  // tiers internally for reporting: standard / premium / top.
  function qualificationTier(assetsValue) {
    if (assetsValue === "Under £100,000") return "not_qualified";
    if (assetsValue === "£100,000 to £200,000") return "standard";
    if (assetsValue === "£200,000 to £250,000") return "premium";
    return "top"; // Over £250,000
  }

  function validateBreakdown() {
    var threshold = requiredThreshold();
    var sum = currentSum();
    var errEl = form.querySelector('[data-error-for="breakdown"]');
    if (sum < threshold) {
      errEl.textContent = "This doesn't quite add up to the range you selected earlier (" + formatGBP(threshold) + "+). Mind double-checking the figures?";
      return false;
    }
    errEl.textContent = "";
    return true;
  }

  // ---- Phone field: strip a leading 0 live, since +44 is already shown ----
  var phoneInput = document.getElementById("phone");
  phoneInput.addEventListener("input", function () {
    if (phoneInput.value.charAt(0) === "0") {
      phoneInput.value = phoneInput.value.slice(1);
    }
  });

  // ---- "Other" text reveal ----
  var otherBox = document.getElementById("interestOtherBox");
  var otherText = document.getElementById("interestOtherText");
  otherBox.addEventListener("change", function () {
    otherText.classList.toggle("hidden", !otherBox.checked);
    if (!otherBox.checked) otherText.value = "";
  });

  // ---- UK phone helpers ----
  function normalizeUkPhone(raw) {
    var digits = (raw || "").replace(/\D/g, "");
    if (digits.indexOf("44") === 0) digits = digits.slice(2);
    if (digits.indexOf("0") === 0) digits = digits.slice(1);
    return digits;
  }
  function isValidUkPhone(raw) {
    var digits = normalizeUkPhone(raw);
    return digits.length === 9 || digits.length === 10;
  }

  // ---- Validation ----
  function setError(field, message) {
    var el = form.querySelector('[data-error-for="' + field + '"]');
    var input = form.querySelector('[name="' + field + '"]');
    if (el) el.textContent = message || "";
    if (input) input.classList.toggle("invalid", !!message);
  }

  function validateStep1() {
    var ok = true;
    var name = form.name.value.trim();
    var email = form.email.value.trim();
    var phone = form.phone.value.trim();
    var city = form.city.value.trim();
    var income = form.income.value;
    var assets = form.querySelector('input[name="assets"]:checked');

    if (!name) { setError("name", "Let us know your full name."); ok = false; }
    else setError("name", "");

    var emailRe = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    if (!email || !emailRe.test(email)) { setError("email", "That doesn't look like a valid email address."); ok = false; }
    else setError("email", "");

    if (!phone || !isValidUkPhone(phone)) { setError("phone", "Enter a valid UK number, for example 7700 900000."); ok = false; }
    else setError("phone", "");

    if (!city) { setError("city", "Let us know your city or town."); ok = false; }
    else setError("city", "");

    if (!income) { setError("income", "Pick the range that's closest."); ok = false; }
    else setError("income", "");

    if (!assets) { setError("assets", "Pick the range that's closest."); ok = false; }
    else setError("assets", "");

    return ok;
  }

  // ---- Submission ----
  form.addEventListener("submit", function (e) {
    e.preventDefault();
    if (!validateBreakdown()) { showStep("2"); return; }
    submitForm(true);
  });

  // ---- Field-level autosave: capture answers as they're given, not just at step transitions ----
  var autosaveFields = form.querySelectorAll(
    'input[name="name"], input[name="email"], input[name="phone"], input[name="city"], ' +
    'select[name="income"], input[name="assets"], input[name="pensionsCount"], ' +
    'input[name="pensionsValue"], input[name="isasValue"], input[name="cashValue"], input[name="otherValue"], ' +
    'input[name="interest"], #interestOtherText, textarea[name="notes"]'
  );
  autosaveFields.forEach(function (el) {
    var evt = (el.tagName === "SELECT" || el.type === "radio" || el.type === "checkbox") ? "change" : "input";
    el.addEventListener(evt, scheduleAutosave);
  });

  function collectPayload(includeBreakdown) {
    var interests = Array.prototype.slice
      .call(form.querySelectorAll('input[name="interest"]:checked'))
      .map(function (i) { return i.value; })
      .filter(function (v) { return v !== "Other"; });

    if (otherBox.checked && otherText.value.trim()) {
      interests.push("Other: " + otherText.value.trim());
    }

    var payload = {
      sessionId: sessionId,
      name: form.name.value.trim(),
      email: form.email.value.trim(),
      phone: "+44 " + normalizeUkPhone(form.phone.value),
      city: form.city.value.trim(),
      income: form.income.value,
      assets: (form.querySelector('input[name="assets"]:checked') || {}).value || "",
      submittedAt: new Date().toISOString()
    };
    payload.tier = qualificationTier(payload.assets);

    if (includeBreakdown) {
      payload.pensionsCount = form.pensionsCount.value;
      payload.pensionsValue = form.pensionsValue.value;
      payload.isasValue = form.isasValue.value;
      payload.cashValue = form.cashValue.value;
      payload.otherValue = form.otherValue.value;
      payload.interests = interests;
      payload.notes = form.notes.value.trim();
    }

    payload.website = form.website.value; // honeypot
    return payload;
  }

  function qualifies(assetsValue) {
    return assetsValue !== "Under £100,000";
  }

  function submitForm(includeBreakdown) {
    var payload = collectPayload(includeBreakdown);
    var qualified = qualifies(payload.assets);

    card.classList.add("submitting");

    fetch("php/submit.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(payload)
    })
      .then(function (res) { return res.json().catch(function () { return { success: true }; }); })
      .finally(function () {
        card.classList.remove("submitting");
        showStep(qualified ? "qualified" : "notqualified");
      });
  }
})();
