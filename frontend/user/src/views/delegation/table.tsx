import { ref, h } from "vue";
import dayjs from "dayjs";
import { ElTag } from "element-plus";
import { message } from "@shared/utils";
import { DocumentCopy } from "@element-plus/icons-vue";
import { parse, type ParsedDomain } from "psl";

export const useDelegationTable = () => {
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
      label: "委托域",
      prop: "zone",
      minWidth: 150,
      cellRenderer: ({ row }) => {
        const zone = row.zone;
        const subdomain =
          row.cname_to?.host?.replace(`.${row.zone}`, "") || row.prefix;
        const copyText = `域名: ${zone}\n主机记录: ${subdomain}\n记录类型: CNAME\n记录值: ${row.target_fqdn || row.cname_to?.value || ""}`;
        return (
          <div className="flex items-center gap-1">
            <span>{zone || "-"}</span>
            <el-button
              link
              size="small"
              onClick={(e: { stopPropagation: () => void }) => {
                e.stopPropagation();
                navigator.clipboard.writeText(copyText).then(() => {
                  message("委托信息已复制到剪贴板", { type: "success" });
                });
              }}
              className="!p-0 !m-0 !mt-1 !bg-transparent !border-none !shadow-none align-middle text-gray-500 hover:text-blue-500"
            >
              <el-icon size="14">
                <DocumentCopy />
              </el-icon>
            </el-button>
          </div>
        );
      }
    },
    {
      label: "委托前缀",
      prop: "prefix",
      width: 150,
      cellRenderer: ({ row }) => {
        const prefix =
          (parse(row.cname_to.host) as ParsedDomain)?.subdomain || "";
        return (
          <div className="flex items-center gap-1">
            <span>{prefix || "-"}</span>
            <el-button
              link
              size="small"
              onClick={(e: { stopPropagation: () => void }) => {
                e.stopPropagation();
                navigator.clipboard.writeText(prefix).then(() => {
                  message("委托前缀已复制到剪贴板", { type: "success" });
                });
              }}
              className="!p-0 !m-0 !mt-1 !bg-transparent !border-none !shadow-none align-middle text-gray-500 hover:text-blue-500"
            >
              <el-icon size="14">
                <DocumentCopy />
              </el-icon>
            </el-button>
          </div>
        );
      }
    },
    {
      label: "CNAME目标",
      prop: "target_fqdn",
      minWidth: 200,
      cellRenderer: ({ row }) => {
        const targetFqdn = row.target_fqdn;
        return (
          <div className="flex items-center gap-1">
            <span>{targetFqdn || "-"}</span>
            <el-button
              link
              size="small"
              onClick={(e: { stopPropagation: () => void }) => {
                e.stopPropagation();
                navigator.clipboard.writeText(targetFqdn).then(() => {
                  message("CNAME目标已复制到剪贴板", { type: "success" });
                });
              }}
              className="!p-0 !m-0 !mt-1 !bg-transparent !border-none !shadow-none align-middle text-gray-500 hover:text-blue-500"
            >
              <el-icon size="14">
                <DocumentCopy />
              </el-icon>
            </el-button>
          </div>
        );
      }
    },
    {
      label: "状态",
      prop: "valid",
      width: 100,
      cellRenderer: ({ row }) =>
        h(
          ElTag,
          {
            type: row.valid ? "success" : "danger"
          },
          {
            default: () => (row.valid ? "有效" : "无效")
          }
        )
    },
    {
      label: "失败次数",
      prop: "fail_count",
      width: 100,
      formatter: ({ fail_count }) =>
        fail_count >= 100 ? "99+" : String(fail_count ?? 0)
    },
    {
      label: "上次检查",
      prop: "last_checked_at",
      width: 180,
      formatter: ({ last_checked_at }) =>
        last_checked_at
          ? dayjs(last_checked_at).format("YYYY-MM-DD HH:mm:ss")
          : "-"
    },
    {
      label: "操作",
      fixed: "right",
      slot: "operation",
      width: 220
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
