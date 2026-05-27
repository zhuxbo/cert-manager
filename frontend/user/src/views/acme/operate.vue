<template>
  <el-dropdown style="margin-top: 7px" @command="acmeOperate">
    <el-button type="primary" size="small">
      操作
      <el-icon style="margin-left: 6px; color: var(--el-color-white)">
        <ArrowDown />
      </el-icon>
    </el-button>
    <template #dropdown>
      <el-dropdown-menu>
        <el-dropdown-item v-if="acme.status === 'unpaid'" command="pay">
          支付
        </el-dropdown-item>
        <el-dropdown-item v-if="acme.status === 'pending'" command="commit">
          提交
        </el-dropdown-item>
        <el-dropdown-item
          v-if="['active', 'cancelling'].includes(acme.status) && !!acme.api_id"
          command="sync"
        >
          同步
        </el-dropdown-item>
        <el-dropdown-item v-if="allowCancel" command="commitCancel" divided>
          取消
        </el-dropdown-item>
        <el-dropdown-item
          v-if="acme.status === 'cancelling'"
          command="revokeCancel"
        >
          撤回取消
        </el-dropdown-item>
      </el-dropdown-menu>
    </template>
  </el-dropdown>
  <el-button
    circle
    size="small"
    style="margin-left: 12px"
    @click="emit('refresh', true)"
  >
    <el-icon size="14" color="var(--el-text-color-regular)">
      <Refresh />
    </el-icon>
  </el-button>
</template>

<script setup lang="ts">
import { computed } from "vue";
import { ElMessageBox } from "element-plus";
import { ArrowDown, Refresh } from "@element-plus/icons-vue";
import * as acmeApi from "@/api/acme";
import type { Acme } from "@/api/acme";
import { message } from "@shared/utils";

const props = defineProps<{ acme: Acme }>();
const emit = defineEmits<{
  (e: "refresh", showMessage?: boolean): void;
}>();

const allowCancel = computed(() =>
  ["unpaid", "pending", "active"].includes(props.acme.status)
);

const acmeOperate = (command: string) => {
  if (!command) return;
  switch (command) {
    case "pay":
      pay();
      break;
    case "commit":
      commit();
      break;
    case "sync":
      sync();
      break;
    case "commitCancel":
      commitCancel();
      break;
    case "revokeCancel":
      revokeCancel();
      break;
  }
};

const pay = () => {
  acmeApi.payOrder(props.acme.id).then(() => {
    message("支付成功", { type: "success" });
    emit("refresh");
  });
};

const commit = () => {
  acmeApi.commitOrder(props.acme.id).then(() => {
    message("提交成功", { type: "success" });
    emit("refresh");
  });
};

const sync = () => {
  acmeApi.syncAcme(props.acme.id).then(() => {
    message("同步成功", { type: "success" });
    emit("refresh");
  });
};

const commitCancel = () => {
  ElMessageBox.confirm(
    "取消提交2分钟后执行！如取消错误，请在2分钟内撤回！",
    "取消订阅",
    {
      confirmButtonText: "确定",
      cancelButtonText: "返回",
      type: "warning",
      draggable: true
    }
  ).then(() => {
    acmeApi.cancelAcme(props.acme.id).then(() => {
      message("提交取消成功", { type: "success" });
      emit("refresh");
    });
  });
};

const revokeCancel = () => {
  acmeApi.revokeCancelAcme(props.acme.id).then(() => {
    message("撤回取消成功", { type: "success" });
    emit("refresh");
  });
};
</script>
