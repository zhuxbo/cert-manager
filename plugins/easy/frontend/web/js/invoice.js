(function () {
  "use strict";

  const $ = id => document.getElementById(id);
  const show = id => ($(id).style.display = "block");
  const hide = id => ($(id).style.display = "none");
  const setError = (id, msg) => {
    const el = $(id);
    el.textContent = msg || "";
    el.classList.toggle("show", !!msg);
  };

  async function api(path, options = {}) {
    const opts = {
      method: "GET",
      headers: { "Content-Type": "application/json" },
      ...options
    };
    if (opts.body && typeof opts.body === "object") {
      opts.body = JSON.stringify(opts.body);
    }
    const res = await fetch("/api/easy/invoice" + path, opts);
    if (!res.ok) {
      return { code: 0, msg: "服务暂不可用", _httpStatus: res.status };
    }
    return res.json();
  }

  const BASE_PATH = "/easy/invoice";

  // 支持两种 URL 风格(与 Easy 主页一致):
  //   /easy/invoice/<tid>             → 自动填 tid
  //   /easy/invoice/<tid>/<email>     → 自动填 tid + email
  //   /easy/invoice?tid=&email=       → query 形式(query 优先级最高)
  // 容错解码:tid/email 含裸 % 等非法转义序列时 decodeURIComponent 会抛 URIError,
  // 回落到原始字符串,避免刷新页面整体崩溃
  function safeDecode(value) {
    try {
      return decodeURIComponent(value);
    } catch {
      return value;
    }
  }

  function fillFromQuery() {
    const path = location.pathname.replace(/^\/easy\/invoice\/?/, "");
    const parts = path.split("/").filter(Boolean);
    if (parts[0]) $("tid").value = safeDecode(parts[0]);
    if (parts[1]) $("email").value = safeDecode(parts[1]);

    const qs = new URLSearchParams(location.search);
    if (qs.get("tid")) $("tid").value = qs.get("tid");
    if (qs.get("email")) $("email").value = qs.get("email");
  }

  // 用 history.pushState 把 tid/email 同步到 URL,与 Easy 主页 main.js 的 updateUrl 行为一致
  // 写入时 encodeURIComponent,读取时 safeDecode 还原,确保 tid/email 含 % 等特殊字符也稳
  function updateUrl(tid, email) {
    if (!tid) {
      window.history.pushState({}, "", BASE_PATH);
      return;
    }
    const encodedTid = encodeURIComponent(tid);
    const target = `${BASE_PATH}/${encodedTid}${
      email ? "/" + encodeURIComponent(email) : ""
    }`;
    window.history.pushState({ tid, email }, "", target);
  }

  async function probe() {
    const result = await api("/ping");
    return result.code === 1;
  }

  async function loadQuota() {
    setError("quota-error", "");
    const tid = $("tid").value.trim();
    const email = $("email").value.trim();
    if (!tid || !email) {
      show("step-input");
      return;
    }

    const result = await api("/quota", {
      method: "POST",
      body: { tid, email }
    });
    if (result.code !== 1) {
      setError("quota-error", result.msg || "查询失败");
      show("step-input");
      return;
    }
    $("info-recharge").textContent = result.data.recharge;
    $("info-invoiced").textContent = result.data.invoiced;
    $("info-quota").textContent = result.data.quota;
    $("delivery-email").value = email;

    // quota=0 时禁用表单 + 显示提示;否则正常初始化 amount + max
    const quotaNum = parseFloat(result.data.quota);
    if (!(quotaNum > 0)) {
      show("quota-empty");
      $("apply-form").style.display = "none";
    } else {
      hide("quota-empty");
      $("apply-form").style.display = "";
      $("amount").value = result.data.quota;
      $("amount").max = result.data.quota;
    }
    hide("step-input");
    show("step-form");
    updateUrl(tid, email);
  }

  async function onQuotaSubmit(e) {
    e.preventDefault();
    await loadQuota();
  }

  async function onApplySubmit(e) {
    e.preventDefault();
    setError("apply-error", "");

    const payload = {
      tid: $("tid").value.trim(),
      email: $("email").value.trim(),
      amount: parseFloat($("amount").value),
      organization: $("organization").value.trim(),
      taxation: $("taxation").value.trim(),
      remark: $("remark").value.trim()
    };
    if (!payload.organization) {
      setError("apply-error", "请填写发票抬头");
      return;
    }
    if (!payload.taxation) {
      setError("apply-error", "请填写税号");
      return;
    }
    if (!(payload.amount > 0)) {
      setError("apply-error", "金额必须大于 0");
      return;
    }

    const result = await api("/apply", { method: "POST", body: payload });
    if (result.code !== 1) {
      setError("apply-error", result.msg || "提交失败");
      return;
    }
    $("done-email").textContent = payload.email;
    hide("step-form");
    show("step-done");
  }

  document.addEventListener("DOMContentLoaded", async () => {
    fillFromQuery();
    const ok = await probe();
    if (!ok) {
      show("disabled-card");
      return;
    }
    $("quota-form").addEventListener("submit", onQuotaSubmit);
    $("apply-form").addEventListener("submit", onApplySubmit);

    // path 含 tid+email 时自动查询额度,否则展示输入卡片让用户手填
    if ($("tid").value.trim() && $("email").value.trim()) {
      await loadQuota();
    } else {
      show("step-input");
    }
  });
})();
