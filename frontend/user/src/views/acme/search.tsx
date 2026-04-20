import "plus-pro-components/es/components/search/style/css";
import { ref } from "vue";
import { debounce } from "lodash-es";
import { getPickerShortcuts } from "@shared/utils";
import type { PlusColumn } from "plus-pro-components";
import {
  statusOptions,
  statusSetOptions,
  ActivatingStatusOptions,
  ArchivedStatusOptions
} from "./dictionary";
import { periodOptions } from "@/views/system/dictionary";

export function useAcmeSearch(onSearch, search) {
  const debouncedSearch = debounce(() => {
    onSearch();
  }, 500);

  const getStatusOptionsBySet = statusSet => {
    if (statusSet === "all") {
      return statusOptions;
    } else if (statusSet === "archived") {
      return ArchivedStatusOptions;
    } else {
      return ActivatingStatusOptions;
    }
  };

  const currentStatusOptions = ref(ActivatingStatusOptions);

  const searchColumns: PlusColumn[] = [
    {
      label: "快速搜索",
      prop: "quickSearch",
      valueType: "input",
      fieldProps: {
        placeholder: "产品/EAB KID/订单号/备注"
      },
      onChange: () => {
        debouncedSearch();
      }
    },
    {
      label: "状态集",
      prop: "statusSet",
      valueType: "select",
      fieldProps: {
        placeholder: "活动中"
      },
      options: statusSetOptions,
      onChange: value => {
        search.value.status = undefined;
        currentStatusOptions.value = getStatusOptionsBySet(value);
        debouncedSearch();
      }
    },
    {
      label: "状态",
      prop: "status",
      valueType: "select",
      fieldProps: {
        placeholder: "请选择状态"
      },
      get options() {
        return currentStatusOptions.value;
      },
      onChange: () => {
        debouncedSearch();
      }
    },
    {
      label: "EAB KID",
      prop: "eab_kid",
      valueType: "input",
      fieldProps: {
        placeholder: "前缀匹配"
      }
    },
    {
      label: "ID",
      prop: "id",
      valueType: "input",
      fieldProps: {
        placeholder: "请输入ID"
      }
    },
    {
      label: "周期",
      prop: "period",
      valueType: "select",
      fieldProps: {
        placeholder: "请选择周期"
      },
      options: periodOptions
    },
    {
      label: "金额",
      prop: "amount",
      renderField: (value, onChange) => {
        const validateAndUpdate = (
          minVal: number | undefined,
          maxVal: number | undefined
        ) => {
          if (minVal !== undefined && maxVal !== undefined && minVal > maxVal) {
            return;
          }
          onChange([minVal, maxVal]);
        };

        return (
          <div class="flex items-center gap-2 w-full">
            <el-input
              class="flex-1"
              modelValue={value?.[0]?.toString()}
              onUpdate:modelValue={val => {
                const num = val === "" ? undefined : Number(val);
                validateAndUpdate(num, value?.[1]);
              }}
              placeholder="最小金额"
              clearable
            />
            <span class="flex-none">至</span>
            <el-input
              class="flex-1"
              modelValue={value?.[1]?.toString()}
              onUpdate:modelValue={val => {
                const num = val === "" ? undefined : Number(val);
                validateAndUpdate(value?.[0], num);
              }}
              placeholder="最大金额"
              clearable
            />
          </div>
        );
      }
    },
    {
      label: "产品名称",
      prop: "product_name",
      valueType: "input",
      fieldProps: {
        placeholder: "请输入产品名称"
      }
    },
    {
      label: "品牌",
      prop: "brand",
      valueType: "input",
      fieldProps: {
        placeholder: "请输入品牌"
      },
      onChange: () => {
        debouncedSearch();
      }
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
    },
    {
      label: "到期时间",
      prop: "period_till",
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

  return {
    searchColumns
  };
}
