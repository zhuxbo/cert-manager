<script setup lang="tsx">
import "plus-pro-components/es/components/search/style/css";
import type { PaginationProps } from "@pureadmin/table";
import type { PlusColumn } from "plus-pro-components";
import { PlusSearch } from "plus-pro-components";
import { computed, h, onMounted, reactive, ref, toRaw } from "vue";
import { useRouter } from "vue-router";
import { ElTag } from "element-plus";
import dayjs from "dayjs";
import { PureTableBar } from "@shared/components";
import ReRemoteSelect from "@shared/components/ReRemoteSelect";

interface IndexParams {
  currentPage?: number;
  pageSize?: number;
  quickSearch?: string;
  order_id?: number;
  user_id?: number;
  status?: "success" | "failure";
  ip?: string;
}

interface ReportListData {
  items: any[];
  total: number;
  pageSize: number;
  currentPage: number;
}

const props = withDefaults(
  defineProps<{
    load: (params: IndexParams) => Promise<any>;
    showUser?: boolean;
  }>(),
  { showUser: false }
);

const router = useRouter();
const tableRef = ref();
const loading = ref(true);
const search = ref<IndexParams>({});
const dataList = ref<any[]>([]);
const pagination = reactive<PaginationProps>({
  total: 0,
  pageSize: 20,
  currentPage: 1,
  background: true,
  pageSizes: [10, 20, 50, 100]
});
let latestRequestId = 0;

const searchColumns = computed<PlusColumn[]>(() => {
  const columns: PlusColumn[] = [
    {
      label: "快速搜索",
      prop: "quickSearch",
      valueType: "input",
      fieldProps: {
        placeholder: props.showUser
          ? "订单ID/用户名/邮箱/域名/IP/失败信息"
          : "订单ID/域名/IP/失败信息"
      }
    }
  ];

  if (props.showUser) {
    columns.push(
      {
        label: "用户",
        prop: "user_id",
        valueType: "select",
        renderField: (value, onChange) => (
          <ReRemoteSelect
            modelValue={value}
            uri="/user"
            searchField="quickSearch"
            labelField="username"
            valueField="id"
            itemsField="items"
            totalField="total"
            placeholder="请选择用户"
            onChange={onChange}
          />
        )
      },
      {
        label: "状态",
        prop: "status",
        valueType: "select",
        options: [
          { label: "成功", value: "success" },
          { label: "失败", value: "failure" }
        ],
        fieldProps: {
          placeholder: "请选择状态"
        }
      }
    );
  }

  columns.push({
    label: "订单 ID",
    prop: "order_id",
    valueType: "input",
    fieldProps: {
      placeholder: "请输入订单 ID"
    }
  });

  if (!props.showUser) {
    columns.push({
      label: "状态",
      prop: "status",
      valueType: "select",
      options: [
        { label: "成功", value: "success" },
        { label: "失败", value: "failure" }
      ],
      fieldProps: {
        placeholder: "请选择状态"
      }
    });
  }

  columns.push({
    label: "上报 IP",
    prop: "ip",
    valueType: "input",
    fieldProps: {
      placeholder: "请输入上报 IP"
    }
  });

  return columns;
});

const tableColumns = computed<TableColumnList>(() => {
  const columns: TableColumnList = [];

  if (props.showUser) {
    columns.push(
      {
        label: "ID",
        prop: "id",
        minWidth: 120
      },
      {
        label: "用户名",
        prop: "order.user.username",
        width: 120,
        showOverflowTooltip: true,
        formatter: row =>
          row.order?.user?.username || row.order?.user?.email || "-"
      }
    );
  }

  columns.push(
    {
      label: "订单 ID",
      prop: "order_id",
      minWidth: 120,
      slot: "order"
    },
    {
      label: "域名",
      prop: "cert.common_name",
      minWidth: 180,
      showOverflowTooltip: true,
      formatter: row => row.cert?.common_name || "-"
    },
    {
      label: "状态",
      prop: "status",
      width: 90,
      cellRenderer: ({ row }) =>
        h(
          ElTag,
          { type: row.status === "success" ? "success" : "danger" },
          { default: () => (row.status === "success" ? "成功" : "失败") }
        )
    },
    {
      label: "部署时间",
      prop: "deployed_at",
      minWidth: 170,
      formatter: row => formatTime(row.deployed_at || row.created_at)
    }
  );

  return columns;
});

function onSearch() {
  const requestId = ++latestRequestId;
  loading.value = true;
  const params = {
    ...toRaw(search.value),
    pageSize: pagination.pageSize,
    currentPage: pagination.currentPage
  };

  props
    .load(params)
    .then(response => {
      if (requestId !== latestRequestId) return;

      const data = response.data as ReportListData;
      dataList.value = data.items;
      pagination.total = data.total;
      pagination.pageSize = data.pageSize;
      pagination.currentPage = data.currentPage;
    })
    .finally(() => {
      if (requestId === latestRequestId) loading.value = false;
    });
}

function handleSearch() {
  pagination.currentPage = 1;
  onSearch();
}

function handleSizeChange(value: number) {
  pagination.pageSize = value;
  onSearch();
}

function handleCurrentChange(value: number) {
  pagination.currentPage = value;
  onSearch();
}

const onReset = () => {
  handleSearch();
};

const onCollapse = () => {
  setTimeout(() => {
    window.dispatchEvent(new Event("resize"));
  }, 500);
};

const openOrder = (orderId: number) => {
  router.push({ name: "OrderDetails", params: { ids: String(orderId) } });
};

const formatTime = (value?: string | null) =>
  value ? dayjs(value).format("YYYY-MM-DD HH:mm:ss") : "-";

onMounted(onSearch);
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
        @search="handleSearch"
        @reset="onReset"
        @collapse="onCollapse"
      />
    </div>

    <PureTableBar title="部署记录" :columns="tableColumns" @refresh="onSearch">
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
          <template #order="{ row }">
            <el-button link type="primary" @click="openOrder(row.order_id)">
              {{ row.order_id }}
            </el-button>
          </template>
        </pure-table>
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
