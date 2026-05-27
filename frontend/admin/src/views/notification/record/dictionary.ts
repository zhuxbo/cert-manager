// 通知对象类型配置
export interface NotifiableOption {
  label: string;
  value: string;
  remote: {
    uri: string;
    searchField: string;
    labelField: string;
    valueField: string;
    itemsField: string;
    totalField: string;
  };
  fetchDetail?: (id: number) => Promise<any>;
}

// 状态选项
export const statusOptions = [
  { label: "全部", value: "" },
  { label: "待发送", value: "pending" },
  { label: "发送中", value: "sending" },
  { label: "已发送", value: "sent" },
  { label: "失败", value: "failed" }
];

// 多行字段列表
export const multilineFields = [
  "pem",
  "cert",
  "key",
  "ca",
  "list_html",
  "list_plain",
  "result_data",
  "error_message",
  "content"
];

// 判断是否为多行字段
export const isMultilineField = (field: string) =>
  field.includes("html") || multilineFields.includes(field);
