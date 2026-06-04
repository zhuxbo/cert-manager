import { ref, computed, resolveComponent, h, type Ref } from "vue";
import type { PlusColumn } from "plus-pro-components";
import type { FormRules } from "element-plus";
import {
  show,
  store,
  update,
  showUser,
  FORM_PARAMS_DEFAULT,
  FORM_PARAMS_KEYS,
  type FormParams
} from "../../api/invoice";
import { pickByKeys } from "../../shared/utils";

export function useInvoiceStore(onSearch: () => void) {
  const showStore = ref(false);
  const storeRef = ref();
  const storeId = ref(0);
  const storeValues = ref<FormParams>({});
  const isEdit = computed(() => storeId.value > 0);
  const status = ref(0);

  const statusOptions: Ref<{ label: string; value: number }[]> = computed(
    () => {
      if (status.value === 0)
        return [
          { label: "处理中", value: 0 },
          { label: "已开票", value: 1 }
        ];
      if (status.value === 1)
        return [
          { label: "已开票", value: 1 },
          { label: "已作废", value: 2 }
        ];
      return [{ label: "已作废", value: 2 }];
    }
  );

  const storeColumns: PlusColumn[] = [
    {
      label: "用户名",
      prop: "user_id",
      valueType: "select",
      renderField: (value: any, onChange: any) => {
        const RemoteSelect = resolveComponent("ReRemoteSelect");
        return h(RemoteSelect, {
          modelValue: value,
          uri: "/user",
          searchField: "quickSearch",
          labelField: "username",
          valueField: "id",
          itemsField: "items",
          totalField: "total",
          placeholder: "请选择用户",
          onChange: (val: any) => {
            onChange(val);
            if (val) {
              showUser(val)
                .then((res: any) => {
                  if (res.data?.email) {
                    storeValues.value.email = res.data.email;
                  }
                })
                .catch(() => {});
            }
          },
          refreshKey: storeId.value,
          disabled: isEdit.value
        });
      }
    },
    {
      label: "金额",
      prop: "amount",
      valueType: "input-number",
      fieldProps: {
        placeholder: "请输入金额",
        min: 0,
        precision: 2,
        controlsPosition: "right",
        get disabled() {
          return isEdit.value;
        }
      }
    },
    {
      label: "组织",
      prop: "organization",
      valueType: "input",
      fieldProps: {
        placeholder: "请输入组织名称",
        get disabled() {
          return isEdit.value;
        }
      }
    },
    {
      label: "税号",
      prop: "taxation",
      valueType: "input",
      fieldProps: {
        placeholder: "请输入税号",
        get disabled() {
          return isEdit.value;
        }
      }
    },
    {
      label: "邮箱",
      prop: "email",
      valueType: "input",
      fieldProps: {
        placeholder: "请输入邮箱",
        get disabled() {
          return isEdit.value;
        }
      }
    },
    {
      label: "备注",
      prop: "remark",
      valueType: "textarea",
      fieldProps: {
        placeholder: "请输入备注",
        rows: 3
      }
    },
    {
      label: "状态",
      prop: "status",
      valueType: "select",
      options: statusOptions as any,
      fieldProps: {
        placeholder: "请选择状态",
        get disabled() {
          return status.value >= 2;
        }
      }
    }
  ];

  const rules: FormRules = {
    user_id: [{ required: true, message: "请选择用户", trigger: "blur" }],
    amount: [
      { required: true, message: "请输入金额", trigger: "blur" },
      { type: "number", message: "金额必须为数字", trigger: "blur" }
    ],
    organization: [
      { required: true, message: "请输入组织名称", trigger: "blur" }
    ],
    taxation: [{ required: true, message: "请输入税号", trigger: "blur" }],
    email: [
      { required: true, message: "请输入邮箱", trigger: "blur" },
      { type: "email", message: "请输入正确的邮箱格式", trigger: "blur" }
    ],
    status: [{ required: true, message: "请选择状态", trigger: "blur" }]
  };

  function openStoreForm(id = 0) {
    showStore.value = true;
    if (id > 0) {
      storeId.value !== id && handleShow(id);
    } else {
      storeRef.value?.formInstance?.resetFields();
      storeValues.value = Object.fromEntries(
        Object.entries(FORM_PARAMS_DEFAULT).filter(
          ([_, value]) => value !== 0 && value !== ""
        )
      );
      status.value = 0;
    }
    storeId.value = id;
  }

  function confirmStoreForm() {
    storeId.value > 0 ? handleUpdate() : handleStore();
  }

  function closeStoreForm() {
    showStore.value = false;
  }

  const handleShow = (id: number) => {
    show(id).then((res: any) => {
      storeValues.value = pickByKeys<FormParams>(res.data, FORM_PARAMS_KEYS);
      storeValues.value.amount = Number(storeValues.value.amount ?? 0);
      status.value = storeValues.value.status ?? 0;
    });
  };

  const handleStore = () => {
    store(storeValues.value).then(() => {
      onSearch();
      showStore.value = false;
    });
  };

  const handleUpdate = () => {
    update(storeId.value, storeValues.value).then(() => {
      onSearch();
      showStore.value = false;
    });
  };

  return {
    showStore,
    storeRef,
    storeId,
    storeValues,
    storeColumns,
    rules,
    openStoreForm,
    confirmStoreForm,
    closeStoreForm
  };
}
