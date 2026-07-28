import type { PaginationProps } from "@pureadmin/table";
import { reactive, ref, toRaw, nextTick } from "vue";
import type { IndexParams } from "@/api/order";
import * as orderApi from "@/api/order";
import { convertDateRangeToISO } from "@/views/system/utils";

export function useOrder(tableRef) {
  const search = ref<IndexParams>({});

  const dataList = ref([]);
  const loading = ref(true);

  // 排序状态
  const sortProp = ref<string>();
  const sortOrder = ref<string>();

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

    if (params.created_at) {
      params.created_at = convertDateRangeToISO(params.created_at);
    }

    if (params.expires_at) {
      params.expires_at = convertDateRangeToISO(params.expires_at);
    }

    if (sortProp.value) {
      params.sort_prop = sortProp.value;
      params.sort_order = sortOrder.value;
    }

    orderApi
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
    sortProp.value = undefined;
    sortOrder.value = undefined;
    tableRef.value?.getTableRef().clearSort();
    onSearch();
  };

  function handleSortChange({
    prop,
    order
  }: {
    prop: string;
    order: string | null;
  }) {
    if (order) {
      sortProp.value = prop;
      sortOrder.value = order === "ascending" ? "asc" : "desc";
    } else {
      sortProp.value = undefined;
      sortOrder.value = undefined;
    }
    pagination.currentPage = 1;
    onSearch();
  }

  function setSort(prop?: string, order?: string) {
    sortProp.value = prop;
    sortOrder.value = order;
  }

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
    handleSortChange,
    setSort,
    onSearch,
    onReset,
    onCollapse
  };
}
