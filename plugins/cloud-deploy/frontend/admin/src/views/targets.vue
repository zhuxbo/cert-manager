<script setup lang="ts">
import { ref, onMounted } from "vue";
import { ElMessage } from "element-plus";
import { targetList, deploy } from "@/api/cloud-deploy";
import RecordsDialog from "./RecordsDialog.vue";

const rows = ref<any[]>([]);
const loading = ref(false);
const currentPage = ref(1);
const pageSize = ref(20);
const total = ref(0);
const pushing = ref<Record<number, boolean>>({});

// 搜索条件（key 对齐后端契约 query 名）
const q = ref<any>({
  quickSearch: "",
  username: "", // admin LIKE 用户名
  order_id: "",
  provider: "",
  product: "",
  last_status: "", // ''=全部, success/failed, unpushed=未推送(NULL)
  enabled: "", // ''=全部, true/false
  keyword: "", // 域名(cert.common_name)
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
    username: "",
    order_id: "",
    provider: "",
    product: "",
    last_status: "",
    enabled: "",
    keyword: "",
    created_at_start: "",
    created_at_end: ""
  };
  onSearch();
}
function onPage(p: number) {
  currentPage.value = p;
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
      <el-form-item
        ><el-input
          v-model="q.username"
          placeholder="用户名"
          clearable
          style="width: 140px"
      /></el-form-item>
      <el-form-item
        ><el-input
          v-model.number="q.order_id"
          placeholder="订单号"
          clearable
          style="width: 120px"
      /></el-form-item>
      <el-form-item
        ><el-input
          v-model="q.keyword"
          placeholder="域名"
          clearable
          style="width: 160px"
      /></el-form-item>
      <el-form-item
        ><el-input
          v-model="q.provider"
          placeholder="云平台"
          clearable
          style="width: 120px"
      /></el-form-item>
      <el-form-item
        ><el-input
          v-model="q.product"
          placeholder="产品"
          clearable
          style="width: 120px"
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
      <el-table-column prop="order_id" label="订单" width="100" />
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
        <template #default="{ row }"
          ><el-tag :type="row.enabled ? '' : 'info'">{{
            row.enabled ? "是" : "否"
          }}</el-tag></template
        >
      </el-table-column>
      <!-- admin 仅 推送 + 记录，无绑定/编辑/删除（用户自助操作） -->
      <el-table-column label="操作" width="150">
        <template #default="{ row }">
          <el-button
            link
            type="primary"
            :loading="pushing[row.id]"
            @click="pushOne(row)"
            >推送</el-button
          >
          <el-button link @click="openRecordsByTarget(row)">记录</el-button>
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
  </div>
</template>
