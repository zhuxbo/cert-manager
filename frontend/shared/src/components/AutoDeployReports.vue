<template>
  <div class="auto-deploy-reports">
    <el-form :inline="true" :model="search" size="small" class="report-search">
      <el-form-item label="部署时间" class="report-search-time">
        <el-date-picker
          v-model="search.time"
          type="daterange"
          range-separator="至"
          start-placeholder="开始日期"
          end-placeholder="结束日期"
          value-format="YYYY-MM-DD"
          class="report-time-field"
          clearable
        />
      </el-form-item>
      <el-form-item label="部署 IP" class="report-search-ip">
        <el-input
          v-model="search.ip"
          placeholder="请输入 IP"
          class="report-ip-field"
          clearable
          @keyup.enter="onSearch"
        />
      </el-form-item>
      <el-form-item label="结果" class="report-search-result">
        <el-select
          v-model="search.status"
          placeholder="请选择结果"
          clearable
          class="report-result-field"
        >
          <el-option label="成功" value="success" />
          <el-option label="失败" value="failure" />
        </el-select>
      </el-form-item>
      <el-form-item class="report-search-actions">
        <el-button type="primary" @click="onSearch">搜索</el-button>
        <el-button @click="onReset">重置</el-button>
      </el-form-item>
    </el-form>

    <el-table
      v-loading="loading"
      :data="reports"
      empty-text="暂无自动部署记录"
      max-height="460"
      size="small"
    >
      <el-table-column label="部署时间" min-width="165">
        <template #default="{ row }">
          {{ formatTime(row.deployed_at || row.created_at) }}
        </template>
      </el-table-column>
      <el-table-column prop="ip" label="部署 IP" min-width="145">
        <template #default="{ row }">{{ row.ip || "-" }}</template>
      </el-table-column>
      <el-table-column label="说明" min-width="180">
        <template #default="{ row }">{{ row.message || "-" }}</template>
      </el-table-column>
      <el-table-column label="结果" width="90">
        <template #default="{ row }">
          <el-tag :type="row.status === 'success' ? 'success' : 'danger'">
            {{ row.status === "success" ? "成功" : "失败" }}
          </el-tag>
        </template>
      </el-table-column>
    </el-table>

    <div class="report-pagination">
      <el-pagination
        v-model:current-page="pagination.currentPage"
        v-model:page-size="pagination.pageSize"
        :page-sizes="[10, 20, 50, 100]"
        :total="pagination.total"
        layout="total, sizes, prev, pager, next"
        background
        small
        @size-change="handleSizeChange"
        @current-change="loadReports"
      />
    </div>
  </div>
</template>

<script setup lang="ts">
import { onMounted, reactive, ref } from "vue";
import dayjs from "dayjs";

type ReportStatus = "success" | "failure";

interface AutoDeployReport {
  id: number;
  status: ReportStatus;
  deployed_at: string | null;
  created_at: string;
  ip: string | null;
  message: string | null;
}

interface IndexParams {
  currentPage: number;
  pageSize: number;
  order_id: number;
  status?: ReportStatus;
  ip?: string;
  time?: [string, string];
}

interface ReportListData {
  items: AutoDeployReport[];
  total: number;
  currentPage: number;
}

const props = defineProps<{
  orderId: number;
  load: (params: IndexParams) => Promise<any>;
}>();

const loading = ref(false);
const reports = ref<AutoDeployReport[]>([]);
const search = reactive<{
  time?: [string, string];
  ip?: string;
  status?: ReportStatus;
}>({});
const pagination = reactive({
  total: 0,
  pageSize: 10,
  currentPage: 1
});
let latestRequestId = 0;

const loadReports = async () => {
  const requestId = ++latestRequestId;
  loading.value = true;
  try {
    const params: IndexParams = {
      order_id: props.orderId,
      currentPage: pagination.currentPage,
      pageSize: pagination.pageSize
    };

    if (search.ip) params.ip = search.ip;
    if (search.status) params.status = search.status;
    if (search.time?.[0] && search.time[1]) {
      params.time = [
        dayjs(search.time[0]).startOf("day").toISOString(),
        dayjs(search.time[1]).endOf("day").toISOString()
      ];
    }

    const response = await props.load(params);
    if (requestId !== latestRequestId) return;

    const data = response.data as ReportListData;
    reports.value = data.items;
    pagination.total = data.total;
    pagination.currentPage = data.currentPage;
  } finally {
    if (requestId === latestRequestId) loading.value = false;
  }
};

const onSearch = () => {
  pagination.currentPage = 1;
  loadReports();
};

const onReset = () => {
  search.time = undefined;
  search.ip = undefined;
  search.status = undefined;
  onSearch();
};

const handleSizeChange = () => {
  pagination.currentPage = 1;
  loadReports();
};

const formatTime = (value: string | null) =>
  value ? dayjs(value).format("YYYY-MM-DD HH:mm:ss") : "-";

onMounted(loadReports);
</script>

<style scoped>
.report-search {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-start;
  margin-bottom: 4px;
}

.report-search :deep(.el-form-item) {
  margin-right: 12px;
  margin-bottom: 12px;
}

.report-search-time {
  flex: 2 1 200px;
  min-width: 100px;
}

.report-search-ip {
  flex: 1.5 1 150px;
  min-width: 100px;
}

.report-search-result {
  flex: 1 1 100px;
  min-width: 100px;
}

.report-search-actions {
  flex: 0 0 auto;
  margin-right: 0 !important;
}

.report-search-time :deep(.el-form-item__content),
.report-search-ip :deep(.el-form-item__content),
.report-search-result :deep(.el-form-item__content) {
  flex: 1;
  min-width: 0;
}

.report-time-field,
.report-ip-field,
.report-result-field {
  width: 100%;
}

.report-pagination {
  display: flex;
  justify-content: flex-end;
  margin-top: 16px;
}

:deep(.el-table .cell) {
  overflow-wrap: anywhere;
}
</style>
