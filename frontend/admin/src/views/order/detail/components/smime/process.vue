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
        <tr
          v-if="
            order.product.validation_type !== 'dv' &&
            order.product.ca?.toLowerCase() === 'certum' &&
            cert?.status === 'processing'
          "
        >
          <td class="label" />
          <td class="content">
            <Documents v-if="hasDocuments" />
            <DocumentUpload />
          </td>
        </tr>
        <tr>
          <td class="label">
            <el-icon :size="16" class="icon" :color="validationColor">
              <Select />
            </el-icon>
          </td>
          <td class="content">邮箱验证</td>
        </tr>
        <tr>
          <td class="label" />
          <td class="content"><SmimeValidation /></td>
        </tr>
        <tr>
          <td class="label">
            <el-icon :size="16" class="icon" :color="issuedColor">
              <Select />
            </el-icon>
          </td>
          <td class="content">签发证书</td>
        </tr>
      </tbody>
    </table>
  </el-card>
</template>

<script setup lang="ts">
import { computed, inject, onMounted, ref } from "vue";
import { statusType, status } from "@/views/order/dictionary";
import Operate from "./operate.vue";
import SmimeValidation from "./validation.vue";
import Documents from "../documents.vue";
import DocumentUpload from "../documentUpload.vue";
import { ElButton } from "element-plus";
import { Select } from "@element-plus/icons-vue";
import dayjs from "dayjs";

const order = inject("order") as any;
const cert = inject("cert") as any;

const hasDocuments = computed(() => {
  const docs = cert.value?.documents;
  if (!docs) return false;
  return Array.isArray(docs) ? docs.length > 0 : true;
});

const getStatusColor = (status: string) => {
  return computed(() => {
    if (cert.value.status === "active") {
      return "var(--el-color-success)";
    }
    if (cert.value.status == "processing") {
      return cert.value[status] == 2
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
    "pending",
    "processing",
    "active",
    "expired",
    "approving",
    "cancelling"
  ];
  if (!validStatuses.includes(cert.value?.status)) return false;
  if (!order.value?.period_till) return true;
  const periodTill = dayjs(order.value.period_till);
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

.hint {
  font-size: 12px;
  color: var(--el-text-color-placeholder);
}

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
</style>
