<script setup lang="tsx">
import {
  ref,
  onMounted,
  onActivated,
  onDeactivated,
  onBeforeUnmount
} from "vue";
import { useRoute } from "vue-router";
import { PureTableBar } from "@shared/components";
import { PlusSearch } from "plus-pro-components";
import { useAcme } from "./hook";
import { useAcmeSearch } from "./search";
import { useAcmeTable } from "./table";
import AcmeButtons from "./buttons.vue";
import AcmeBatch from "./batch.vue";
import AcmeCreate from "./create.vue";
import { useRenderIcon } from "@shared/components/ReIcon/src/hooks";
import CloseBold from "~icons/ep/close-bold";

defineOptions({
  name: "Acme"
});

const route = useRoute();

const {
  tableRef,
  tableColumns,
  selectedIds,
  selectedRows,
  handleSelectionChange,
  handleCancelSelection,
  handleRowClick
} = useAcmeTable();

const {
  loading,
  search,
  dataList,
  pagination,
  handleSizeChange,
  handleCurrentChange,
  onSearch,
  onReset,
  onCollapse
} = useAcme(tableRef);

const { searchColumns } = useAcmeSearch(onSearch, search);

const createVisible = ref(false);
const presetProductId = ref(0);
// 记录已弹过窗的 product_id，避免切换 tab 回来重复弹出；同 id 再点产品列表申请需手动重开
const lastHandledPid = ref(0);

type TimerRef = ReturnType<typeof setInterval>;
let searchTimer: TimerRef | null = null;

// keep-alive 下 onMounted 只触发一次，产品列表跳入需在 onActivated 兜底
const autoOpenFromQuery = () => {
  const pid = Number(route.query.product_id);
  if (pid > 0 && pid !== lastHandledPid.value) {
    lastHandledPid.value = pid;
    presetProductId.value = pid;
    createVisible.value = true;
  }
};

// 启动 3 分钟轮询：幂等，重复调用不会产生多个定时器
const startPolling = () => {
  if (searchTimer !== null) return;
  searchTimer = setInterval(
    () => {
      // 页面被切到后台标签页时跳过本次刷新，回到前台再恢复
      if (document.hidden) return;
      // 用户已勾选批量操作目标行时跳过本次自动刷新，避免清空选择
      if (selectedIds.value.length > 0) return;
      onSearch();
    },
    3 * 60 * 1000
  );
};

const stopPolling = () => {
  if (searchTimer !== null) {
    clearInterval(searchTimer);
    searchTimer = null;
  }
};

// 标签页重新可见时立即刷新一次（勾选中则跳过），避免等待整个轮询周期
const handleVisibilityChange = () => {
  if (document.hidden) return;
  if (selectedIds.value.length > 0) return;
  onSearch();
};

onMounted(() => {
  // 支持从交易流水等页面通过 ?id= 跳转定位到具体 ACME 订阅
  const queryId = Number(route.query.id);
  if (queryId > 0) {
    search.value.id = queryId;
  }
  onSearch();
  autoOpenFromQuery();
  document.addEventListener("visibilitychange", handleVisibilityChange);
  startPolling();
});

// keepAlive 缓存下离开页面不会触发卸载，需在 deactivated 暂停轮询，
// 避免多个列表页同时在后台并发刷新
onActivated(() => {
  autoOpenFromQuery();
  document.addEventListener("visibilitychange", handleVisibilityChange);
  startPolling();
});

onDeactivated(() => {
  document.removeEventListener("visibilitychange", handleVisibilityChange);
  stopPolling();
});

onBeforeUnmount(() => {
  document.removeEventListener("visibilitychange", handleVisibilityChange);
  stopPolling();
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
    <PureTableBar title="ACME订阅" :columns="tableColumns" @refresh="onSearch">
      <template #buttons>
        <el-button
          type="primary"
          @click="
            () => {
              presetProductId = 0;
              createVisible = true;
            }
          "
          >创建订阅</el-button
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
          <AcmeBatch
            :selected-rows="selectedRows"
            :table-ref="tableRef?.getTableRef?.()"
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
          @page-size-change="handleSizeChange"
          @page-current-change="handleCurrentChange"
          @row-click="handleRowClick"
          @selection-change="handleSelectionChange"
        >
          <template #operation="{ row, size }">
            <AcmeButtons :row="row" :size="size" @refresh="onSearch" />
          </template>
        </pure-table>
      </template>
    </PureTableBar>

    <AcmeCreate
      v-model:visible="createVisible"
      :product-id="presetProductId"
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
