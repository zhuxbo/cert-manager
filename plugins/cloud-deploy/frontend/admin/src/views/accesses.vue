<script setup lang="ts">
import { computed, onMounted, ref, watch } from "vue";
import { ElMessage, ElMessageBox } from "element-plus";
import {
  accessDestroy,
  accessList,
  accessStore,
  accessUpdate,
  getProviders,
  type CredentialField,
  type ProviderCatalogItem
} from "@/api/cloud-deploy";
import { formatDateTime } from "@/utils/time";
import SchemaFieldLabel from "@cloud-deploy/shared/SchemaFieldLabel.vue";
import {
  configForVisibleSchema,
  isSchemaFieldRequired,
  isSchemaFieldVisible,
  valuesForVisibleSchema
} from "@cloud-deploy/shared/schemaConditions";

const rows = ref<any[]>([]);
const loading = ref(false);
const currentPage = ref(1);
const pageSize = ref(20);
const total = ref(0);
const dialog = ref(false);
const editing = ref<any | null>(null);
const catalog = ref<ProviderCatalogItem[]>([]);
const form = ref<any>({
  user_id: undefined,
  name: "",
  provider: "",
  credentials: {}
});
const credentialsDirty = ref(false);
let initializingCredentials = false;

const q = ref<any>({
  username: "",
  name: "",
  provider: ""
});

const credFields = computed<CredentialField[]>(() => {
  const provider = catalog.value.find(c => c.key === form.value.provider);
  return provider?.credentialSchema ?? [];
});
const visibleCredFields = computed<CredentialField[]>(() =>
  credFields.value.filter(field =>
    isSchemaFieldVisible(field, form.value.credentials ?? {}, credFields.value)
  )
);

watch(
  () => form.value.credentials,
  () => {
    if (!initializingCredentials) credentialsDirty.value = true;
  },
  { deep: true, flush: "sync" }
);

function setCredentials(credentials: Record<string, unknown>) {
  initializingCredentials = true;
  form.value.credentials = credentials;
  initializingCredentials = false;
}

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
    const [res, cat] = await Promise.all([
      accessList(buildParams()),
      getProviders()
    ]);
    rows.value = res.data.items;
    total.value = res.data.total;
    catalog.value = cat;
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

function providerLabel(key: string): string {
  return catalog.value.find(c => c.key === key)?.label ?? key;
}

function onProviderChange() {
  setCredentials(configForVisibleSchema(credFields.value, {}));
}

function openCreate() {
  editing.value = null;
  form.value = {
    user_id: undefined,
    name: "",
    provider: catalog.value[0]?.key ?? "",
    credentials: configForVisibleSchema(
      catalog.value[0]?.credentialSchema ?? [],
      {}
    )
  };
  credentialsDirty.value = false;
  dialog.value = true;
}

function openEdit(row: any) {
  editing.value = row;
  const provider = catalog.value.find(item => item.key === row.provider);
  initializingCredentials = true;
  form.value = {
    user_id: row.user_id,
    name: row.name,
    provider: row.provider,
    // 旧凭证缺少新选择字段时采用 schema 默认值；未编辑时不回写。
    credentials: configForVisibleSchema(provider?.credentialSchema ?? [], {})
  };
  initializingCredentials = false;
  credentialsDirty.value = false;
  dialog.value = true;
}

function validate(): boolean {
  if (!editing.value && !form.value.user_id) {
    ElMessage.warning("请选择用户");
    return false;
  }
  if (!form.value.name) {
    ElMessage.warning("请填写凭证名");
    return false;
  }
  if (!form.value.provider) {
    ElMessage.warning("请选择云平台");
    return false;
  }
  if (!editing.value) {
    for (const f of visibleCredFields.value) {
      const v = form.value.credentials?.[f.key];
      if (
        isSchemaFieldRequired(f, form.value.credentials ?? {}, credFields.value) &&
        (v === undefined || v === null || v === "")
      ) {
        ElMessage.warning(`请填写：${f.label}`);
        return false;
      }
    }
  }

  return true;
}

async function submit() {
  if (!validate()) return;

  const credentials = valuesForVisibleSchema(
    credFields.value,
    form.value.credentials ?? {}
  );
  const filled = Object.values(credentials).some(v => v);
  if (editing.value) {
    const payload: Record<string, any> = { name: form.value.name };
    if (credentialsDirty.value && filled) payload.credentials = credentials;
    await accessUpdate(editing.value.id, payload);
  } else {
    await accessStore({
      user_id: form.value.user_id,
      name: form.value.name,
      provider: form.value.provider,
      credentials
    });
  }
  ElMessage.success("已保存");
  dialog.value = false;
  load();
}

async function remove(row: any) {
  await ElMessageBox.confirm("确认删除该凭证？", "提示");
  await accessDestroy(row.id);
  ElMessage.success("已删除");
  load();
}

onMounted(load);
defineExpose({ openCreate });
</script>

<template>
  <div>
    <el-form :inline="true" :model="q" style="margin-bottom: 8px">
      <el-form-item>
        <el-input
          v-model="q.username"
          placeholder="用户名"
          clearable
          style="width: 140px"
        />
      </el-form-item>
      <el-form-item>
        <el-input
          v-model="q.name"
          placeholder="凭证名称"
          clearable
          style="width: 160px"
        />
      </el-form-item>
      <el-form-item>
        <el-select
          v-model="q.provider"
          placeholder="云平台"
          clearable
          style="width: 160px"
        >
          <el-option
            v-for="p in catalog"
            :key="p.key"
            :label="p.label"
            :value="p.key"
          />
        </el-select>
      </el-form-item>
      <el-form-item>
        <el-button type="primary" @click="onSearch">搜索</el-button>
        <el-button @click="onReset">重置</el-button>
      </el-form-item>
    </el-form>

    <el-table v-loading="loading" :data="rows">
      <el-table-column prop="username" label="用户" width="130" />
      <el-table-column prop="name" label="名称" />
      <el-table-column label="云平台" width="150">
        <template #default="{ row }">
          {{ providerLabel(row.provider) }}
        </template>
      </el-table-column>
      <el-table-column label="创建时间" width="180">
        <template #default="{ row }">
          {{ formatDateTime(row.created_at) }}
        </template>
      </el-table-column>
      <el-table-column label="操作" width="150">
        <template #default="{ row }">
          <el-button link type="primary" @click="openEdit(row)">编辑</el-button>
          <el-button link type="danger" @click="remove(row)">删除</el-button>
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

    <el-dialog
      v-model="dialog"
      :title="editing ? '编辑云凭证' : '新增云凭证'"
      width="640px"
    >
      <el-form label-width="120px">
        <el-form-item label="用户">
          <re-remote-select
            v-model="form.user_id"
            uri="/user"
            search-field="quickSearch"
            label-field="username"
            value-field="id"
            items-field="items"
            total-field="total"
            placeholder="搜索用户"
            :disabled="!!editing"
            style="width: 100%"
          />
        </el-form-item>
        <el-form-item label="凭证名">
          <el-input v-model="form.name" />
        </el-form-item>
        <el-form-item label="云平台">
          <el-select
            v-model="form.provider"
            style="width: 100%"
            :disabled="!!editing"
            @change="onProviderChange"
          >
            <el-option
              v-for="p in catalog"
              :key="p.key"
              :label="p.label"
              :value="p.key"
            />
          </el-select>
        </el-form-item>
        <el-form-item
          v-for="f in visibleCredFields"
          :key="f.key"
          :required="false"
        >
          <template #label>
            <SchemaFieldLabel :field="f" />
          </template>
          <el-select
            v-if="f.type === 'select'"
            v-model="form.credentials[f.key]"
            style="width: 100%"
          >
            <el-option
              v-for="option in f.options ?? []"
              :key="option.value"
              :label="option.label"
              :value="option.value"
            />
          </el-select>
          <el-input
            v-else
            v-model="form.credentials[f.key]"
            :placeholder="editing ? '留空不修改' : ''"
            :show-password="f.secret"
            :type="f.secret ? 'password' : 'text'"
          />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="dialog = false">取消</el-button>
        <el-button type="primary" @click="submit">保存</el-button>
      </template>
    </el-dialog>
  </div>
</template>
