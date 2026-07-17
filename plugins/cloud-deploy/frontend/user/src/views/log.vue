<script setup lang="ts">
import { ref, onMounted } from "vue";
import { logList } from "@/api/cloud-deploy";
import { formatDateTime } from "@/utils/time";

const rows = ref<any[]>([]);
const loading = ref(false);
const currentPage = ref(1);
const pageSize = ref(20);
const total = ref(0);
const showRetries = ref(false);
const q = ref<any>({
  quickSearch: "",
  status: "",
  trigger: ""
});

function buildParams(): Record<string, any> {
  const p: Record<string, any> = {
    currentPage: currentPage.value,
    pageSize: pageSize.value
  };
  for (const [k, v] of Object.entries(q.value)) {
    if (v !== "" && v !== null && v !== undefined) p[k] = v;
  }
  if (!showRetries.value) p.is_final = 1;
  return p;
}

async function load() {
  loading.value = true;
  try {
    const res = await logList(buildParams());
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
    status: "",
    trigger: ""
  };
  onSearch();
}

function onPage(p: number) {
  currentPage.value = p;
  load();
}
onMounted(load);
</script>

<template>
  <div>
    <el-form :inline="true" :model="q" style="margin-bottom: 8px">
      <el-form-item>
        <el-input
          v-model="q.quickSearch"
          placeholder="订单号/域名/凭证名"
          clearable
          style="width: 220px"
          @keyup.enter="onSearch"
        />
      </el-form-item>
      <el-form-item>
        <el-select
          v-model="q.status"
          placeholder="结果"
          clearable
          style="width: 110px"
        >
          <el-option label="成功" value="success" />
          <el-option label="失败" value="failed" />
        </el-select>
      </el-form-item>
      <el-form-item>
        <el-select
          v-model="q.trigger"
          placeholder="触发"
          clearable
          style="width: 100px"
        >
          <el-option label="手动" value="manual" />
          <el-option label="自动" value="auto" />
        </el-select>
      </el-form-item>
      <el-form-item>
        <el-switch v-model="showRetries" />
        <el-text size="small" style="margin-left: 6px">重试细节</el-text>
      </el-form-item>
      <el-form-item>
        <el-button type="primary" @click="onSearch">搜索</el-button>
        <el-button @click="onReset">重置</el-button>
      </el-form-item>
    </el-form>

    <el-table v-loading="loading" :data="rows">
      <el-table-column prop="order_id" label="订单" width="150" />
      <el-table-column prop="provider" label="云平台" width="100" />
      <el-table-column prop="product" label="产品" width="90" />
      <el-table-column prop="resource_summary" label="资源" />
      <el-table-column label="触发" width="80">
        <template #default="{ row }">{{
          row.trigger === "manual" ? "手动" : "自动"
        }}</template>
      </el-table-column>
      <el-table-column label="结果" width="90">
        <template #default="{ row }">
          <el-tag :type="row.status === 'success' ? 'success' : 'danger'">
            {{ row.status === "success" ? "成功" : "失败" }}
          </el-tag>
        </template>
      </el-table-column>
      <el-table-column prop="error_code" label="错误码" width="120" />
      <el-table-column label="时间" width="180">
        <template #default="{ row }">
          {{ formatDateTime(row.deployed_at) }}
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
  </div>
</template>
