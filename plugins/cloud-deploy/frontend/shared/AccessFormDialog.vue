<script setup lang="ts">
import { computed, ref, watch } from "vue";
import { ElMessage } from "element-plus";
import SchemaFieldLabel from "./SchemaFieldLabel.vue";
import {
  configForVisibleSchema,
  isSchemaFieldRequired,
  isSchemaFieldVisible,
  valuesForVisibleSchema
} from "./schemaConditions";

export interface CredentialField {
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
}

export interface CredentialProvider {
  key: string;
  label: string;
  credentialSchema: CredentialField[];
}

export interface AccessFormApi {
  accessStore: (data: Record<string, any>) => Promise<any>;
  accessUpdate: (id: number, data: Record<string, any>) => Promise<any>;
}

const props = withDefaults(
  defineProps<{
    modelValue: boolean;
    api: AccessFormApi;
    catalog: CredentialProvider[];
    access?: any | null;
    userId?: number | string | null;
  }>(),
  {
    access: null,
    userId: null
  }
);

const emit = defineEmits<{
  (e: "update:modelValue", value: boolean): void;
  (
    e: "saved",
    value: { id?: number; name: string; provider: string; created: boolean }
  ): void;
}>();

const saving = ref(false);
const credentialsDirty = ref(false);
const form = ref<any>({ name: "", provider: "", credentials: {} });
let initializingCredentials = false;

const isEdit = computed(() => !!props.access?.id);
const credentialFields = computed<CredentialField[]>(() => {
  const provider = props.catalog.find(item => item.key === form.value.provider);
  return provider?.credentialSchema ?? [];
});
const visibleCredentialFields = computed<CredentialField[]>(() =>
  credentialFields.value.filter(field =>
    isSchemaFieldVisible(
      field,
      form.value.credentials ?? {},
      credentialFields.value
    )
  )
);

watch(
  () => form.value.credentials,
  () => {
    if (!initializingCredentials) credentialsDirty.value = true;
  },
  { deep: true, flush: "sync" }
);

watch(
  () => props.modelValue,
  visible => {
    if (visible) open();
  }
);

function setForm(value: Record<string, unknown>) {
  initializingCredentials = true;
  form.value = value;
  initializingCredentials = false;
  credentialsDirty.value = false;
}

function open() {
  if (isEdit.value) {
    const provider = props.catalog.find(
      item => item.key === props.access.provider
    );
    setForm({
      name: props.access.name,
      provider: props.access.provider,
      credentials: configForVisibleSchema(
        provider?.credentialSchema ?? [],
        {}
      )
    });
    return;
  }

  const provider = props.catalog[0];
  setForm({
    name: "",
    provider: provider?.key ?? "",
    credentials: configForVisibleSchema(provider?.credentialSchema ?? [], {})
  });
}

function onProviderChange() {
  initializingCredentials = true;
  form.value.credentials = configForVisibleSchema(credentialFields.value, {});
  initializingCredentials = false;
  credentialsDirty.value = false;
}

function close() {
  emit("update:modelValue", false);
}

function validate(): boolean {
  if (!form.value.name) {
    ElMessage.warning("请填写凭证名");
    return false;
  }
  if (!form.value.provider) {
    ElMessage.warning("请选择云平台");
    return false;
  }
  if (!isEdit.value) {
    for (const field of visibleCredentialFields.value) {
      const value = form.value.credentials?.[field.key];
      if (
        isSchemaFieldRequired(
          field,
          form.value.credentials ?? {},
          credentialFields.value
        ) &&
        (value === undefined || value === null || value === "")
      ) {
        ElMessage.warning(`请填写：${field.label}`);
        return false;
      }
    }
  }

  return true;
}

async function submit() {
  if (!validate()) return;

  const credentials = valuesForVisibleSchema(
    credentialFields.value,
    form.value.credentials ?? {}
  );
  const filled = Object.values(credentials).some(value => value);
  saving.value = true;
  try {
    if (isEdit.value) {
      const payload: Record<string, any> = { name: form.value.name };
      if (credentialsDirty.value && filled) payload.credentials = credentials;
      await props.api.accessUpdate(props.access.id, payload);
    } else {
      const payload: Record<string, any> = {
        name: form.value.name,
        provider: form.value.provider,
        credentials
      };
      if (props.userId) payload.user_id = props.userId;
      await props.api.accessStore(payload);
    }

    ElMessage.success("已保存");
    emit("saved", {
      id: props.access?.id,
      name: form.value.name,
      provider: form.value.provider,
      created: !isEdit.value
    });
    close();
  } finally {
    saving.value = false;
  }
}
</script>

<template>
  <el-dialog
    :model-value="modelValue"
    :title="isEdit ? '编辑云凭证' : '新增云凭证'"
    width="640px"
    append-to-body
    @update:model-value="emit('update:modelValue', $event)"
  >
    <el-form label-width="120px">
      <el-form-item label="凭证名">
        <el-input v-model="form.name" />
      </el-form-item>
      <el-form-item label="云平台">
        <el-select
          v-model="form.provider"
          style="width: 100%"
          :disabled="isEdit"
          @change="onProviderChange"
        >
          <el-option
            v-for="provider in catalog"
            :key="provider.key"
            :label="provider.label"
            :value="provider.key"
          />
        </el-select>
      </el-form-item>
      <el-form-item
        v-for="field in visibleCredentialFields"
        :key="field.key"
        :required="false"
      >
        <template #label>
          <SchemaFieldLabel :field="field" />
        </template>
        <el-select
          v-if="field.type === 'select'"
          v-model="form.credentials[field.key]"
          style="width: 100%"
        >
          <el-option
            v-for="option in field.options ?? []"
            :key="option.value"
            :label="option.label"
            :value="option.value"
          />
        </el-select>
        <el-input
          v-else
          v-model="form.credentials[field.key]"
          :placeholder="isEdit ? '留空不修改' : ''"
          :show-password="field.secret"
          :type="field.secret ? 'password' : 'text'"
        />
      </el-form-item>
    </el-form>

    <template #footer>
      <el-button @click="close">取消</el-button>
      <el-button type="primary" :loading="saving" @click="submit">
        保存
      </el-button>
    </template>
  </el-dialog>
</template>
