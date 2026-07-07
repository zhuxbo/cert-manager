<script setup lang="ts">
import { ref, computed, onMounted } from "vue";
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

const rows = ref<any[]>([]);
const total = ref(0);
const loading = ref(false);
const currentPage = ref(1);
const pageSize = ref(20);
const dialog = ref(false);
const editing = ref<any>(null);
const catalog = ref<ProviderCatalogItem[]>([]);
const form = ref<any>({ name: "", provider: "aliyun", credentials: {} });
const q = ref<any>({
  name: "",
  provider: ""
});

// 据选中 provider 的 credentialSchema 渲染凭证字段（secret 脱敏）
const credFields = computed<CredentialField[]>(() => {
  const p = catalog.value.find(c => c.key === form.value.provider);
  return p?.credentialSchema ?? [];
});

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
  form.value.credentials = {};
}
function openCreate() {
  editing.value = null;
  const first = catalog.value[0]?.key ?? "aliyun";
  form.value = { name: "", provider: first, credentials: {} };
  dialog.value = true;
}
function openEdit(row: any) {
  editing.value = row;
  form.value = { name: row.name, provider: row.provider, credentials: {} }; // 留空=不改
  dialog.value = true;
}
async function submit() {
  if (!form.value.name) {
    ElMessage.warning("请填写备注名");
    return;
  }
  const filled = Object.values(form.value.credentials).some(v => v);
  // 新增：按 schema 校验必填凭证（编辑留空=不改，跳过）
  if (!editing.value) {
    for (const f of credFields.value) {
      const v = form.value.credentials?.[f.key];
      if (f.required && (v === undefined || v === null || v === "")) {
        ElMessage.warning(`请填写：${f.label}`);
        return;
      }
    }
  }
  const payload: any = { name: form.value.name, provider: form.value.provider };
  if (editing.value) {
    if (filled) payload.credentials = form.value.credentials; // 仅在填了才提交
    await accessUpdate(editing.value.id, payload);
  } else {
    payload.credentials = form.value.credentials;
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
          v-for="f in credFields"
          :key="f.key"
          :required="false"
        >
          <template #label>
            <SchemaFieldLabel :field="f" />
          </template>
          <el-input
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
