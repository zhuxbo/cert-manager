<script setup lang="ts">
import { ref, onMounted } from "vue";
import { logList } from "@/api/cloud-deploy";

const rows = ref<any[]>([]);
const loading = ref(false);
async function load() {
  loading.value = true;
  try {
    rows.value = (await logList({ pageSize: 50 })).data.items;
  } finally {
    loading.value = false;
  }
}
onMounted(load);
</script>

<template>
  <el-table v-loading="loading" :data="rows">
    <el-table-column prop="provider" label="云厂商" width="100" />
    <el-table-column prop="product" label="产品" width="90" />
    <el-table-column prop="resource_summary" label="资源" />
    <el-table-column prop="trigger" label="触发" width="80" />
    <el-table-column label="结果" width="90">
      <template #default="{ row }">
        <el-tag :type="row.status === 'success' ? 'success' : 'danger'">{{
          row.status
        }}</el-tag>
      </template>
    </el-table-column>
    <el-table-column prop="error_code" label="错误码" width="120" />
    <el-table-column prop="deployed_at" label="时间" width="180" />
  </el-table>
</template>
