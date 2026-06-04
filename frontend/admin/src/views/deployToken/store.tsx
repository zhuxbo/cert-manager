import { h, ref } from "vue";
import type { PlusColumn } from "plus-pro-components";
import {
  show,
  store,
  update,
  FORM_PARAMS_DEFAULT,
  FORM_PARAMS_KEYS,
  type FormParams
} from "@/api/deployToken";
import { ElButton, type FormRules } from "element-plus";
import ReRemoteSelect from "@shared/components/ReRemoteSelect";
import { pickByKeys } from "@/views/system/utils";
import { switchOptions } from "@/views/system/dictionary";
import IpArrayInput from "../apiToken/IpArrayInput";
import { uuid } from "@pureadmin/utils";

export const useDeployTokenStore = (onSearch: () => void) => {
  const showStore = ref(false);
  const storeRef = ref();
  const storeId = ref(0);
  const storeValues = ref<FormParams>({});
  const ipArrayInputRef = ref();

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
      label: "Token",
      prop: "token",
      valueType: "input",
      fieldProps: {
        placeholder: "留空表示不修改，填写或点「生成」则更新"
      },
      fieldSlots: {
        append: () =>
          h(
            ElButton,
            {
              onClick: () => {
                storeValues.value.token = uuid(32);
              }
            },
            () => "生成"
          )
      }
    },
    {
      label: "IP白名单",
      prop: "allowed_ips",
      renderField: (value, onChange) => {
        const arrayValue = Array.isArray(value) ? value : value ? [value] : [];
        return (
          <IpArrayInput
            ref={ipArrayInputRef}
            modelValue={arrayValue}
            onUpdate:modelValue={onChange}
            maxItems={10}
          />
        );
      }
    },
    {
      label: "频率限制",
      prop: "rate_limit",
      valueType: "input-number",
      fieldProps: {
        placeholder: "请输入频率限制/分钟",
        min: 1,
        max: 10000
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
    user_id: [{ required: true, message: "请选择用户" }],
    token: [{ len: 32, message: "Token 必须为32个字符" }],
    rate_limit: [{ type: "number", min: 1, message: "频率限制必须大于0" }],
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
    storeRef.value?.formInstance?.validate(valid => {
      if (valid) {
        storeId.value > 0 ? handleUpdate() : handleStore();
      }
    });
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
    // token 留空表示不修改：后端详情已隐藏 token 明文，编辑态默认空；为空则不提交该字段，
    // 避免把现有 token 覆盖成空（后端 setTokenAttribute('') 会清空 token+token_hash 使令牌失效）
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
