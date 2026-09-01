import type { PlusColumn } from "plus-pro-components";
import { getPickerShortcuts } from "../../../shared/utils";

export const searchColumns: PlusColumn[] = [
  {
    label: "操作",
    prop: "action",
    valueType: "input",
    fieldProps: { placeholder: "请输入操作名称" }
  },
  {
    label: "请求URL",
    prop: "url",
    valueType: "input",
    fieldProps: { placeholder: "请输入请求URL" }
  },
  {
    label: "状态",
    prop: "status",
    valueType: "select",
    options: [
      { label: "成功", value: "1" },
      { label: "失败", value: "0" }
    ],
    fieldProps: { placeholder: "请选择状态" }
  },
  {
    label: "参数",
    prop: "params",
    valueType: "input",
    fieldProps: { placeholder: "请输入参数" }
  },
  {
    label: "响应",
    prop: "response",
    valueType: "input",
    fieldProps: { placeholder: "请输入响应" }
  },
  {
    label: "请求方法",
    prop: "method",
    valueType: "select",
    fieldProps: { placeholder: "请选择请求方法" },
    options: [
      { label: "GET", value: "GET" },
      { label: "POST", value: "POST" },
      { label: "OPTIONS", value: "OPTIONS" }
    ]
  },
  {
    label: "IP地址",
    prop: "ip",
    valueType: "input",
    fieldProps: { placeholder: "请输入IP地址" }
  },
  {
    label: "创建时间",
    prop: "created_at",
    valueType: "date-picker",
    fieldProps: {
      type: "daterange",
      rangeSeparator: "至",
      startPlaceholder: "开始日期",
      endPlaceholder: "结束日期",
      valueFormat: "YYYY-MM-DD",
      shortcuts: getPickerShortcuts()
    }
  }
];
