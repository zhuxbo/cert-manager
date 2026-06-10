/**
 * 主程序入口
 * @version 1.0.0
 */
(function () {
  "use strict";

  // 轻量级路由工具：使用 History 模式模拟 path 参数，读取时兼容 Hash/Query
  const Router = (function () {
    function getBasePath() {
      // 优先根据当前脚本路径推导，例如 /easy/js/main.js -> /easy/
      try {
        const cs = document.currentScript;
        if (cs && cs.src) {
          const u = new URL(cs.src, window.location.href);
          const p = u.pathname; // 如 /easy/js/main.js
          const base = p.replace(/\/js\/[^/]*$/, "/");
          if (base && base !== p) return base;
        }
      } catch (_) {}
      // 回退：使用文档路径的目录部分
      const path = window.location.pathname;
      if (path.endsWith("/")) {
        // 若以 / 结尾（如 /easy/123/），取上一级目录作为基路径
        const trimmed = path.replace(/\/?$/, "");
        const idx2 = trimmed.lastIndexOf("/");
        return idx2 >= 0 ? trimmed.slice(0, idx2 + 1) : "/";
      }
      const idx = path.lastIndexOf("/");
      return idx >= 0 ? path.slice(0, idx + 1) : "/";
    }
    const BASE_PATH = getBasePath();

    function compilePattern(pattern) {
      const keys = [];
      let regexStr = pattern
        .replace(/([.+*?=^!:${}()[\]|/\\])/g, "\\$1")
        .replace(/\/:([A-Za-z0-9_]+)\?/g, (_m, key) => {
          keys.push({ name: key, optional: true });
          return "(?:/([^/]+))?";
        })
        .replace(/\/:([A-Za-z0-9_]+)/g, (_m, key) => {
          keys.push({ name: key, optional: false });
          return "/([^/]+)";
        });
      regexStr = "^" + regexStr + "/?$";
      return { regex: new RegExp(regexStr), keys };
    }

    function safeDecode(value) {
      try {
        return decodeURIComponent(value);
      } catch {
        return value;
      }
    }

    function match(pattern, path) {
      const { regex, keys } = compilePattern(pattern);
      const m = path.match(regex);
      if (!m) return null;
      const params = {};
      keys.forEach((k, i) => {
        const v = m[i + 1] ? safeDecode(m[i + 1]) : undefined;
        params[k.name] = v;
      });
      return params;
    }

    function readTidEmail() {
      const url = new URL(window.location.href);
      const pathname = url.pathname;
      if (pathname.startsWith(BASE_PATH)) {
        const rel = pathname.slice(BASE_PATH.length - 1); // 保留前导 '/'
        let p = match("/:tid/:email?", rel);
        if (p && p.tid) return { tid: p.tid || "", email: p.email || "" };
        p = match("/:tid", rel);
        if (p && p.tid) return { tid: p.tid || "", email: "" };
      }
      // 兼容 hash 读取
      const hash = window.location.hash || "";
      const hashPath = hash.startsWith("#") ? hash.slice(1) : hash;
      if (hashPath.startsWith(BASE_PATH)) {
        const rel = hashPath.slice(BASE_PATH.length - 1);
        let p = match("/:tid/:email?", rel);
        if (p && p.tid) return { tid: p.tid || "", email: p.email || "" };
        p = match("/:tid", rel);
        if (p && p.tid) return { tid: p.tid || "", email: "" };
      }
      // 兜底一：相对 BASE_PATH 的路径段长度需大于 BASE_PATH 段数，才解析为 tid/email
      const parts = pathname.split("/").filter(Boolean);
      const baseSegs = BASE_PATH.split("/").filter(Boolean);
      if (parts.length > baseSegs.length) {
        const relParts = parts.slice(baseSegs.length);
        const last = safeDecode(relParts[relParts.length - 1]);
        if (last !== "index.html" && last !== "index.htm") {
          if (last.includes("@") && relParts.length >= 2) {
            const maybeTid = safeDecode(relParts[relParts.length - 2]);
            if (maybeTid) return { tid: maybeTid, email: last };
          } else if (last) {
            return { tid: last, email: "" };
          }
        }
      }

      // 兜底二：Query
      const params = new URLSearchParams(url.search);
      return { tid: params.get("tid") || "", email: params.get("email") || "" };
    }

    function updateUrl(tid, email) {
      // 根据要求：tid/email 不做 encode 编码
      if (!tid) {
        window.history.pushState({}, "", BASE_PATH);
        return;
      }
      const target = `${BASE_PATH}${tid}${email ? "/" + email : ""}`;
      window.history.pushState({ tid, email }, "", target);
    }

    function onChange(fn) {
      window.addEventListener("popstate", fn);
      window.addEventListener("hashchange", fn);
    }

    return { readTidEmail, updateUrl, onChange };
  })();

  // 全局状态
  window.appState = {
    step: -1,
    loading: false,
    apply: {
      tid: "",
      email: "",
      domain: "",
      validation_method: ""
    },
    product: {},
    validation: {},
    cert: {},
    status: "", // 后端状态：processing/approving/active
    is_applied: false,
    autoRefreshTimer: null // 自动刷新定时器
  };

  // 获取委托验证前缀
  function getDelegationPrefix(ca, channel) {
    if (channel === "acme") return "_acme-challenge";
    const caLower = (ca || "").toLowerCase();
    switch (caLower) {
      case "sectigo":
      case "comodo":
        return "_pki-validation";
      case "certum":
        return "_certum";
      case "digicert":
      case "geotrust":
      case "thawte":
      case "rapidssl":
      case "symantec":
      case "trustasia":
        return "_dnsauth";
      default:
        return "_acme-challenge";
    }
  }

  // 通用复制功能（针对移动端优化）
  async function copyToClipboard(text) {
    // 方案1：优先使用 Clipboard API（现代浏览器）
    if (navigator.clipboard && navigator.clipboard.writeText) {
      try {
        await navigator.clipboard.writeText(text);
        return Promise.resolve();
      } catch (err) {
        console.warn("Clipboard API failed, trying fallback:", err);
        // 继续尝试备用方案
      }
    }

    // 方案2：使用 textarea + execCommand（移动端优化版）
    const textarea = document.createElement("textarea");
    textarea.value = text;
    textarea.style.position = "absolute";
    textarea.style.left = "-9999px";
    textarea.style.top = "0";
    textarea.style.opacity = "0";
    textarea.style.pointerEvents = "none";

    // 移动端需要设置这些属性
    textarea.setAttribute("readonly", "");
    textarea.setAttribute("contenteditable", "true");

    document.body.appendChild(textarea);

    // 保存当前焦点
    const currentFocus = document.activeElement;

    try {
      // 移动端浏览器的选择策略
      if (/iPhone|iPad|iPod|Android/i.test(navigator.userAgent)) {
        // 移动端：先聚焦
        textarea.focus();

        // 使用 setSelectionRange 选中所有文本
        textarea.setSelectionRange(0, textarea.value.length);

        // 某些 Android 浏览器需要这个
        if (/Android/i.test(navigator.userAgent)) {
          // 创建一个 range 并选中
          const selection = window.getSelection();
          const range = document.createRange();
          range.selectNodeContents(textarea);
          selection.removeAllRanges();
          selection.addRange(range);
        }
      } else {
        // 桌面端
        textarea.select();
      }

      // 执行复制
      const success = document.execCommand("copy");

      // 恢复焦点
      if (currentFocus && typeof currentFocus.focus === "function") {
        currentFocus.focus();
      }

      document.body.removeChild(textarea);

      if (success) {
        return Promise.resolve();
      } else {
        // 如果 execCommand 失败，尝试方案3
        throw new Error("execCommand failed");
      }
    } catch (err) {
      document.body.removeChild(textarea);

      // 方案3：对于某些浏览器，创建一个可见但快速消失的输入框
      // 这是最后的兜底方案，用户可能会看到闪烁
      const input = document.createElement("input");
      input.value = text;
      input.style.position = "fixed";
      input.style.top = "50%";
      input.style.left = "50%";
      input.style.transform = "translate(-50%, -50%)";
      input.style.zIndex = "999999";
      input.style.background = "white";
      input.style.border = "1px solid #ccc";
      input.style.padding = "10px";
      input.style.fontSize = "16px"; // 防止 iOS 自动缩放

      document.body.appendChild(input);

      return new Promise((resolve, reject) => {
        input.focus();
        input.select();

        // 给用户一点时间，然后尝试复制
        setTimeout(() => {
          try {
            const success = document.execCommand("copy");
            document.body.removeChild(input);

            if (success) {
              resolve();
            } else {
              reject(new Error("无法复制，请长按选择文本手动复制"));
            }
          } catch (e) {
            document.body.removeChild(input);
            reject(new Error("无法复制，请长按选择文本手动复制"));
          }
        }, 100);
      });
    }
  }

  // Toast 提示
  function showToast(message, type = "info") {
    const container = document.getElementById("toast-container");
    const toast = document.createElement("div");
    toast.className = `toast ${type}`;
    toast.innerHTML = `
            <span class="toast-message">${message}</span>
            <span class="toast-close">&times;</span>
        `;

    container.appendChild(toast);

    // 点击关闭
    toast.querySelector(".toast-close").addEventListener("click", () => {
      toast.remove();
    });

    // 自动关闭
    setTimeout(() => {
      toast.remove();
    }, 3000);
  }

  // 设置加载状态
  function setLoading(isLoading, buttonId) {
    window.appState.loading = isLoading;

    if (buttonId) {
      const button = document.getElementById(buttonId);
      if (button) {
        button.disabled = isLoading;
        const spinner = button.querySelector(".loading-spinner");
        const text = button.querySelector(".btn-text");
        if (spinner) {
          spinner.style.display = isLoading ? "inline-block" : "none";
        }
      }
    }
  }

  // 切换步骤
  function setStep(step) {
    window.appState.step = step;

    // 隐藏所有步骤内容
    document.querySelectorAll(".step-content").forEach(content => {
      content.style.display = "none";
    });

    // 显示当前步骤
    if (step === -1) {
      document.getElementById("step-tid").style.display = "block";
      document.getElementById("steps-container").style.display = "none";
      stopAutoRefresh(); // 停止自动刷新
    } else {
      document.getElementById(`step-${step}`).style.display = "block";
      document.getElementById("steps-container").style.display = "block";

      // 更新步骤条状态
      updateSteps(step);

      // 如果是验证步骤（包括 approving 状态），启动自动刷新
      if (step === 1) {
        startAutoRefresh();
      } else if (step === 2) {
        // 证书已签发，停止自动刷新
        stopAutoRefresh();
      } else {
        stopAutoRefresh();
      }
    }
  }

  // 更新步骤条
  function updateSteps(currentStep) {
    const status =
      window.appState?.status ||
      (typeof window.appState.cert === "object" && window.appState.cert
        ? window.appState.cert.status
        : undefined);
    const steps = Array.from(document.querySelectorAll(".step"));
    steps.forEach(step => step.classList.remove("active", "completed"));

    if (status === "approving") {
      // 验证已完成（绿色），签发中（蓝色）
      steps.forEach((step, index) => {
        if (index <= 1) step.classList.add("completed"); // 提交域名、验证域名 -> 绿色
        if (index === 2) step.classList.add("active"); // 签发证书 -> 蓝色
      });
      return;
    }

    if (status === "active") {
      // 证书签发完成：所有步骤均为绿色
      steps.forEach(step => step.classList.add("completed"));
      return;
    }

    // 默认逻辑：当前步骤蓝色，之前步骤绿色
    steps.forEach((step, index) => {
      if (index < currentStep) {
        step.classList.add("completed");
      } else if (index === currentStep) {
        step.classList.add("active");
      }
    });
  }

  // 处理订单号提交
  async function handleTidSubmit(e) {
    e.preventDefault();

    if (!Validator.validateForm("tid-form")) {
      return;
    }

    const tid = document.getElementById("tid").value.trim();
    window.appState.apply.tid = tid;

    // 使用 path 参数更新 URL
    Router.updateUrl(tid, window.appState.apply.email || "");

    // 查询订单
    await checkOrder();
  }

  // 查询订单
  async function checkOrder() {
    setLoading(true, "tid-submit-btn");
    // 清除历史错误提示，避免残留
    try {
      Validator.clearErrors("apply-form");
    } catch (_) {}
    try {
      Validator.clearErrors("tid-form");
    } catch (_) {}

    try {
      const result = await API.check(
        window.appState.apply.tid,
        window.appState.apply.email
      );

      handleCheckResponse(result);
    } catch (error) {
      const msg = error?.message || "";
      // 按需求：表单(JS)校验错误在表单下；提交后的服务端错误弹出消息
      // 特殊处理：若路径中带有 tid 与 email，但提示不匹配，则回落为仅订单查询或订单页
      if (
        msg === "订单与邮箱不匹配" &&
        window.appState.apply.tid &&
        window.appState.apply.email
      ) {
        try {
          // 去掉 email 重新查询，判断订单是否存在
          const fallback = await API.check(window.appState.apply.tid, "");
          // 成功则进入“输入邮箱查询页面”（Step 0），并清空 email
          window.appState.apply.email = "";
          handleCheckResponse(fallback);
          // 更新 URL 为仅包含 tid
          try {
            Router.updateUrl(window.appState.apply.tid, "");
          } catch (_) {}
          showToast("邮箱与订单不匹配，请重新输入邮箱", "error");
        } catch (_e) {
          // 订单也不匹配，返回查询订单页
          setStep(-1);
          try {
            Router.updateUrl("", "");
          } catch (_) {}
          showToast("订单错误，请重新输入订单号", "error");
        }
      } else if (msg === "订单错误" || msg === "订单不存在") {
        // 订单错误：返回查询订单页
        setStep(-1);
        try {
          Router.updateUrl("", "");
        } catch (_) {}
        showToast(msg, "error");
      } else {
        showToast(msg || "请求失败", "error");
      }
    } finally {
      setLoading(false, "tid-submit-btn");
    }
  }

  // 处理查询响应
  function handleCheckResponse(response) {
    const data = response.data || {};

    // 更新状态
    window.appState.product = data.product || {};
    window.appState.cert = data.cert || {};

    // 处理 validation 数据
    // 如果 data 直接包含 validation（来自 revalidate 响应）
    if (data.validation) {
      window.appState.validation = {
        ...data.validation,
        domain: data.validation.domain || "",
        method: data.validation.method || "",
        host: data.validation.host || "",
        value: data.validation.value || ""
      };
    } else {
      // 从 cert 中获取 validation 数据
      const certValidation = data.cert?.validation?.[0] || {};
      const certDcv = data.cert?.dcv || {};
      window.appState.validation = {
        ...certValidation,
        domain: certValidation.domain || data.cert?.domains || "",
        method: certValidation.method || certDcv.method || "",
        host: certValidation.host || certDcv.dns?.host || "",
        value: certValidation.value || certDcv.dns?.value || ""
      };
    }
    window.appState.is_applied = data.is_applied || false;

    // 仅使用后端 status
    const status = typeof data.status === "string" ? data.status : "";
    window.appState.status = status;
    const step =
      status === "active"
        ? 2
        : status === "approving" || status === "processing"
          ? 1
          : 0;

    // 根据步骤更新界面
    if (step === 2) {
      updateIssuedInfo(data);
    } else if (step === 1) {
      updateValidationInfo(data);
    } else {
      updateApplyForm(data);
    }

    // 管理自动刷新（仅依赖 status）：active 停止；processing/approving 在验证页启动
    if (window.appState.status === "active") {
      stopAutoRefresh();
    } else if (
      (window.appState.status === "processing" ||
        window.appState.status === "approving") &&
      step === 1
    ) {
      // 在验证页面且状态需要刷新时，确保自动刷新启动
      if (!window.appState.autoRefreshTimer) {
        startAutoRefresh();
      }
    }

    setStep(step);
  }

  // 更新申请表单
  function updateApplyForm(data) {
    // 设置基本信息
    document.getElementById("apply-tid").value = window.appState.apply.tid;
    document.getElementById("product-name").value = data.product?.name || "";

    // 优先使用已保存的邮箱（从URL参数或之前的输入）
    if (window.appState.apply.email) {
      document.getElementById("email").value = window.appState.apply.email;
    } else if (data.cert?.order_email) {
      document.getElementById("email").value = data.cert.order_email;
      window.appState.apply.email = data.cert.order_email;
    }

    // 更新验证方式选项
    const methodSelect = document.getElementById("validation_method");
    methodSelect.innerHTML = '<option value="">请选择域名验证方式</option>';

    if (data.product?.validation_methods) {
      Object.entries(data.product.validation_methods).forEach(
        ([key, value]) => {
          const option = document.createElement("option");
          option.value = key;
          option.textContent = value;
          methodSelect.appendChild(option);
        }
      );
    }

    // 默认选择：优先沿用之前选择；无则选择列表第一项
    const methodKeys = Object.keys(data.product?.validation_methods || {});
    const prevMethod = window.appState.apply.validation_method;
    const defaultMethod =
      prevMethod && methodKeys.includes(prevMethod)
        ? prevMethod
        : methodKeys[0] || "";
    if (defaultMethod) {
      methodSelect.value = defaultMethod;
      window.appState.apply.validation_method = defaultMethod;
    }
    // 不再强制因通配符产品禁用选择，交由后端下发的 validation_methods 控制

    // 根据是否已申请显示不同内容
    if (window.appState.is_applied) {
      document.getElementById("domain-input-group").style.display = "none";
      document.getElementById("query-btn-group").style.display = "block";
    } else {
      document.getElementById("domain-input-group").style.display = "block";
      document.getElementById("query-btn-group").style.display = "none";
    }
  }

  // 更新验证信息
  function updateValidationInfo(data) {
    const cert = data.cert || {};
    const product = data.product || {};

    // 添加安全检查
    if (!cert) {
      return;
    }

    const validation = data.validation || cert.validation?.[0] || {};
    const dcv = cert.dcv || {};

    // 更新 window.appState.validation - 合并 validation 和 dcv 信息
    window.appState.validation = {
      ...validation,
      domain: validation.domain || cert.domains || "",
      method: validation.method || dcv.method || "",
      host: validation.host || dcv.dns?.host || "",
      value: validation.value || dcv.dns?.value || "",
      // 兼容后端返回的 name/content 字段
      file_name:
        validation.file_name || validation.name || dcv.file?.name || "",
      file_content:
        validation.file_content || validation.content || dcv.file?.content || ""
    };

    // 保存 cert 信息（包含 dcv）
    window.appState.cert = cert;

    // 保存产品信息
    window.appState.product = product;

    document.getElementById("validation-domain").value =
      window.appState.validation.domain || "";

    // 更新验证方式选择框
    const methodSelect = document.getElementById("validation-method-select");
    methodSelect.innerHTML = '<option value="">请选择域名验证方式</option>';
    if (product.validation_methods) {
      Object.entries(product.validation_methods).forEach(([key, value]) => {
        const option = document.createElement("option");
        option.value = key;
        option.textContent = value;
        methodSelect.appendChild(option);
      });
    }

    // 设置当前验证方式（dcv.is_delegate 时使用 delegation，因为后端提交到 CA 时会转换为 txt）
    let currentMethod =
      window.appState.validation.method ||
      Object.keys(product.validation_methods || {})[0];
    if (dcv.is_delegate) {
      // 宽松判断，兼容 true/1/"true"
      currentMethod = "delegation";
    }
    methodSelect.value = currentMethod;
    window.appState.apply.validation_method = currentMethod;
    // 保存原始验证方式，用于判断是否有改变
    window.appState.originalValidationMethod = currentMethod;

    // 通配符产品也可以切换验证方式（如果有多个选项）
    methodSelect.disabled = false;
    document.getElementById("update-method-btn").style.display = "inline-block";

    // 显示对应的验证内容
    updateValidationDisplay(currentMethod, dcv);

    // 审核中（approving）时，隐藏验证相关控件，仅显示提示与"查询"按钮（仅依赖全局 status）
    const isApproving = window.appState.status === "approving";
    const approvingSection = document.getElementById("approving-section");
    const methodGroup = document.getElementById("method-group");
    const actionButtonsRow = document.getElementById("action-buttons-row");
    const helpSection = document.getElementById("help-section");
    const dnsValidation = document.getElementById("dns-validation");
    const fileValidation = document.getElementById("file-validation");
    if (isApproving) {
      if (approvingSection) approvingSection.style.display = "flex";
      if (methodGroup) methodGroup.style.display = "none";
      if (actionButtonsRow) actionButtonsRow.style.display = "none";
      if (helpSection) helpSection.style.display = "none";
      if (dnsValidation) dnsValidation.style.display = "none";
      if (fileValidation) fileValidation.style.display = "none";
    } else {
      if (approvingSection) approvingSection.style.display = "none";
      if (methodGroup) methodGroup.style.display = "";
      if (actionButtonsRow) actionButtonsRow.style.display = "";
      if (helpSection) helpSection.style.display = "";
      // DNS/File 区域的显示由 updateValidationDisplay 决定
    }
  }

  // 更新验证显示
  function updateValidationDisplay(method, dcv) {
    // 判断是否为委托验证（dcv.is_delegate 标记优先于 method）
    const certDcv = window.appState.cert?.dcv || {};
    const isDelegate = Boolean(certDcv.is_delegate); // 宽松判断，兼容 true/1/"true"
    const isDNS = !isDelegate && ["cname", "txt"].includes(method);
    const isFile = ["file", "http", "https"].includes(method);

    // 委托验证
    if (isDelegate) {
      document.getElementById("dns-validation").style.display = "block";
      document.getElementById("file-validation").style.display = "none";

      // 获取委托验证前缀（主机记录只显示前缀，与 user/admin 保持一致）
      const ca = certDcv.ca || window.appState.product?.ca || "";
      const channel = window.appState.cert?.channel || "";
      const prefix = getDelegationPrefix(ca, channel);
      // 委托验证的目标从 validation 获取
      const delegationTarget =
        window.appState.validation.delegation_target || "";

      document.getElementById("dns-host").value = prefix;
      document.getElementById("dns-type").value = "CNAME";
      document.getElementById("dns-value").value = delegationTarget;

      // 显示复制全部
      document.getElementById("dns-help-extra").style.display = "inline";

      // 更新帮助链接
      document.getElementById("help-text").textContent = "如何做委托验证？";
      document.getElementById("validation-help-link").href =
        Config.getConfig("helpURL") + "/verify/";
    }
    // DNS验证
    else if (isDNS) {
      document.getElementById("dns-validation").style.display = "block";
      document.getElementById("file-validation").style.display = "none";

      document.getElementById("dns-host").value =
        dcv.dns?.host || window.appState.validation.host || "";
      document.getElementById("dns-type").value = method.toUpperCase();
      document.getElementById("dns-value").value =
        dcv.dns?.value || window.appState.validation.value || "";
      // 不显示域名后缀，避免在输入与按钮之间插入纯文字造成突兀

      // 显示复制全部
      document.getElementById("dns-help-extra").style.display = "inline";

      // 更新帮助链接
      document.getElementById("help-text").textContent = "如何做解析验证？";
      document.getElementById("validation-help-link").href =
        Config.getConfig("helpURL") + "/verify/";
    }
    // 文件验证
    else if (isFile) {
      document.getElementById("dns-validation").style.display = "none";
      document.getElementById("file-validation").style.display = "block";

      // 隐藏复制全部
      document.getElementById("dns-help-extra").style.display = "none";

      const protocol = method === "file" ? "https:" : `${method}:`;
      const name =
        dcv.file?.name ||
        window.appState.validation.file_name ||
        window.appState.validation.name ||
        "";
      const content =
        dcv.file?.content ||
        window.appState.validation.file_content ||
        window.appState.validation.content ||
        "";
      const link =
        window.appState.validation.link ||
        `${protocol}//${window.appState.validation.domain}/.well-known/pki-validation/${name}`;

      document.getElementById("file-link").value = link;
      document.getElementById("file-content").value = content;
      document.getElementById("file-path").value = name
        ? `/.well-known/pki-validation/${name}`
        : "";

      // 更新帮助链接
      document.getElementById("help-text").textContent = "如何做文件验证？";
      document.getElementById("validation-help-link").href =
        Config.getConfig("helpURL") + "/verify/";
    }
  }

  // 更新签发信息
  function updateIssuedInfo(data) {
    const validation = data.validation || data.cert?.validation?.[0] || {};
    document.getElementById("issued-domain").value =
      validation.domain || data.cert?.domains || "";

    // 设置证书内容
    if (data.cert) {
      document.getElementById("cert-pem").value = data.cert || "";
    }
    if (data.key) {
      document.getElementById("cert-key").value = data.key || "";
    }

    // 根据是否有 key 控制 IIS 下载项显隐
    const iisLink = document.querySelector('a.download-link[data-type="iis"]');
    if (iisLink) {
      if (!data.key) {
        iisLink.setAttribute("style", "display:none");
      } else {
        iisLink.removeAttribute("style");
      }
    }

    // 更新安装帮助链接
    const help = Config.getConfig("helpURL");
    const helpLink = document.getElementById("help-url-link");
    if (helpLink && help) {
      // 安装帮助链接添加 /install 路径
      const installUrl = help + "/install";
      helpLink.href = installUrl;
      helpLink.textContent = installUrl;
    }

    // 自动部署区域
    const deploySection = document.getElementById("deploy-section");
    if (deploySection && data.deploy) {
      deploySection.style.display = "block";
      window.appState.deployData = data.deploy;

      const setVal = (id, val) => {
        const el = document.getElementById(id);
        if (el) el.value = val || "";
      };
      setVal("deploy-bt-install", data.deploy.bt_install?.linux);
      setVal("deploy-bt-deploy", data.deploy.bt_deploy);
      setVal("deploy-install-linux", data.deploy.install?.linux);
      setVal("deploy-install-windows", data.deploy.install?.windows);
      setVal("deploy-nginx-deploy", data.deploy.deploy);
      setVal("deploy-iis-install-windows", data.deploy.iis_install?.windows);
      setVal("deploy-iis-deploy", data.deploy.iis_deploy);

      // 重置 Windows 版本 toggle 为 2016+
      document.querySelectorAll(".deploy-win-btn").forEach(btn => {
        btn.classList.toggle("active", btn.getAttribute("data-win-ver") === "2019");
      });
    } else if (deploySection) {
      deploySection.style.display = "none";
    }
  }

  // 处理申请提交
  async function handleApplySubmit(e) {
    e.preventDefault();

    if (!Validator.validateForm("apply-form")) {
      return;
    }

    // 清理域名
    let domain = Validator.cleanDomain(
      document.getElementById("domain").value.trim()
    );

    // 处理通配符
    if (window.appState.product.is_wildcard) {
      // 通配符产品：去掉 *. 后再添加，确保格式正确
      domain = domain.replace("*.", "");
      domain = "*." + domain;

      // 检查 *.www. 模式（在自动补齐后检查）
      if (domain.includes("*.www.")) {
        // 使用自定义确认弹窗（屏幕居中，点击遮罩可关闭）
        const confirmResult = await showConfirm(
          "检测到域名包含 *.www. 通配符，通常 www 不需要包含在通配符证书中。是否继续申请 " +
            domain +
            " 的证书？"
        );
        if (!confirmResult) return;
      }
    } else {
      // 单域名产品：去掉 *.
      domain = domain.replace("*.", "");
    }

    window.appState.apply.domain = domain;
    window.appState.apply.email = document.getElementById("email").value.trim();
    window.appState.apply.validation_method =
      document.getElementById("validation_method").value;

    setLoading(true, "apply-btn");

    try {
      await API.apply(window.appState.apply);
      showToast("申请已提交", "success");

      // 使用 path 参数更新 URL
      Router.updateUrl(window.appState.apply.tid, window.appState.apply.email);

      // 重新查询状态
      await checkOrder();
    } catch (error) {
      showToast(error.message, "error");
    } finally {
      setLoading(false, "apply-btn");
    }
  }

  // 处理查询按钮
  async function handleQuery() {
    const email = document.getElementById("email").value.trim();

    if (!email) {
      Validator.showError("email", "请输入邮箱地址");
      return;
    }

    window.appState.apply.email = email;

    // 使用 path 参数更新 URL
    Router.updateUrl(window.appState.apply.tid, email);

    setLoading(true, "query-btn");
    await checkOrder();
    setLoading(false, "query-btn");
  }

  // 提交验证（验证按钮点击）
  async function checkValidation() {
    setLoading(true, "check-validation-btn");

    try {
      // 提交验证请求
      await API.request("/revalidate", {
        method: "POST",
        body: {
          tid: window.appState.apply.tid,
          email: window.appState.apply.email
        }
      });

      showToast("验证开始，请等几分钟后刷新查看", "success");

      // 延迟后刷新状态
      setTimeout(async () => {
        await checkOrder();
      }, 3000);
    } catch (error) {
      showToast(error.message || "验证失败", "error");
    } finally {
      setLoading(false, "check-validation-btn");
    }
  }

  // 测试验证（检测按钮点击）
  async function testValidation() {
    // 确保有验证数据
    if (!window.appState.validation || !window.appState.validation.domain) {
      showToast("验证信息不完整", "error");
      return;
    }

    const dialog = document.getElementById("check-dialog");
    dialog.style.display = "flex";

    // 更新弹窗内容
    updateCheckDialog(window.appState.validation);

    // 执行检测
    await performVerification();
  }

  // 更新检测弹窗
  function updateCheckDialog(validation) {
    const certDcv = window.appState.cert?.dcv || {};
    const isDelegate = Boolean(certDcv.is_delegate);

    document.getElementById("check-domain").textContent =
      validation.domain || "";
    document.getElementById("check-method").textContent = isDelegate
      ? "委托验证 (CNAME)"
      : validation.method || "";

    const isDNS =
      !isDelegate &&
      ["cname", "txt"].includes(validation.method?.toLowerCase());
    const isFile = ["file", "http", "https"].includes(
      validation.method?.toLowerCase()
    );

    // 显示/隐藏相应内容（在表格中显示为 table-row，避免错乱）
    document.querySelectorAll(".dns-result").forEach(el => {
      el.style.display = isDNS ? "table-row" : "none";
    });
    document.querySelectorAll(".file-result").forEach(el => {
      el.style.display = isFile ? "table-row" : "none";
    });
    document.querySelectorAll(".delegation-result").forEach(el => {
      el.style.display = isDelegate ? "table-row" : "none";
    });
    // TXT 行仅在有 TXT 值时显示
    const hasTxtValue = validation.value || certDcv.dns?.value;
    document.querySelectorAll(".delegation-txt-result").forEach(el => {
      el.style.display = isDelegate && hasTxtValue ? "table-row" : "none";
    });
    // 错误行按需显示
    document.querySelectorAll(".delegation-cname-error").forEach(el => {
      el.style.display =
        isDelegate && validation.delegation_cname_error ? "table-row" : "none";
    });
    document.querySelectorAll(".delegation-txt-error").forEach(el => {
      el.style.display =
        isDelegate && hasTxtValue && validation.delegation_txt_error
          ? "table-row"
          : "none";
    });

    if (isDelegate) {
      const ca = certDcv.ca || window.appState.product?.ca || "";
      const channel = window.appState.cert?.channel || "";
      const prefix = getDelegationPrefix(ca, channel);
      const zone =
        validation.delegation_zone ||
        (validation.domain || "").replace(/^\*\./, "");
      const cnameHost = `${prefix}.${zone}`;
      const delegationTarget = validation.delegation_target || "";

      // CNAME 部分
      document.getElementById("check-cname-host").textContent = cnameHost;
      document.getElementById("check-cname-expected").textContent =
        delegationTarget;
      document.getElementById("check-cname-detected").textContent =
        validation.delegation_cname_detected || "-";
      const cnameStatusEl = document.getElementById("check-cname-status");
      if (validation.delegation_cname_checked === true) {
        cnameStatusEl.textContent = "正确";
        cnameStatusEl.className = "status-badge success";
      } else if (validation.delegation_cname_checked === false) {
        cnameStatusEl.textContent = "异常";
        cnameStatusEl.className = "status-badge error";
      } else {
        cnameStatusEl.textContent = "待检测";
        cnameStatusEl.className = "status-badge";
      }
      document.getElementById("check-cname-error").textContent =
        validation.delegation_cname_error || "";

      // TXT 部分
      if (hasTxtValue) {
        document.getElementById("check-txt-host").textContent =
          delegationTarget;
        document.getElementById("check-txt-expected").textContent =
          validation.value || certDcv.dns?.value || "";
        document.getElementById("check-txt-detected").textContent =
          validation.delegation_txt_detected || "-";
        const txtStatusEl = document.getElementById("check-txt-status");
        if (validation.delegation_txt_checked === true) {
          txtStatusEl.textContent = "正确";
          txtStatusEl.className = "status-badge success";
        } else if (validation.delegation_txt_checked === false) {
          txtStatusEl.textContent = "异常";
          txtStatusEl.className = "status-badge error";
        } else {
          txtStatusEl.textContent = "待检测";
          txtStatusEl.className = "status-badge";
        }
        document.getElementById("check-txt-error").textContent =
          validation.delegation_txt_error || "";
      }
    } else if (isDNS) {
      document.getElementById("check-query").textContent =
        validation.query ||
        `${
          validation.host || window.appState.cert.dcv?.dns?.host
        }.${validation.domain.replace("*.", "")}`;
      document.getElementById("check-expected").textContent =
        validation.value || window.appState.cert.dcv?.dns?.value || "";
      document.getElementById("check-detected").textContent =
        validation.detected_value || "-";
    } else if (isFile) {
      const protocol =
        validation.method === "file" ? "https:" : `${validation.method}:`;
      document.getElementById("check-link").textContent =
        validation.link ||
        `${protocol}//${validation.domain}/.well-known/pki-validation/${window.appState.cert.dcv?.file?.name}`;
      document.getElementById("check-file-expected").textContent =
        validation.content || window.appState.cert.dcv?.file?.content || "";
      document.getElementById("check-file-detected").textContent =
        validation.detected_value || "-";
    }

    // 更新状态
    const statusBadge = document.getElementById("check-status");
    if (validation.checked) {
      statusBadge.textContent = "验证通过";
      statusBadge.className = "status-badge success";
    } else {
      statusBadge.textContent = "验证失败";
      statusBadge.className = "status-badge error";
    }

    // 错误信息
    const errorRow = document.getElementById("check-error-row");
    const errorText = document.getElementById("check-error");
    if (errorRow && errorText) {
      if (validation.error) {
        errorRow.style.display = "table-row";
        errorText.textContent = validation.error;
      } else {
        errorRow.style.display = "none";
      }
    }

    // 同步"检测"按钮颜色（仅代表检测通过与否，不代表 CA 最终通过）
    const testBtn = document.getElementById("test-validation-btn");
    if (testBtn) {
      if (validation.checked) {
        testBtn.classList.add("btn-success");
      } else {
        testBtn.classList.remove("btn-success");
      }
    }
  }

  // 执行验证
  async function performVerification() {
    // 确保有验证数据
    if (!window.appState.validation || !window.appState.validation.domain) {
      showToast("验证信息不完整", "error");
      return;
    }

    setLoading(true, "recheck-btn");

    try {
      const certDcv = window.appState.cert?.dcv || {};
      const isDelegate = Boolean(certDcv.is_delegate);

      if (isDelegate) {
        // 委托验证：同时检测 CNAME 和 TXT
        const validation = window.appState.validation;
        const ca = certDcv.ca || window.appState.product?.ca || "";
        const channel = window.appState.cert?.channel || "";
        const prefix = getDelegationPrefix(ca, channel);
        const zone =
          validation.delegation_zone ||
          (validation.domain || "").replace(/^\*\./, "");
        const delegationTarget = validation.delegation_target || "";

        const cnameResult = await API.verifyCname(
          zone,
          prefix,
          delegationTarget
        );

        // TXT 检测（仅在有值时）
        const expectedTxtValue = validation.value || certDcv.dns?.value;
        const txtResult = expectedTxtValue
          ? await API.verifyDelegationTxt(delegationTarget, expectedTxtValue)
          : { checked: true, detected_value: "", error: "" };

        const overallChecked = cnameResult.checked && txtResult.checked;

        Object.assign(window.appState.validation, {
          checked: overallChecked,
          error: "",
          delegation_cname_checked: cnameResult.checked,
          delegation_cname_detected: cnameResult.detected_value || "",
          delegation_cname_error: cnameResult.error || "",
          delegation_txt_checked: txtResult.checked,
          delegation_txt_detected: txtResult.detected_value || "",
          delegation_txt_error: txtResult.error || ""
        });

        updateCheckDialog(window.appState.validation);

        // TXT 冲突检测：非阻塞，不影响主验证流程
        const cnameHost = `${prefix}.${zone}`;
        API.queryTxtRecords(cnameHost)
          .then(records => {
            if (records.length > 0) {
              showToast(
                `检测到 ${cnameHost} 存在TXT记录，TXT和CNAME同一名称同时存在会导致CNAME委托不生效，请删除TXT记录`,
                "error"
              );
            }
          })
          .catch(() => {});

        const testBtn = document.getElementById("test-validation-btn");
        if (testBtn) {
          if (overallChecked) testBtn.classList.add("btn-success");
          else testBtn.classList.remove("btn-success");
        }
        if (overallChecked) showToast("验证通过", "success");
      } else {
        // 普通验证
        const validationData = {
          domain: window.appState.validation.domain,
          method:
            window.appState.validation.method ||
            window.appState.cert?.dcv?.method,
          host:
            window.appState.validation.host ||
            window.appState.cert?.dcv?.dns?.host,
          value:
            window.appState.validation.value ||
            window.appState.cert?.dcv?.dns?.value,
          file_name:
            window.appState.validation.file_name ||
            window.appState.validation.name ||
            window.appState.cert?.dcv?.file?.name,
          file_content:
            window.appState.validation.file_content ||
            window.appState.validation.content ||
            window.appState.cert?.dcv?.file?.content,
          link: window.appState.validation.link
        };

        const result = await API.verifyDCV(validationData);

        Object.assign(window.appState.validation, result);
        updateCheckDialog(window.appState.validation);

        const testBtn = document.getElementById("test-validation-btn");
        if (testBtn) {
          if (result.checked) testBtn.classList.add("btn-success");
          else testBtn.classList.remove("btn-success");
        }
        if (result.checked) showToast("验证通过", "success");
      }
    } catch (error) {
      showToast(error.message, "error");
    } finally {
      setLoading(false, "recheck-btn");
    }
  }

  // 关闭弹窗
  function closeDialog() {
    document.getElementById("check-dialog").style.display = "none";
  }

  // 通用确认弹窗（返回 Promise<boolean>）
  function showConfirm(message) {
    return new Promise(resolve => {
      const dialog = document.getElementById("confirm-dialog");
      const msgEl = document.getElementById("confirm-message");
      const okBtn = document.getElementById("confirm-ok");
      const cancelBtn = document.getElementById("confirm-cancel");
      const overlay = dialog.querySelector(".dialog-overlay");
      const closeBtn = dialog.querySelector(".dialog-close");

      msgEl.textContent = message || "确定要继续吗？";
      dialog.style.display = "flex";

      const cleanup = () => {
        dialog.style.display = "none";
        okBtn.removeEventListener("click", onOk);
        cancelBtn.removeEventListener("click", onCancel);
        overlay && overlay.removeEventListener("click", onCancel);
        closeBtn && closeBtn.removeEventListener("click", onCancel);
      };
      const onOk = () => {
        cleanup();
        resolve(true);
      };
      const onCancel = () => {
        cleanup();
        resolve(false);
      };

      okBtn.addEventListener("click", onOk);
      cancelBtn.addEventListener("click", onCancel);
      overlay && overlay.addEventListener("click", onCancel);
      closeBtn && closeBtn.addEventListener("click", onCancel);
    });
  }

  // 刷新状态
  async function refreshStatus() {
    await checkOrder();
  }

  // 启动自动刷新
  function startAutoRefresh() {
    // 清除已有的定时器
    stopAutoRefresh();

    // 每60秒刷新一次
    window.appState.autoRefreshTimer = setInterval(async () => {
      // 页面不可见时跳过，减少无效请求
      if (document.hidden) {
        return;
      }
      if (window.appState.step === 1) {
        // 只在验证步骤时刷新
        await refreshStatus();
      } else {
        stopAutoRefresh();
      }
    }, 60000);
  }

  // 停止自动刷新
  function stopAutoRefresh() {
    if (window.appState.autoRefreshTimer) {
      clearInterval(window.appState.autoRefreshTimer);
      window.appState.autoRefreshTimer = null;
    }
  }

  // 复制所有DNS记录
  function copyAllDNSRecords() {
    const host = document.getElementById("dns-host")?.value || "";
    const value = document.getElementById("dns-value")?.value || "";
    const domain = document.getElementById("validation-domain")?.value || "";

    if (!host || !value) {
      showToast("DNS记录信息不完整", "error");
      return;
    }

    const type =
      document.getElementById("dns-type")?.value ||
      (window.appState.validation?.method || "TXT").toString().toUpperCase();
    const text = `域名: ${domain}\n主机记录: ${host}\n记录类型: ${type}\n记录值: ${value}`;

    copyToClipboard(text)
      .then(() => {
        showToast("DNS记录已复制到剪贴板", "success");
      })
      .catch(() => {
        showToast("复制失败，请手动复制", "error");
      });
  }

  // 更新验证方法
  async function updateValidationMethod() {
    const methodSelect = document.getElementById("validation-method-select");
    const newMethod = methodSelect.value;

    if (!newMethod) {
      showToast("请选择验证方式", "error");
      return;
    }

    // 检查是否真的改变了（与原始值比较）
    if (newMethod === window.appState.originalValidationMethod) {
      showToast("验证方式未改变", "info");
      return;
    }

    setLoading(true, "update-method-btn");

    try {
      await API.request("/update-validation-method", {
        method: "POST",
        body: {
          tid: window.appState.apply.tid,
          email: window.appState.apply.email,
          validation_method: newMethod
        }
      });

      window.appState.apply.validation_method = newMethod;
      window.appState.originalValidationMethod = newMethod; // 更新原始值
      showToast("修改成功", "success");

      // 刷新状态
      await checkOrder();
    } catch (error) {
      showToast(error.message || "修改失败", "error");
    } finally {
      setLoading(false, "update-method-btn");
    }
  }

  // 下载验证文件
  async function downloadValidationFile() {
    try {
      const response = await fetch(
        `${Config.getConfig("baseURL")}/validate-file`,
        {
          method: "POST",
          headers: {
            "Content-Type": "application/json"
          },
          body: JSON.stringify({
            tid: window.appState.apply.tid,
            email: window.appState.apply.email
          })
        }
      );

      if (!response.ok) {
        // 尝试读取后端错误消息
        const ct = response.headers.get("content-type") || "";
        if (ct.includes("application/json")) {
          try {
            const err = await response.json();
            throw new Error(err?.msg || "下载失败");
          } catch (_) {
            throw new Error("下载失败");
          }
        }
        throw new Error("下载失败");
      }

      const blob = await response.blob();
      const contentDisposition = response.headers.get("content-disposition");
      let filename = "validation.zip";

      if (contentDisposition) {
        const match = contentDisposition.match(/filename="?(.+)"?/);
        if (match && match[1]) {
          filename = match[1].replace(/"/g, "");
          try {
            filename = decodeURI(filename);
          } catch (_) {}
        }
      }

      // 创建下载链接
      const url = window.URL.createObjectURL(blob);
      const a = document.createElement("a");
      a.href = url;
      a.download = filename;
      document.body.appendChild(a);
      a.click();
      document.body.removeChild(a);
      window.URL.revokeObjectURL(url);

      showToast("下载成功", "success");
    } catch (error) {
      showToast("下载失败", "error");
    }
  }

  // 同步状态（用于等待签发页面）
  async function syncStatus() {
    setLoading(true, "sync-status-btn");

    try {
      // 直接调用 checkOrder 刷新状态
      await checkOrder();

      // 如果状态仍是 approving，提示用户
      if (window.appState.status === "approving") {
        showToast("证书正在签发中，请稍后再查询", "info");
      }
    } catch (error) {
      showToast(error.message, "error");
    } finally {
      setLoading(false, "sync-status-btn");
    }
  }

  // 下载证书
  async function downloadCert(type) {
    try {
      const response = await fetch(`${Config.getConfig("baseURL")}/download`, {
        method: "POST",
        headers: {
          "Content-Type": "application/json"
        },
        body: JSON.stringify({
          tid: window.appState.apply.tid,
          email: window.appState.apply.email,
          type: type
        })
      });

      if (!response.ok) {
        // 尝试读取后端错误消息
        const ct = response.headers.get("content-type") || "";
        if (ct.includes("application/json")) {
          try {
            const err = await response.json();
            throw new Error(err?.msg || "下载失败");
          } catch (_) {
            throw new Error("下载失败");
          }
        }
        throw new Error("下载失败");
      }

      const blob = await response.blob();
      const contentDisposition = response.headers.get("content-disposition");
      let filename = `certificate.${type}.zip`;

      if (contentDisposition) {
        const match = contentDisposition.match(/filename="?(.+)"?/);
        if (match && match[1]) {
          filename = match[1].replace(/"/g, "");
          try {
            filename = decodeURI(filename);
          } catch (_) {}
        }
      }

      // 创建下载链接
      const url = window.URL.createObjectURL(blob);
      const a = document.createElement("a");
      a.href = url;
      a.download = filename;
      document.body.appendChild(a);
      a.click();
      document.body.removeChild(a);
      window.URL.revokeObjectURL(url);

      showToast("下载成功", "success");
    } catch (error) {
      showToast("下载失败", "error");
    }
  }

  // 复制功能
  function setupCopyButtons() {
    document.querySelectorAll(".copy-btn").forEach(btn => {
      btn.addEventListener("click", function () {
        const targetId = this.getAttribute("data-copy");
        const target = document.getElementById(targetId);

        if (target) {
          let text = target.value || target.textContent;

          copyToClipboard(text)
            .then(() => {
              showToast("复制成功", "success");
            })
            .catch(() => {
              showToast("复制失败，请手动复制", "error");
            });
        }
      });
    });
  }

  // 初始化
  async function init() {
    // 加载配置
    await Config.loadConfig();

    // 绑定事件
    document
      .getElementById("tid-form")
      .addEventListener("submit", handleTidSubmit);
    document
      .getElementById("apply-form")
      .addEventListener("submit", handleApplySubmit);
    document.getElementById("query-btn").addEventListener("click", handleQuery);
    document
      .getElementById("check-validation-btn")
      .addEventListener("click", checkValidation);
    document
      .getElementById("test-validation-btn")
      ?.addEventListener("click", testValidation);
    document
      .getElementById("recheck-btn")
      .addEventListener("click", performVerification);
    document
      .getElementById("sync-status-btn")
      ?.addEventListener("click", syncStatus);

    // 复制全部DNS记录
    document
      .getElementById("copy-all-dns")
      ?.addEventListener("click", function (e) {
        e.preventDefault();
        copyAllDNSRecords();
      });

    // 验证方法相关事件
    document
      .getElementById("update-method-btn")
      ?.addEventListener("click", updateValidationMethod);
    document
      .getElementById("download-file-btn")
      ?.addEventListener("click", downloadValidationFile);
    document
      .getElementById("validation-method-select")
      ?.addEventListener("change", function () {
        // 仅提示：需点击“修改”后才应用新的验证方式
        const newMethod = this.value;
        if (
          newMethod &&
          newMethod !== window.appState.apply.validation_method
        ) {
          showToast("已选择新的验证方式，请点击“修改”应用", "info");
        }
      });

    // 绑定下载链接
    document.querySelectorAll(".download-link").forEach(link => {
      link.addEventListener("click", function (e) {
        e.preventDefault();
        const type = this.getAttribute("data-type");
        downloadCert(type);
      });
    });

    // 部署 tab 切换
    document.querySelectorAll(".deploy-tab-btn").forEach(btn => {
      btn.addEventListener("click", function () {
        document
          .querySelectorAll(".deploy-tab-btn")
          .forEach(b => b.classList.remove("active"));
        this.classList.add("active");
        const tab = this.getAttribute("data-deploy-tab");
        document
          .querySelectorAll(".deploy-tab-content")
          .forEach(c => (c.style.display = "none"));
        const target = document.getElementById("deploy-tab-" + tab);
        if (target) target.style.display = "block";
      });
    });

    // 部署 Windows 版本 toggle（2019+ / 2016/2012）
    const tls12Line =
      "[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12;";
    document.querySelectorAll(".deploy-win-toggle").forEach(toggle => {
      const targetId = toggle.getAttribute("data-target");
      const source = toggle.getAttribute("data-source");
      toggle.querySelectorAll(".deploy-win-btn").forEach(btn => {
        btn.addEventListener("click", function () {
          toggle
            .querySelectorAll(".deploy-win-btn")
            .forEach(b => b.classList.remove("active"));
          this.classList.add("active");
          const ver = this.getAttribute("data-win-ver");
          const baseCmd =
            window.appState.deployData?.[source]?.windows || "";
          const field = document.getElementById(targetId);
          if (field) {
            field.value =
              ver === "2016" ? tls12Line + "\n" + baseCmd : baseCmd;
          }
        });
      });
    });

    // 弹窗关闭
    document
      .querySelectorAll(".dialog-close, .dialog-close-btn")
      .forEach(btn => {
        btn.addEventListener("click", closeDialog);
      });

    // 点击遮罩关闭弹窗（判空避免 DOM 缺失报错）
    const overlay = document.querySelector(".dialog-overlay");
    if (overlay) {
      overlay.addEventListener("click", closeDialog);
    }

    // 设置复制按钮
    setupCopyButtons();

    // 从路径参数初始化（兼容 hash/query）
    const initial = Router.readTidEmail();
    if (initial.tid) {
      document.getElementById("tid").value = initial.tid;
      window.appState.apply.tid = initial.tid;
      window.appState.apply.email = initial.email || "";
      await checkOrder();
    }

    // 监听浏览器前进/后退及 hash 变化
    Router.onChange(async () => {
      const p = Router.readTidEmail();
      window.appState.apply.tid = p.tid || "";
      window.appState.apply.email = p.email || "";
      if (p.tid) {
        const tidInput = document.getElementById("tid");
        if (tidInput) tidInput.value = p.tid;
        await checkOrder();
      } else {
        setStep(-1);
      }
    });
  }

  // DOM加载完成后初始化
  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
  } else {
    init();
  }
})();
