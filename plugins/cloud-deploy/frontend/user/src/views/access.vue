<script setup lang="ts">
import { ref, computed, onMounted, watch } from "vue";
import { ElMessage, ElMessageBox } from "element-plus";
import {
  accessList,
  accessStore,
  accessUpdate,
  accessDestroy,
  getProviders,
  type ProviderCatalogItem,
  type CredentialField
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
const total = ref(0);
const loading = ref(false);
const currentPage = ref(1);
const pageSize = ref(20);
const dialog = ref(false);
const editing = ref<any>(null);
const catalog = ref<ProviderCatalogItem[]>([]);
const form = ref<any>({ name: "", provider: "aliyun", credentials: {} });
const credentialsDirty = ref(false);
let initializingCredentials = false;
const q = ref<any>({
  name: "",
  provider: ""
});

// 据选中 provider 的 credentialSchema 渲染凭证字段（secret 脱敏）
const credFields = computed<CredentialField[]>(() => {
  const p = catalog.value.find(c => c.key === form.value.provider);
  return p?.credentialSchema ?? [];
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
  q.value = {
    name: "",
    provider: ""
  };
  onSearch();
}

function onPage(p: number) {
  currentPage.value = p;
  load();
}

function onProviderChange() {
  setCredentials(configForVisibleSchema(credFields.value, {}));
}
function openCreate() {
  editing.value = null;
  const first = catalog.value[0]?.key ?? "aliyun";
  const provider = catalog.value.find(item => item.key === first);
  form.value = {
    name: "",
    provider: first,
    credentials: configForVisibleSchema(provider?.credentialSchema ?? [], {})
  };
  credentialsDirty.value = false;
  dialog.value = true;
}
function openEdit(row: any) {
  editing.value = row;
  const provider = catalog.value.find(item => item.key === row.provider);
  initializingCredentials = true;
  form.value = {
    name: row.name,
    provider: row.provider,
    // 新增选择字段在旧记录中不存在时仍按 schema default 展示；未编辑不回写凭证。
    credentials: configForVisibleSchema(provider?.credentialSchema ?? [], {})
  };
  initializingCredentials = false;
  credentialsDirty.value = false;
  dialog.value = true;
}
async function submit() {
  if (!form.value.name) {
    ElMessage.warning("请填写备注名");
    return;
  }
  // 新增：按 schema 校验必填凭证（编辑留空=不改，跳过）
  if (!editing.value) {
    for (const f of visibleCredFields.value) {
      const v = form.value.credentials?.[f.key];
      if (
        isSchemaFieldRequired(f, form.value.credentials ?? {}, credFields.value) &&
        (v === undefined || v === null || v === "")
      ) {
        ElMessage.warning(`请填写：${f.label}`);
        return;
      }
    }
  }
  const credentials = valuesForVisibleSchema(
    credFields.value,
    form.value.credentials ?? {}
  );
  const filled = Object.values(credentials).some(v => v);
  const payload: any = { name: form.value.name, provider: form.value.provider };
  if (editing.value) {
    if (credentialsDirty.value && filled) payload.credentials = credentials;
    await accessUpdate(editing.value.id, payload);
  } else {
    payload.credentials = credentials;
    await accessStore(payload);
  }
  ElMessage.success("已保存");
  dialog.value = false;
  load();
}
async function remove(row: any) {
  await ElMessageBox.confirm("确认删除该凭证？", "提示");
  await accessDestroy(row.id);
  load();
}

const providerLabel = (key: string) =>
  catalog.value.find(c => c.key === key)?.label ?? key;

onMounted(load);
defineExpose({ openCreate });
</script>

<template>
  <div>
    <el-form :inline="true" :model="q" style="margin-bottom: 8px">
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
      <el-table-column prop="name" label="备注名" />
      <el-table-column label="云厂商">
        <template #default="{ row }">{{
          providerLabel(row.provider)
        }}</template>
      </el-table-column>
      <el-table-column label="创建时间" width="180">
        <template #default="{ row }">
          {{ formatDateTime(row.created_at) }}
        </template>
      </el-table-column>
      <el-table-column label="操作" width="160">
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
      :title="editing ? '编辑凭证' : '新增凭证'"
      width="640px"
    >
      <el-form label-width="120px">
        <el-form-item label="备注名"
          ><el-input v-model="form.name"
        /></el-form-item>
        <el-form-item label="云厂商">
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
        <!-- 据 provider credentialSchema 渲染；secret 字段脱敏 -->
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
