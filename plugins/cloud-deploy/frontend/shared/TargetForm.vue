<script setup lang="ts">
import { computed, ref, watch } from "vue";
import { ElMessage } from "element-plus";
import AccessFormDialog from "./AccessFormDialog.vue";
import SchemaFieldLabel from "./SchemaFieldLabel.vue";
import { isBooleanConfigField } from "./schemaConfig";
import {
  configForVisibleSchema,
  isSchemaFieldRequired,
  isSchemaFieldVisible
} from "./schemaConditions";

export interface ConfigField {
  key: string;
  label: string;
  type: "string" | "number" | "select" | "bool" | "boolean";
  required?: boolean;
  default?: unknown;
  required_when?: { key: string; equals: unknown };
  visible_when?: { key: string; equals: unknown };
  options?: Array<{ label: string; value: string }>;
  description?: string;
  help?: string;
  tip?: string;
}

export interface ProviderProduct {
  product: string;
  label: string;
  configSchema: ConfigField[];
}

export interface ProviderCatalogItem {
  key: string;
  label: string;
  credentialSchema: Array<{
    key: string;
    label: string;
    type?: "string" | "number" | "select" | "bool" | "boolean";
    required?: boolean;
    default?: unknown;
    required_when?: { key: string; equals: unknown };
    visible_when?: { key: string; equals: unknown };
    options?: Array<{ label: string; value: string }>;
    secret?: boolean;
    description?: string;
    help?: string;
    tip?: string;
  }>;
  products: ProviderProduct[];
}

export interface TargetFormApi {
  accessList: (params: Record<string, any>) => Promise<any>;
  getProviders: (force?: boolean) => Promise<ProviderCatalogItem[]>;
  targetShow?: (id: number) => Promise<any>;
  accessStore: (data: Record<string, any>) => Promise<any>;
  accessUpdate: (id: number, data: Record<string, any>) => Promise<any>;
  targetStore: (data: Record<string, any>) => Promise<any>;
  targetUpdate: (id: number, data: Record<string, any>) => Promise<any>;
}

const props = withDefaults(
  defineProps<{
    modelValue: boolean;
    mode: "admin" | "user";
    api: TargetFormApi;
    target?: any | null;
    orderId?: number | string | null;
    userId?: number | string | null;
    hideOrder?: boolean;
  }>(),
  {
    target: null,
    orderId: null,
    userId: null,
    hideOrder: false
  }
);

const emit = defineEmits<{
  (e: "update:modelValue", value: boolean): void;
  (e: "saved"): void;
}>();

const accesses = ref<any[]>([]);
const catalog = ref<ProviderCatalogItem[]>([]);
const loading = ref(false);
const saving = ref(false);
const suppressReset = ref(false);
const accessDialog = ref(false);
const editingAccess = ref<any | null>(null);
const accessIdsBeforeCreate = ref<Set<string>>(new Set());
const form = ref<any>({
  user_id: undefined,
  access_id: undefined,
  order_id: undefined,
  product: undefined,
  config: {},
  enabled: true
});

const isAdmin = computed(() => props.mode === "admin");
const isEdit = computed(() => !!props.target?.id);
const showUserField = computed(() => isAdmin.value && !props.hideOrder);
const dialogWidth = computed(() => (isAdmin.value ? "680px" : "640px"));
const accessDisabled = computed(() => isAdmin.value && !form.value.user_id);
const orderDisabled = computed(
  () => accessDisabled.value || !form.value.access_id
);
const accessPlaceholder = computed(() =>
  isAdmin.value ? "先选择用户" : "选择云凭证"
);
const orderQueryParams = computed(() =>
  isAdmin.value && form.value.user_id ? { user_id: form.value.user_id } : {}
);
const orderRefreshKey = computed(() =>
  isAdmin.value ? (form.value.user_id ?? undefined) : undefined
);
const selectedAccess = computed(() =>
  accesses.value.find(a => String(a.id) === String(form.value.access_id))
);
const selectedProvider = computed<ProviderCatalogItem | undefined>(() => {
  if (!selectedAccess.value) return undefined;
  return catalog.value.find(p => p.key === selectedAccess.value.provider);
});
const products = computed<ProviderProduct[]>(
  () => selectedProvider.value?.products ?? []
);
const configFields = computed<ConfigField[]>(() => {
  const prod = products.value.find(p => p.product === form.value.product);
  return prod?.configSchema ?? [];
});
const visibleConfigFields = computed<ConfigField[]>(() =>
  configFields.value.filter(field =>
    isSchemaFieldVisible(field, form.value.config ?? {}, configFields.value)
  )
);

watch(
  () => form.value.user_id,
  async userId => {
    if (!isAdmin.value || suppressReset.value) return;
    form.value.access_id = undefined;
    form.value.order_id = undefined;
    form.value.product = undefined;
    form.value.config = {};
    accesses.value = [];
    if (userId) await loadAccesses(userId);
  }
);

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
    form.value.config = configForVisibleSchema(
      configFields.value,
      form.value.config ?? {}
    );
  }
);

watch(
  () => form.value.config,
  current => {
    const next = configForVisibleSchema(configFields.value, current ?? {});
    if (!sameConfig(current ?? {}, next)) form.value.config = next;
  },
  { deep: true }
);

watch(
  () => props.modelValue,
  visible => {
    if (visible) open();
  }
);

async function loadAccesses(userId?: number | string) {
  const params: Record<string, any> = { pageSize: 100 };
  if (isAdmin.value && userId) params.user_id = userId;
  const res = await props.api.accessList(params);
  accesses.value = res.data.items;
}

function openAccessCreate() {
  editingAccess.value = null;
  accessIdsBeforeCreate.value = new Set(
    accesses.value.map(access => String(access.id))
  );
  accessDialog.value = true;
}

function openAccessEdit() {
  if (!selectedAccess.value) return;
  editingAccess.value = selectedAccess.value;
  accessDialog.value = true;
}

async function onAccessSaved(result: {
  id?: number;
  name: string;
  provider: string;
  created: boolean;
}) {
  const selectedId = form.value.access_id;
  await loadAccesses(form.value.user_id);

  if (result.created) {
    const createdAccess = accesses.value.find(
      access =>
        !accessIdsBeforeCreate.value.has(String(access.id)) &&
        access.name === result.name &&
        access.provider === result.provider
    );
    if (createdAccess) form.value.access_id = createdAccess.id;
    return;
  }

  form.value.access_id = result.id ?? selectedId;
}

async function open() {
  loading.value = true;
  try {
    catalog.value = await props.api.getProviders();

    const detail =
      props.target?.id && props.api.targetShow
        ? (await props.api.targetShow(props.target.id)).data
        : props.target?.id
          ? props.target
          : null;
    const userId = detail?.user_id ?? props.userId ?? undefined;

    if (isAdmin.value) {
      if (userId) await loadAccesses(userId);
    } else {
      await loadAccesses();
    }

    suppressReset.value = true;
    form.value = detail
      ? {
          user_id: detail.user_id ?? userId,
          access_id: detail.access_id,
          order_id: detail.order_id,
          product: detail.product,
          config: { ...(detail.config ?? {}) },
          enabled: !!detail.enabled
        }
      : {
          user_id: userId,
          access_id: undefined,
          order_id: props.hideOrder ? props.orderId : undefined,
          product: undefined,
          config: {},
          enabled: true
        };
    form.value.config = configForVisibleSchema(
      configFields.value,
      form.value.config ?? {}
    );
    setTimeout(() => (suppressReset.value = false), 0);
  } finally {
    loading.value = false;
  }
}

function close() {
  emit("update:modelValue", false);
}

function validate(): boolean {
  if (showUserField.value && !form.value.user_id) {
    ElMessage.warning("请选择用户");
    return false;
  }
  if (!form.value.access_id) {
    ElMessage.warning("请选择云凭证");
    return false;
  }
  const orderId = props.hideOrder ? props.orderId : form.value.order_id;
  if (!orderId) {
    ElMessage.warning("请选择订单");
    return false;
  }
  if (!form.value.product) {
    ElMessage.warning("请选择产品");
    return false;
  }
  for (const f of visibleConfigFields.value) {
    const v = form.value.config?.[f.key];
    if (
      isSchemaFieldRequired(f, form.value.config ?? {}, configFields.value) &&
      (v === undefined || v === null || v === "")
    ) {
      ElMessage.warning(`请填写：${f.label}`);
      return false;
    }
  }

  return true;
}

async function submit() {
  if (!validate()) return;

  form.value.config = configForVisibleSchema(
    configFields.value,
    form.value.config ?? {}
  );

  const payload: Record<string, any> = {
    access_id: form.value.access_id,
    product: form.value.product,
    config: form.value.config,
    enabled: form.value.enabled
  };
  if (!props.hideOrder || !isEdit.value) {
    payload.order_id = props.hideOrder ? props.orderId : form.value.order_id;
  }

  saving.value = true;
  try {
    if (isEdit.value) {
      await props.api.targetUpdate(props.target.id, payload);
      ElMessage.success("已保存");
    } else {
      await props.api.targetStore(payload);
      ElMessage.success("已添加");
    }
    emit("saved");
    close();
  } finally {
    saving.value = false;
  }
}

function providerLabel(key: string): string {
  return catalog.value.find(p => p.key === key)?.label ?? key;
}

function accessLabel(access: any): string {
  const label = `${access.name}(${providerLabel(access.provider)})`;
  return isAdmin.value ? `${label} · ${access.username ?? ""}` : label;
}

function sameConfig(
  left: Record<string, unknown>,
  right: Record<string, unknown>
): boolean {
  const leftKeys = Object.keys(left);
  const rightKeys = Object.keys(right);
  return (
    leftKeys.length === rightKeys.length &&
    leftKeys.every(key => Object.is(left[key], right[key]))
  );
}
</script>

<template>
  <el-dialog
    :model-value="modelValue"
    :title="isEdit ? '编辑部署目标' : '新增部署目标'"
    :width="dialogWidth"
    @update:model-value="emit('update:modelValue', $event)"
  >
    <el-form v-loading="loading" label-width="120px">
      <el-form-item v-if="showUserField" label="用户">
        <re-remote-select
          v-model="form.user_id"
          uri="/user"
          search-field="quickSearch"
          label-field="username"
          value-field="id"
          items-field="items"
          total-field="total"
          placeholder="搜索用户"
          style="width: 100%"
        />
      </el-form-item>

      <el-form-item label="云凭证">
        <div
          style="display: flex; width: 100%; align-items: center; gap: 8px"
        >
          <el-select
            v-model="form.access_id"
            :placeholder="accessPlaceholder"
            :disabled="accessDisabled"
            clearable
            style="flex: 1; min-width: 0"
          >
            <el-option
              v-for="a in accesses"
              :key="a.id"
              :label="accessLabel(a)"
              :value="a.id"
            />
          </el-select>
          <template v-if="hideOrder">
            <el-button
              v-if="!selectedAccess"
              type="primary"
              :disabled="accessDisabled"
              @click="openAccessCreate"
            >
              新增
            </el-button>
            <el-button v-else type="primary" @click="openAccessEdit">
              编辑
            </el-button>
          </template>
        </div>
      </el-form-item>

      <el-form-item v-if="!hideOrder" label="订单">
        <re-remote-select
          v-model="form.order_id"
          uri="/cloud-deploy/order-options"
          search-field="quickSearch"
          label-field="label"
          value-field="id"
          items-field="items"
          total-field="total"
          placeholder="按订单号或域名搜索"
          :query-params="orderQueryParams"
          :refresh-key="orderRefreshKey"
          :disabled="orderDisabled"
          style="width: 100%"
        />
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
        v-for="f in visibleConfigFields"
        :key="f.key"
        :required="false"
      >
        <template #label>
          <SchemaFieldLabel :field="f" />
        </template>
        <el-select
          v-if="isBooleanConfigField(f)"
          v-model="form.config[f.key]"
          style="width: 100%"
        >
          <el-option label="是" :value="true" />
          <el-option label="否" :value="false" />
        </el-select>
        <el-select
          v-else-if="f.type === 'select'"
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
        <el-input v-else v-model="form.config[f.key]" :placeholder="f.label" />
      </el-form-item>

      <el-form-item label="启用自动推送">
        <el-switch v-model="form.enabled" />
      </el-form-item>
    </el-form>

    <template #footer>
      <el-button @click="close">取消</el-button>
      <el-button type="primary" :loading="saving" @click="submit">
        保存
      </el-button>
    </template>
  </el-dialog>

  <AccessFormDialog
    v-model="accessDialog"
    :api="api"
    :catalog="catalog"
    :access="editingAccess"
    :user-id="isAdmin ? form.user_id : null"
    @saved="onAccessSaved"
  />
</template>
