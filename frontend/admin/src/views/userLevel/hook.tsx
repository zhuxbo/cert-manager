import type { PaginationProps } from "@pureadmin/table";
import { reactive, ref, toRaw, nextTick } from "vue";
import type { IndexParams, UserLevelDto } from "@/api/userLevel";
import * as userLevelApi from "@/api/userLevel";
import { message } from "@shared/utils";

export function useUserLevel(tableRef) {
  const search = ref<IndexParams>({});

  const dataList = ref<UserLevelDto[]>([]);
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
    userLevelApi.destroy(id).then(() => {
      message("删除成功", {
        type: "success"
      });
      onSearch();
    });
  };

  const handleBatchDestroy = (ids: number[]) => {
    userLevelApi.batchDestroy(ids).then(() => {
      message("删除成功", {
        type: "success"
      });
      onSearch();
    });
  };

  function onSearch() {
    loading.value = true;
    const params = {
      ...toRaw(search.value),
      pageSize: pagination.pageSize,
      currentPage: pagination.currentPage
    };

    userLevelApi
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

  return {
    loading,
    search,
    dataList,
    pagination,
    handleSizeChange,
    handleCurrentChange,
    onSearch,
    showStore,
    updateId,
    handleStore,
    handleDestroy,
    handleBatchDestroy
  };
}
