<template>
  <div class="main">
    <template v-if="acme">
      <div class="layout-main bg-bg_color">
        <el-scrollbar class="horizontal-scrollbar" wrap-class="scroll-wrapper">
          <div class="box">
            <el-tabs v-model="activeTab">
              <el-tab-pane label="ACME" name="acme">
                <!-- 订单详情（状态按钮 + 操作下拉 + 刷新一并放在标题旁） -->
                <el-card shadow="never" :style="{ border: 'none' }">
                  <h2 class="title">
                    <span style="margin-right: 12px">订单详情</span>
                    <el-button
                      ref="statusButton"
                      :type="statusType[acme.status] || 'info'"
                      size="small"
                      class="no-hover-effect"
                    >
                      {{ status[acme.status] || acme.status }}
                    </el-button>
                    <span style="margin-left: 15px">
                      <AcmeOperate :acme="acme" @refresh="handleRefresh" />
                    </span>
                  </h2>
                  <table class="descriptions">
                    <tbody>
                      <tr>
                        <td class="label">用户</td>
                        <td class="content">
                          {{ acme.user?.username || "-" }}
                        </td>
                      </tr>
                      <tr>
                        <td class="label">订单ID</td>
                        <td class="content">{{ acme.id }}</td>
                      </tr>
                      <tr>
                        <td class="label">品牌</td>
                        <td class="content">{{ acme.brand }}</td>
                      </tr>
                      <tr>
                        <td class="label">产品</td>
                        <td class="content">{{ acme.product?.name || "-" }}</td>
                      </tr>
                      <tr v-if="acme.channel">
                        <td class="label">来源</td>
                        <td class="content">
                          {{ getChannelLabel(acme.channel) }}
                        </td>
                      </tr>
                      <tr v-if="acme.api_id">
                        <td class="label">接口Id</td>
                        <td class="content">{{ acme.api_id }}</td>
                      </tr>
                      <tr v-if="acme.vendor_id">
                        <td class="label">CA订单Id</td>
                        <td class="content">{{ acme.vendor_id }}</td>
                      </tr>
                      <tr>
                        <td class="label">购买时长</td>
                        <td class="content">{{ acme.period }} 个月</td>
                      </tr>
                      <tr v-if="acme.plus">
                        <td class="label">赠送时间</td>
                        <td class="content">是</td>
                      </tr>
                      <tr>
                        <td class="label">金额</td>
                        <td class="content">¥{{ acme.amount }}</td>
                      </tr>
                      <tr>
                        <td class="label">标准域名额度</td>
                        <td class="content">
                          {{ acme.purchased_standard_count }}
                        </td>
                      </tr>
                      <tr>
                        <td class="label">通配符域名额度</td>
                        <td class="content">
                          {{ acme.purchased_wildcard_count }}
                        </td>
                      </tr>
                      <tr>
                        <td class="label">创建时间</td>
                        <td class="content">
                          {{ formatDate(acme.created_at) }}
                        </td>
                      </tr>
                      <tr>
                        <td class="label">有效期从</td>
                        <td class="content">
                          {{ formatDate(acme.period_from) }}
                        </td>
                      </tr>
                      <tr>
                        <td class="label">有效期到</td>
                        <td class="content">
                          {{ formatDate(acme.period_till) }}
                        </td>
                      </tr>
                      <tr v-if="acme.cancelled_at">
                        <td class="label">取消时间</td>
                        <td class="content">
                          {{ formatDate(acme.cancelled_at) }}
                        </td>
                      </tr>
                      <tr v-if="acme.remark">
                        <td class="label">备注</td>
                        <td class="content">{{ acme.remark }}</td>
                      </tr>
                      <tr>
                        <td class="label">管理员备注</td>
                        <td class="content">
                          <span>{{ acme.admin_remark || "-" }}</span>
                          <el-button
                            link
                            size="small"
                            class="copy-btn"
                            @click="openRemarkDialog"
                          >
                            编辑
                          </el-button>
                        </td>
                      </tr>
                    </tbody>
                  </table>
                </el-card>

                <!-- EAB 凭据 -->
                <el-card shadow="never" :style="{ border: 'none' }">
                  <h2 class="title">
                    <span>EAB 凭据</span>
                  </h2>
                  <table class="descriptions">
                    <tbody>
                      <tr>
                        <td class="label">端点</td>
                        <td class="content">
                          <span class="break-all">{{
                            acme.directory_url || "-"
                          }}</span>
                          <el-button
                            v-if="acme.directory_url"
                            link
                            size="small"
                            class="copy-btn"
                            @click="handleCopy(acme.directory_url!)"
                          >
                            <el-icon size="14"><DocumentCopy /></el-icon>
                          </el-button>
                        </td>
                      </tr>
                      <tr>
                        <td class="label">账号邮箱</td>
                        <td class="content">
                          <span class="break-all">{{
                            acme.contact_email || "-"
                          }}</span>
                          <el-button
                            v-if="acme.contact_email"
                            link
                            size="small"
                            class="copy-btn"
                            @click="handleCopy(acme.contact_email!)"
                          >
                            <el-icon size="14"><DocumentCopy /></el-icon>
                          </el-button>
                        </td>
                      </tr>
                      <tr>
                        <td class="label">EAB KID</td>
                        <td class="content">
                          <span class="break-all">{{
                            acme.eab_kid || "-"
                          }}</span>
                          <el-button
                            v-if="acme.eab_kid"
                            link
                            size="small"
                            class="copy-btn"
                            @click="handleCopy(acme.eab_kid!)"
                          >
                            <el-icon size="14"><DocumentCopy /></el-icon>
                          </el-button>
                        </td>
                      </tr>
                      <tr>
                        <td class="label">EAB HMAC</td>
                        <td class="content">
                          <span class="break-all">{{
                            acme.eab_hmac || "-"
                          }}</span>
                          <el-button
                            v-if="acme.eab_hmac"
                            link
                            size="small"
                            class="copy-btn"
                            @click="handleCopy(acme.eab_hmac!)"
                          >
                            <el-icon size="14"><DocumentCopy /></el-icon>
                          </el-button>
                        </td>
                      </tr>
                    </tbody>
                  </table>
                </el-card>
              </el-tab-pane>
            </el-tabs>
          </div>
        </el-scrollbar>
      </div>
    </template>

    <el-dialog v-model="remarkDialogVisible" title="编辑管理员备注" width="500">
      <el-input
        v-model="remarkInput"
        type="textarea"
        :rows="3"
        maxlength="255"
        show-word-limit
      />
      <template #footer>
        <el-button @click="remarkDialogVisible = false">取消</el-button>
        <el-button type="primary" @click="handleRemark">确定</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup lang="ts">
import { nextTick, onMounted, onBeforeUnmount, ref } from "vue";
import { getAcmeDetail, remarkAcme } from "@/api/acme";
import type { Acme } from "@/api/acme";
import { status, statusType, getChannelLabel } from "./dictionary";
import { useRoute } from "vue-router";
import { message } from "@shared/utils";
import { DocumentCopy } from "@element-plus/icons-vue";
import { ElButton } from "element-plus";
import AcmeOperate from "./operate.vue";
import dayjs from "dayjs";

defineOptions({
  name: "AcmeDetails"
});

const acme = ref<Acme | null>(null);
const activeTab = ref("acme");
const remarkDialogVisible = ref(false);
const remarkInput = ref("");
const statusButton = ref<InstanceType<typeof ElButton> | null>(null);

const formatDate = (date: string | null) => {
  return date ? dayjs(date).format("YYYY-MM-DD HH:mm:ss") : "-";
};

const handleCopy = (text: string) => {
  navigator.clipboard
    .writeText(text)
    .then(() => {
      message("已复制到剪贴板", { type: "success" });
    })
    .catch(() => {
      message("复制失败，请手动复制", { type: "error" });
    });
};

// 弹窗打开时才读 acme.admin_remark 到 remarkInput，
// 避免 3 分钟自动刷新覆盖正在编辑中的输入（与 user 端 openRemarkDialog 风格对齐）
const openRemarkDialog = () => {
  remarkInput.value = acme.value?.admin_remark || "";
  remarkDialogVisible.value = true;
};

const handleRemark = () => {
  if (!acme.value) return;
  remarkAcme(acme.value.id, remarkInput.value).then(() => {
    message("备注已更新", { type: "success" });
    remarkDialogVisible.value = false;
    getDetails();
  });
};

const route = useRoute();

// 与传统 SSL process.vue 一致：把当前状态按钮的"原始"颜色记到 CSS 变量，
// 再用 .no-hover-effect 还原，避免 hover/focus/active 时颜色变化（状态按钮只是展示态）
const lockStatusButtonColors = () => {
  nextTick(() => {
    if (!statusButton.value) return;
    const el = statusButton.value.$el as HTMLElement;
    const styles = window.getComputedStyle(el);
    el.style.setProperty("--original-bg-color", styles.backgroundColor);
    el.style.setProperty("--original-border-color", styles.borderColor);
    el.style.setProperty("--original-text-color", styles.color);
  });
};

const getDetails = (showMessage = false) => {
  const ids = route.params.ids;
  if (ids) {
    getAcmeDetail(Number(ids)).then(res => {
      acme.value = res.data;
      lockStatusButtonColors();
      showMessage && message("刷新成功", { type: "success" });
    });
  }
};

// operate 组件 emit('refresh', showMessage?)
const handleRefresh = (showMessage = false) => getDetails(showMessage);

// 与传统订单详情一致：3 分钟自动刷新
type TimerRef = ReturnType<typeof setInterval>;
let autoRefreshIntervalId: TimerRef | null = null;

onMounted(() => {
  getDetails();
  autoRefreshIntervalId = setInterval(
    () => {
      getDetails();
    },
    3 * 60 * 1000
  );
});

onBeforeUnmount(() => {
  if (autoRefreshIntervalId !== null) {
    clearInterval(autoRefreshIntervalId);
    autoRefreshIntervalId = null;
  }
});
</script>

<style scoped lang="scss">
.layout-main {
  width: 100%;
  height: 100%;
  margin-bottom: 20px;
  overflow: hidden;
}

.box {
  float: left;
  width: 100%;
  min-width: 920px;
  padding: 20px;
  margin-top: 10px;
  border: 1px solid var(--ba-border-color);
}

.title {
  margin-bottom: 20px;
  color: var(--el-text-color-regular);

  span,
  button {
    vertical-align: middle;
  }
}

.descriptions {
  width: 100%;
  margin-bottom: 10px;
  font-size: 14px;
  line-height: 28px;
  border-left: 4px solid var(--el-border-color);
}

.label {
  display: inline-block;
  width: 140px;
  margin-right: 16px;
  vertical-align: top;
  color: var(--el-text-color-regular);
  text-align: right;
}

.content {
  display: inline-block;
  width: calc(100% - 156px);
  vertical-align: top;
  color: var(--el-text-color-regular);
  overflow-wrap: break-word;
}

.copy-btn {
  padding: 0 5px;
  margin-left: 6px;
  border: 0;
}

/* 与 SSL 详情页一致：状态按钮 hover/focus/active 时保持原色 */
.no-hover-effect {
  --original-bg-color: initial;
  --original-border-color: initial;
  --original-text-color: initial;
}

.no-hover-effect:hover,
.no-hover-effect:focus,
.no-hover-effect:active {
  color: var(--original-text-color) !important;
  background-color: var(--original-bg-color) !important;
  border-color: var(--original-border-color) !important;
  box-shadow: none !important;
}

.horizontal-scrollbar {
  width: 100%;
  overflow-x: auto;
}

.scroll-wrapper {
  white-space: nowrap;
}
</style>
