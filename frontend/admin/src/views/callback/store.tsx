import { ref } from "vue";
import type { PlusColumn } from "plus-pro-components";
import {
  show,
  store,
  update,
  FORM_PARAMS_DEFAULT,
  FORM_PARAMS_KEYS,
  type FormParams
} from "@/api/callback";
import type { FormRules } from "element-plus";
import ReRemoteSelect from "@shared/components/ReRemoteSelect";
import { pickByKeys } from "@/views/system/utils";
import { switchOptions } from "@/views/system/dictionary";

export const useCallbackStore = (onSearch: () => void) => {
  const showStore = ref(false);
  const storeRef = ref();
  const storeId = ref(0);
  const storeValues = ref<FormParams>({});

  const storeColumns: PlusColumn[] = [
    {
      label: "用户",
      prop: "user_id",
      valueType: "select",
      renderField: (value, onChange) => {
        return (
          <ReRemoteSelect
            modelValue={value}
            uri="/user"
            searchField="quickSearch"
            labelField="username"
            valueField="id"
            itemsField="items"
            totalField="total"
            placeholder="请选择用户"
            onChange={onChange}
            refreshKey={storeId.value}
          />
        );
      }
    },
    {
      label: "URL",
      prop: "url",
      valueType: "input",
      fieldProps: {
        placeholder: "请输入回调URL"
      }
    },
    {
      label: "认证令牌",
      prop: "token",
      valueType: "input",
      fieldProps: {
        placeholder: "留空表示不修改，填写则更新"
      }
    },
    {
      label: "状态",
      prop: "status",
      valueType: "switch",
      fieldProps: switchOptions
    }
  ];

  const rules: FormRules = {
    user_id: [
      { required: true, message: "请选择用户" },
      { type: "number", min: 1, message: "用户ID必须大于0" }
    ],
    url: [
      { required: true, message: "请输入回调URL" },
      {
        pattern: /^https?:\/\/.+/,
        message: "请输入有效的URL地址（以http://或https://开头）",
        trigger: "blur"
      }
    ],
    token: [
      {
        required: true,
        message: "请输入认证令牌",
        trigger: "blur"
      }
    ],
    status: [{ required: true, message: "请选择状态" }]
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
        Object.entries(FORM_PARAMS_DEFAULT).filter(
          ([_, value]) => value !== 0 && value !== ""
        )
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
    show(id).then(res => {
      storeValues.value = pickByKeys<FormParams>(res.data, FORM_PARAMS_KEYS);
    });
  };

  const handleStore = () => {
    store(storeValues.value).then(() => {
      onSearch();
      showStore.value = false;
    });
  };

  const handleUpdate = () => {
    // token 留空表示不修改：后端详情已隐藏 token，编辑态默认空；为空则不提交该字段，
    // 避免把现有 token 覆盖成空串（Callback token 无访问器，空串会使回调失去 token 鉴权）
    const payload = { ...storeValues.value };
    if (!payload.token) delete payload.token;
    update(storeId.value, payload).then(() => {
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
