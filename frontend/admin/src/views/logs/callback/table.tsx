import dayjs from "dayjs";
import Info from "~icons/ri/question-line";

export const tableColumns: TableColumnList = [
  {
    label: "ID",
    prop: "id",
    minWidth: 90
  },
  { label: "模块", prop: "module", minWidth: 100 },
  { label: "操作", prop: "action", minWidth: 120 },
  {
    headerRenderer: () => (
      <div class="flex flex-row items-center">
        <span>请求URL</span>
        <iconifyIconOffline
          icon={Info}
          class="ml-1 cursor-help"
          v-tippy={{
            content: "双击下面请求URL进行拷贝"
          }}
        />
      </div>
    ),
    prop: "url",
    minWidth: 140
  },
  {
    label: "请求方法",
    prop: "method",
    minWidth: 140
  },
  {
    label: "IP 地址",
    prop: "ip",
    minWidth: 100
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
        {row.status === 1 ? "成功" : "失败"}
      </el-tag>
    )
  },
  {
    label: "请求时间",
    prop: "created_at",
    minWidth: 180,
    formatter: ({ created_at }) =>
      created_at ? dayjs(created_at).format("YYYY-MM-DD HH:mm:ss") : "-"
  },
  {
    label: "操作",
    fixed: "right",
    slot: "operation",
    width: 80
  }
];
