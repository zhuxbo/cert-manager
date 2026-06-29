<template>
  <el-card shadow="never" :style="{ border: 'none' }">
    <h2 class="title">
      <span style="margin-right: 12px">订单状态</span>
      <el-button
        ref="statusButton"
        :type="statusType[cert?.status]"
        size="small"
        class="no-hover-effect"
        >{{ status[cert?.status] }}</el-button
      >
      <span style="margin-left: 15px">
        <Operate v-if="showOperate" />
      </span>
    </h2>
    <table class="descriptions" style="width: 100%">
      <tbody>
        <tr>
          <td class="label">
            <el-icon :size="16" class="icon" :color="commitColor">
              <Select />
            </el-icon>
          </td>
          <td class="content">提交订单</td>
        </tr>
        <tr v-if="order.product.validation_type !== 'dv'">
          <td class="label">
            <el-icon :size="16" class="icon" :color="orgValidationColor">
              <Select />
            </el-icon>
          </td>
          <td class="content">企业验证</td>
        </tr>
        <!-- OV/EV Certum 文档上传 -->
        <tr
          v-if="
            order.product.validation_type !== 'dv' &&
            order.brand?.toLowerCase() === 'certum' &&
            cert?.status !== 'active'
          "
        >
          <td class="label" />
          <td class="content">
            <Documents v-if="hasDocuments" />
            <DocumentUpload />
          </td>
        </tr>
        <!-- SSL 域名验证 -->
        <tr>
          <td class="label">
            <el-icon :size="16" class="icon" :color="validationColor">
              <Select />
            </el-icon>
          </td>
          <td class="content">域名验证</td>
        </tr>
        <tr>
          <td class="label" />
          <td class="content"><Validation /></td>
        </tr>
        <tr>
          <td class="label">
            <el-icon :size="16" class="icon" :color="issuedColor">
              <Select />
            </el-icon>
          </td>
          <td class="content">证书部署</td>
        </tr>
        <tr>
          <td class="label" />
          <td class="content">
            <div class="deploy-block">
              <div class="deploy-block-title">下载证书</div>
              <Install />
            </div>
            <div v-if="!isGm" class="deploy-block">
              <div class="deploy-block-title">自动部署</div>
              <Deploy />
            </div>
            <!-- 块3 仅在有 widget（cloud-deploy 插件已安装并注入插槽）时渲染，
                 否则未装插件的实例每个订单详情会出现空标题「推送到云平台」+ 左竖条（悬空空块） -->
            <div v-if="sslActionWidgets.length" class="deploy-block">
              <div class="deploy-block-title">推送到云平台</div>
              <component
                :is="w.component"
                v-for="w in sslActionWidgets"
                :key="w.name"
                :order="order"
                :cert="cert"
              />
            </div>
          </td>
        </tr>
      </tbody>
    </table>
  </el-card>
</template>
<script setup lang="ts">
import { computed, inject, onMounted, ref } from "vue";
import { statusType, status } from "@/views/order/dictionary";
import Operate from "./operate.vue";
import Validation from "./validation.vue";
import Install from "./install.vue";
import Deploy from "./deploy.vue";
import Documents from "../documents.vue";
import DocumentUpload from "../documentUpload.vue";
import { ElButton } from "element-plus";
import { Select } from "@element-plus/icons-vue";
import dayjs from "dayjs";
import { getPluginWidgets } from "@shared/utils/plugin-loader";

const sslActionWidgets = getPluginWidgets("admin-order-detail-ssl-actions");

const order = inject("order") as any;
const cert = inject("cert") as any;
// 国密(SM2)证书为双证书，不支持单证书自动部署，隐藏自动部署入口（后端 Deploy API 亦拒绝）
const isGm = computed(() => /sm2/i.test(cert.value?.encryption_alg ?? ""));
// 检查是否有文档
const hasDocuments = computed(() => {
  const docs = cert.value?.documents;
  if (!docs) return false;
  return Array.isArray(docs) ? docs.length > 0 : true;
});

const getStatusColor = (statusField: string) => {
  return computed(() => {
    if (cert.value.status === "active") {
      return "var(--el-color-success)";
    }
    if (["processing", "pending"].includes(cert.value.status)) {
      return cert.value[statusField] == 2
        ? "var(--el-color-success)"
        : "var(--el-text-color-regular)";
    }
    return "var(--el-text-color-regular)";
  });
};

const commitColor = getStatusColor("cert_apply_status");
const orgValidationColor = getStatusColor("org_verify_status");
const validationColor = getStatusColor("domain_verify_status");
const issuedColor = computed(() =>
  cert.value.status === "active"
    ? "var(--el-color-success)"
    : "var(--el-text-color-regular)"
);

const showOperate = computed(() => {
  const validStatuses = [
    "unpaid",
    "pending",
    "processing",
    "active",
    "expired",
    "approving",
    "cancelling"
  ];
  if (!validStatuses.includes(cert.value?.status)) return false;
  if (!order?.period_till) return true;
  const periodTill = dayjs(order.period_till);
  return periodTill.isValid() && periodTill.isAfter(dayjs());
});

const statusButton = ref<InstanceType<typeof ElButton> | null>(null);

onMounted(() => {
  if (statusButton.value) {
    const buttonElement = statusButton.value.$el as HTMLElement;
    const styles = window.getComputedStyle(buttonElement);

    const bgColor = styles.backgroundColor;
    const borderColor = styles.borderColor;
    const textColor = styles.color;

    buttonElement.style.setProperty("--original-bg-color", bgColor);
    buttonElement.style.setProperty("--original-border-color", borderColor);
    buttonElement.style.setProperty("--original-text-color", textColor);
  }
});
</script>
<style scoped lang="scss">
@import url("../../styles/detail.scss");

.deploy-block {
  position: relative;
  padding-left: 12px;
  margin-bottom: 16px;

  &:last-child {
    margin-bottom: 0;
  }

  &::before {
    content: "";
    position: absolute;
    top: 2px;
    bottom: 2px;
    left: 0;
    width: 3px;
    border-radius: 2px;
    background: var(--el-color-primary-light-5);
  }
}

.deploy-block-title {
  margin-bottom: 8px;
  font-weight: 600;
  color: var(--el-text-color-primary);
}

.label {
  width: 35px;
  margin-right: 5px;

  .icon {
    margin-top: 5px;
  }
}

.content {
  width: calc(100% - 40px);
}

.no-hover-effect {
  --original-bg-color: initial;
  --original-border-color: initial;
  --original-text-color: initial;
}

/* 禁用按钮 hover 和 active 变色效果 */
.no-hover-effect:hover,
.no-hover-effect:focus,
.no-hover-effect:active {
  color: var(--original-text-color) !important;
  background-color: var(--original-bg-color) !important;
  border-color: var(--original-border-color) !important;
  box-shadow: none !important; /* 禁用阴影 */
}
</style>
