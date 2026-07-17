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
        <el-dropdown-item v-if="cert.status == 'active'" command="send">{{
          "发送"
        }}</el-dropdown-item>
        <el-dropdown-item v-if="cert.status == 'active'" command="transfer">{{
          "过户"
        }}</el-dropdown-item>
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
        <el-dropdown-item v-if="inRenewWindow" command="renew" divided>{{
          "续费"
        }}</el-dropdown-item>
        <el-dropdown-item v-if="inRenewWindow" command="markRenewed">{{
          "已续"
        }}</el-dropdown-item>
        <el-dropdown-item
          v-if="['active', 'expired'].includes(cert.status)"
          command="reissue"
          divided
          >{{ "重签" }}</el-dropdown-item
        >
      </el-dropdown-menu>
    </template>
  </el-dropdown>
  <el-button circle size="small" style="margin-left: 12px" @click="get(true)">
    <el-icon size="14" color="var(--el-text-color-regular)"
      ><Refresh />
    </el-icon>
  </el-button>
  <el-dialog v-model="sendEmailDialog" title="发送邮件" width="400px">
    <el-form-item label="邮箱" :label-width="100" class="ml-3 mr-3">
      <el-input v-model="email" autocomplete="off" />
    </el-form-item>
    <template #footer>
      <span class="dialog-footer">
        <el-button @click="sendEmailDialog = false">{{ "取消" }}</el-button>
        <el-button type="primary" @click="send()">{{ "发送" }}</el-button>
      </span>
    </template>
  </el-dialog>
  <el-dialog v-model="transferDialog" title="过户证书" width="400px">
    <el-form-item label="新用户" :label-width="100" class="ml-3 mr-3">
      <re-remote-select
        v-model="transferUserId"
        uri="/user"
        searchField="quickSearch"
        labelField="username"
        valueField="id"
        itemsField="items"
        totalField="total"
        placeholder="请选择要过户到的用户"
        style="width: 100%"
      />
    </el-form-item>
    <template #footer>
      <span class="dialog-footer">
        <el-button @click="transferDialog = false">{{ "取消" }}</el-button>
        <el-button type="primary" @click="transferCert()">{{
          "确定"
        }}</el-button>
      </span>
    </template>
  </el-dialog>
  <OrderAction
    v-model:visible="action.visible"
    :actionType="action.type"
    :orderId="action.id"
    @success="get(true)"
  />
</template>

<script setup lang="ts">
import { ref, inject, reactive, computed } from "vue";
import { buildUUID } from "@pureadmin/utils";
import router from "@/router";
import { ElMessageBox } from "element-plus";
import * as OrderApi from "@/api/order";
import { message } from "@shared/utils";
import { useOrderAction } from "@/views/order/action";
import OrderAction from "@/views/order/action.vue";
import { ArrowDown, Refresh } from "@element-plus/icons-vue";
import { useMultiTagsStoreHook } from "@/store/modules/multiTags";
import { useRoute } from "vue-router";
import { useDetail } from "@/views/order/detail";
import ReRemoteSelect from "@shared/components/ReRemoteSelect";
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

// 续费 / 已续 共用显示窗口：active 证书 + 订单到期前 30 天内（未过期且 ≤30 天）。
// 按 order.period_till（订单到期）判定、非单张证书 expires_at —— 与后端 gate 对齐：
// 手工续费（ActionTrait 的 period_till>now+30 报错）与 markRenewed 同窗口。
// 续费=在窗口内续；已续=在窗口内但用户另开新单续了、标旧单止到期通知。
const inRenewWindow = computed(() => {
  if (order.latest_cert.status !== "active" || !order.period_till) {
    return false;
  }
  const till = dayjs(order.period_till);
  return till.isAfter(dayjs()) && till.diff(dayjs(), "day") <= 30;
});

// 打开操作抽屉
const { action, openAction } = useOrderAction();

const orderOperate = (command: string) => {
  if (!command) {
    return;
  }
  switch (command) {
    case "send":
      sendEmailDialog.value = true;
      break;
    case "transfer":
      transferDialog.value = true;
      break;
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
    case "markRenewed":
      markRenewed();
      break;
    case "renew":
      openAction("renew", order.id);
      break;
    case "reissue":
      openAction("reissue", order.id);
      break;
    default:
      break;
  }
};

const email = ref(order?.user?.email);
const sendEmailDialog = ref(false);
const send = () => {
  OrderApi.sendActive(order.id, email.value).then(() => {
    sendEmailDialog.value = false;
    message("发送成功", { type: "success" });
  });
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
        // 如果参数是多个id 则去除当前id再打开新的详情
        let ids = params.ids.toString().split(",");
        if (ids.length > 1) {
          // 从ids中去除当前id
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
const markRenewed = () => {
  ElMessageBox.confirm(
    "标记后该订单将不再自动续费、不再到期提醒，且无法撤回。确认已在别处续费？",
    "标记已续费",
    {
      confirmButtonText: "确定",
      cancelButtonText: "返回",
      type: "warning",
      draggable: true
    }
  ).then(() => {
    OrderApi.markRenewed(order.id).then(() => {
      message("标记已续费成功", { type: "success" });
      OrderApi.show(order.id).then(res => {
        res.data.sync = buildUUID();
        Object.assign(order, reactive(res.data));
      });
    });
  });
};

const transferUserId = ref("");
const transferDialog = ref(false);
const transferCert = () => {
  if (!transferUserId.value) {
    message("请选择用户", { type: "error" });
    return;
  }
  OrderApi.transfer({
    order_id: order.id,
    user_id: transferUserId.value
  }).then(() => {
    message("过户成功", { type: "success" });
    get();
    transferDialog.value = false;
  });
};
</script>
