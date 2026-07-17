<script setup lang="tsx">
import { onMounted, ref } from "vue";
import { PureTableBar } from "@shared/components";
import { PlusSearch } from "plus-pro-components";
import { useProductPrice } from "./hook";
import { useProductPriceSearch } from "./search";
import { useProductPriceTable } from "./table";
import Initialization from "./initialization.vue";
import Levels from "./levels.vue";

import { useRenderIcon } from "@shared/components/ReIcon/src/hooks";
import CloseBold from "~icons/ep/close-bold";

defineOptions({
  name: "ProductPrice"
});

const levelsVisible = ref(false);
const initializationVisible = ref(false);

const {
  tableRef,
  selectedIds,
  tableColumns,
  handleSelectionChange,
  handleSelectionCancel,
  handleRowClick
} = useProductPriceTable();

const {
  loading,
  search,
  dataList,
  pagination,
  handleSizeChange,
  handleCurrentChange,
  onSearch,
  handleBatchDestroy
} = useProductPrice(tableRef);

// 创建搜索列配置
const { searchColumns, debouncedSearch } = useProductPriceSearch(() =>
  onSearch()
);

onMounted(() => {
  onSearch();
});

function onFullscreen() {
  // 重置表格高度
  tableRef.value.setAdaptive();
}
</script>

<template>
  <div class="main">
    <Initialization v-model="initializationVisible" @saved="onSearch" />
    <Levels v-model="levelsVisible" @saved="onSearch" />
    <div
      class="search bg-bg_color w-[99/100] pl-4 pr-4 pt-[24px] pb-[12px] overflow-auto"
    >
      <PlusSearch
        v-model="search"
        :columns="searchColumns"
        :show-number="3"
        :row-props="{ gutter: 12 }"
        :col-props="{ xs: 24, sm: 12, md: 8, lg: 6, xl: 4 }"
        label-width="80"
        label-position="right"
        label-suffix=""
        :has-footer="false"
        @change="debouncedSearch"
      />
    </div>
    <PureTableBar
      title="产品价格"
      :columns="tableColumns"
      @refresh="onSearch"
      @fullscreen="onFullscreen"
    >
      <template #buttons>
        <el-button type="warning" @click="initializationVisible = true">
          初始化价格
        </el-button>
        <el-button type="primary" @click="levelsVisible = true">
          设置价格
        </el-button>
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
        />
      </template>
    </PureTableBar>
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
