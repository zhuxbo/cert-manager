import type { PaginationProps } from "@pureadmin/table";
import { reactive, ref, toRaw, nextTick } from "vue";
import type { IndexParams } from "@/api/user";
import * as userApi from "@/api/user";
import { message } from "@shared/utils";
import { convertDateRangeToISO } from "@/views/system/utils";

export function useUser(tableRef) {
  const search = ref<IndexParams>({});

  const dataList = ref([]);
  const loading = ref(true);

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
    userApi.destroy(id).then(() => {
      message("删除成功", {
        type: "success"
      });
      onSearch();
    });
  };

  const handleBatchDestroy = (ids: number[]) => {
    userApi.batchDestroy(ids).then(() => {
      message("删除成功", {
        type: "success"
      });
      onSearch();
    });
  };

  const handleDirectLogin = (id: number) => {
    userApi
      .directLogin(id)
      .then(({ data }) => {
        if (data.direct_login_url) {
          // 在新窗口打开会员中心
          window.open(data.direct_login_url, "_blank");
          message("正在跳转到会员中心...", {
            type: "success"
          });
        } else {
          message("获取登录链接失败", {
            type: "error"
          });
        }
      })
      .catch(() => {
        message("直接登录失败", {
          type: "error"
        });
      });
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

  function onSearch() {
    loading.value = true;
    const params = {
      ...toRaw(search.value),
      pageSize: pagination.pageSize,
      currentPage: pagination.currentPage
    };

    if (sortProp.value) {
      params.sort_prop = sortProp.value;
      params.sort_order = sortOrder.value;
    }

    if (params.created_at) {
      params.created_at = convertDateRangeToISO(params.created_at);
    }

    userApi
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
    handleDirectLogin,
    handleSortChange
  };
}
