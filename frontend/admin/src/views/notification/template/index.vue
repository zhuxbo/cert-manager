<script setup lang="tsx">
import { onMounted } from "vue";
import { PureTableBar } from "@shared/components";
import { PlusSearch } from "plus-pro-components";
import { useNotificationTemplate } from "./hook";
import { useNotificationTemplateSearch } from "./search";
import { useNotificationTemplateTable } from "./table";
import { useNotificationTemplateStore } from "./store";

defineOptions({
  name: "NotificationTemplate"
});

const { tableRef, tableColumns } = useNotificationTemplateTable();

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
  handleDelete
} = useNotificationTemplate();

const { searchColumns } = useNotificationTemplateSearch();

const {
  formDialogVisible,
  formRef,
  editingId,
  formModel,
  formRules,
  variableInput,
  openCreate,
  openEdit,
  submitForm,
  closeForm
} = useNotificationTemplateStore(() => onSearch());

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
        :show-number="3"
        label-width="80"
        label-position="right"
        label-suffix=""
        :has-footer="false"
        @search="onSearch"
        @reset="onReset"
        @collapse="onCollapse"
      />
    </div>

    <PureTableBar title="通知模板" :columns="tableColumns" @refresh="onSearch">
      <template #buttons>
        <el-button type="primary" @click="openCreate">新增模板</el-button>
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
        >
          <template #operation="{ row }">
            <el-button
              class="reset-margin !outline-none"
              type="primary"
              link
              :size="size"
              @click="openEdit(row)"
            >
              编辑
            </el-button>
            <el-button
              class="reset-margin !outline-none"
              type="danger"
              link
              :size="size"
              @click="handleDelete(row)"
            >
              删除
            </el-button>
          </template>
        </pure-table>
      </template>
    </PureTableBar>

    <!-- 表单对话框 -->
    <el-dialog
      v-model="formDialogVisible"
      :title="editingId ? '编辑模板' : '新增模板'"
      width="680px"
      destroy-on-close
    >
      <el-form
        ref="formRef"
        :model="formModel"
        :rules="formRules"
        label-width="100px"
      >
        <el-form-item label="名称" prop="name">
          <el-input v-model="formModel.name" placeholder="证书签发通知" />
        </el-form-item>
        <el-form-item label="标识" prop="code">
          <el-input
            v-model="formModel.code"
            placeholder="cert_issued"
            :disabled="Boolean(editingId)"
          />
        </el-form-item>
        <el-form-item label="状态" prop="status">
          <el-switch
            v-model="formModel.status"
            :active-value="1"
            :inactive-value="0"
          />
        </el-form-item>
        <el-form-item label="变量" prop="variables">
          <el-select
            v-model="formModel.variables"
            multiple
            allow-create
            filterable
            default-first-option
            placeholder="请输入变量名称，例如 username"
          >
            <el-option
              v-for="item in variableInput"
              :key="item"
              :label="item"
              :value="item"
            />
          </el-select>
        </el-form-item>
        <el-form-item label="内容" prop="content">
          <el-input
            v-model="formModel.content"
            type="textarea"
            :rows="6"
            placeholder="例如：您好 {username}"
          />
        </el-form-item>
        <el-form-item label="示例">
          <el-input
            v-model="formModel.example"
            type="textarea"
            :rows="4"
            placeholder="示例内容"
          />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="closeForm">取消</el-button>
        <el-button type="primary" @click="submitForm">保存</el-button>
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
