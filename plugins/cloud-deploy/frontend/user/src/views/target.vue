<script setup lang="ts">
import { ref, computed, watch, onMounted } from "vue";
import { ElMessage, ElMessageBox } from "element-plus";
import {
  targetList,
  targetStore,
  targetDestroy,
  accessList,
  getProviders,
  type ProviderCatalogItem,
  type ProviderProduct,
  type ConfigField
} from "@/api/cloud-deploy";

const rows = ref<any[]>([]);
const accesses = ref<any[]>([]);
const catalog = ref<ProviderCatalogItem[]>([]);
const loading = ref(false);
const dialog = ref(false);
const form = ref<any>({
  access_id: undefined,
  order_id: undefined,
  product: undefined,
  config: {},
  enabled: true
});

// 选中凭证 → 决定 provider（同一 product 在不同 provider 下 schema 可能不同）
const selectedProvider = computed<ProviderCatalogItem | undefined>(() => {
  const acc = accesses.value.find(a => a.id === form.value.access_id);
  if (!acc) return undefined;
  return catalog.value.find(p => p.key === acc.provider);
});
// 该 provider 下可选产品
const products = computed<ProviderProduct[]>(
  () => selectedProvider.value?.products ?? []
);
// 选中产品的 config 字段 schema
const configFields = computed<ConfigField[]>(() => {
  const prod = products.value.find(p => p.product === form.value.product);
  return prod?.configSchema ?? [];
});

// 切换凭证：provider 可能变，清空已选产品与 config
watch(
  () => form.value.access_id,
  () => {
    form.value.product = undefined;
    form.value.config = {};
  }
);
// 切换产品：按新 schema 重置 config（保留同名字段值，避免误清）
watch(
  () => form.value.product,
  () => {
    const next: Record<string, any> = {};
    for (const f of configFields.value)
      next[f.key] = form.value.config?.[f.key] ?? "";
    form.value.config = next;
  }
);

function resourceSummary(row: any): string {
  // 列表「资源」列：优先 domain，其次首个 config 值
  const c = row.config ?? {};
  if (c.domain) return c.domain;
  const first = Object.values(c)[0];
  return first != null ? String(first) : "-";
}

async function load() {
  loading.value = true;
  try {
    rows.value = (await targetList({ pageSize: 100 })).data.items;
  } finally {
    loading.value = false;
  }
}
async function openCreate() {
  // 并行拉凭证 + catalog（catalog 走缓存）
  const [accRes, cat] = await Promise.all([
    accessList({ pageSize: 100 }),
    getProviders()
  ]);
  accesses.value = accRes.data.items;
  catalog.value = cat;
  form.value = {
    access_id: undefined,
    order_id: undefined,
    product: undefined,
    config: {},
    enabled: true
  };
  dialog.value = true;
}
async function submit() {
  // 提交前按 schema 校验必填（与后端 ValidatesAgainstSchema 同口径）
  if (!form.value.access_id) {
    ElMessage.warning("请选择云凭证");
    return;
  }
  if (!form.value.order_id) {
    ElMessage.warning("请填写订单 ID");
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
  await targetStore(form.value);
  ElMessage.success("已添加");
  dialog.value = false;
  load();
}
async function remove(row: any) {
  await ElMessageBox.confirm("确认删除该目标？", "提示");
  await targetDestroy(row.id);
  load();
}
onMounted(load);
</script>

<template>
  <div>
    <el-button type="primary" @click="openCreate">新增目标</el-button>
    <el-table v-loading="loading" :data="rows" style="margin-top: 12px">
      <el-table-column prop="order_id" label="订单" width="120" />
      <el-table-column prop="product" label="产品" width="100" />
      <el-table-column label="资源">
        <template #default="{ row }">{{ resourceSummary(row) }}</template>
      </el-table-column>
      <el-table-column label="状态" width="120">
        <template #default="{ row }">
          <el-tag v-if="row.last_status === 'success'" type="success"
            >成功</el-tag
          >
          <el-tag v-else-if="row.last_status === 'failed'" type="danger"
            >失败</el-tag
          >
          <el-tag v-else type="info">未推送</el-tag>
        </template>
      </el-table-column>
      <el-table-column label="启用" width="80">
        <template #default="{ row }"
          ><el-tag :type="row.enabled ? '' : 'info'">{{
            row.enabled ? "是" : "否"
          }}</el-tag></template
        >
      </el-table-column>
      <el-table-column label="操作" width="100">
        <template #default="{ row }"
          ><el-button link type="danger" @click="remove(row)"
            >删除</el-button
          ></template
        >
      </el-table-column>
    </el-table>

    <el-dialog v-model="dialog" title="新增部署目标" width="480px">
      <el-form label-width="120px">
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
        <el-form-item label="订单ID" label-width="120px">
          <el-input
            v-model.number="form.order_id"
            placeholder="要部署的证书订单ID"
          />
          <el-text
            type="info"
            size="small"
            style="margin-top: 4px; display: block"
          >
            部署目标锚定该订单；续费/重签自动跟随，另开新订单换证书需重新配置目标
          </el-text>
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
        <!-- 据选中产品 configSchema 动态渲染字段 -->
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
        <el-form-item label="启用自动推送"
          ><el-switch v-model="form.enabled"
        /></el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="dialog = false">取消</el-button>
        <el-button type="primary" @click="submit">保存</el-button>
      </template>
    </el-dialog>
  </div>
</template>
