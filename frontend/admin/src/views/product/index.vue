<script setup lang="tsx">
import { onMounted, ref } from "vue";
import { PureTableBar } from "@shared/components";
import { PlusSearch, PlusDrawerForm } from "plus-pro-components";
import { useDrawerSize } from "@/views/system/drawer";
import { useProduct } from "./hook";
import { useProductSearch } from "./search";
import { useProductStore } from "./store";
import { useProductTable } from "./table";
import { useProductSources } from "./sources";
import ProductImport from "./import.vue";
import ProductExport from "./export.vue";
import CostDialog from "./cost.vue";
import Levels from "@/views/productPrice/levels.vue";

import { useRenderIcon } from "@shared/components/ReIcon/src/hooks";
import CloseBold from "~icons/ep/close-bold";

defineOptions({
  name: "Product"
});

// 使用统一的响应式抽屉宽度
const { drawerSize } = useDrawerSize();

// 来源列表相关
const { sourcesList, getSourcesList } = useProductSources();

const {
  tableRef,
  selectedIds,
  handleSelectionChange,
  handleSelectionCancel,
  tableColumns,
  handleRowClick
} = useProductTable(sourcesList);

const {
  loading,
  search,
  dataList,
  pagination,
  isSinglePage,
  handleSizeChange,
  handleCurrentChange,
  onSearch,
  handleDestroy,
  handleBatchDestroy,
  handleBatchDelegation
} = useProduct(tableRef);

// 创建搜索列配置
const { searchColumns, debouncedSearch } = useProductSearch(() => onSearch());

// 创建表单列配置
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
} = useProductStore(() => onSearch(), sourcesList);

// 产品导入相关
const showImport = ref(false);
const openImportForm = () => {
  showImport.value = true;
};

const showCost = ref(false);
const currentId = ref(0);

const handleCost = (row: any) => {
  currentId.value = row.id;
  showCost.value = true;
};

// 价格设置弹窗
const showLevels = ref(false);
const priceProductId = ref<number | null>(null);
const openLevels = (row: any) => {
  priceProductId.value = row?.id ?? null;
  showLevels.value = true;
};

// 导出相关
const showExport = ref(false);
const openExportForm = () => {
  showExport.value = true;
};

// 判断成本是否为空或对象/数组中所有“叶子值”均为 0（或空）
const isEmpty = (cost: any) => {
  const isZeroLike = (val: any): boolean => {
    if (val == null) return true;
    if (typeof val === "number") return val === 0;
    if (typeof val === "string") {
      const s = val.trim();
      if (s === "") return true;
      const n = Number(s);
      return !Number.isNaN(n) && n === 0;
    }
    if (Array.isArray(val)) {
      if (val.length === 0) return true;
      return val.every(item => isZeroLike(item));
    }
    if (typeof val === "object") {
      const entries = Object.values(val ?? {});
      if (entries.length === 0) return true;
      return entries.every(item => isZeroLike(item));
    }
    return false;
  };

  return isZeroLike(cost);
};

onMounted(() => {
  getSourcesList();
  onSearch();
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
        :show-number="7"
        :col-props="{ xs: 24, sm: 24, md: 24, lg: 24, xl: 24 }"
        label-width="80"
        label-position="right"
        label-suffix=""
        :has-footer="false"
        @change="debouncedSearch"
      />
    </div>
    <PureTableBar title="产品管理" :columns="tableColumns" @refresh="onSearch">
      <template #buttons>
        <el-button type="primary" @click="openImportForm">导入产品</el-button>
        <el-button type="primary" @click="openStoreForm()">新增产品</el-button>
        <el-button type="success" @click="openExportForm">导出价格</el-button>
      </template>
      <template v-slot="{ size, dynamicColumns }">
        <div
          v-if="selectedIds.length > 0"
          v-motion-fade
          class="bg-[var(--el-fill-color-light)] w-full h-[46px] mb-2 pl-3 pr-2 flex items-center"
        >
          <div class="flex-auto">
            <el-tooltip placement="top" content="取消选择">
              <el-button
                type="primary"
                size="small"
                class="!w-[15px] !p-0 !h-[15px] !rounded-[3px]"
                :icon="useRenderIcon(CloseBold)"
                @click="handleSelectionCancel"
              />
            </el-tooltip>
            <span
              style="font-size: var(--el-font-size-base)"
              class="text-[rgba(42,46,54,0.5)] dark:text-[rgba(220,220,242,0.5)] ml-2"
            >
              已选 {{ selectedIds.length }} 项
            </span>
          </div>
          <el-popconfirm
            title="确定为所选产品开启委托验证吗？"
            width="220px"
            @confirm="handleBatchDelegation(selectedIds, true)"
          >
            <template #reference>
              <el-button type="success" size="small">开启委托</el-button>
            </template>
          </el-popconfirm>
          <el-popconfirm
            title="确定为所选产品关闭委托验证吗？"
            width="220px"
            @confirm="handleBatchDelegation(selectedIds, false)"
          >
            <template #reference>
              <el-button type="warning" size="small">关闭委托</el-button>
            </template>
          </el-popconfirm>
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
          :adaptiveConfig="{ offsetBottom: isSinglePage ? 44 : 108 }"
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
              class="reset-margin !outline-none"
              type="primary"
              link
              :size="size"
              @click="openStoreForm(row.id)"
            >
              编辑
            </el-button>
            <el-button
              class="reset-margin !outline-none"
              :type="isEmpty(row.cost) ? 'danger' : 'primary'"
              link
              :size="size"
              @click="handleCost(row)"
            >
              成本
            </el-button>
            <el-button
              class="reset-margin !outline-none"
              :type="isEmpty(row.prices) ? 'danger' : 'primary'"
              link
              :size="size"
              @click="openLevels(row)"
            >
              价格
            </el-button>
            <el-popconfirm
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
        labelSuffix: '',
        labelWidth: '150px'
      }"
      :size="drawerSize"
      :closeOnClickModal="true"
      :title="storeId > 0 ? '编辑产品' : '新增产品'"
      confirmText="提交"
      cancelText="取消"
      @confirm="confirmStoreForm"
      @cancel="closeStoreForm"
    />

    <!-- 导入产品组件 -->
    <ProductImport
      v-model:visible="showImport"
      :sources-list="sourcesList"
      @success="onSearch"
    />

    <!-- 成本弹窗 -->
    <cost-dialog
      :id="currentId"
      :visible="showCost"
      @update:visible="showCost = $event"
      @success="onSearch"
    />

    <!-- 价格设置弹窗 -->
    <Levels
      v-model="showLevels"
      :product-id="priceProductId"
      @saved="onSearch"
    />

    <!-- 导出弹窗 -->
    <ProductExport v-model:visible="showExport" />
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
