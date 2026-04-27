<script setup lang="tsx">
import { onMounted, ref } from "vue";
import { PureTableBar } from "@shared/components";
import { PlusSearch } from "plus-pro-components";
import { useProduct } from "./hook";
import { useProductSearch } from "./search";
import { useProductTable } from "./table";
import ProductExport from "./export.vue";
import { IconifyIconOffline } from "@shared/components/ReIcon";
import { getPluginWidgets } from "@shared/utils/plugin-loader";

const productTopWidgets = getPluginWidgets("user-product-top");

defineOptions({
  name: "Product"
});

const { tableRef, tableColumns, expandedRows } = useProductTable();

const handleRowClick = (row: any, _column: any, event: any) => {
  const target = event.target as HTMLElement;
  if (
    target.tagName === "BUTTON" ||
    target.closest("button") ||
    target.closest(".el-button")
  ) {
    return;
  }
  if (expandedRows.has(row.id)) {
    expandedRows.delete(row.id);
  } else {
    expandedRows.add(row.id);
  }
};

const {
  loading,
  search,
  dataList,
  pagination,
  isSinglePage,
  handleSizeChange,
  handleCurrentChange,
  onSearch,
  handleApply,
  handleBatchApply
} = useProduct(tableRef);

// 创建搜索列配置
const { searchColumns, debouncedSearch } = useProductSearch(() => onSearch());

// 导出相关
const showExport = ref(false);
const openExportForm = () => {
  showExport.value = true;
};

onMounted(() => {
  onSearch();
});
</script>

<template>
  <div class="main">
    <component
      :is="w.component"
      v-for="w in productTopWidgets"
      :key="w.name"
      class="mb-3"
    />
    <div
      class="search bg-bg_color w-[99/100] pl-4 pr-4 pt-[24px] pb-[12px] overflow-auto"
    >
      <PlusSearch
        v-model="search"
        :columns="searchColumns"
        :show-number="6"
        :col-props="{ xs: 24, sm: 24, md: 24, lg: 24, xl: 24 }"
        label-width="80"
        label-position="right"
        label-suffix=""
        :has-footer="false"
        @change="debouncedSearch"
      />
    </div>
    <PureTableBar title="产品列表" :columns="tableColumns" @refresh="onSearch">
      <template #buttons>
        <IconifyIconOffline
          icon="ri/download-2-line"
          style="cursor: pointer"
          @click="openExportForm"
        />
        <ElDivider direction="vertical" style="margin-right: -8px" />
      </template>
      <template v-slot="{ size, dynamicColumns }">
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
          @page-size-change="handleSizeChange"
          @page-current-change="handleCurrentChange"
          @row-click="handleRowClick"
        >
          <template #operation="{ row }">
            <el-button
              class="reset-margin !outline-none"
              type="primary"
              link
              :size="size"
              @click="handleApply(row)"
            >
              申请
            </el-button>
            <el-button
              v-if="
                row.product_type !== 'acme' &&
                !row.alternative_name_types.length
              "
              class="reset-margin !outline-none"
              type="primary"
              link
              :size="size"
              @click="handleBatchApply(row)"
            >
              批量
            </el-button>
          </template>
        </pure-table>
      </template>
    </PureTableBar>

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
