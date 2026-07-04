<script setup lang="ts">
import { ref, computed, watch } from "vue";
import { ElMessage, ElMessageBox } from "element-plus";
import {
  targetList,
  targetStore,
  targetUpdate,
  targetDestroy,
  deploy,
  logList,
  accessList,
  getProviders,
  type ProviderCatalogItem,
  type ProviderProduct,
  type ConfigField
} from "@/api/cloud-deploy";

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
const editingId = ref<number | null>(null); // null=新建，非空=编辑
const accesses = ref<any[]>([]);
const catalog = ref<ProviderCatalogItem[]>([]);
const saving = ref(false);
const form = ref<any>({
  access_id: undefined,
  product: undefined,
  config: {},
  enabled: true
});

const selectedProvider = computed<ProviderCatalogItem | undefined>(() => {
  const acc = accesses.value.find(a => a.id === form.value.access_id);
  if (!acc) return undefined;
  return catalog.value.find(p => p.key === acc.provider);
});
const products = computed<ProviderProduct[]>(
  () => selectedProvider.value?.products ?? []
);
const configFields = computed<ConfigField[]>(() => {
  const prod = products.value.find(p => p.product === form.value.product);
  return prod?.configSchema ?? [];
});

// 切换凭证：provider 可能变，清空已选产品与 config（编辑回填时用 suppressReset 跳过首帧清空）
const suppressReset = ref(false);
watch(
  () => form.value.access_id,
  () => {
    if (suppressReset.value) return;
    form.value.product = undefined;
    form.value.config = {};
  }
);
watch(
  () => form.value.product,
  () => {
    if (suppressReset.value) return;
    const next: Record<string, any> = {};
    for (const f of configFields.value)
      next[f.key] = form.value.config?.[f.key] ?? "";
    form.value.config = next;
  }
);

async function ensureCatalog() {
  const [accRes, cat] = await Promise.all([
    accessList({ pageSize: 100 }),
    getProviders()
  ]);
  accesses.value = accRes.data.items;
  catalog.value = cat;
}
async function openCreate() {
  await ensureCatalog();
  editingId.value = null;
  form.value = {
    access_id: undefined,
    product: undefined,
    config: {},
    enabled: true
  };
  bindDialog.value = true;
}
async function openEdit(row: any) {
  await ensureCatalog();
  editingId.value = row.id;
  // 回填：先抑制 watch 清空，赋值后下一 tick 解除
  suppressReset.value = true;
  form.value = {
    access_id: row.access_id,
    product: row.product,
    config: { ...(row.config ?? {}) },
    enabled: !!row.enabled
  };
  bindDialog.value = true;
  setTimeout(() => (suppressReset.value = false), 0);
}
async function submitBind() {
  if (!form.value.access_id) {
    ElMessage.warning("请选择云凭证");
    return;
  }
  if (!form.value.product) {
    ElMessage.warning("请选择产品");
    return;
  }
  for (const f of configFields.value) {
    const v = form.value.config?.[f.key];
    if (f.required && (v === undefined || v === null || v === "")) {
      ElMessage.warning(`请填写：${f.label}`);
      return;
    }
  }
  saving.value = true;
  try {
    if (editingId.value == null) {
      // 绑定：order_id 锁定当前订单
      await targetStore({ ...form.value, order_id: props.order.id });
      ElMessage.success("已绑定");
    } else {
      await targetUpdate(editingId.value, {
        access_id: form.value.access_id,
        product: form.value.product,
        config: form.value.config,
        enabled: form.value.enabled
      });
      ElMessage.success("已保存");
    }
    bindDialog.value = false;
    load();
  } finally {
    saving.value = false;
  }
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
              :underline="false"
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

      <!-- 绑定/编辑弹窗（内容见 7.3）-->
      <el-dialog
        v-model="bindDialog"
        :title="editingId == null ? '绑定推送目标' : '编辑推送目标'"
        width="480px"
      >
        <el-form label-width="120px">
          <el-form-item label="订单（锁定）">
            <el-input :model-value="props.order?.id" disabled />
            <el-text
              type="info"
              size="small"
              style="margin-top: 4px; display: block"
            >
              部署目标锚定当前订单；续费/重签自动跟随，另开新订单换证书需重新配置目标
            </el-text>
          </el-form-item>
          <el-form-item label="凭证">
            <el-select
              v-model="form.access_id"
              placeholder="选择云凭证"
              style="width: 100%"
            >
              <el-option
                v-for="a in accesses"
                :key="a.id"
                :label="`${a.name}(${a.provider})`"
                :value="a.id"
              />
            </el-select>
          </el-form-item>
          <el-form-item label="产品">
            <el-select
              v-model="form.product"
              placeholder="先选择凭证"
              :disabled="!form.access_id"
              style="width: 100%"
            >
              <el-option
                v-for="p in products"
                :key="p.product"
                :label="p.label"
                :value="p.product"
              />
            </el-select>
          </el-form-item>
          <!-- 据选中产品 configSchema 动态渲染字段（与 target.vue 同口径）-->
          <el-form-item
            v-for="f in configFields"
            :key="f.key"
            :label="f.label"
            :required="f.required"
          >
            <el-select
              v-if="f.type === 'select'"
              v-model="form.config[f.key]"
              :placeholder="f.label"
              style="width: 100%"
            >
              <el-option
                v-for="opt in f.options || []"
                :key="opt.value"
                :label="opt.label"
                :value="opt.value"
              />
            </el-select>
            <el-input
              v-else-if="f.type === 'number'"
              v-model.number="form.config[f.key]"
              type="number"
              :placeholder="f.label"
            />
            <el-input
              v-else
              v-model="form.config[f.key]"
              :placeholder="f.label"
            />
          </el-form-item>
          <el-form-item label="启用自动推送">
            <el-switch v-model="form.enabled" />
          </el-form-item>
        </el-form>
        <template #footer>
          <el-button @click="bindDialog = false">取消</el-button>
          <el-button type="primary" :loading="saving" @click="submitBind"
            >保存</el-button
          >
        </template>
      </el-dialog>

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
          <el-table-column prop="created_at" label="时间" width="170" />
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
          small
          style="margin-top: 8px; justify-content: flex-end"
        />
      </el-dialog>
    </template>
  </div>
</template>
