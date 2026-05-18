<script setup lang="tsx">
import { onMounted, ref } from "vue";
import { PureTableBar } from "@shared/components";
import { PlusSearch } from "plus-pro-components";
import { OrganizationEditor } from "@shared/components/OrganizationEditor";
import ReRemoteSelect from "@shared/components/ReRemoteSelect";
import { countryCodes } from "@/views/system/country";
import { useOrganization } from "./hook";
import { useOrganizationSearch } from "./search";
import { useOrganizationTable } from "./table";

import { useRenderIcon } from "@shared/components/ReIcon/src/hooks";
import CloseBold from "~icons/ep/close-bold";

defineOptions({
  name: "Organization"
});

const {
  tableRef,
  selectedIds,
  handleSelectionChange,
  handleCancelSelection,
  tableColumns,
  handleRowClick
} = useOrganizationTable();

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
} = useOrganization(tableRef);

// 创建搜索列配置
const { searchColumns } = useOrganizationSearch(() => onSearch());

// Editor 状态
const editorVisible = ref(false);
const editingOrgId = ref<number | null>(null);
const editingUserId = ref<number | undefined>(undefined);

// 选用户弹窗状态
const userPickerVisible = ref(false);
const pickedUserId = ref<number | null>(null);

function openEdit(orgId: number, userId: number) {
  editingOrgId.value = orgId;
  editingUserId.value = userId;
  editorVisible.value = true;
}

function openCreate() {
  pickedUserId.value = null;
  userPickerVisible.value = true;
}

function confirmUserPicker() {
  if (!pickedUserId.value) return;
  userPickerVisible.value = false;
  editingOrgId.value = null;
  editingUserId.value = pickedUserId.value;
  editorVisible.value = true;
}

function onEditorSaved() {
  editorVisible.value = false;
  onSearch();
}

onMounted(() => {
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
        :show-number="1"
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
    <PureTableBar title="组织管理" :columns="tableColumns" @refresh="onSearch">
      <template #buttons>
        <el-button type="primary" @click="openCreate">新增组织</el-button>
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
              class="reset-margin !outline-none"
              type="primary"
              link
              :size="size"
              @click="openEdit(row.id, row.user_id)"
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
    <!-- 选择目标用户弹窗（新增时使用） -->
    <el-dialog
      v-model="userPickerVisible"
      title="选择目标用户"
      width="400px"
      :close-on-click-modal="true"
      destroy-on-close
      append-to-body
    >
      <re-remote-select
        v-model="pickedUserId"
        uri="/user"
        search-field="quickSearch"
        label-field="username"
        value-field="id"
        items-field="items"
        total-field="total"
        placeholder="请搜索并选择用户"
        style="width: 100%"
      />
      <template #footer>
        <el-button @click="userPickerVisible = false">取消</el-button>
        <el-button
          type="primary"
          :disabled="!pickedUserId"
          @click="confirmUserPicker"
        >
          下一步
        </el-button>
      </template>
    </el-dialog>

    <!-- 企业 / 联系人 Editor -->
    <organization-editor
      v-model:visible="editorVisible"
      role="admin"
      :organization-id="editingOrgId"
      :user-id="editingUserId"
      :country-options="countryCodes"
      @success="onEditorSaved"
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
