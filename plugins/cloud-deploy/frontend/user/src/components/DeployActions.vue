<script setup lang="ts">
import { ref, watch } from "vue";
import { ElMessage, ElMessageBox } from "element-plus";
import {
  targetList,
  targetUpdate,
  targetDestroy,
  deploy,
  logList
} from "@/api/cloud-deploy";
import { formatDateTime } from "@/utils/time";
import TargetForm from "./TargetForm.vue";

// 插槽 props：order（含 id）、cert（含 status / encryption_alg）
const props = defineProps<{ order?: any; cert?: any }>();

const targets = ref<any[]>([]);
const loading = ref(false);
const pushing = ref(false);
const rowPushing = ref<Record<number, boolean>>({});

// active 仅作用于「推送/一键推送」按钮 disabled（下沉，绑定/列表/记录非 active 也渲染）
const isActive = () => props.cert?.status === "active";
// 国密(SM2)证书：标准接口不支持，隐藏推送（对齐主系统 process.vue 的 isGm 口径）
const isSM2 = () => /sm2/i.test(props.cert?.encryption_alg ?? "");

// —— 目标列表 ——
async function load() {
  if (!props.order?.id) return;
  loading.value = true;
  try {
    targets.value = (
      await targetList({ order_id: props.order.id, pageSize: 100 })
    ).data.items;
  } finally {
    loading.value = false;
  }
}

// 列表「资源」列：优先 domain，其次首个「非打码」config 值（admin 经脱敏后 secret 键为 '******'，须跳过）
function resourceSummary(row: any): string {
  const c = row.config ?? {};
  if (c.domain) return c.domain;
  const first = Object.values(c).find(v => v != null && v !== "******");
  return first != null ? String(first) : "-";
}

// 启用开关：切换调 PUT target/{id}（仅传 enabled，后端按需 merge）
async function toggleEnabled(row: any) {
  try {
    await targetUpdate(row.id, { enabled: row.enabled });
  } catch (e) {
    row.enabled = !row.enabled; // 失败回滚 UI
    throw e;
  }
}

// —— 推送（一律 force:true，绕过幂等短路；零 enabled 前置兜底，避免后端空集合 throw 走 error toast）——
async function pushAll() {
  const enabledCount = targets.value.filter(t => t.enabled).length;
  if (!enabledCount) {
    ElMessage.warning("没有启用的部署目标可推送");
    return;
  }
  pushing.value = true;
  try {
    const res = await deploy({ order_id: props.order.id, force: true });
    const n = res?.data?.dispatched ?? 0;
    if (n > 0) {
      ElMessage.success(`已发起推送 ${n} 个目标，结果稍后在状态/记录中查看`);
      setTimeout(load, 1500);
    } else {
      ElMessage.warning("没有可推送的目标（证书可能非签发态）");
    }
  } finally {
    pushing.value = false;
  }
}
async function pushOne(row: any) {
  rowPushing.value = { ...rowPushing.value, [row.id]: true };
  try {
    const res = await deploy({ target_ids: [row.id], force: true });
    const n = res?.data?.dispatched ?? 0;
    if (n > 0) {
      ElMessage.success("已发起推送，结果稍后在状态/记录中查看");
      setTimeout(load, 1500);
    } else {
      ElMessage.warning("证书签发后可推送");
    }
  } finally {
    rowPushing.value = { ...rowPushing.value, [row.id]: false };
  }
}

async function remove(row: any) {
  await ElMessageBox.confirm("确认删除该部署目标？", "提示");
  await targetDestroy(row.id);
  ElMessage.success("已删除");
  load();
}

// —— 绑定 / 编辑弹窗 ——
const bindDialog = ref(false);
const editingTarget = ref<any | null>(null);

async function openCreate() {
  editingTarget.value = null;
  bindDialog.value = true;
}
async function openEdit(row: any) {
  editingTarget.value = row;
  bindDialog.value = true;
}

// —— 推送记录弹窗 ——
const recordsDialog = ref(false);
const recordRows = ref<any[]>([]);
const recordLoading = ref(false);
const recordTotal = ref(0);
const recordPage = ref(1);
const recordPageSize = ref(10);
const showRetry = ref(false); // 显示重试细节（传 is_final=false/不传）
const recordTargetId = ref<number | null>(null); // 非空=按单目标筛选

async function loadRecords() {
  recordLoading.value = true;
  try {
    const params: any = {
      currentPage: recordPage.value,
      pageSize: recordPageSize.value
    };
    if (recordTargetId.value != null) params.target_id = recordTargetId.value;
    else params.order_id = props.order.id;
    // 默认去噪只显终态。用 1（非 JS 布尔 true）：qs 序列化 true→"true" 不被 Laravel boolean 规则接受会 422；传 1→"1" 才命中
    if (!showRetry.value) params.is_final = 1;
    const res = await logList(params);
    recordRows.value = res.data.items;
    recordTotal.value = res.data.total ?? 0;
  } finally {
    recordLoading.value = false;
  }
}
function openRecords(targetId: number | null) {
  recordTargetId.value = targetId;
  recordPage.value = 1;
  showRetry.value = false;
  recordsDialog.value = true;
  loadRecords();
}

watch(recordPage, () => {
  if (recordsDialog.value) loadRecords();
});
watch(showRetry, () => {
  if (recordsDialog.value) {
    recordPage.value = 1;
    loadRecords();
  }
});

watch(
  () => props.order?.id,
  () => {
    targets.value = [];
    load();
  },
  { immediate: true }
);
</script>

<template>
  <div style="margin-top: 8px">
    <!-- SM2：隐藏全部推送内容，仅提示 -->
    <el-text v-if="isSM2()" type="info" size="small">
      国密(SM2)证书暂不支持推送云平台
    </el-text>

    <template v-else>
      <!-- 底部操作条（绑定/列表/记录在非 active 也渲染；推送按钮 disabled 下沉）-->
      <div style="margin-bottom: 8px">
        <el-button type="primary" size="small" @click="openCreate"
          >+ 绑定新目标</el-button
        >
        <el-button
          size="small"
          :loading="pushing"
          :disabled="!isActive()"
          :title="isActive() ? '' : '证书签发后可推送'"
          @click="pushAll"
          >一键推送</el-button
        >
        <el-button link type="primary" size="small" @click="openRecords(null)"
          >推送记录</el-button
        >
        <el-text
          v-if="!isActive()"
          type="info"
          size="small"
          style="margin-left: 8px"
          >证书签发后可推送</el-text
        >
      </div>

      <!-- 目标列表 -->
      <el-table
        v-loading="loading"
        :data="targets"
        size="small"
        empty-text="尚未绑定推送目标"
      >
        <el-table-column label="云平台·产品" min-width="160">
          <template #default="{ row }"
            >{{ row.provider }} · {{ row.product }}</template
          >
        </el-table-column>
        <el-table-column label="资源" min-width="140">
          <template #default="{ row }">{{ resourceSummary(row) }}</template>
        </el-table-column>
        <el-table-column label="状态" width="100">
          <template #default="{ row }">
            <el-link
              :type="
                row.last_status === 'success'
                  ? 'success'
                  : row.last_status === 'failed'
                    ? 'danger'
                    : 'info'
              "
              underline="never"
              @click="openRecords(row.id)"
            >
              {{
                row.last_status === "success"
                  ? "成功"
                  : row.last_status === "failed"
                    ? "失败"
                    : "未推送"
              }}
            </el-link>
          </template>
        </el-table-column>
        <el-table-column label="启用" width="70">
          <template #default="{ row }">
            <el-switch
              v-model="row.enabled"
              size="small"
              @change="toggleEnabled(row)"
            />
          </template>
        </el-table-column>
        <el-table-column label="操作" width="170">
          <template #default="{ row }">
            <el-button
              link
              type="primary"
              size="small"
              :loading="rowPushing[row.id]"
              :disabled="!isActive()"
              :title="isActive() ? '' : '证书签发后可推送'"
              @click="pushOne(row)"
              >推送</el-button
            >
            <el-button link type="primary" size="small" @click="openEdit(row)"
              >编辑</el-button
            >
            <el-button link type="danger" size="small" @click="remove(row)"
              >删除</el-button
            >
          </template>
        </el-table-column>
      </el-table>

      <TargetForm
        v-model="bindDialog"
        :target="editingTarget"
        :order-id="props.order?.id"
        hide-order
        @saved="load"
      />

      <!-- 推送记录弹窗（内容见 7.4）-->
      <el-dialog v-model="recordsDialog" title="推送记录" width="720px">
        <div style="margin-bottom: 8px">
          <el-switch
            v-model="showRetry"
            size="small"
            active-text="显示重试细节"
          />
          <el-text type="info" size="small" style="margin-left: 8px">
            默认仅显示最终结果，开启后含每次重试的中间记录
          </el-text>
        </div>
        <el-table
          v-loading="recordLoading"
          :data="recordRows"
          size="small"
          empty-text="暂无推送记录"
        >
          <el-table-column label="时间" width="170">
            <template #default="{ row }">{{
              formatDateTime(row.created_at)
            }}</template>
          </el-table-column>
          <el-table-column label="目标" min-width="180">
            <template #default="{ row }">
              {{ row.provider }} · {{ row.product }}
              <span v-if="row.resource_summary"
                >（{{ row.resource_summary }}）</span
              >
            </template>
          </el-table-column>
          <el-table-column label="触发" width="80">
            <template #default="{ row }">
              {{ row.trigger === "manual" ? "手动" : "自动" }}
            </template>
          </el-table-column>
          <el-table-column label="结果" min-width="180">
            <template #default="{ row }">
              <el-tag
                v-if="row.status === 'success'"
                type="success"
                size="small"
                >成功</el-tag
              >
              <span v-else>
                <el-tag type="danger" size="small">失败</el-tag>
                <el-text type="danger" size="small" style="margin-left: 4px">
                  {{ row.error_code
                  }}{{ row.message ? "：" + row.message : "" }}
                </el-text>
              </span>
            </template>
          </el-table-column>
        </el-table>
        <el-pagination
          v-model:current-page="recordPage"
          :page-size="recordPageSize"
          :total="recordTotal"
          layout="prev, pager, next, total"
          size="small"
          style="margin-top: 8px; justify-content: flex-end"
        />
      </el-dialog>
    </template>
  </div>
</template>
