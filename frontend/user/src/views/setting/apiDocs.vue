<script setup lang="ts">
import { ref } from "vue";
// 接口文档源在后端 backend/resources/docs/api/*.md（单一来源），build 期由
// unplugin-vue-markdown 编译为 Vue 组件，运行时不带 markdown 库
import V2Doc from "@apidoc/v2.md";
import AcmeDoc from "@apidoc/acme.md";
import DeployDoc from "@apidoc/deploy.md";

defineOptions({ name: "ApiDocs" });

const visible = ref(false);
const active = ref("v2");
</script>

<template>
  <el-card shadow="never" :style="{ border: 'none', paddingTop: '20px' }">
    <div class="api-docs__header">
      <div>
        <div class="api-docs__title">接口文档</div>
        <div class="api-docs__desc">
          对外 API（v2 / ACME / Deploy）接入说明，随版本同步
        </div>
      </div>
      <el-button type="primary" plain @click="visible = true">
        查看文档
      </el-button>
    </div>

    <el-drawer v-model="visible" title="接口文档" size="62%">
      <el-tabs v-model="active">
        <el-tab-pane label="API v2" name="v2">
          <article class="markdown-body"><V2Doc /></article>
        </el-tab-pane>
        <el-tab-pane label="ACME" name="acme">
          <article class="markdown-body"><AcmeDoc /></article>
        </el-tab-pane>
        <el-tab-pane label="Deploy" name="deploy">
          <article class="markdown-body"><DeployDoc /></article>
        </el-tab-pane>
      </el-tabs>
    </el-drawer>
  </el-card>
</template>

<style scoped lang="scss">
.api-docs__header {
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.api-docs__title {
  font-size: 16px;
  font-weight: 600;
  color: var(--el-text-color-primary);
}

.api-docs__desc {
  margin-top: 4px;
  font-size: 13px;
  color: var(--el-text-color-secondary);
}

// 编译后的 Markdown 为裸 HTML，给最小可读样式
.markdown-body {
  font-size: 14px;
  line-height: 1.7;
  color: var(--el-text-color-primary);
  word-break: break-word;

  :deep(h1) {
    margin: 0 0 12px;
    font-size: 20px;
  }

  :deep(h2) {
    margin: 20px 0 10px;
    font-size: 17px;
  }

  :deep(p) {
    margin: 8px 0;
  }

  :deep(table) {
    width: 100%;
    margin: 12px 0;
    border-collapse: collapse;
  }

  :deep(th),
  :deep(td) {
    padding: 6px 10px;
    text-align: left;
    border: 1px solid var(--el-border-color);
  }

  :deep(th) {
    background: var(--el-fill-color-light);
  }

  :deep(code) {
    padding: 1px 5px;
    font-size: 12.5px;
    background: var(--el-fill-color-light);
    border-radius: 3px;
  }

  :deep(pre) {
    padding: 12px;
    overflow-x: auto;
    background: var(--el-fill-color-light);
    border-radius: 6px;

    code {
      padding: 0;
      background: none;
    }
  }
}
</style>
