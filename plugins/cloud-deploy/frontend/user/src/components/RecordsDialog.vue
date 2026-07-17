<script setup lang="ts">
import { ref, watch } from "vue";
import { logList } from "@/api/cloud-deploy";
import { formatDateTime } from "@/utils/time";

const props = defineProps<{
  modelValue: boolean;
  orderId?: number | string | null;
  targetId?: number | string | null;
}>();

const emit = defineEmits<{
  (e: "update:modelValue", value: boolean): void;
}>();

const rows = ref<any[]>([]);
const loading = ref(false);
const total = ref(0);
const page = ref(1);
const pageSize = ref(10);
const showRetry = ref(false);

async function load() {
  if (!props.modelValue) return;
  loading.value = true;
  try {
    const params: Record<string, any> = {
      currentPage: page.value,
      pageSize: pageSize.value
    };
    if (props.targetId) params.target_id = props.targetId;
    else if (props.orderId) params.order_id = props.orderId;
    if (!showRetry.value) params.is_final = 1;

    const res = await logList(params);
    rows.value = res.data.items;
    total.value = res.data.total ?? 0;
  } finally {
    loading.value = false;
  }
}

watch(
  () => props.modelValue,
  visible => {
    if (visible) {
      page.value = 1;
      showRetry.value = false;
      load();
    }
  }
);
watch(page, load);
watch(showRetry, () => {
  page.value = 1;
  load();
});
</script>

<template>
  <el-dialog
    :model-value="modelValue"
    title="推送记录"
    width="720px"
    @update:model-value="emit('update:modelValue', $event)"
  >
    <div style="margin-bottom: 8px">
      <el-switch
        v-model="showRetry"
        size="small"
        active-text="显示重试细节"
      />
    </div>
    <el-table
      v-loading="loading"
      :data="rows"
      size="small"
      empty-text="暂无推送记录"
    >
      <el-table-column label="时间" width="170">
        <template #default="{ row }">
          {{ formatDateTime(row.created_at) }}
        </template>
      </el-table-column>
      <el-table-column label="目标" min-width="180">
        <template #default="{ row }">
          {{ row.provider }} · {{ row.product }}
          <span v-if="row.resource_summary">（{{ row.resource_summary }}）</span>
        </template>
      </el-table-column>
      <el-table-column label="触发" width="80">
        <template #default="{ row }">
          {{ row.trigger === "manual" ? "手动" : "自动" }}
        </template>
      </el-table-column>
      <el-table-column label="结果" min-width="180">
        <template #default="{ row }">
          <el-tag v-if="row.status === 'success'" type="success" size="small">
            成功
          </el-tag>
          <span v-else>
            <el-tag type="danger" size="small">失败</el-tag>
            <el-text type="danger" size="small" style="margin-left: 4px">
              {{ row.error_code }}{{ row.message ? "：" + row.message : "" }}
            </el-text>
          </span>
        </template>
      </el-table-column>
    </el-table>
    <el-pagination
      v-model:current-page="page"
      style="margin-top: 8px; justify-content: flex-end"
      layout="prev, pager, next"
      :page-size="pageSize"
      :total="total"
    />
  </el-dialog>
</template>
