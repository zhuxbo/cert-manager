<script setup lang="tsx">
import { onMounted } from "vue";
import { usePolling } from "@shared/hooks/usePolling";
import { PureTableBar } from "@shared/components";
import { PlusSearch } from "plus-pro-components";
import { useOrder } from "./hook";
import { useOrderSearch } from "./search";
import { useOrderTable } from "./table";
import { useOrderAction } from "./action";
import OrderAction from "./action.vue";
import OrderButtons from "./buttons.vue";
import OrderBatch from "./batch.vue";
import router from "@/router";
import { useRenderIcon } from "@shared/components/ReIcon/src/hooks";
import { getPluginWidgets } from "@shared/utils/plugin-loader";
import CloseBold from "~icons/ep/close-bold";

const orderTopWidgets = getPluginWidgets("user-order-top");

defineOptions({
  name: "Order"
});

const {
  tableRef,
  selectedIds,
  selectedRows,
  tableColumns,
  handleSelectionChange,
  handleCancelSelection,
  handleRowClick
} = useOrderTable();

const {
  loading,
  search,
  dataList,
  pagination,
  handleSizeChange,
  handleCurrentChange,
  handleSortChange,
  onSearch,
  onReset,
  onCollapse
} = useOrder(tableRef);

const { action, openAction } = useOrderAction();

// 创建搜索列配置
const { searchColumns } = useOrderSearch(onSearch, search);

// 3 分钟轮询 + 切后台跳过 + 切回前台立即刷新 + keep-alive 暂停/恢复 + 卸载清理：
// 已勾选批量操作目标行时跳过本次自动刷新（含轮询与切回前台），避免清空选择。
usePolling(onSearch, {
  shouldSkip: () => selectedIds.value.length > 0,
  keepAlive: true
});

onMounted(() => {
  // 检查是否有查询参数
  const query = router.currentRoute.value.query;

  if (query.id) {
    search.value.id = Number(query.id);
  }
  if (query.status) {
    search.value.status = query.status as string;
  }
  if (query.statusSet) {
    search.value.statusSet = query.statusSet as string;
  }
  if (query.domain) {
    search.value.domain = query.domain as string;
  }
  if (query.product_name) {
    search.value.product_name = query.product_name as string;
  }
  if (query.channel) {
    search.value.channel = query.channel as string;
  }
  if (query.action) {
    search.value.action = query.action as string;
  }

  // 处理日期范围参数
  if (query.expires_at && Array.isArray(query.expires_at)) {
    search.value.expires_at = query.expires_at as [string, string];
  }
  if (query.created_at && Array.isArray(query.created_at)) {
    search.value.created_at = query.created_at as [string, string];
  }

  // 处理金额范围参数
  if (query.amount && Array.isArray(query.amount)) {
    search.value.amount = query.amount.map(Number) as [number, number];
  }

  // 处理周期参数
  if (query.period) {
    search.value.period = Number(query.period);
  }

  onSearch();

  const { product_id, type } = query;
  if (product_id && type) {
    openAction(type as string);
  }
});
</script>

<template>
  <div class="main">
    <component
      :is="w.component"
      v-for="w in orderTopWidgets"
      :key="w.name"
      class="mb-3"
    />
    <div
      class="search bg-bg_color w-[99/100] pl-4 pr-4 pt-6 pb-3 overflow-auto"
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
        search-text="搜索"
        reset-text="重置"
        expand-text="展开"
        retract-text="收起"
        @search="onSearch"
        @reset="onReset"
        @collapse="onCollapse"
      />
    </div>
    <PureTableBar title="订单管理" :columns="tableColumns" @refresh="onSearch">
      <template #buttons>
        <el-button type="primary" @click="openAction('apply')"
          >申请证书</el-button
        >
        <el-button type="success" @click="openAction('batchApply')"
          >批量申请</el-button
        >
      </template>
      <template v-slot="{ size, dynamicColumns }">
        <div
          v-if="selectedIds.length > 0"
          v-motion-fade
          class="bg-(--el-fill-color-light) w-full h-[46px] mb-2 pl-3 pr-2 flex items-center"
        >
          <div class="flex-auto">
            <el-tooltip placement="top" content="取消选择">
              <el-button
                type="primary"
                size="small"
                class="w-[15px]! p-0! h-[15px]! rounded-[3px]!"
                :icon="useRenderIcon(CloseBold)"
                @click="handleCancelSelection"
              />
            </el-tooltip>
            <span
              style="font-size: var(--el-font-size-base)"
              class="text-[rgba(42,46,54,0.5)] dark:text-[rgba(220,220,242,0.5)] ml-2"
            >
              已选 {{ selectedIds.length }} 项
            </span>
          </div>
          <OrderBatch
            :selectedRows="selectedRows"
            :tableRef="tableRef.getTableRef()"
            @refresh="onSearch"
          />
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
          @sort-change="handleSortChange"
          @page-size-change="handleSizeChange"
          @page-current-change="handleCurrentChange"
        >
          <template #operation="{ row, size }">
            <OrderButtons :row="row" :size="size" @refresh="onSearch" />
          </template>
        </pure-table>
      </template>
    </PureTableBar>

    <!-- 订单操作抽屉 -->
    <OrderAction
      v-model:visible="action.visible"
      :actionType="action.type"
      :orderId="action.id"
      @success="onSearch"
    />
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
