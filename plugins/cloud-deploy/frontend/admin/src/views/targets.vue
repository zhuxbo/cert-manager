<script setup lang="ts">
import { ref, onMounted } from "vue";
import { ElMessage, ElMessageBox } from "element-plus";
import { deploy, targetDestroy, targetList, targetUpdate } from "@/api/cloud-deploy";
import RecordsDialog from "./RecordsDialog.vue";
import TargetForm from "../components/TargetForm.vue";

const rows = ref<any[]>([]);
const loading = ref(false);
const currentPage = ref(1);
const pageSize = ref(20);
const total = ref(0);
const pushing = ref<Record<number, boolean>>({});
const dialog = ref(false);
const editingTarget = ref<any | null>(null);

// 搜索条件（key 对齐后端契约 query 名）
const q = ref<any>({
  quickSearch: "",
  last_status: "", // ''=全部, success/failed, unpushed=未推送(NULL)
  enabled: "", // ''=全部, true/false
  created_at_start: "",
  created_at_end: ""
});

// 记录弹窗
const recDialog = ref(false);
const recTargetId = ref<number | undefined>(undefined);
const recOrderId = ref<number | undefined>(undefined);

function buildParams(): Record<string, any> {
  const p: Record<string, any> = {
    currentPage: currentPage.value,
    pageSize: pageSize.value
  };
  for (const [k, v] of Object.entries(q.value)) {
    if (v !== "" && v !== null && v !== undefined) p[k] = v;
  }
  return p;
}
async function load() {
  loading.value = true;
  try {
    const res = await targetList(buildParams());
    rows.value = res.data.items;
    total.value = res.data.total;
  } finally {
    loading.value = false;
  }
}
function onSearch() {
  currentPage.value = 1;
  load();
}
function onReset() {
  q.value = {
    quickSearch: "",
    last_status: "",
    enabled: "",
    created_at_start: "",
    created_at_end: ""
  };
  onSearch();
}
function onPage(p: number) {
  currentPage.value = p;
  load();
}

function openCreate() {
  editingTarget.value = null;
  dialog.value = true;
}

function openEdit(row: any) {
  editingTarget.value = row;
  dialog.value = true;
}

async function toggleEnabled(row: any) {
  try {
    await targetUpdate(row.id, { enabled: row.enabled });
  } catch (e) {
    row.enabled = !row.enabled;
    throw e;
  }
}

async function remove(row: any) {
  await ElMessageBox.confirm("确认删除该部署目标？", "提示");
  await targetDestroy(row.id);
  ElMessage.success("已删除");
  load();
}

// 资源列：脱敏后 config 仍保留 domain/非 secret 键；优先 domain，其次首个「非打码」config 值
// （admin config 已逐键脱敏，secret 键置 '******'，须跳过，否则 webhook target 资源列会显 '******'）
function resourceSummary(row: any): string {
  const c = row.config ?? {};
  if (c.domain) return c.domain;
  const first = Object.values(c).find((v) => v != null && v !== "******");
  return first != null ? String(first) : "-";
}

// 管理员手动推送（target_ids 模式 + force:true，对已成功 target 也重推）
async function pushOne(row: any) {
  pushing.value[row.id] = true;
  try {
    const res = await deploy({ target_ids: [row.id], force: true });
    const n = res?.data?.dispatched ?? 0;
    if (n > 0) {
      ElMessage.success("已发起推送（结果稍后在记录中查看）");
      setTimeout(load, 1500);
    } else {
      ElMessage.warning("未发起推送（证书可能非签发态）");
    }
  } finally {
    pushing.value[row.id] = false;
  }
}
function openRecordsByTarget(row: any) {
  recTargetId.value = row.id;
  recOrderId.value = undefined;
  recDialog.value = true;
}

onMounted(load);
defineExpose({ openCreate });
</script>

<template>
  <div>
    <el-form :inline="true" :model="q" style="margin-bottom: 8px">
      <el-form-item
        ><el-input
          v-model="q.quickSearch"
          placeholder="订单号/域名/用户名/凭证名"
          clearable
          style="width: 220px"
      /></el-form-item>
      <el-form-item>
        <el-select
          v-model="q.last_status"
          placeholder="状态"
          clearable
          style="width: 120px"
        >
          <el-option label="成功" value="success" />
          <el-option label="失败" value="failed" />
          <el-option label="未推送" value="unpushed" />
        </el-select>
      </el-form-item>
      <el-form-item>
        <el-select
          v-model="q.enabled"
          placeholder="启用"
          clearable
          style="width: 100px"
        >
          <el-option label="启用" :value="true" />
          <el-option label="停用" :value="false" />
        </el-select>
      </el-form-item>
      <el-form-item>
        <el-button type="primary" @click="onSearch">搜索</el-button>
        <el-button @click="onReset">重置</el-button>
      </el-form-item>
    </el-form>

    <el-table v-loading="loading" :data="rows">
      <el-table-column prop="username" label="用户" width="110" />
      <el-table-column prop="order_id" label="订单" width="150" />
      <el-table-column prop="provider" label="云平台" width="100" />
      <el-table-column prop="product" label="产品" width="100" />
      <el-table-column label="资源">
        <template #default="{ row }">{{ resourceSummary(row) }}</template>
      </el-table-column>
      <el-table-column label="状态" width="100">
        <template #default="{ row }">
          <!-- 状态可点 → 打开该目标推送记录 -->
          <el-button link @click="openRecordsByTarget(row)">
            <el-tag v-if="row.last_status === 'success'" type="success"
              >成功</el-tag
            >
            <el-tag v-else-if="row.last_status === 'failed'" type="danger"
              >失败</el-tag
            >
            <el-tag v-else type="info">未推送</el-tag>
          </el-button>
        </template>
      </el-table-column>
      <el-table-column label="启用" width="80">
        <template #default="{ row }">
          <el-switch
            v-model="row.enabled"
            size="small"
            @change="toggleEnabled(row)"
          />
        </template>
      </el-table-column>
      <el-table-column label="操作" width="230">
        <template #default="{ row }">
          <el-button
            link
            type="primary"
            :loading="pushing[row.id]"
            @click="pushOne(row)"
            >推送</el-button
          >
          <el-button link @click="openRecordsByTarget(row)">记录</el-button>
          <el-button link type="primary" @click="openEdit(row)">编辑</el-button>
          <el-button link type="danger" @click="remove(row)">删除</el-button>
        </template>
      </el-table-column>
    </el-table>

    <el-pagination
      style="margin-top: 8px; justify-content: flex-end"
      layout="prev, pager, next"
      :total="total"
      :page-size="pageSize"
      :current-page="currentPage"
      @current-change="onPage"
    />

    <RecordsDialog
      v-model="recDialog"
      :order-id="recOrderId"
      :target-id="recTargetId"
    />
    <TargetForm v-model="dialog" :target="editingTarget" @saved="load" />
  </div>
</template>
