import { ref } from "vue";
import dayjs from "dayjs";
import { createUsernameRenderer } from "@/views/system/username";

export const useUserTable = () => {
  const tableRef = ref();
  const selectedIds = ref<number[]>([]);

  const handleSelectionChange = (val: any) => {
    selectedIds.value = val.map((row: any) => row.id);
    // 重置表格高度
    tableRef.value.setAdaptive();
  };

  const handleSelectionCancel = () => {
    tableRef.value?.getTableRef().clearSelection();
  };

  const tableColumns: TableColumnList = [
    {
      label: "勾选列",
      type: "selection",
      reserveSelection: true
    },
    {
      label: "ID",
      prop: "id",
      minWidth: 90
    },
    {
      label: "用户名",
      prop: "username",
      minWidth: 100,
      cellRenderer: createUsernameRenderer("username")
    },
    {
      label: "邮箱",
      prop: "email",
      minWidth: 140
    },
    {
      label: "手机号",
      prop: "mobile",
      minWidth: 100
    },
    {
      label: "级别",
      prop: "level.name",
      minWidth: 100
    },
    {
      label: "定制级别",
      prop: "custom_level.name",
      minWidth: 100
    },
    {
      label: "余额",
      prop: "balance",
      minWidth: 100,
      sortable: "custom"
    },
    {
      label: "信用额度",
      prop: "credit_limit",
      minWidth: 100
    },
    {
      label: "最后登录",
      prop: "last_login_at",
      minWidth: 150,
      formatter: ({ last_login_at }) =>
        last_login_at ? dayjs(last_login_at).format("YYYY-MM-DD HH:mm:ss") : "-"
    },
    {
      label: "创建时间",
      prop: "created_at",
      minWidth: 150,
      formatter: ({ created_at }) =>
        created_at ? dayjs(created_at).format("YYYY-MM-DD HH:mm:ss") : "-"
    },
    {
      label: "状态",
      prop: "status",
      minWidth: 100,
      cellRenderer: ({ row, props }) => (
        <el-tag
          size={props.size}
          type={row.status === 1 ? "success" : "danger"}
          effect="plain"
        >
          {row.status === 1 ? "正常" : "禁用"}
        </el-tag>
      )
    },
    {
      label: "操作",
      fixed: "right",
      slot: "operation",
      width: 160
    }
  ];

  const handleRowClick = (row: any, _column: any, event: any) => {
    // 通过事件目标判断是否点击了按钮
    const target = event.target as HTMLElement;
    if (
      target.tagName === "BUTTON" ||
      target.closest("button") ||
      target.closest(".el-button")
    ) {
      return;
    }
    // 切换当前行的选中状态
    tableRef.value?.getTableRef().toggleRowSelection(row);
  };

  return {
    tableRef,
    selectedIds,
    handleSelectionChange,
    handleSelectionCancel,
    tableColumns,
    handleRowClick
  };
};
