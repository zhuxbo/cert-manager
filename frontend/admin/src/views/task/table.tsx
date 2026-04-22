import { ref } from "vue";
import { useRouter } from "vue-router";
import dayjs from "dayjs";
import {
  getActionLabel,
  getActionType,
  getStatusLabel,
  getStatusType
} from "./dictionary";

export const useTaskTable = () => {
  const tableRef = ref();
  const selectedIds = ref<number[]>([]);
  const router = useRouter();

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
      width: 30,
      reserveSelection: true
    },
    {
      label: "ID",
      prop: "id",
      width: 60
    },
    {
      label: "订单ID",
      prop: "order_id",
      minWidth: 100,
      cellRenderer: ({ row }) => {
        const handleClick = () => {
          const path = String(row.action ?? "").endsWith("_acme")
            ? "/acme"
            : "/order";
          router.push({
            path,
            query: {
              id: row.order_id
            }
          });
        };
        return (
          <span class="cursor-pointer" onClick={handleClick}>
            {row.order_id}
          </span>
        );
      }
    },
    {
      label: "动作",
      prop: "action",
      minWidth: 100,
      cellRenderer: ({ row }) => (
        <el-tag type={getActionType(row.action) as any}>
          {getActionLabel(row.action)}
        </el-tag>
      )
    },
    {
      label: "状态",
      prop: "status",
      minWidth: 100,
      cellRenderer: ({ row }) => (
        <el-tag type={getStatusType(row.status) as any}>
          {getStatusLabel(row.status)}
        </el-tag>
      )
    },
    {
      label: "执行次数",
      prop: "attempts",
      minWidth: 120
    },
    {
      label: "权重",
      prop: "weight",
      minWidth: 100
    },
    {
      label: "来源",
      prop: "source",
      width: 100
    },
    {
      label: "开始时间",
      prop: "started_at",
      width: 170,
      formatter: ({ started_at }) =>
        started_at ? dayjs(started_at).format("YYYY-MM-DD HH:mm:ss") : "-"
    },
    {
      label: "最后执行时间",
      prop: "last_execute_at",
      width: 170,
      formatter: ({ last_execute_at }) =>
        last_execute_at
          ? dayjs(last_execute_at).format("YYYY-MM-DD HH:mm:ss")
          : "-"
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
    tableColumns,
    handleSelectionChange,
    handleSelectionCancel,
    handleRowClick
  };
};
