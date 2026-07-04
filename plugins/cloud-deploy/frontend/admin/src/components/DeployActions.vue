<script setup lang="ts">
import { computed, ref, watch } from "vue";
import { ElMessage } from "element-plus";
import {
  targetList,
  targetStore,
  deploy,
  accessList,
  getProviders,
  type ProviderCatalogItem,
  type ProviderProduct,
  type ConfigField
} from "@/api/cloud-deploy";
import RecordsDialog from "../views/RecordsDialog.vue";

// 插槽 props：order（含 id）、cert（含 status/encryption_alg）
const props = defineProps<{ order?: any; cert?: any }>();
const targets = ref<any[]>([]);
const pushingAll = ref(false);
const pushing = ref<Record<number, boolean>>({});
const bindDialog = ref(false);
const saving = ref(false);
const accesses = ref<any[]>([]);
const catalog = ref<ProviderCatalogItem[]>([]);
const form = ref<any>({
  access_id: undefined,
  product: undefined,
  config: {},
  enabled: true
});

// active 仅作用「推送/一键推送」按钮 disable；列表/记录在非 active 也渲染
const isActive = () => props.cert?.status === "active";
// 国密(SM2)证书：标准接口不支持，隐藏推送内容/按钮（对齐 user widget + 主系统 process.vue isGm 口径）
const isSM2 = () => /sm2/i.test(props.cert?.encryption_alg ?? "");

// 记录弹窗（点状态=按 target_id；点「推送记录」=按 order_id 整单）
const recDialog = ref(false);
const recTargetId = ref<number | undefined>(undefined);
const recOrderId = ref<number | undefined>(undefined);

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

watch(
  () => form.value.access_id,
  () => {
    form.value.product = undefined;
    form.value.config = {};
  }
);
watch(
  () => form.value.product,
  () => {
    const next: Record<string, any> = {};
    for (const f of configFields.value)
      next[f.key] = form.value.config?.[f.key] ?? "";
    form.value.config = next;
  }
);

async function load() {
  if (!props.order?.id) return;
  // 依赖后端 admin target 端点支持 order_id 精确筛选（只取本订单目标，不串他单）
  targets.value = (
    await targetList({ order_id: props.order.id, pageSize: 100 })
  ).data.items;
}

async function ensureCatalog() {
  const params: Record<string, any> = { pageSize: 100 };
  if (props.order?.user_id) params.user_id = props.order.user_id;
  const [accRes, cat] = await Promise.all([accessList(params), getProviders()]);
  accesses.value = accRes.data.items;
  catalog.value = cat;
}

async function openCreate() {
  if (!props.order?.id) return;
  await ensureCatalog();
  form.value = {
    access_id: undefined,
    product: undefined,
    config: {},
    enabled: true
  };
  bindDialog.value = true;
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
    await targetStore({ ...form.value, order_id: props.order.id });
    ElMessage.success("已绑定");
    bindDialog.value = false;
    load();
  } finally {
    saving.value = false;
  }
}

function resourceSummary(row: any): string {
  const config = row.config ?? {};
  if (config.domain) return config.domain;
  const first = Object.values(config).find(v => v != null && v !== "******");
  return first != null ? String(first) : "-";
}

// 单条推送：target_ids 模式 + force:true（对已成功 target 也重推，绕过幂等短路）
async function pushOne(t: any) {
  pushing.value[t.id] = true;
  try {
    const res = await deploy({ target_ids: [t.id], force: true });
    const n = res?.data?.dispatched ?? 0;
    if (n > 0) {
      ElMessage.success("已发起推送（结果稍后在记录中查看）");
      setTimeout(load, 1500);
    } else {
      ElMessage.warning("未发起推送");
    }
  } finally {
    pushing.value[t.id] = false;
  }
}
// 一键推送：推本订单全部 enabled 目标（与 user widget 同口径）。
// target_ids 模式后端不过滤 enabled，故须前端先 filter(enabled) 收敛，否则会把用户主动停用的目标也推上去。
// 单条行内「推送」走 target_ids 不过滤 enabled 是合理的（显式选行），仅「一键」需收敛。
async function pushAll() {
  const ids = targets.value.filter(t => t.enabled).map(t => t.id);
  if (!ids.length) {
    ElMessage.warning("没有启用的部署目标可推送");
    return;
  }
  pushingAll.value = true;
  try {
    const res = await deploy({ target_ids: ids, force: true });
    const n = res?.data?.dispatched ?? 0;
    if (n > 0) {
      ElMessage.success(`已发起推送 ${n} 个目标`);
      setTimeout(load, 1500);
    } else {
      ElMessage.warning("没有可推送的目标（证书可能非签发态）");
    }
  } finally {
    pushingAll.value = false;
  }
}
function openRecordsByTarget(t: any) {
  recTargetId.value = t.id;
  recOrderId.value = undefined;
  recDialog.value = true;
}
function openRecordsByOrder() {
  recTargetId.value = undefined;
  recOrderId.value = props.order?.id;
  recDialog.value = true;
}

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
    <!-- SM2：隐藏推送内容/按钮，仅提示 -->
    <el-text v-if="isSM2()" type="info" size="small"
      >国密(SM2)证书暂不支持推送云平台</el-text
    >
    <template v-else>
      <!-- 一键推送 + 推送记录；非 active 时一键推送 disabled 并提示 -->
      <el-button type="primary" size="small" @click="openCreate"
        >绑定新目标</el-button
      >
      <el-button
        size="small"
        :loading="pushingAll"
        :disabled="!isActive()"
        @click="pushAll"
        >一键推送</el-button
      >
      <el-button size="small" @click="openRecordsByOrder">推送记录</el-button>
      <el-text
        v-if="!isActive()"
        type="info"
        size="small"
        style="margin-left: 8px"
        >证书签发后可推送</el-text
      >

      <!-- 目标列表（非 active 也渲染，便于查看已绑目标/状态） -->
      <div v-if="targets.length" style="margin-top: 8px">
        <div
          v-for="t in targets"
          :key="t.id"
          style="
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 4px;
          "
        >
          <el-tag
            size="small"
            style="cursor: pointer"
            :type="
              t.last_status === 'success'
                ? 'success'
                : t.last_status === 'failed'
                  ? 'danger'
                  : 'info'
            "
            @click="openRecordsByTarget(t)"
          >
            {{ t.provider }}·{{ t.product }}:{{ resourceSummary(t) }}
            {{ t.last_status || "未推送" }}
          </el-tag>
          <el-button
            link
            type="primary"
            size="small"
            :loading="pushing[t.id]"
            :disabled="!isActive()"
            @click="pushOne(t)"
            >推送</el-button
          >
        </div>
      </div>
      <el-text v-else type="info" size="small">暂无部署目标</el-text>
    </template>

    <el-dialog v-model="bindDialog" title="绑定推送目标" width="480px">
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
              :label="`${a.name}(${a.provider})${a.username ? ' · ' + a.username : ''}`"
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

    <RecordsDialog
      v-model="recDialog"
      :order-id="recOrderId"
      :target-id="recTargetId"
    />
  </div>
</template>
