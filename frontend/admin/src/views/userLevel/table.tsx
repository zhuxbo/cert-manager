import { ref } from "vue";
import dayjs from "dayjs";
import { formatCostRate } from "./costRate";

export const useUserLevelTable = () => {
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
      width: 50
    },
    {
      label: "编码",
      prop: "code",
      minWidth: 100
    },
    {
      label: "名称",
      prop: "name",
      minWidth: 140
    },
    {
      label: "定制",
      prop: "custom",
      width: 60,
      formatter: ({ custom }) => (custom === 1 ? "是" : "否")
    },
    {
      label: "成本价倍率",
      prop: "cost_rate",
      width: 100,
      formatter: ({ cost_rate }) => formatCostRate(cost_rate)
    },
    {
      label: "权重",
      prop: "weight",
      width: 70
    },
    {
      label: "创建时间",
      prop: "created_at",
      width: 170,
      formatter: ({ created_at }) =>
        created_at ? dayjs(created_at).format("YYYY-MM-DD HH:mm:ss") : "-"
    },
    {
      label: "更新时间",
      prop: "updated_at",
      width: 170,
      formatter: ({ updated_at }) =>
        updated_at ? dayjs(updated_at).format("YYYY-MM-DD HH:mm:ss") : "-"
    },
    {
      label: "操作",
      fixed: "right",
      slot: "operation",
      width: 110
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
