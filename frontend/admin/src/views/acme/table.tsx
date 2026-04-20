import { ref } from "vue";
import dayjs from "dayjs";
import { DocumentCopy } from "@element-plus/icons-vue";
import { status, statusType } from "./dictionary";
import { createUsernameRenderer } from "@/views/system/username";
import { message } from "@shared/utils";
import * as acmeApi from "@/api/acme";

const expiryColor = (date: string | null) => {
  if (!date) return "";
  const diff = dayjs(date).diff(dayjs(), "day");
  if (diff < 0) return "color: var(--el-color-danger)";
  if (diff < 15) return "color: var(--el-color-warning)";
  return "";
};

export function useAcmeTable() {
  const tableRef = ref();
  const selectedIds = ref<number[]>([]);
  const selectedRows = ref<any[]>([]);

  const copyEabOne = async (row: any, event: Event) => {
    event.stopPropagation();
    if (!row.eab_kid) {
      return message("EAB 未生成，暂无可复制内容", { type: "warning" });
    }
    const res = await acmeApi.batchCopyEabAcme([row.id]);
    if (res.code !== 1) return;
    const text = res.data?.text ?? "";
    try {
      await navigator.clipboard.writeText(text);
      message("EAB 已复制到剪贴板", { type: "success" });
    } catch {
      const ta = document.createElement("textarea");
      ta.value = text;
      ta.style.position = "fixed";
      ta.style.opacity = "0";
      document.body.appendChild(ta);
      ta.select();
      document.execCommand("copy");
      document.body.removeChild(ta);
      message("EAB 已复制到剪贴板", { type: "success" });
    }
  };

  const handleSelectionChange = (rows: any[]) => {
    selectedIds.value = rows.map(v => v.id);
    selectedRows.value = rows;
    tableRef.value?.setAdaptive?.();
  };

  const handleCancelSelection = () => {
    selectedIds.value = [];
    selectedRows.value = [];
    tableRef.value?.getTableRef?.()?.clearSelection();
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
      minWidth: 100
    },
    {
      label: "用户名",
      prop: "user.username",
      minWidth: 120,
      cellRenderer: createUsernameRenderer("user.username")
    },
    {
      label: "EAB Kid",
      prop: "eab_kid",
      minWidth: 200,
      cellRenderer: ({ row }) => {
        const kid = row.eab_kid
          ? row.eab_kid.length > 20
            ? row.eab_kid.slice(0, 20)
            : row.eab_kid
          : "-";
        const productName = row.product?.name || "-";
        return (
          <div class="flex flex-col">
            <div class="flex items-center gap-1">
              <span>{kid}</span>
              {row.eab_kid && (
                <el-button
                  link
                  size="small"
                  onClick={(e: Event) => copyEabOne(row, e)}
                  class="p-0! m-0! bg-transparent! border-none! shadow-none! text-gray-500 hover:text-blue-500"
                >
                  <el-icon size="14">
                    <DocumentCopy />
                  </el-icon>
                </el-button>
              )}
            </div>
            <span class="text-xs text-gray-400">{productName}</span>
          </div>
        );
      }
    },
    {
      label: "周期",
      prop: "period",
      minWidth: 100,
      cellRenderer: ({ row }) => (
        <div class="flex flex-col">
          <span>{row.period ? `${row.period} 个月` : "-"}</span>
          <span class="text-xs text-gray-400">¥{row.amount}</span>
        </div>
      )
    },
    {
      label: "标准域名额度",
      prop: "purchased_standard_count",
      minWidth: 120
    },
    {
      label: "通配符域名额度",
      prop: "purchased_wildcard_count",
      minWidth: 130
    },
    {
      label: "状态",
      prop: "status",
      minWidth: 80,
      cellRenderer: ({ row }) => (
        <el-tag type={statusType[row.status] || "info"}>
          {status[row.status] || row.status}
        </el-tag>
      )
    },
    {
      label: "订单周期",
      prop: "period_till",
      minWidth: 170,
      sortable: "custom",
      cellRenderer: ({ row }) => {
        const from = row.period_from
          ? dayjs(row.period_from).format("YYYY-MM-DD HH:mm:ss")
          : row.created_at
            ? dayjs(row.created_at).format("YYYY-MM-DD HH:mm:ss")
            : "-";
        const till = row.period_till
          ? dayjs(row.period_till).format("YYYY-MM-DD HH:mm:ss")
          : "-";
        return (
          <div class="flex flex-col">
            <span style={expiryColor(row.period_till)}>{till}</span>
            <span class="text-xs text-gray-400">{from}</span>
          </div>
        );
      }
    },
    {
      label: "操作",
      fixed: "right",
      width: 200,
      slot: "operation"
    }
  ];

  const handleRowClick = (row: any, _column: any, event: any) => {
    const target = event.target as HTMLElement;
    if (
      target.tagName === "BUTTON" ||
      target.closest("button") ||
      target.closest(".el-button")
    ) {
      return;
    }
    tableRef.value?.getTableRef?.()?.toggleRowSelection(row);
  };

  return {
    tableRef,
    tableColumns,
    selectedIds,
    selectedRows,
    handleSelectionChange,
    handleCancelSelection,
    handleRowClick
  };
}
