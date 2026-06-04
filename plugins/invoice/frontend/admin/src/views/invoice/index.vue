<script setup lang="tsx">
import {
  onMounted,
  onActivated,
  nextTick,
  getCurrentInstance,
  ref
} from "vue";
import { ElMessage, ElMessageBox } from "element-plus";
import { PlusSearch, PlusDrawerForm } from "plus-pro-components";
import { useInvoice } from "./hook";
import { useInvoiceSearch } from "./search";
import { useInvoiceStore } from "./store";
import { useInvoiceTable } from "./table";
import { useDrawerSize } from "../../shared/utils";
import {
  getExternalConfig,
  updateExternalConfig,
  regenerateExternalToken
} from "../../api/invoice";

defineOptions({
  name: "Invoice"
});

const { drawerSize } = useDrawerSize();
// IIFE 插件不能用 useRoute()（Symbol 注入不匹配），通过实例获取
const instance = getCurrentInstance();
const route = instance?.appContext.config.globalProperties.$route;

const {
  tableRef,
  selectedIds,
  handleSelectionChange,
  handleSelectionCancel,
  tableColumns,
  handleRowClick
} = useInvoiceTable();

const {
  loading,
  search,
  dataList,
  pagination,
  handleSizeChange,
  handleCurrentChange,
  onSearch,
  onReset,
  onCollapse,
  handleDestroy,
  handleBatchDestroy
} = useInvoice(tableRef);

const { searchColumns } = useInvoiceSearch(() => onSearch());

const {
  storeRef,
  showStore,
  storeId,
  storeColumns,
  rules,
  storeValues,
  openStoreForm,
  confirmStoreForm,
  closeStoreForm
} = useInvoiceStore(() => onSearch());

const triggerResize = () => {
  nextTick(() => window.dispatchEvent(new Event("resize")));
};

const externalDialog = ref(false);
const external = ref({ has_token: false, token_masked: "", allowed_ips: "" });

async function loadExternal() {
  const { data } = await getExternalConfig();
  external.value = data;
}

async function openExternal() {
  await loadExternal();
  externalDialog.value = true;
}

async function onSaveIps() {
  await updateExternalConfig({ allowed_ips: external.value.allowed_ips });
  ElMessage.success("已保存");
}

async function onRegenerate() {
  await ElMessageBox.confirm(
    "重置后旧 Token 立即失效，且新 Token 仅本次显示一次，是否继续？",
    "重置 Token",
    { type: "warning" }
  );
  const { data } = await regenerateExternalToken();
  await ElMessageBox.alert(
    `新 Token（请妥善保管，关闭此窗后将无法再次查看）：\n\n${data.token}`,
    "新 Token",
    { customClass: "select-text" }
  );
  await loadExternal();
}

onMounted(() => {
  if (route?.query?.id) {
    search.value.id = Number(route.query.id);
  }
  onSearch();
  triggerResize();
});

onActivated(() => {
  triggerResize();
});
</script>

<template>
  <div class="main">
    <div
      class="search bg-bg_color w-[99/100] pl-4 pr-4 pt-[24px] pb-[12px] overflow-auto"
    >
      <PlusSearch
        v-model="search"
        :columns="searchColumns"
        :show-number="2"
        :row-props="{ gutter: 12 }"
        :col-props="{ xs: 24, sm: 12, md: 8, lg: 6, xl: 4 }"
        label-width="80"
        label-position="right"
        label-suffix=""
        search-text="搜索"
        reset-text="重置"
        expand-text="展开"
        retract-text="收起"
        @search="onSearch"
        @reset="onReset"
        @collapse="onCollapse"
      />
    </div>
    <PureTableBar title="发票管理" :columns="tableColumns" @refresh="onSearch">
      <template #buttons>
        <el-button type="primary" @click="openStoreForm()">新增发票</el-button>
        <el-button @click="openExternal">外部接入</el-button>
      </template>
      <template v-slot="{ size, dynamicColumns }">
        <div
          v-if="selectedIds.length > 0"
          v-motion-fade
          class="bg-[var(--el-fill-color-light)] w-full h-[46px] mb-2 pl-3 pr-2 flex items-center"
        >
          <div class="flex-auto">
            <el-button
              type="primary"
              size="small"
              class="!w-[15px] !p-0 !h-[15px] !rounded-[3px]"
              @click="handleSelectionCancel"
            >
              ✕
            </el-button>
            <span
              style="font-size: var(--el-font-size-base)"
              class="text-[rgba(42,46,54,0.5)] dark:text-[rgba(220,220,242,0.5)] ml-2"
            >
              已选 {{ selectedIds.length }} 项
            </span>
          </div>
          <el-popconfirm
            title="确定要删除吗？"
            width="160px"
            @confirm="handleBatchDestroy(selectedIds)"
          >
            <template #reference>
              <el-button type="danger" size="small"> 批量删除 </el-button>
            </template>
          </el-popconfirm>
        </div>
        <pure-table
          ref="tableRef"
          row-key="id"
          align-whole="left"
          table-layout="auto"
          :loading="loading"
          :size="size"
          adaptive
          :adaptiveConfig="{ offsetBottom: 108 }"
          :data="dataList"
          :columns="dynamicColumns"
          :pagination="{ ...pagination, size }"
          :header-cell-style="{
            background: 'var(--el-fill-color-light)',
            color: 'var(--el-text-color-primary)'
          }"
          @row-click="handleRowClick"
          @selection-change="handleSelectionChange"
          @page-size-change="handleSizeChange"
          @page-current-change="handleCurrentChange"
        >
          <template #operation="{ row }">
            <el-button
              v-if="row.status !== 2"
              class="reset-margin !outline-none"
              type="primary"
              link
              :size="size"
              @click="openStoreForm(row.id)"
            >
              编辑
            </el-button>
            <el-popconfirm
              v-if="row.status === 0"
              title="确定要删除吗？"
              width="160px"
              @confirm="handleDestroy(row.id)"
            >
              <template #reference>
                <el-button
                  class="reset-margin !outline-none"
                  link
                  type="danger"
                  :size="size"
                >
                  删除
                </el-button>
              </template>
            </el-popconfirm>
          </template>
        </pure-table>
      </template>
    </PureTableBar>
    <PlusDrawerForm
      ref="storeRef"
      v-model="storeValues"
      :visible="showStore"
      :form="{
        columns: storeColumns,
        rules,
        labelPosition: 'right',
        labelSuffix: ''
      }"
      :size="drawerSize"
      :closeOnClickModal="true"
      :title="storeId > 0 ? '编辑发票' : '新增发票'"
      confirmText="提交"
      cancelText="取消"
      @confirm="confirmStoreForm"
      @cancel="closeStoreForm"
    />
    <el-dialog
      v-model="externalDialog"
      title="外部接入设置"
      width="560px"
      :close-on-click-modal="false"
    >
      <el-form label-width="90" label-position="right" label-suffix="">
        <el-form-item label="Token">
          <div class="flex items-center gap-2 w-full">
            <el-input
              v-model="external.token_masked"
              readonly
              :placeholder="external.has_token ? '已设置' : '未设置'"
            />
            <el-button type="primary" @click="onRegenerate">
              重置 Token
            </el-button>
          </div>
        </el-form-item>
        <el-form-item label="IP 白名单">
          <div class="flex items-center gap-2 w-full">
            <el-input
              v-model="external.allowed_ips"
              placeholder="逗号分隔，空 = 不限制"
            />
            <el-button type="primary" @click="onSaveIps">保存</el-button>
          </div>
        </el-form-item>
        <el-form-item label="接入端点">
          <div class="text-xs text-gray-500 leading-6">
            <div>GET <code>/api/invoice/external/pending</code></div>
            <div>POST <code>/api/invoice/external/complete/&#123;id&#125;</code></div>
            <div>鉴权：Authorization: Bearer &lt;token&gt;</div>
          </div>
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="externalDialog = false">关闭</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<style scoped lang="scss">
:deep(.el-dropdown-menu__item i) {
  margin: 0;
}

.main-content {
  margin: 24px 24px 0 !important;
}

.search {
  :deep(.el-form-item) {
    margin-bottom: 12px;
  }
}
</style>
