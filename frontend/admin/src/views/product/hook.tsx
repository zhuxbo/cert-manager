import type { PaginationProps } from "@pureadmin/table";
import { reactive, ref, toRaw, nextTick, computed } from "vue";
import type { IndexParams } from "@/api/product";
import * as productApi from "@/api/product";
import { message } from "@shared/utils";

export function useProduct(tableRef) {
  const search = ref<IndexParams>({});

  const dataList = ref([]);
  const loading = ref(true);

  const pagination = reactive<PaginationProps>({
    total: 0,
    pageSize: 100,
    currentPage: 1,
    background: true,
    pageSizes: [10, 20, 50, 100],
    hideOnSinglePage: true
  });

  const isSinglePage = computed(() => {
    return pagination.total <= pagination.pageSize;
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
    productApi.destroy(id).then(() => {
      message("删除成功", {
        type: "success"
      });
      onSearch();
    });
  };

  const handleBatchDestroy = (ids: number[]) => {
    productApi.batchDestroy(ids).then(() => {
      message("删除成功", {
        type: "success"
      });
      onSearch();
    });
  };

  const handleBatchDelegation = (ids: number[], enabled: boolean) => {
    loading.value = true;
    productApi
      .batchSetDelegation(ids, enabled)
      .then(() => {
        message(enabled ? "委托开启成功" : "委托关闭成功", {
          type: "success"
        });
        onSearch();
      })
      .finally(() => {
        loading.value = false;
      });
  };

  function onSearch() {
    loading.value = true;
    const params = {
      ...toRaw(search.value),
      pageSize: pagination.pageSize,
      currentPage: pagination.currentPage
    };

    productApi
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
    isSinglePage,
    handleSizeChange,
    handleCurrentChange,
    onSearch,
    showStore,
    updateId,
    handleStore,
    handleDestroy,
    handleBatchDestroy,
    handleBatchDelegation
  };
}
