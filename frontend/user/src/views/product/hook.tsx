import type { PaginationProps } from "@pureadmin/table";
import { reactive, ref, toRaw, nextTick, computed } from "vue";
import type { IndexParams } from "@/api/product";
import * as productApi from "@/api/product";
import router from "@/router";

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

  function handleApply(row) {
    if (row?.product_type === "acme") {
      router.push({
        name: "Acme",
        query: { product_id: row.id }
      });
      return;
    }
    router.push({
      name: "Order",
      query: {
        product_id: row.id,
        type: "apply"
      }
    });
  }

  function handleBatchApply(row) {
    router.push({
      name: "Order",
      query: {
        product_id: row.id,
        type: "batchApply"
      }
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
    handleApply,
    handleBatchApply
  };
}
