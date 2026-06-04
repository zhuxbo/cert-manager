import "plus-pro-components/es/components/search/style/css";
import type { PlusColumn } from "plus-pro-components";
import { statusOptions } from "./dictionary";

export function useNotificationTemplateSearch() {
  const searchColumns: PlusColumn[] = [
    {
      label: "名称",
      prop: "name",
      valueType: "input",
      fieldProps: {
        clearable: true,
        placeholder: "请输入模板名称"
      }
    },
    {
      label: "标识",
      prop: "code",
      valueType: "input",
      fieldProps: {
        clearable: true,
        placeholder: "如 cert_issued"
      }
    },
    {
      label: "状态",
      prop: "status",
      valueType: "select",
      options: [{ label: "全部", value: "" }, ...statusOptions],
      fieldProps: {
        placeholder: "请选择状态"
      }
    }
  ];

  return {
    searchColumns
  };
}
