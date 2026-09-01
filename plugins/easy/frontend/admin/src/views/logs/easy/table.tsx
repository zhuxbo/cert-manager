import dayjs from "dayjs";

export const tableColumns: any[] = [
  { label: "ID", prop: "id", minWidth: 90 },
  { label: "操作", prop: "action", minWidth: 140 },
  { label: "请求URL", prop: "url", minWidth: 140 },
  { label: "请求方法", prop: "method", minWidth: 140 },
  { label: "IP 地址", prop: "ip", minWidth: 100 },
  {
    label: "状态",
    prop: "status",
    minWidth: 100,
    cellRenderer: ({ row, props }: any) => (
      <el-tag
        size={props.size}
        type={row.status === 1 ? "success" : "danger"}
        effect="plain"
      >
        {row.status === 1 ? "成功" : "失败"}
      </el-tag>
    )
  },
  {
    label: "请求时间",
    prop: "created_at",
    minWidth: 180,
    formatter: ({ created_at }: any) =>
      created_at ? dayjs(created_at).format("YYYY-MM-DD HH:mm:ss") : "-"
  },
  { label: "操作", fixed: "right", slot: "operation", width: 80 }
];
