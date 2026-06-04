import type { PaginationProps } from "@pureadmin/table";
import { reactive, ref, toRaw } from "vue";
import { ElMessage } from "element-plus";
import type { NotificationRecord } from "@/api/notification";
import * as notificationApi from "@/api/notification";
import { convertDateRangeToISO } from "@/views/system/utils";

export interface SearchParams {
  template_code?: string;
  status?: "" | NotificationRecord["status"];
  user_id?: number | null;
  created_at?: [Date | string | null, Date | string | null];
}

export function useNotificationRecord() {
  const search = ref<SearchParams>({
    template_code: "",
    status: "",
    user_id: null,
    created_at: [null, null]
  });

  const dataList = ref<NotificationRecord[]>([]);
  const loading = ref(true);

  const pagination = reactive<PaginationProps>({
    total: 0,
    pageSize: 10,
    currentPage: 1,
    background: true,
    pageSizes: [10, 20, 50, 100]
  });

  // 详情对话框
  const detailDialogVisible = ref(false);
  const detailRecord = ref<NotificationRecord | null>(null);

  // 重发对话框
  const resendDialogVisible = ref(false);
  const resendTarget = ref<NotificationRecord | null>(null);

  function handleSizeChange(val: number) {
    pagination.pageSize = val;
    onSearch();
  }

  function handleCurrentChange(val: number) {
    pagination.currentPage = val;
    onSearch();
  }

  function onSearch() {
    loading.value = true;
    const params: any = {
      ...toRaw(search.value),
      pageSize: pagination.pageSize,
      currentPage: pagination.currentPage
    };

    // 处理用户ID
    if (params.user_id) {
      params.user_id = Number(params.user_id);
    }

    // 处理日期范围
    if (params.created_at && params.created_at[0] && params.created_at[1]) {
      params.created_at = convertDateRangeToISO(params.created_at);
    } else {
      delete params.created_at;
    }

    notificationApi
      .index(params)
      .then(({ data }) => {
        dataList.value = data.items;
        pagination.total = data.total;
        pagination.pageSize = data.pageSize;
        pagination.currentPage = data.currentPage;
      })
      .finally(() => {
        loading.value = false;
      });
  }

  const onReset = () => {
    search.value.template_code = "";
    search.value.status = "";
    search.value.user_id = null;
    search.value.created_at = [null, null];
    pagination.currentPage = 1;
    onSearch();
  };

  const onCollapse = () => {
    setTimeout(() => {
      window.dispatchEvent(new Event("resize"));
    }, 500);
  };

  // 查看详情
  const openDetail = (row: NotificationRecord) => {
    detailRecord.value = row;
    detailDialogVisible.value = true;
  };

  // 查看数据
  const showPayload = (payload: Record<string, any>) =>
    JSON.stringify(payload ?? {}, null, 2);

  // 打开重发对话框
  const openResend = (row: NotificationRecord) => {
    resendTarget.value = row;
    resendDialogVisible.value = true;
  };

  // 确认重发
  const confirmResend = () => {
    if (!resendTarget.value) return;
    notificationApi.resend(resendTarget.value.id, {}).then(() => {
      ElMessage.success("已提交重发任务");
      resendDialogVisible.value = false;
      onSearch();
    });
  };

  // 关闭重发对话框
  const closeResend = () => {
    resendDialogVisible.value = false;
  };

  return {
    loading,
    search,
    dataList,
    pagination,
    handleSizeChange,
    handleCurrentChange,
    onSearch,
    onReset,
    onCollapse,
    // 详情相关
    detailDialogVisible,
    detailRecord,
    openDetail,
    showPayload,
    // 重发相关
    resendDialogVisible,
    resendTarget,
    openResend,
    confirmResend,
    closeResend
  };
}
