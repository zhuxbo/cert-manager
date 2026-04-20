<template>
  <div class="flex items-center gap-2">
    <el-button
      v-if="row.status === 'unpaid'"
      class="reset-margin !outline-none"
      type="primary"
      :link="link"
      :size="size"
      @click="handlePay(row)"
    >
      支付
    </el-button>
    <el-button
      v-if="row.status === 'pending'"
      class="reset-margin !outline-none"
      type="success"
      :link="link"
      :size="size"
      @click="handleCommit(row)"
    >
      提交
    </el-button>
    <el-button
      v-if="showView"
      class="reset-margin !outline-none"
      type="primary"
      :link="link"
      :size="size"
      @click="handleView(row)"
    >
      查看
    </el-button>
    <el-button
      v-if="['active', 'cancelling'].includes(row.status) && !!row.api_id"
      class="reset-margin !outline-none"
      type="primary"
      :link="link"
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
          :link="link"
          :size="size"
        >
          取消
        </el-button>
      </template>
    </el-popconfirm>
    <el-button
      v-if="row.status === 'cancelling'"
      class="reset-margin !outline-none"
      type="warning"
      :link="link"
      :size="size"
      @click="handleRevokeCancel(row)"
    >
      撤回取消
    </el-button>
  </div>
</template>

<script setup lang="ts">
import * as acmeApi from "@/api/acme";
import type { Acme } from "@/api/acme";
import { message } from "@shared/utils";
import { useDetail } from "./detail";

const { toDetail } = useDetail();

const emit = defineEmits<{
  (e: "refresh"): void;
}>();

withDefaults(
  defineProps<{
    row: Acme;
    size?: "default" | "small" | "large";
    showView?: boolean;
    link?: boolean;
  }>(),
  {
    showView: true,
    link: true
  }
);

const allowCancel = (row: Acme) => {
  return ["pending", "active"].includes(row.status);
};

const handlePay = (row: Acme) => {
  acmeApi.payOrder(row.id).then(() => {
    message("支付成功", { type: "success" });
    emit("refresh");
  });
};

const handleCommit = (row: Acme) => {
  acmeApi.commitOrder(row.id).then(() => {
    message("提交成功", { type: "success" });
    emit("refresh");
  });
};

const handleView = (row: Acme) => {
  toDetail({ ids: String(row.id) }, "params");
};

const handleSync = (row: Acme) => {
  acmeApi.syncAcme(row.id).then(() => {
    message("同步成功", { type: "success" });
    emit("refresh");
  });
};

const handleCancel = (row: Acme) => {
  acmeApi.cancelAcme(row.id).then(() => {
    message("取消成功", { type: "success" });
    emit("refresh");
  });
};

const handleRevokeCancel = (row: Acme) => {
  acmeApi.revokeCancelAcme(row.id).then(() => {
    message("撤回取消成功", { type: "success" });
    emit("refresh");
  });
};
</script>

<style scoped lang="scss">
.reset-margin {
  margin: 0;
}
</style>
