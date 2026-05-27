import { ref } from "vue";
import dayjs from "dayjs";

export function useNotificationRecordTable() {
  const tableRef = ref();

  const statusMap = {
    pending: { label: "待发送", type: "info" },
    sending: { label: "发送中", type: "warning" },
    sent: { label: "已发送", type: "success" },
    failed: { label: "失败", type: "danger" }
  };

  const tableColumns: TableColumnList = [
    {
      label: "ID",
      prop: "id",
      minWidth: 90
    },
    {
      label: "模板",
      prop: "template",
      minWidth: 220,
      cellRenderer: ({ row }) => (
        <div class="flex flex-col">
          <span>{row.template?.name || "-"}</span>
          <small class="text-muted">{row.template?.code}</small>
        </div>
      )
    },
    {
      label: "用户信息",
      prop: "user",
      minWidth: 220,
      cellRenderer: ({ row }) => (
        <div class="flex flex-col">
          <span>{row.notifiable?.username || row.notifiable_id}</span>
          <small class="text-muted">{row.notifiable?.email || "-"}</small>
        </div>
      )
    },
    {
      label: "创建时间",
      prop: "created_at",
      minWidth: 160,
      formatter: ({ created_at }) => {
        return created_at
          ? dayjs(created_at).format("YYYY-MM-DD HH:mm:ss")
          : "-";
      }
    },
    {
      label: "发送时间",
      prop: "sent_at",
      minWidth: 160,
      formatter: ({ sent_at }) => {
        return sent_at ? dayjs(sent_at).format("YYYY-MM-DD HH:mm:ss") : "-";
      }
    },
    {
      label: "状态",
      prop: "status",
      width: 120,
      cellRenderer: ({ row, props }) => (
        <el-tag
          size={props.size}
          type={statusMap[row.status]?.type}
          effect="plain"
        >
          {statusMap[row.status]?.label || row.status}
        </el-tag>
      )
    },
    {
      label: "操作",
      prop: "operation",
      width: 140,
      fixed: "right",
      slot: "operation"
    }
  ];

  return {
    tableRef,
    tableColumns
  };
}
