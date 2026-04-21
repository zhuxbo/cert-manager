<template>
  <div class="batch-buttons">
    <el-popconfirm
      v-if="canPay()"
      title="确定要为这些订单扣款支付吗？"
      width="200px"
      @confirm="pay()"
    >
      <template #reference>
        <el-button type="primary" size="small" class="ml-2">
          批量支付
        </el-button>
      </template>
    </el-popconfirm>
    <el-popconfirm
      v-if="canCommit()"
      title="确定要提交这些订单到上游吗？"
      width="200px"
      @confirm="commit()"
    >
      <template #reference>
        <el-button type="primary" size="small" class="ml-2">
          批量提交
        </el-button>
      </template>
    </el-popconfirm>
    <el-popconfirm
      v-if="canSync()"
      title="确定要同步这些订单状态吗？"
      width="200px"
      @confirm="sync()"
    >
      <template #reference>
        <el-button type="primary" size="small" class="ml-2">
          批量同步
        </el-button>
      </template>
    </el-popconfirm>
    <el-popconfirm
      v-if="canCommitCancel()"
      title="确定要取消这些订单吗？"
      width="180px"
      @confirm="commitCancel()"
    >
      <template #reference>
        <el-button type="danger" size="small" class="ml-2">
          批量取消
        </el-button>
      </template>
    </el-popconfirm>
    <el-popconfirm
      v-if="canRevokeCancel()"
      title="确定要撤回这些订单的取消吗？"
      width="200px"
      @confirm="revokeCancel()"
    >
      <template #reference>
        <el-button type="warning" size="small" class="ml-2">
          批量撤回取消
        </el-button>
      </template>
    </el-popconfirm>
    <el-button
      v-if="canCopyEab()"
      type="primary"
      size="small"
      class="ml-2"
      @click="copyEab()"
    >
      批量复制 EAB
    </el-button>
  </div>
</template>

<script setup lang="ts">
import { message } from "@shared/utils";
import * as acmeApi from "@/api/acme";
import type { Acme } from "@/api/acme";

const props = defineProps<{
  selectedRows: Acme[];
  tableRef: any;
}>();

const emit = defineEmits<{
  (e: "refresh"): void;
}>();

const getSelectedRows = () => props.selectedRows || [];

const canPay = () => getSelectedRows().some(r => r.status === "unpaid");
const canCommit = () => getSelectedRows().some(r => r.status === "pending");
const canSync = () =>
  getSelectedRows().some(
    r => ["active", "cancelling"].includes(r.status) && r.api_id
  );
const canCommitCancel = () =>
  getSelectedRows().some(r =>
    ["unpaid", "pending", "active"].includes(r.status)
  );
const canRevokeCancel = () =>
  getSelectedRows().some(r => r.status === "cancelling");
const canCopyEab = () => getSelectedRows().some(r => !!r.eab_kid);

const filterIdsByStatus = (allowed: string[]): number[] => {
  const ids: number[] = [];
  props.tableRef?.clearSelection();
  getSelectedRows().forEach(row => {
    if (allowed.includes(row.status)) {
      ids.push(row.id);
      props.tableRef?.toggleRowSelection(row);
    }
  });
  return ids;
};

const reportResult = (res: any, action: string) => {
  const successCount = res?.data?.success_count;
  const errors = res?.data?.errors ?? [];
  if (successCount !== undefined) {
    const suffix = errors.length > 0 ? `，失败 ${errors.length} 条` : "";
    message(`${action}：成功 ${successCount} 条${suffix}`, { type: "success" });
  } else {
    message(`${action}成功`, { type: "success" });
  }
};

const pay = () => {
  const ids = filterIdsByStatus(["unpaid"]);
  if (!ids.length)
    return message("请至少选择一个未支付订单", { type: "error" });
  acmeApi.batchPayAcme(ids).then(res => {
    reportResult(res, "批量支付");
    emit("refresh");
  });
};

const commit = () => {
  const ids = filterIdsByStatus(["pending"]);
  if (!ids.length)
    return message("请至少选择一个待提交订单", { type: "error" });
  acmeApi.batchCommitAcme(ids).then(() => {
    message("已加入批量提交队列", { type: "success" });
    emit("refresh");
  });
};

const sync = () => {
  const ids: number[] = [];
  props.tableRef?.clearSelection();
  getSelectedRows().forEach(row => {
    if (["active", "cancelling"].includes(row.status) && row.api_id) {
      ids.push(row.id);
      props.tableRef?.toggleRowSelection(row);
    }
  });
  if (!ids.length)
    return message("请至少选择一个可同步订单", { type: "error" });
  acmeApi.batchSyncAcme(ids).then(() => {
    message("已加入批量同步队列", { type: "success" });
    emit("refresh");
  });
};

const commitCancel = () => {
  const ids = filterIdsByStatus(["unpaid", "pending", "active"]);
  if (!ids.length)
    return message("请至少选择一个可取消订单", { type: "error" });
  acmeApi.batchCommitCancelAcme(ids).then(res => {
    reportResult(res, "批量取消");
    emit("refresh");
  });
};

const revokeCancel = () => {
  const ids = filterIdsByStatus(["cancelling"]);
  if (!ids.length)
    return message("请至少选择一个取消中订单", { type: "error" });
  acmeApi.batchRevokeCancelAcme(ids).then(res => {
    reportResult(res, "批量撤回取消");
    emit("refresh");
  });
};

const copyEab = async () => {
  const ids = getSelectedRows()
    .filter(r => !!r.eab_kid)
    .map(r => r.id);
  if (!ids.length)
    return message("请至少选择一个已有 EAB 的订单", { type: "error" });

  const res = await acmeApi.batchCopyEabAcme(ids);
  if (res.code !== 1) return;

  const text = res.data?.text ?? "";
  try {
    await navigator.clipboard.writeText(text);
    message(`已复制 ${res.data?.count ?? 0} 条 EAB 到剪贴板`, {
      type: "success"
    });
  } catch {
    const ta = document.createElement("textarea");
    ta.value = text;
    ta.style.position = "fixed";
    ta.style.opacity = "0";
    document.body.appendChild(ta);
    ta.select();
    document.execCommand("copy");
    document.body.removeChild(ta);
    message(`已复制 ${res.data?.count ?? 0} 条 EAB 到剪贴板`, {
      type: "success"
    });
  }
};
</script>

<style scoped lang="scss">
.batch-buttons {
  display: flex;
  flex-wrap: wrap;
  gap: 0;
  align-items: center;
}
</style>
