<script setup lang="tsx">
import { onMounted } from "vue";
import { PureTableBar } from "@shared/components";
import { PlusSearch } from "plus-pro-components";
import { useNotificationRecord } from "./hook";
import { useNotificationRecordSearch } from "./search";
import { useNotificationRecordTable } from "./table";
import { useNotificationRecordStore, notifiableOptions } from "./store";
import { ReRemoteSelect } from "@shared/components/ReRemoteSelect";

defineOptions({
  name: "NotificationRecords"
});

const { tableRef, tableColumns } = useNotificationRecordTable();

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
  detailDialogVisible,
  detailRecord,
  openDetail,
  showPayload,
  resendDialogVisible,
  openResend,
  confirmResend,
  closeResend
} = useNotificationRecord();

const { searchColumns } = useNotificationRecordSearch(() => onSearch());

const {
  testDialogVisible,
  templateOptions,
  templateLoading,
  testForm,
  testPayload,
  currentNotifiableOption,
  templateVariables,
  openTestDialog,
  confirmTestSend,
  closeTestDialog,
  isMultilineField
} = useNotificationRecordStore(() => onSearch());

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
        :show-number="4"
        label-width="90"
        label-position="right"
        label-suffix=""
        :has-footer="false"
        @search="onSearch"
        @reset="onReset"
        @collapse="onCollapse"
      />
    </div>

    <PureTableBar title="通知记录" :columns="tableColumns" @refresh="onSearch">
      <template #buttons>
        <el-button type="primary" @click="openTestDialog">测试通知</el-button>
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
              @click="openDetail(row)"
            >
              查看详情
            </el-button>
            <el-button
              class="reset-margin !outline-none"
              type="success"
              link
              :size="size"
              @click="openResend(row)"
            >
              重发
            </el-button>
          </template>
        </pure-table>
      </template>
    </PureTableBar>

    <!-- 详情对话框 -->
    <el-dialog
      v-model="detailDialogVisible"
      width="640px"
      title="通知详情"
      destroy-on-close
    >
      <div v-if="detailRecord" class="detail-block">
        <p><strong>ID：</strong>{{ detailRecord.id }}</p>
        <p>
          <strong>模板：</strong>{{ detailRecord.template?.name }} ({{
            detailRecord.template?.code
          }})
        </p>
        <p><strong>用户：</strong>{{ detailRecord.notifiable?.username }}</p>
        <p v-if="detailRecord.sent_at">
          <strong>发送时间：</strong>{{ detailRecord.sent_at }}
        </p>
        <div>
          <strong>数据：</strong>
          <pre>{{ showPayload(detailRecord.data) }}</pre>
        </div>
      </div>
    </el-dialog>

    <!-- 重发确认对话框 -->
    <el-dialog
      v-model="resendDialogVisible"
      title="确认重发"
      width="420px"
      destroy-on-close
    >
      <p>确认要重新发送该通知吗？</p>
      <template #footer>
        <el-button @click="closeResend">取消</el-button>
        <el-button type="primary" @click="confirmResend">确定</el-button>
      </template>
    </el-dialog>

    <!-- 测试通知对话框 -->
    <el-dialog
      v-model="testDialogVisible"
      title="测试发送通知"
      width="640px"
      destroy-on-close
    >
      <el-form label-width="110px">
        <el-form-item label="通知对象">
          <div class="flex flex-col gap-2 w-full">
            <el-select
              v-model="testForm.notifiable_type"
              placeholder="请选择对象类型"
            >
              <el-option
                v-for="item in notifiableOptions"
                :key="item.value"
                :label="item.label"
                :value="item.value"
              />
            </el-select>
            <ReRemoteSelect
              v-if="currentNotifiableOption"
              :key="testForm.notifiable_type"
              v-model="testForm.notifiable_id"
              :uri="currentNotifiableOption.remote.uri"
              :searchField="currentNotifiableOption.remote.searchField"
              :labelField="currentNotifiableOption.remote.labelField"
              :valueField="currentNotifiableOption.remote.valueField"
              :itemsField="currentNotifiableOption.remote.itemsField"
              :totalField="currentNotifiableOption.remote.totalField"
              clearable
              placeholder="请选择通知接收者"
            />
          </div>
        </el-form-item>
        <el-form-item label="模板">
          <el-select
            v-model="testForm.template_type"
            filterable
            placeholder="请选择模板"
            :loading="templateLoading"
          >
            <el-option
              v-for="item in templateOptions"
              :key="item.id"
              :label="item.name"
              :value="item.code"
            >
              <div class="flex flex-col">
                <span>{{ item.name }}</span>
                <small class="text-muted">{{ item.code }}</small>
              </div>
            </el-option>
          </el-select>
        </el-form-item>
        <el-divider content-position="left">模板变量</el-divider>
        <el-alert
          v-if="!templateVariables.length"
          type="info"
          :closable="false"
          description="请选择模板后填写所需的变量。未填写的变量将在发送时留空。"
          class="mb-4"
        />
        <div v-else class="flex flex-col gap-2">
          <el-form-item
            v-for="field in templateVariables"
            :key="field"
            :label="field"
          >
            <el-input
              v-if="!isMultilineField(field)"
              v-model="testPayload[field]"
              clearable
              :placeholder="`请输入 ${field}`"
            />
            <el-input
              v-else
              v-model="testPayload[field]"
              type="textarea"
              :rows="3"
              :placeholder="`请输入 ${field}`"
            />
          </el-form-item>
        </div>
      </el-form>
      <template #footer>
        <el-button @click="closeTestDialog">取消</el-button>
        <el-button type="primary" @click="confirmTestSend">提交</el-button>
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

.detail-block pre {
  padding: 12px;
  overflow: auto;
  font-size: 12px;
  background-color: var(--el-fill-color-light);
  border-radius: 6px;
}

.text-muted {
  color: var(--el-text-color-secondary);
}
</style>
