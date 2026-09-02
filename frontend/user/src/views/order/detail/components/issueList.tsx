import {
  action,
  channel,
  status,
  actionType,
  channelType,
  statusType
} from "@/views/order/dictionary";
import dayjs from "dayjs";

export const tableColumns: TableColumnList = [
  {
    label: "证书ID",
    prop: "id",
    minWidth: 140
  },
  {
    label: "通用名称",
    prop: "common_name",
    minWidth: 150
  },
  {
    label: "金额(元)",
    prop: "amount",
    minWidth: 90,
    cellRenderer: ({ row }) => (row.amount > 0 ? row.amount : "-")
  },
  {
    label: "渠道",
    prop: "channel",
    minWidth: 80,
    cellRenderer: ({ row }) => {
      return (
        <el-tag type={channelType[row.channel]}>{channel[row.channel]}</el-tag>
      );
    }
  },
  {
    label: "操作",
    prop: "action",
    minWidth: 80,
    cellRenderer: ({ row }) => {
      return (
        <el-tag type={actionType[row.action]}>{action[row.action]}</el-tag>
      );
    }
  },
  {
    label: "状态",
    prop: "status",
    minWidth: 80,
    cellRenderer: ({ row }) => {
      return (
        <el-tag type={statusType[row.status] || "info"}>
          {status[row.status]}
        </el-tag>
      );
    }
  },
  {
    label: "签发时间",
    prop: "issued_at",
    minWidth: 170,
    formatter: ({ issued_at }) => {
      return issued_at ? dayjs(issued_at).format("YYYY-MM-DD HH:mm:ss") : "-";
    }
  },
  {
    label: "到期时间",
    prop: "expires_at",
    minWidth: 170,
    formatter: ({ expires_at }) => {
      return expires_at ? dayjs(expires_at).format("YYYY-MM-DD HH:mm:ss") : "-";
    }
  }
];
