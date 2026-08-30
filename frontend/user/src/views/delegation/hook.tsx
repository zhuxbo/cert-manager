import type { PaginationProps } from "@pureadmin/table";
import { reactive, ref, toRaw, nextTick } from "vue";
import type { DelegationItem, IndexParams } from "@/api/delegation";
import * as delegationApi from "@/api/delegation";
import { formatDelegationCopyText, message } from "@shared/utils";

export function useDelegation(tableRef, selectedIds: { value: number[] }) {
  const search = ref<IndexParams>({});

  const dataList = ref([]);
  const loading = ref(true);

  const pagination = reactive<PaginationProps>({
    total: 0,
    pageSize: 20,
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

  const showStore = ref(false);
  const updateId = ref(0);

  const handleStore = (id?: number) => {
    showStore.value = true;
    if (id) {
      updateId.value = id;
    } else {
      updateId.value = 0;
    }
  };

  const handleDestroy = (id: number) => {
    delegationApi.destroy(id).then(() => {
      message("删除成功", {
        type: "success"
      });
      onSearch();
    });
  };

  const handleBatchDestroy = (ids: number[]) => {
    delegationApi.batchDestroy(ids).then(() => {
      message("删除成功", {
        type: "success"
      });
      onSearch();
    });
  };

  const handleCheck = (id: number) => {
    delegationApi
      .check(id)
      .then(res => {
        message(res.msg || "检查完成", {
          type: "success"
        });
        if (res.data?.warning) {
          message(res.data.warning, {
            type: "warning",
            duration: 8000
          });
        }
        onSearch();
      })
      .catch(error => {
        message(error.message || "检查失败", {
          type: "error"
        });
      });
  };

  const handleBatchCopy = () => {
    if (!selectedIds.value.length) {
      message("请先选择委托记录", { type: "warning" });
      return;
    }

    delegationApi.batchShow(selectedIds.value).then(res => {
      if (res.data && Array.isArray(res.data)) {
        const copyText = res.data
          .map((item: DelegationItem) => formatDelegationCopyText(item))
          .join("\n\n");

        navigator.clipboard.writeText(copyText).then(() => {
          message("已复制 " + res.data.length + " 条委托记录到剪贴板", {
            type: "success"
          });
        });
      }
    });
  };

  function onSearch() {
    loading.value = true;
    const params = {
      ...toRaw(search.value),
      pageSize: pagination.pageSize,
      currentPage: pagination.currentPage
    };

    delegationApi
      .index(params)
      .then(({ data }) => {
        dataList.value = data.items;
        pagination.total = data.total;
        pagination.pageSize = data.pageSize;
        pagination.currentPage = data.currentPage;

        nextTick(() => {
          tableRef.value && tableRef.value.getTableRef().clearSelection();
        });
      })
      .finally(() => {
        loading.value = false;
      });
  }

  const onReset = () => {
    onSearch();
  };

  const onCollapse = () => {
    setTimeout(() => {
      window.dispatchEvent(new Event("resize"));
    }, 500);
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
    showStore,
    updateId,
    handleStore,
    handleDestroy,
    handleBatchDestroy,
    handleCheck,
    handleBatchCopy
  };
}
