(function () {
  const config = window.ProcyonImagePackerAdmin || {};
  const root = document.querySelector("[data-procyon-image-packer-root]");
  if (!root || !config.root || !config.nonce) return;

  const messagesEl = root.querySelector("[data-messages]");
  const progressBar = root.querySelector("[data-progress-bar]");
  const buttons = {
    start: root.querySelector('[data-action="start"]'),
    pause: root.querySelector('[data-action="pause"]'),
    resume: root.querySelector('[data-action="resume"]'),
    refresh: root.querySelector('[data-action="refresh"]'),
  };

  let busy = false;
  let pollTimer = null;
  const i18n = config.i18n || {};
  const statusLabels = i18n.statusLabels || {
    idle: "Idle",
    queued: "Queued",
    running: "Running",
    paused: "Paused",
    completed: "Completed",
    failed: "Failed",
  };
  const modeLabels = i18n.modeLabels || {
    full: "Full batch",
    dirty: "Dirty queue",
  };

  function statusLabel(status) {
    return statusLabels[status] || status || "";
  }

  function modeLabel(mode) {
    return modeLabels[mode] || mode || "";
  }

  function setField(name, value) {
    const node = root.querySelector(`[data-field="${name}"]`);
    if (!node) return;
    node.textContent = value == null || value === "" ? "-" : String(value);
  }

  function renderMessages(payload) {
    if (!messagesEl) return;

    const issues = payload.issues || {};
    const errors = Array.isArray(issues.errors) ? issues.errors : [];
    const warnings = Array.isArray(issues.warnings) ? issues.warnings : [];
    const notes = Array.isArray(payload.job && payload.job.notes) ? payload.job.notes : [];
    let html = "";

    if (errors.length) {
      html += '<div class="notice notice-error inline"><ul>';
      errors.forEach((item) => {
        html += `<li>${escapeHtml(item)}</li>`;
      });
      html += "</ul></div>";
    }

    if (warnings.length) {
      html += '<div class="notice notice-warning inline"><ul>';
      warnings.forEach((item) => {
        html += `<li>${escapeHtml(item)}</li>`;
      });
      html += "</ul></div>";
    }

    if (notes.length) {
      html += '<div class="notice notice-info inline"><ul>';
      notes.forEach((item) => {
        html += `<li>${escapeHtml(item)}</li>`;
      });
      html += "</ul></div>";
    }

    if (!html) {
      html = `<div class="notice notice-success inline"><p>${escapeHtml(i18n.environmentReady || "Environment and settings are ready to work.")}</p></div>`;
    }

    messagesEl.innerHTML = html;
  }

  function escapeHtml(value) {
    return String(value)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;")
      .replace(/'/g, "&#039;");
  }

  function render(payload) {
    if (!payload || !payload.job) return;

    const job = payload.job;
    const total = Number(job.total || 0);
    const processed = Number(job.processed || 0);
    const success = Number(job.success || 0);
    const failed = Number(job.failed || 0);
    const progressPercent = Number(job.progress_percent || 0);

    setField("status_label", statusLabel(job.status));
    setField("progress_text", `${processed} / ${total}`);
    setField("optimized_text", `${success} / ${total}`);
    setField("failed", failed);
    setField("job_id", job.job_id || "");
    setField("mode", modeLabel(job.mode || "full"));
    setField("attachments_scanned", job.attachments_scanned || 0);
    setField("attachments_queued", job.attachments_queued || 0);
    setField("current_file", job.current_file || "");
    setField("updated_at", job.updated_at || "");

    if (progressBar) {
      progressBar.style.width = `${Math.max(0, Math.min(100, progressPercent))}%`;
    }

    if (buttons.start) buttons.start.disabled = busy || !payload.can_start;
    if (buttons.pause) buttons.pause.disabled = busy || !payload.can_pause;
    if (buttons.resume) buttons.resume.disabled = busy || !payload.can_resume;

    renderMessages(payload);
  }

  async function request(path, options) {
    const response = await fetch(`${config.root}${path}`, {
      method: "GET",
      credentials: "same-origin",
      headers: {
        "X-WP-Nonce": config.nonce,
        "Content-Type": "application/json",
      },
      ...options,
    });

    const json = await response.json();
    if (!response.ok) {
      throw new Error(json && json.message ? json.message : (i18n.genericRestError || "REST request failed."));
    }

    return json;
  }

  async function refresh() {
    const payload = await request("/status");
    render(payload);
    return payload;
  }

  async function trigger(path, body) {
    busy = true;
    render(config.initialStatus || { job: {} });

    try {
      const payload = await request(path, {
        method: "POST",
        body: body ? JSON.stringify(body) : "{}",
      });
      config.initialStatus = payload;
      render(payload);
      return payload;
    } catch (error) {
      if (messagesEl) {
        messagesEl.innerHTML = `<div class="notice notice-error inline"><p>${escapeHtml(error.message)}</p></div>`;
      }
      throw error;
    } finally {
      busy = false;
    }
  }

  function bindActions() {
    if (buttons.start) {
      buttons.start.addEventListener("click", function () {
        trigger("/start", { mode: "full" }).catch(function () {});
      });
    }

    if (buttons.pause) {
      buttons.pause.addEventListener("click", function () {
        trigger("/pause").catch(function () {});
      });
    }

    if (buttons.resume) {
      buttons.resume.addEventListener("click", function () {
        trigger("/resume").catch(function () {});
      });
    }

    if (buttons.refresh) {
      buttons.refresh.addEventListener("click", function () {
        refresh().catch(function () {});
      });
    }
  }

  function startPolling() {
    if (pollTimer) {
      window.clearInterval(pollTimer);
    }

    pollTimer = window.setInterval(function () {
      if (busy) return;
      refresh().catch(function () {});
    }, 5000);
  }

  render(config.initialStatus || { job: {} });
  bindActions();
  startPolling();
})();
