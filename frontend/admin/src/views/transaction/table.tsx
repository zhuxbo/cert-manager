import { ref } from "vue";
import { useRouter } from "vue-router";
import dayjs from "dayjs";
import { transactionTypeOptions, transactionTypeMap } from "./dictionary";
import { createUsernameRenderer } from "@/views/system/username";

export function useTransactionTable() {
  const tableRef = ref();
  const router = useRouter();

  const tableColumns: TableColumnList = [
    {
      label: "用户名",
      prop: "user.username",
      width: 100,
      cellRenderer: createUsernameRenderer("user.username")
    },
    {
      label: "类型",
      prop: "type",
      width: 80,
      cellRenderer: ({ row, props }) => (
        <el-tag
          size={props.size}
          type={transactionTypeMap[row.type]}
          effect="plain"
        >
          {transactionTypeOptions.find(item => item.value === row.type)?.label}
        </el-tag>
      )
    },
    {
      label: "单号",
      prop: "transaction_id",
      width: 145,
      cellRenderer: ({ row }) => {
        const handleClick = () => {
          let path = "/funds";
          if (["order", "cancel"].includes(row.type)) {
            path = "/order";
          } else if (["acme_order", "acme_cancel"].includes(row.type)) {
            path = "/acme";
          }
          router.push({
            path,
            query: {
              id: row.transaction_id
            }
          });
        };
        return (
          <span class="cursor-pointer" onClick={handleClick}>
            {row.transaction_id}
          </span>
        );
      }
    },
    {
      label: "交易金额",
      prop: "amount",
      width: 100
    },
    {
      label: "交易前余额",
      prop: "balance_before",
      width: 100
    },
    {
      label: "交易后余额",
      prop: "balance_after",
      width: 100
    },
    {
      label: "备注",
      prop: "remark",
      minWidth: 150
    },
    {
      label: "创建时间",
      prop: "created_at",
      width: 160,
      formatter: ({ created_at }) => {
        return created_at
          ? dayjs(created_at).format("YYYY-MM-DD HH:mm:ss")
          : "-";
      }
    }
  ];

  return {
    tableRef,
    tableColumns
  };
}
