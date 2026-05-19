import { ref } from "vue";
import type { PlusColumn } from "plus-pro-components";
import type { FormRules } from "element-plus";
import {
  showGroup,
  storeGroup,
  updateGroup,
  GROUP_PARAMS_DEFAULT,
  GROUP_PARAMS_KEYS,
  type GroupParams
} from "@/api/setting";
import { message } from "@shared/utils";
import { pickByKeys } from "@/views/system/utils";

export function useSettingGroupStore(onSuccess) {
  const showGroupForm = ref(false);
  const groupFormRef = ref();
  const groupId = ref(0);
  const groupValues = ref<GroupParams>({});

  const groupColumns: PlusColumn[] = [
    {
      label: "名称",
      prop: "name",
      valueType: "input",
      fieldProps: {
        placeholder: "请输入唯一名称（英文标识符）"
      }
    },
    {
      label: "标题",
      prop: "title",
      valueType: "input",
      fieldProps: {
        placeholder: "请输入组标题"
      }
    },
    {
      label: "描述",
      prop: "description",
      valueType: "input",
      fieldProps: {
        placeholder: "请输入组描述",
        type: "textarea",
        rows: 3
      }
    },
    {
      label: "权重",
      prop: "weight",
      valueType: "input-number",
      fieldProps: {
        placeholder: "请输入权重",
        min: 0,
        max: 10000,
        step: 1,
        controlsPosition: "right"
      }
    }
  ];

  const rules: FormRules = {
    name: [
      { required: true, message: "请输入组名称", trigger: "blur" },
      {
        pattern: /^[a-z0-9_-]+$/i,
        message: "只能包含字母、数字、下划线和连字符",
        trigger: "blur"
      }
    ],
    title: [{ required: true, message: "请输入组标题", trigger: "blur" }],
    weight: [{ required: true, message: "请输入权重", trigger: "blur" }]
  };

  // 打开表单
  function openGroupForm(id = 0) {
    showGroupForm.value = true;

    if (id > 0) {
      // 编辑模式
      groupId.value !== id && handleShowGroup(id);
    } else {
      // 新增模式
      groupFormRef.value?.formInstance?.resetFields();
      groupValues.value = { ...GROUP_PARAMS_DEFAULT };
    }

    groupId.value = id;
  }

  // 提交表单
  function confirmGroupForm() {
    groupFormRef.value?.formInstance?.validate(valid => {
      if (valid) {
        groupId.value > 0 ? handleUpdateGroup() : handleStoreGroup();
      }
    });
  }

  // 关闭表单
  function closeGroupForm() {
    showGroupForm.value = false;
  }

  const handleShowGroup = (id: number) => {
    showGroup(id).then(({ data }) => {
      groupValues.value = pickByKeys<GroupParams>(data, GROUP_PARAMS_KEYS);
    });
  };

  const handleStoreGroup = () => {
    storeGroup(groupValues.value).then(() => {
      message("添加成功", { type: "success" });
      showGroupForm.value = false;
      onSuccess && onSuccess(groupValues.value.name);
    });
  };

  const handleUpdateGroup = () => {
    updateGroup(groupId.value, groupValues.value).then(() => {
      message("更新成功", { type: "success" });
      showGroupForm.value = false;
      onSuccess && onSuccess(groupValues.value.name);
    });
  };

  return {
    groupFormRef,
    showGroupForm,
    groupId,
    groupValues,
    groupColumns,
    rules,
    openGroupForm,
    confirmGroupForm,
    closeGroupForm
  };
}
