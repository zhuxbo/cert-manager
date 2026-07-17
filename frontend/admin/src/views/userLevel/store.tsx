import { ref } from "vue";
import type { PlusColumn } from "plus-pro-components";
import {
  show,
  store,
  update,
  FORM_PARAMS_DEFAULT,
  FORM_PARAMS_KEYS,
  type FormParams,
  type MutationParams
} from "@/api/userLevel";
import type { FormRules } from "element-plus";
import { pickByKeys } from "@/views/system/utils";

export const useUserLevelStore = (onSearch: () => void) => {
  const showStore = ref(false);
  const storeRef = ref();
  const storeId = ref(0);
  const storeValues = ref<FormParams>({});

  const storeColumns: PlusColumn[] = [
    {
      label: "编码",
      prop: "code",
      valueType: "input",
      fieldProps: {
        placeholder: "请输入编码"
      }
    },
    {
      label: "名称",
      prop: "name",
      valueType: "input",
      fieldProps: {
        placeholder: "请输入名称"
      }
    },
    {
      label: "定制",
      prop: "custom",
      valueType: "select",
      options: [
        {
          label: "是",
          value: 1
        },
        {
          label: "否",
          value: 0
        }
      ],
      fieldProps: {
        placeholder: "请选择是否定制"
      }
    },
    {
      label: "权重",
      prop: "weight",
      valueType: "input-number",
      fieldProps: {
        placeholder: "请输入权重",
        min: 1,
        max: 10000,
        controlsPosition: "right"
      }
    },
    {
      label: "成本价倍率",
      prop: "cost_rate",
      valueType: "input-number",
      fieldProps: {
        placeholder: "请输入成本价倍率",
        min: 1,
        max: 99.9999,
        precision: 4,
        step: 0.01,
        controlsPosition: "right"
      }
    }
  ];

  const rules: FormRules = {
    code: [
      { required: true, message: "请输入编码" },
      {
        min: 3,
        max: 50,
        message: "编码长度在 3 到 50 个字符",
        trigger: "blur"
      }
    ],
    name: [
      { required: true, message: "请输入名称" },
      {
        min: 3,
        max: 100,
        message: "名称长度在 3 到 100 个字符",
        trigger: "blur"
      }
    ],
    custom: [{ required: true, message: "请选择类型" }],
    weight: [
      { required: true, message: "请输入权重" },
      {
        type: "number",
        message: "请输入数字",
        trigger: "blur"
      }
    ],
    cost_rate: [
      { required: true, message: "请输入成本价倍率" },
      {
        type: "number",
        min: 1,
        max: 99.9999,
        message: "成本价倍率必须在1至99.9999之间",
        trigger: "blur"
      }
    ]
  };

  // 打开表单
  function openStoreForm(id = 0) {
    showStore.value = true;
    if (id > 0) {
      storeId.value !== id && handleShow(id);
    } else {
      storeRef.value?.formInstance?.resetFields();
      // 过滤空值和0
      storeValues.value = Object.fromEntries(
        Object.entries(FORM_PARAMS_DEFAULT).filter(([_, value]) => value !== "")
      );
    }
    storeId.value = id;
  }

  // 提交表单
  function confirmStoreForm() {
    storeId.value > 0 ? handleUpdate() : handleStore();
  }

  // 关闭表单
  function closeStoreForm() {
    showStore.value = false;
  }

  const handleShow = (id: number) => {
    show(id).then(({ data }) => {
      const values = pickByKeys<FormParams>(data, FORM_PARAMS_KEYS);
      storeValues.value = {
        ...values,
        cost_rate: Number(values.cost_rate)
      };
    });
  };

  const mutationParams = (): MutationParams => {
    const values = { ...storeValues.value };
    const costRate = values.cost_rate;

    return {
      ...values,
      cost_rate:
        costRate === undefined
          ? undefined
          : Number(costRate)
              .toFixed(4)
              .replace(/\.?0+$/, "")
    };
  };

  const handleStore = () => {
    store(mutationParams()).then(() => {
      onSearch();
      showStore.value = false;
    });
  };

  const handleUpdate = () => {
    update(storeId.value, mutationParams()).then(() => {
      onSearch();
      showStore.value = false;
    });
  };

  return {
    storeRef,
    showStore,
    storeId,
    storeValues,
    storeColumns,
    rules,
    openStoreForm,
    confirmStoreForm,
    closeStoreForm
  };
};
