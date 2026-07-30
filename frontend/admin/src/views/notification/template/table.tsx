import { ref } from "vue";
import dayjs from "dayjs";

export function useNotificationTemplateTable() {
  const tableRef = ref();
  const selectedIds = ref<number[]>([]);

  const handleSelectionChange = (rows: Array<{ id: number }>) => {
    selectedIds.value = rows.map(row => row.id);
    tableRef.value?.setAdaptive();
  };

  const tableColumns: TableColumnList = [
    {
      label: "勾选列",
      type: "selection",
      width: 55
    },
    {
      label: "ID",
      prop: "id",
      minWidth: 90
    },
    {
      label: "名称",
      prop: "name",
      minWidth: 180
    },
    {
      label: "标识",
      prop: "code",
      minWidth: 180
    },
    {
      label: "变量",
      prop: "variables",
      minWidth: 200,
      cellRenderer: ({ row }) => {
        if (!row.variables || row.variables.length === 0) {
          return <span class="text-muted">-</span>;
        }
        return (
          <div>
            {row.variables.map((item: string) => (
              <el-tag key={item} size="small" effect="light" class="mr-1 mb-1">
                {item}
              </el-tag>
            ))}
          </div>
        );
      }
    },
    {
      label: "状态",
      prop: "status",
      width: 100,
      cellRenderer: ({ row, props }) => (
        <el-tag
          size={props.size}
          type={row.status === 1 ? "success" : "info"}
          effect="plain"
        >
          {row.status === 1 ? "启用" : "停用"}
        </el-tag>
      )
    },
    {
      label: "更新时间",
      prop: "updated_at",
      minWidth: 180,
      formatter: ({ updated_at }) => {
        return updated_at
          ? dayjs(updated_at).format("YYYY-MM-DD HH:mm:ss")
          : "-";
      }
    },
    {
      label: "操作",
      prop: "operation",
      width: 110,
      fixed: "right",
      slot: "operation"
    }
  ];

  return {
    tableRef,
    selectedIds,
    handleSelectionChange,
    tableColumns
  };
}
