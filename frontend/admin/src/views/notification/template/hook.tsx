import type { PaginationProps } from "@pureadmin/table";
import { reactive, ref, toRaw } from "vue";
import { ElMessage, ElMessageBox } from "element-plus";
import type { TemplateItem } from "@/api/notificationTemplate";
import * as templateApi from "@/api/notificationTemplate";

export interface SearchParams {
  name?: string;
  code?: string;
  status?: "" | 0 | 1;
}

export function useNotificationTemplate(tableRef) {
  const search = ref<SearchParams>({
    name: "",
    code: "",
    status: ""
  });

  const dataList = ref<TemplateItem[]>([]);
  const loading = ref(true);

  const pagination = reactive<PaginationProps>({
    total: 0,
    pageSize: 10,
    currentPage: 1,
    background: true,
    pageSizes: [10, 20, 50, 100]
  });

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
    const params = {
      ...toRaw(search.value),
      pageSize: pagination.pageSize,
      currentPage: pagination.currentPage
    };

    templateApi
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
    search.value.name = "";
    search.value.code = "";
    search.value.status = "";
    pagination.currentPage = 1;
    onSearch();
  };

  const onCollapse = () => {
    setTimeout(() => {
      window.dispatchEvent(new Event("resize"));
    }, 500);
  };

  // 删除模板
  const handleDelete = (row: TemplateItem) => {
    ElMessageBox.confirm(`确定删除模板「${row.name}」吗？`, "提示", {
      type: "warning"
    }).then(() => {
      templateApi.destroy(row.id).then(() => {
        ElMessage.success("删除成功");
        onSearch();
      });
    });
  };

  const handleResetTemplates = (ids: number[]) => {
    if (ids.length === 0) return;

    ElMessageBox.confirm(
      `确定重置选中的 ${ids.length} 个模板吗？模板内容和状态将恢复为系统默认值。`,
      "重置模板",
      {
        type: "warning",
        confirmButtonText: "确定重置",
        cancelButtonText: "取消"
      }
    ).then(() => {
      templateApi.reset(ids).then(() => {
        ElMessage.success("模板重置成功");
        tableRef.value?.getTableRef()?.clearSelection();
        onSearch();
      });
    });
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
    handleDelete,
    handleResetTemplates
  };
}
