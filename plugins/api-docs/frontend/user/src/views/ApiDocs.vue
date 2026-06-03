<script setup lang="ts">
import { ref, computed, onMounted, onBeforeUnmount } from "vue";

defineOptions({ name: "ApiDocs" });

// 三套对外 API；spec 由主系统公开端点提供（iframe 内同源 fetch）
const segOptions = [
  { label: "API v2", value: "v2" },
  { label: "ACME", value: "acme" },
  { label: "Deploy", value: "deploy" }
];
const active = ref("v2");

// iframe 高度自适应：先按 视口高-顶部距离 设一个值，下一帧测整页实际溢出量再扣除，
// 这样不依赖页脚/内边距的具体数值，底部有任何占位都能吃掉
const frameEl = ref<HTMLIFrameElement>();
const frameHeight = ref("600px");
function updateHeight() {
  if (!frameEl.value) return;
  const top = frameEl.value.getBoundingClientRect().top;
  frameHeight.value = `${Math.max(window.innerHeight - top - 8, 360)}px`;
  requestAnimationFrame(() => {
    if (!frameEl.value) return;
    // 找 iframe 真正的滚动祖先（Pure Admin 用 el-scrollbar 内部滚动，documentElement 不滚）
    let node: HTMLElement | null = frameEl.value.parentElement;
    while (node && node !== document.body) {
      const oy = getComputedStyle(node).overflowY;
      if (
        (oy === "auto" || oy === "scroll") &&
        node.scrollHeight > node.clientHeight
      )
        break;
      node = node.parentElement;
    }
    const scroller =
      node && node !== document.body ? node : document.documentElement;
    const overflow = scroller.scrollHeight - scroller.clientHeight;
    if (overflow > 0) {
      frameHeight.value = `${Math.max(parseFloat(frameHeight.value) - overflow, 360)}px`;
    }
  });
}
onMounted(() => {
  updateHeight();
  window.addEventListener("resize", updateHeight);
});
onBeforeUnmount(() => window.removeEventListener("resize", updateHeight));

// Scalar 跑在 iframe 内（官方 standalone bundle，自带 Vue）：与主系统完全隔离、
// 布局为 Scalar 原生行为；srcdoc 继承父页 origin，故 /plugins 与 /api 均同源。
// try-it 保留（对外 API 需 Bearer Token）；Ask AI(agent) 关闭。
// 注入 CSS 隐藏 Introduction（概述）章节与其侧栏项。
const srcdoc = computed(() => {
  // 在父页拼好绝对地址（origin 明确），不依赖 srcdoc 内的相对解析（其 base 是 about:srcdoc、
  // location.origin 可能为 "null"，会让 spec 的相对 server 拼成 null → test request 地址 null）
  const origin = window.location.origin;
  const cfg = JSON.stringify({
    url: `${origin}/api/meta/api-doc?surface=${active.value}`,
    servers: [{ url: `${origin}/api/${active.value}` }],
    layout: "modern",
    hideClientButton: true,
    hideDownloadButton: false,
    agentEnabled: false,
    // 只隐藏概述描述文字，保留 introduction 区的「下载 OpenAPI 文档」按钮（与 description 平级）
    customCss:
      ".introduction-description{display:none!important}.section-container{border-top:none!important}"
  });
  return [
    "<!doctype html><html><head><meta charset='utf-8'>",
    // base 设为父页 origin：srcdoc 默认 base 是 about:srcdoc，会让 Scalar 解析相对 server
    // (/api/v2) 得到 null（test request 地址显示 null）；指定后正确拼成 origin/api/<surface>
    `<base href='${window.location.origin}/'>`,
    "<style>html,body,#app{height:100%;margin:0}",
    ".introduction-description{display:none!important}",
    ".section-container{border-top:none!important}",
    "[class*='sidebar'] a[href*='description'],[class*='sidebar'] a[href*='introduction']{display:none!important}",
    "</style></head>",
    "<body><div id='app'></div>",
    "<script src='/plugins/api-docs/frontend/user/scalar-standalone.js'><\/script>",
    `<script>Scalar.createApiReference('#app',${cfg});(function(){var h=function(r){r.querySelectorAll('*').forEach(function(e){if(e.shadowRoot)h(e.shadowRoot);if(!e.children.length&&e.textContent.trim()==='Introduction'){(e.closest('li')||e.closest('a')||e).style.setProperty('display','none','important')}})};new MutationObserver(function(){h(document)}).observe(document.documentElement,{childList:true,subtree:true});h(document)})()<\/script>`,
    "</body></html>"
  ].join("");
});
</script>

<template>
  <div class="api-docs">
    <div class="api-docs__bar">
      <el-segmented v-model="active" :options="segOptions" />
    </div>
    <iframe
      ref="frameEl"
      :srcdoc="srcdoc"
      :style="{ height: frameHeight }"
      class="api-docs__frame"
      title="接口文档"
    />
  </div>
</template>

<style scoped>
.api-docs__bar {
  padding: 0 16px 12px 0;
}
.api-docs__frame {
  display: block;
  width: 100%;
  border: 0;
}
</style>
