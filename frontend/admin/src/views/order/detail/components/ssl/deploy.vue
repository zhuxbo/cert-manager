<template>
  <div class="deploy-section">
    <el-tabs v-model="activeTab" class="deploy-tabs">
      <el-tab-pane label="宝塔面板" name="bt">
        <div class="deploy-step">
          <div class="step-title">第一步：安装 sslbt</div>
          <div class="command-block">
            <div class="command-label">Linux</div>
            <div class="command-line">
              <code>{{ commands.bt_install?.linux }}</code>
              <el-button
                type="primary"
                link
                size="small"
                :disabled="!isActive"
                @click="copy(commands.bt_install?.linux)"
                >复制</el-button
              >
            </div>
          </div>
        </div>
        <div class="deploy-step">
          <div class="step-title">第二步：一键部署</div>
          <div class="command-block">
            <div class="command-line">
              <code>{{ commands.bt_deploy }}</code>
              <el-button
                type="primary"
                link
                size="small"
                :disabled="!isActive"
                @click="copy(commands.bt_deploy)"
                >复制</el-button
              >
            </div>
          </div>
        </div>
      </el-tab-pane>
      <el-tab-pane label="Nginx / Apache" name="nginx">
        <div class="deploy-step">
          <div class="step-title">第一步：安装 sslctl</div>
          <div class="command-block">
            <div class="command-label">Linux</div>
            <div class="command-line">
              <code>{{ commands.install?.linux }}</code>
              <el-button
                type="primary"
                link
                size="small"
                :disabled="!isActive"
                @click="copy(commands.install?.linux)"
                >复制</el-button
              >
            </div>
          </div>
          <div class="command-block">
            <div class="command-label">
              Windows (PowerShell)
              <el-radio-group
                v-model="winVersion"
                size="small"
                style="margin-left: 12px"
              >
                <el-radio-button value="2019">2019+</el-radio-button>
                <el-radio-button value="2016">2016/2012</el-radio-button>
              </el-radio-group>
            </div>
            <div class="command-line">
              <code>{{ windowsInstallCmd }}</code>
              <el-button
                type="primary"
                link
                size="small"
                :disabled="!isActive"
                @click="copy(windowsInstallCmd)"
                >复制</el-button
              >
            </div>
          </div>
        </div>
        <div class="deploy-step">
          <div class="step-title">第二步：一键部署</div>
          <div class="command-block">
            <div class="command-line">
              <code>{{ commands.deploy }}</code>
              <el-button
                type="primary"
                link
                size="small"
                :disabled="!isActive"
                @click="copy(commands.deploy)"
                >复制</el-button
              >
            </div>
          </div>
        </div>
      </el-tab-pane>
      <el-tab-pane label="IIS" name="iis">
        <div class="deploy-step">
          <div class="step-title">第一步：安装 sslctlw</div>
          <div class="command-block">
            <div class="command-label">
              Windows (PowerShell)
              <el-radio-group
                v-model="winVersion"
                size="small"
                style="margin-left: 12px"
              >
                <el-radio-button value="2019">2019+</el-radio-button>
                <el-radio-button value="2016">2016/2012</el-radio-button>
              </el-radio-group>
            </div>
            <div class="command-line">
              <code>{{ iisWindowsInstallCmd }}</code>
              <el-button
                type="primary"
                link
                size="small"
                :disabled="!isActive"
                @click="copy(iisWindowsInstallCmd)"
                >复制</el-button
              >
            </div>
          </div>
        </div>
        <div class="deploy-step">
          <div class="step-title">第二步：一键部署</div>
          <div class="command-block">
            <div class="command-line">
              <code>{{ commands.iis_deploy }}</code>
              <el-button
                type="primary"
                link
                size="small"
                :disabled="!isActive"
                @click="copy(commands.iis_deploy)"
                >复制</el-button
              >
            </div>
          </div>
        </div>
      </el-tab-pane>
      <el-tab-pane label="URL" name="url">
        <div class="deploy-step">
          <div class="step-title">证书</div>
          <div class="command-block">
            <div class="command-label">
              <span
                >GET 请求返回 PEM 全链证书（cert + intermediate），适配
                certimate 等支持 URL 拉取的部署工具</span
              >
              <el-radio-group
                v-model="urlIdentifier"
                size="small"
                style="margin-left: 12px"
              >
                <el-radio-button value="domain">域名</el-radio-button>
                <el-radio-button value="id">ID</el-radio-button>
              </el-radio-group>
            </div>
            <div class="command-line">
              <code>{{ certUrl }}</code>
              <el-button
                type="primary"
                link
                size="small"
                :disabled="!isActive"
                @click="copy(certUrl)"
                >复制</el-button
              >
            </div>
          </div>
        </div>
        <div class="deploy-step">
          <div class="step-title">私钥</div>
          <div class="command-block">
            <div class="command-label">GET 请求返回 PEM 私钥</div>
            <div class="command-line">
              <code>{{ keyUrl }}</code>
              <el-button
                type="primary"
                link
                size="small"
                :disabled="!isActive"
                @click="copy(keyUrl)"
                >复制</el-button
              >
            </div>
          </div>
        </div>
      </el-tab-pane>
      <el-tab-pane label="部署记录" name="reports" lazy>
        <AutoDeployReports
          :load="AutoDeployReportApi.index"
          :order-id="order.id"
        />
      </el-tab-pane>
    </el-tabs>
  </div>
</template>

<script setup lang="ts">
import { inject, ref, computed, watch } from "vue";
import * as OrderApi from "@/api/order";
import * as AutoDeployReportApi from "@/api/autoDeployReport";
import { message } from "@shared/utils";
import AutoDeployReports from "@shared/components/AutoDeployReports.vue";

const order = inject("order") as any;
const cert = inject("cert") as any;

const commands = ref<any>({});
const activeTab = ref("bt");
const winVersion = ref("2019");
const urlIdentifier = ref<"domain" | "id">("domain");

const isActive = computed(() => cert.value?.status === "active");

const tls12Prefix =
  "[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12;";

const windowsInstallCmd = computed(() => {
  const base = commands.value.install?.windows || "";
  return winVersion.value === "2016" ? `${tls12Prefix}\n${base}` : base;
});

const iisWindowsInstallCmd = computed(() => {
  const base = commands.value.iis_install?.windows || "";
  return winVersion.value === "2016" ? `${tls12Prefix}\n${base}` : base;
});

const certUrl = computed(() =>
  urlIdentifier.value === "domain"
    ? commands.value.cert_url_domain || commands.value.cert_url_id
    : commands.value.cert_url_id
);

const keyUrl = computed(() =>
  urlIdentifier.value === "domain"
    ? commands.value.key_url_domain || commands.value.key_url_id
    : commands.value.key_url_id
);

watch(
  isActive,
  val => {
    if (val) {
      OrderApi.deployCommands(order.id).then(res => {
        if (res.code === 1) {
          commands.value = res.data;
        }
      });
    }
  },
  { immediate: true }
);

const copy = (content: string) => {
  if (!content) return;
  navigator.clipboard
    .writeText(content)
    .then(() => {
      message("复制成功", { type: "success" });
    })
    .catch(() => {
      message("复制失败", { type: "error" });
    });
};
</script>

<style scoped lang="scss">
.deploy-section {
  padding: 5px 0;
}

.deploy-tabs {
  :deep(.el-tabs__header) {
    margin-bottom: 8px;
  }
}

.deploy-step {
  margin-bottom: 12px;
}

.step-title {
  margin-bottom: 6px;
  font-weight: 500;
  color: var(--el-text-color-primary);
}

.command-block {
  margin-bottom: 8px;
}

.command-label {
  display: flex;
  align-items: center;
  margin-bottom: 2px;
  font-size: 12px;
  color: var(--el-text-color-secondary);
}

.command-line {
  display: flex;
  gap: 8px;
  align-items: center;
  padding: 6px 10px;
  background: var(--el-fill-color-light);
  border-radius: 4px;

  code {
    flex: 1;
    font-family: Consolas, Monaco, monospace;
    font-size: 12px;
    color: var(--el-text-color-regular);
    word-break: break-all;
  }
}
</style>
