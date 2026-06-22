<script setup lang="tsx">
import { onMounted } from "vue";
import { useRoute } from "vue-router";
import { PureTableBar } from "@shared/components";
import { PlusSearch, PlusDrawerForm } from "plus-pro-components";
import { useUser } from "./hook";
import { useUserSearch } from "./search";
import { useUserStore } from "./store";
import { useUserTable } from "./table";

import { useRenderIcon } from "@shared/components/ReIcon/src/hooks";
import { useDrawerSize } from "@/views/system/drawer";
import CloseBold from "~icons/ep/close-bold";

defineOptions({
  name: "User"
});

// 使用统一的响应式抽屉宽度
const { drawerSize } = useDrawerSize();

const route = useRoute();

const {
  tableRef,
  selectedIds,
  handleSelectionChange,
  handleSelectionCancel,
  tableColumns,
  handleRowClick
} = useUserTable();

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
  handleBatchDestroy,
  handleDirectLogin,
  handleSortChange
} = useUser(tableRef);

// 创建搜索列配置
const { searchColumns } = useUserSearch(() => onSearch());

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
} = useUserStore(() => onSearch());

// 检查是否有查询参数
onMounted(() => {
  if (route.query.username) {
    search.value.username = route.query.username as string;
  }
  if (route.query.balance) {
    const parts = (route.query.balance as string).split(",");
    search.value.balance = [parts[0] || null, parts[1] || null];
  }
  if (route.query.credit_limit !== undefined) {
    search.value.credit_limit = Number(route.query.credit_limit);
  }
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
    <PureTableBar title="用户管理" :columns="tableColumns" @refresh="onSearch">
      <template #buttons>
        <el-button type="primary" @click="openStoreForm()">新增用户</el-button>
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
          @sort-change="handleSortChange"
          @page-size-change="handleSizeChange"
          @page-current-change="handleCurrentChange"
        >
          <template #operation="{ row }">
            <el-button
              class="reset-margin !outline-none"
              type="success"
              link
              :size="size"
              @click="handleDirectLogin(row.id)"
            >
              登录
            </el-button>
            <el-button
              class="reset-margin !outline-none"
              type="primary"
              link
              :size="size"
              @click="openStoreForm(row.id)"
            >
              编辑
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
        labelSuffix: ''
      }"
      :size="drawerSize"
      :closeOnClickModal="true"
      :title="storeId > 0 ? '编辑用户' : '新增用户'"
      confirmText="提交"
      cancelText="取消"
      @confirm="confirmStoreForm"
      @cancel="closeStoreForm"
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
