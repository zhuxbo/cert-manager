<script setup lang="ts">
import { ref, onMounted } from "vue";
import { accessList } from "@/api/cloud-deploy";

const rows = ref<any[]>([]);
const loading = ref(false);
const currentPage = ref(1);
const pageSize = ref(20);
const total = ref(0);
// 云凭证维度：用户/名称/云平台/时间，无 quickSearch（凭证维度不含聚合搜索）
const q = ref<any>({
  username: "",
  name: "",
  provider: ""
});

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
    const res = await accessList(buildParams());
    rows.value = res.data.items; // 后端白名单 select，无 credentials
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
  q.value = { username: "", name: "", provider: "" };
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
      <el-form-item
        ><el-input
          v-model="q.username"
          placeholder="用户名"
          clearable
          style="width: 140px"
      /></el-form-item>
      <el-form-item
        ><el-input
          v-model="q.name"
          placeholder="凭证名称"
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
      <el-form-item>
        <el-button type="primary" @click="onSearch">搜索</el-button>
        <el-button @click="onReset">重置</el-button>
      </el-form-item>
    </el-form>

    <!-- 脱敏：仅 用户/名称/云平台/时间，绝不含 AK/SK -->
    <el-table v-loading="loading" :data="rows">
      <el-table-column prop="username" label="用户" width="110" />
      <el-table-column prop="name" label="名称" />
      <el-table-column prop="provider" label="云平台" width="120" />
      <el-table-column prop="created_at" label="创建时间" width="180" />
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
