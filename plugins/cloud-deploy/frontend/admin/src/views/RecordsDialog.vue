<script setup lang="ts">
import { ref, watch } from "vue";
import { logList } from "@/api/cloud-deploy";

// 按 order_id（整单入口）或 target_id（点状态列入口）筛选本订单/本目标推送记录
const props = defineProps<{
  modelValue: boolean;
  orderId?: number;
  targetId?: number;
}>();
const emit = defineEmits<{ "update:modelValue": [boolean] }>();

const rows = ref<any[]>([]);
const loading = ref(false);
const showRetries = ref(false); // 开=显示重试细节(is_final=false 中间行)；关=默认只显终态
const currentPage = ref(1);
const pageSize = ref(20);
const total = ref(0);

async function load() {
  loading.value = true;
  try {
    const params: Record<string, any> = {
      currentPage: currentPage.value,
      pageSize: pageSize.value
    };
    if (props.orderId) params.order_id = props.orderId;
    if (props.targetId) params.target_id = props.targetId;
    // 默认去噪：只显终态行；开「显示重试细节」则不传 is_final（拿全部含中间重试行）。
    // 用 1（非 JS 布尔 true）：qs 把 true 序列化为 "true"，Laravel boolean 规则只认 true/false/0/1/'0'/'1'，"true" 会 422；传 1→"1" 命中
    if (!showRetries.value) params.is_final = 1;
    const res = await logList(params);
    rows.value = res.data.items;
    total.value = res.data.total;
  } finally {
    loading.value = false;
  }
}

// 弹窗打开 / 筛选目标变化 / 开关切换 → 重新拉
watch(
  () => [props.modelValue, props.orderId, props.targetId, showRetries.value],
  () => {
    if (props.modelValue) {
      currentPage.value = 1;
      load();
    }
  }
);
function onPage(p: number) {
  currentPage.value = p;
  load();
}
</script>

<template>
  <el-dialog
    :model-value="modelValue"
    title="推送记录"
    width="760px"
    @update:model-value="emit('update:modelValue', $event)"
  >
    <div style="margin-bottom: 8px">
      <el-switch v-model="showRetries" />
      <el-text size="small" style="margin-left: 6px">显示重试细节</el-text>
    </div>
    <el-table v-loading="loading" :data="rows">
      <el-table-column prop="deployed_at" label="时间" width="180" />
      <el-table-column label="目标">
        <template #default="{ row }"
          >{{ row.provider }}·{{ row.product }}·{{
            row.resource_summary || "-"
          }}</template
        >
      </el-table-column>
      <el-table-column prop="trigger" label="触发" width="80">
        <template #default="{ row }">{{
          row.trigger === "manual" ? "手动" : "自动"
        }}</template>
      </el-table-column>
      <el-table-column label="结果" width="220">
        <template #default="{ row }">
          <el-tag v-if="row.status === 'success'" type="success">成功</el-tag>
          <span v-else
            ><el-tag type="danger">失败</el-tag>
            <el-text size="small" style="margin-left: 6px"
              >{{ row.error_code }} {{ row.message }}</el-text
            ></span
          >
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
  </el-dialog>
</template>
