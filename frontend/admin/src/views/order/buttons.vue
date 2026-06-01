<template>
  <div class="flex items-center gap-2">
    <el-button
      v-if="['unpaid'].includes(row.latest_cert?.status)"
      class="reset-margin !outline-none"
      type="warning"
      link
      :size="size"
      @click="handlePay(row)"
    >
      支付
    </el-button>
    <el-button
      v-if="['active'].includes(row.latest_cert?.status)"
      class="reset-margin !outline-none"
      type="success"
      link
      :size="size"
      @click="handleDownload(row)"
    >
      下载
    </el-button>
    <el-button
      v-if="['pending'].includes(row.latest_cert?.status)"
      class="reset-margin !outline-none"
      type="warning"
      link
      :size="size"
      @click="handleCommit(row)"
    >
      提交
    </el-button>
    <el-button
      class="reset-margin !outline-none"
      type="primary"
      link
      :size="size"
      @click="handleView(row)"
    >
      查看
    </el-button>
    <el-button
      v-if="['processing'].includes(row.latest_cert?.status)"
      class="reset-margin !outline-none"
      type="primary"
      link
      :size="size"
      @click="handleRevalidate(row)"
    >
      验证
    </el-button>
    <el-button
      v-if="
        ['processing', 'active', 'approving'].includes(row.latest_cert?.status)
      "
      class="reset-margin !outline-none"
      type="primary"
      link
      :size="size"
      @click="handleSync(row)"
    >
      同步
    </el-button>
    <el-popconfirm
      v-if="allowCancel(row)"
      title="确定要取消吗？"
      width="160px"
      @confirm="handleCancel(row)"
    >
      <template #reference>
        <el-button
          class="reset-margin !outline-none"
          type="danger"
          link
          :size="size"
        >
          取消
        </el-button>
      </template>
    </el-popconfirm>
    <el-button
      v-if="['cancelling'].includes(row.latest_cert?.status)"
      class="reset-margin !outline-none"
      type="warning"
      link
      :size="size"
      @click="handleRevokeCancel(row)"
    >
      撤销取消
    </el-button>
  </div>
</template>

<script setup lang="ts">
import * as OrderApi from "@/api/order";
import { message } from "@shared/utils";
import { useDetail } from "./detail";
import dayjs from "dayjs";

const { toDetail } = useDetail();

const emit = defineEmits<{
  (e: "refresh"): void;
}>();

defineProps<{
  row: any;
  size?: "default" | "small" | "large";
}>();

const allowCancel = (row: any) => {
  if (
    !row.created_at ||
    ["unpaid", "pending"].includes(row.latest_cert?.status)
  ) {
    return true;
  }
  return (
    ["processing", "approving", "active"].includes(row.latest_cert?.status) &&
    dayjs().diff(dayjs(row.created_at), "seconds") <
      (row.product?.refund_period ?? 0) * 86400
  );
};

const handlePay = (row: any) => {
  OrderApi.pay(row.id).then(() => {
    message("支付成功", { type: "success" });
    emit("refresh");
  });
};

const handleDownload = (row: any) => {
  OrderApi.download(row.id);
};

const handleView = (row: any) => {
  toDetail({ ids: row.id }, "params");
};

const handleRevalidate = (row: any) => {
  OrderApi.revalidate(row.id).then(() => {
    message("开始验证，请等待几分钟后刷新页面查看结果", { type: "success" });
    emit("refresh");
  });
};

const handleSync = (row: any) => {
  OrderApi.sync(row.id).then(() => {
    message("同步成功", { type: "success" });
    emit("refresh");
  });
};

const handleCommit = (row: any) => {
  OrderApi.commit(row.id).then(() => {
    message("提交成功", { type: "success" });
    emit("refresh");
  });
};

const handleCancel = (row: any) => {
  OrderApi.commitCancel(row.id).then(() => {
    message("取消成功", { type: "success" });
    emit("refresh");
  });
};

const handleRevokeCancel = (row: any) => {
  OrderApi.revokeCancel(row.id).then(() => {
    message("撤销取消成功", { type: "success" });
    emit("refresh");
  });
};
</script>

<style scoped lang="scss">
.reset-margin {
  margin: 0;
}
</style>
