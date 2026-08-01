<template>
  <el-dropdown style="margin-top: 7px" @command="orderOperate">
    <el-button type="primary" size="small">
      {{ "操作" }}
      <el-icon style="margin-left: 6px; color: var(--el-color-white)">
        <ArrowDown />
      </el-icon>
    </el-button>
    <template #dropdown>
      <el-dropdown-menu>
        <el-dropdown-item v-if="cert.status == 'pending'" command="commit">{{
          "提交"
        }}</el-dropdown-item>
        <el-dropdown-item
          v-if="['processing', 'active', 'approving'].includes(cert.status)"
          command="sync"
          >{{ "同步" }}</el-dropdown-item
        >
        <el-dropdown-item v-if="allowCancel" command="commitCancel" divided>{{
          "取消"
        }}</el-dropdown-item>
        <el-dropdown-item
          v-if="cert.status == 'cancelling'"
          command="revokeCancel"
          >{{ "撤回" }}</el-dropdown-item
        >
      </el-dropdown-menu>
    </template>
  </el-dropdown>
  <el-button circle size="small" style="margin-left: 12px" @click="get(true)">
    <el-icon size="14" color="var(--el-text-color-regular)"
      ><Refresh />
    </el-icon>
  </el-button>
</template>

<script setup lang="ts">
import { inject, reactive, computed } from "vue";
import { buildUUID } from "@pureadmin/utils";
import router from "@/router";
import { ElMessageBox } from "element-plus";
import * as OrderApi from "@/api/order";
import { message } from "@shared/utils";
import { ArrowDown, Refresh } from "@element-plus/icons-vue";
import { useMultiTagsStoreHook } from "@/store/modules/multiTags";
import { useRoute } from "vue-router";
import { useDetail } from "@/views/order/detail";
import dayjs from "dayjs";

const { toDetail } = useDetail();
const route = useRoute();
const currentPath = route.path;
const params = route.params;

const order = inject("order") as any;
const cert = inject("cert") as any;
const sync = inject("sync") as Function;
const get = inject("get") as Function;

const allowCancel = computed(() => {
  if (
    !order.created_at ||
    ["unpaid", "pending"].includes(order.latest_cert.status)
  ) {
    return true;
  }
  return (
    ["processing", "approving", "active"].includes(order.latest_cert.status) &&
    dayjs().diff(dayjs(order.created_at), "seconds") <
      86400 * order.product.refund_period
  );
});

const orderOperate = (command: string) => {
  if (!command) {
    return;
  }
  switch (command) {
    case "commit":
      commit();
      break;
    case "sync":
      sync(true);
      break;
    case "commitCancel":
      commitCancel();
      break;
    case "revokeCancel":
      revokeCancel();
      break;
    default:
      break;
  }
};

const commit = () => {
  OrderApi.commit(order.id).then(() => {
    message("提交成功", { type: "success" });
    get();
  });
};
const commitCancel = () => {
  ElMessageBox.confirm(
    "取消提交2分钟后执行！如取消错误，请在2分钟内撤回！",
    "取消证书",
    {
      confirmButtonText: "确定",
      cancelButtonText: "返回",
      type: "warning",
      draggable: true
    }
  ).then(() => {
    OrderApi.commitCancel(order.id).then(() => {
      message("提交取消成功", { type: "success" });
      if (order.latest_cert.status === "unpaid") {
        useMultiTagsStoreHook().handleTags("splice", currentPath);
        let ids = params.ids.toString().split(",");
        if (ids.length > 1) {
          ids = ids.filter(id => id !== order.id.toString());
          toDetail({ ids: ids.join(",") }, "params");
        } else {
          router.push({ name: "Order" });
        }
      } else {
        OrderApi.show(order.id).then(res => {
          res.data.sync = buildUUID();
          Object.assign(order, reactive(res.data));
        });
      }
    });
  });
};
const revokeCancel = () => {
  OrderApi.revokeCancel(order.id).then(() => {
    message("撤回取消成功", { type: "success" });
    OrderApi.show(order.id).then(res => {
      res.data.sync = buildUUID();
      Object.assign(order, reactive(res.data));
    });
  });
};
</script>
