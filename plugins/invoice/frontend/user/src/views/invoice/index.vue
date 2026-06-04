<script setup lang="ts">
import { onMounted, ref, reactive, h, resolveComponent } from "vue";
import { PlusSearch, PlusDrawerForm } from "plus-pro-components";
import type { PlusColumn } from "plus-pro-components";
import type { PaginationProps } from "@pureadmin/table";
import type { FormRules } from "element-plus";
import { debounce } from "lodash-es";
import dayjs from "dayjs";
import * as invoiceApi from "../../api/invoice";
import {
  convertDateRangeToISO,
  getPickerShortcuts,
  useDrawerSize
} from "../../shared/utils";

defineOptions({ name: "Invoice" });

const { drawerSize } = useDrawerSize();

// 额度
const quotaInfo = reactive({
  recharge: "0.00",
  invoiced: "0.00",
  quota: "0.00"
});
const fetchQuota = async () => {
  try {
    const { data } = await invoiceApi.quota();
    Object.assign(quotaInfo, data);
  } catch {}
};

// 列表
const tableRef = ref();
const search = ref<Record<string, any>>({});
const dataList = ref([]);
const loading = ref(true);
const pagination = reactive<PaginationProps>({
  total: 0,
  pageSize: 10,
  currentPage: 1,
  background: true,
  pageSizes: [10, 20, 50, 100]
});

function onSearch() {
  loading.value = true;
  const params: any = {
    ...search.value,
    pageSize: pagination.pageSize,
    currentPage: pagination.currentPage
  };
  if (params.created_at) {
    params.created_at = convertDateRangeToISO(params.created_at);
  }
  invoiceApi
    .index(params)
    .then(({ data }: any) => {
      dataList.value = data.items;
      pagination.total = data.total;
      pagination.pageSize = data.pageSize;
      pagination.currentPage = data.currentPage;
    })
    .finally(() => {
      loading.value = false;
    });
}

const handleSizeChange = (val: number) => {
  pagination.pageSize = val;
  onSearch();
};
const handleCurrentChange = (val: number) => {
  pagination.currentPage = val;
  onSearch();
};
const onReset = () => onSearch();
const onCollapse = () =>
  setTimeout(() => window.dispatchEvent(new Event("resize")), 500);

const handleDestroy = (id: number) => {
  invoiceApi.destroy(id).then(() => {
    onSearch();
    fetchQuota();
  });
};

// 搜索列
const debouncedSearch = debounce(() => onSearch(), 500);
const searchColumns: PlusColumn[] = [
  {
    label: "快速搜索",
    prop: "quickSearch",
    valueType: "input",
    fieldProps: { placeholder: "组织/邮箱/备注" },
    onChange: () => debouncedSearch()
  },
  {
    label: "状态",
    prop: "status",
    valueType: "select",
    fieldProps: { placeholder: "请选择状态" },
    options: [
      { label: "处理中", value: 0 },
      { label: "已开票", value: 1 },
      { label: "已作废", value: 2 }
    ],
    onChange: () => debouncedSearch()
  },
  {
    label: "创建时间",
    prop: "created_at",
    valueType: "date-picker",
    fieldProps: {
      type: "daterange",
      rangeSeparator: "至",
      startPlaceholder: "开始日期",
      endPlaceholder: "结束日期",
      valueFormat: "YYYY-MM-DD",
      shortcuts: getPickerShortcuts()
    }
  }
];

// 表格列
const statusMap: Record<number, { label: string; type: string }> = {
  0: { label: "处理中", type: "primary" },
  1: { label: "已开票", type: "success" },
  2: { label: "已作废", type: "danger" }
};
const tableColumns: any[] = [
  { label: "组织", prop: "organization", minWidth: 150 },
  { label: "金额", prop: "amount", width: 100 },
  { label: "邮箱", prop: "email", minWidth: 150 },
  { label: "备注", prop: "remark", minWidth: 150 },
  {
    label: "状态",
    prop: "status",
    width: 100,
    cellRenderer: ({ row, props }: any) =>
      h(
        resolveComponent("ElTag"),
        {
          size: props.size,
          type: statusMap[row.status]?.type,
          effect: "plain"
        },
        () => statusMap[row.status]?.label
      )
  },
  {
    label: "创建时间",
    prop: "created_at",
    width: 160,
    formatter: ({ created_at }: any) =>
      created_at ? dayjs(created_at).format("YYYY-MM-DD HH:mm:ss") : "-"
  },
  { label: "操作", fixed: "right", width: 80, slot: "operation" }
];

// 表单
const showStore = ref(false);
const storeRef = ref();
const storeValues = ref<Record<string, any>>({});
const storeColumns: PlusColumn[] = [
  {
    label: "金额",
    prop: "amount",
    valueType: "input-number",
    fieldProps: {
      placeholder: "请输入金额",
      min: 1,
      precision: 2,
      controlsPosition: "right"
    }
  },
  {
    label: "组织",
    prop: "organization",
    valueType: "input",
    fieldProps: { placeholder: "请输入组织名称" }
  },
  {
    label: "税号",
    prop: "taxation",
    valueType: "input",
    fieldProps: { placeholder: "请输入税号" }
  },
  {
    label: "邮箱",
    prop: "email",
    valueType: "input",
    fieldProps: { placeholder: "请输入邮箱" }
  },
  {
    label: "备注",
    prop: "remark",
    valueType: "textarea",
    fieldProps: { placeholder: "请输入备注", rows: 3 }
  }
];
const storeRules: FormRules = {
  amount: [{ required: true, message: "请输入金额", trigger: "blur" }],
  organization: [
    { required: true, message: "请输入组织名称", trigger: "blur" }
  ],
  taxation: [{ required: true, message: "请输入税号", trigger: "blur" }],
  email: [
    { required: true, message: "请输入邮箱", trigger: "blur" },
    { type: "email", message: "请输入正确的邮箱格式", trigger: "blur" }
  ]
};

const openStoreForm = async () => {
  storeRef.value?.formInstance?.resetFields();
  storeValues.value = {};
  try {
    const { data } = await invoiceApi.me();
    if (data?.email) {
      storeValues.value.email = data.email;
    }
  } catch {}
  showStore.value = true;
};

const confirmStoreForm = () => {
  invoiceApi.store(storeValues.value).then(() => {
    showStore.value = false;
    onSearch();
    fetchQuota();
  });
};

onMounted(() => {
  fetchQuota();
  onSearch();
});
</script>

<template>
  <div class="main">
    <!-- 额度信息 -->
    <div
      class="bg-bg_color w-[99/100]"
      style="padding: 20px 24px; margin-bottom: 8px"
    >
      <div style="display: flex; gap: 48px">
        <div>
          <span style="font-size: 13px; color: var(--el-text-color-secondary)"
            >年度充值</span
          >
          <div style="font-size: 18px; font-weight: bold; margin-top: 4px">
            ¥{{ quotaInfo.recharge }}
          </div>
        </div>
        <div>
          <span style="font-size: 13px; color: var(--el-text-color-secondary)"
            >已开票</span
          >
          <div style="font-size: 18px; font-weight: bold; margin-top: 4px">
            ¥{{ quotaInfo.invoiced }}
          </div>
        </div>
        <div>
          <span style="font-size: 13px; color: var(--el-text-color-secondary)"
            >可开票</span
          >
          <div
            style="
              font-size: 18px;
              font-weight: bold;
              margin-top: 4px;
              color: var(--el-color-primary);
            "
          >
            ¥{{ quotaInfo.quota }}
          </div>
        </div>
      </div>
    </div>

    <div
      class="search bg-bg_color w-[99/100] pl-4 pr-4 pt-[24px] pb-[12px] overflow-auto"
    >
      <PlusSearch
        v-model="search"
        :columns="searchColumns"
        :show-number="2"
        :row-props="{ gutter: 12 }"
        :col-props="{ xs: 24, sm: 12, md: 8, lg: 8, xl: 6 }"
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
        <el-button type="primary" @click="openStoreForm">申请开票</el-button>
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
        rules: storeRules,
        labelPosition: 'right',
        labelSuffix: ''
      }"
      :size="drawerSize"
      :closeOnClickModal="true"
      title="申请开票"
      confirmText="提交"
      cancelText="取消"
      @confirm="confirmStoreForm"
      @cancel="showStore = false"
    />
  </div>
</template>

<style scoped lang="scss">
.search {
  :deep(.el-form-item) {
    margin-bottom: 12px;
  }
}
</style>
