import { ref, h, computed } from "vue";
import type { PlusColumn } from "plus-pro-components";
import type { FormRules } from "element-plus";
import {
  show,
  store,
  update,
  FORM_PARAMS_DEFAULT,
  FORM_PARAMS_KEYS,
  type FormParams
} from "@/api/setting";
import {
  ElInput,
  ElInputNumber,
  ElSwitch,
  ElSelect,
  ElOption
} from "element-plus";
import { pickByKeys } from "@/views/system/utils";
import { message } from "@shared/utils";
import ArrayInput from "./ArrayInput.vue";
import KeyValueInput from "./KeyValueInput.vue";

export function useSettingFormStore(onSuccess) {
  const showStore = ref(false);
  const storeRef = ref();
  const storeId = ref(0);
  const storeValues = ref<FormParams>({});

  // 使用计算属性控制表单列的显示
  const storeColumns: PlusColumn[] = [
    {
      label: "键名",
      prop: "key",
      valueType: "input",
      fieldProps: {
        placeholder: "请输入键名"
      }
    },
    {
      label: "类型",
      prop: "type",
      valueType: "select",
      fieldProps: {
        placeholder: "请选择类型"
      },
      options: [
        {
          label: "字符串",
          value: "string"
        },
        {
          label: "整数",
          value: "integer"
        },
        {
          label: "浮点数",
          value: "float"
        },
        {
          label: "布尔值",
          value: "boolean"
        },
        {
          label: "数组",
          value: "array"
        },
        {
          label: "选择",
          value: "select"
        },
        {
          label: "文本",
          value: "base64"
        },
        {
          label: "图片",
          value: "image"
        }
      ],
      onChange: val => handleTypeChange(val as string)
    },
    {
      label: "值",
      prop: "value",
      valueType: "input", // 默认使用input，但会被renderField替代
      fieldProps: {
        placeholder: "请输入值"
      },
      // 根据类型渲染不同的输入组件
      renderField: (value, onChange) => {
        const type = storeValues.value.type;
        const isMultiple = storeValues.value.is_multiple;
        const options = storeValues.value.options || [];

        if (!type) return null;

        // 确保options是数组
        const selectOptions = Array.isArray(options) ? options : [];

        switch (type) {
          case "string":
            return h(ElInput, {
              modelValue: value as string,
              "onUpdate:modelValue": onChange,
              placeholder: "请输入文本"
            });
          case "base64":
            return h(ElInput, {
              modelValue: value as string,
              "onUpdate:modelValue": onChange,
              placeholder: "请输入文本",
              type: "textarea",
              rows: 3
            });
          case "integer":
            return h(ElInputNumber, {
              modelValue: value !== "" ? Number(value) : 0,
              "onUpdate:modelValue": (val: number | undefined) =>
                onChange(val !== undefined ? String(val) : "0"),
              placeholder: "请输入整数",
              precision: 0,
              controls: false,
              style: { width: "100%" }
            });
          case "float":
            return h(ElInputNumber, {
              modelValue: value !== "" ? Number(value) : 0,
              "onUpdate:modelValue": (val: number | undefined) =>
                onChange(val !== undefined ? String(val) : "0"),
              placeholder: "请输入浮点数",
              precision: 2,
              controls: false,
              style: { width: "100%" }
            });
          case "boolean":
            return h(ElSwitch, {
              modelValue: (value === "1" ||
                value === "true" ||
                value === true) as boolean,
              "onUpdate:modelValue": val => onChange(val ? "1" : "0"),
              activeValue: true,
              inactiveValue: false
            });
          case "array":
            return h(ArrayInput, {
              modelValue:
                Array.isArray(value) ||
                (typeof value === "object" && value !== null)
                  ? value
                  : [],
              "onUpdate:modelValue": onChange
            });
          case "image":
            return h(ElInput, {
              modelValue: value as string,
              disabled: true,
              placeholder: "保存设置后，请在设置列表上传图片"
            });
          case "select":
            // 确保值的正确类型
            const selectValue = isMultiple
              ? Array.isArray(value)
                ? value
                : []
              : value;

            return h(
              ElSelect,
              {
                modelValue: selectValue,
                "onUpdate:modelValue": newValue => {
                  onChange(newValue);
                },
                placeholder: `请选择${isMultiple ? "(可多选)" : ""}`,
                multiple: isMultiple,
                filterable: true,
                clearable: true,
                style: { width: "100%" }
              },
              () =>
                selectOptions.map(opt =>
                  h(ElOption, {
                    key: opt.value,
                    label: opt.label,
                    value: opt.value
                  })
                )
            );
          default:
            return h(ElInput, {
              modelValue: value as string,
              "onUpdate:modelValue": onChange,
              placeholder: "请输入值"
            });
        }
      }
    },
    {
      label: "描述",
      prop: "description",
      valueType: "textarea",
      fieldProps: {
        placeholder: "请输入描述",
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
    },
    {
      label: "允许多选",
      prop: "is_multiple",
      valueType: "switch",
      initialValue: false,
      hideInForm: computed(() => storeValues.value.type !== "select"),
      onChange: val => handleMultipleChange(val as boolean)
    },
    {
      label: "选项配置",
      prop: "options",
      hideInForm: computed(() => storeValues.value.type !== "select"),
      renderField: (value, onChange) => {
        // 确保传递给 KeyValueInput 的是数组类型
        const optionsValue = Array.isArray(value) ? value : [];
        return h(KeyValueInput, {
          modelValue: optionsValue,
          "onUpdate:modelValue": onChange
        });
      }
    }
  ];

  // 校验选项格式
  const validateOptions = (_rule, value, callback) => {
    if (storeValues.value.type === "select") {
      if (!value || !Array.isArray(value) || value.length === 0) {
        return callback(new Error("请至少配置一个选项"));
      }

      // 验证数组中的选项都有label和value
      let hasValidItems = false;
      for (const item of value) {
        if (!item || typeof item !== "object") continue;

        if (
          item.label !== undefined &&
          item.label !== null &&
          item.value !== undefined &&
          item.value !== null
        ) {
          hasValidItems = true;
        }
      }

      if (!hasValidItems) {
        return callback(
          new Error("请至少配置一个有效的选项（标签和值都不能为空）")
        );
      }
    }
    callback();
  };

  // 校验 Select 的值
  const validateSelectValue = (_rule, value, callback) => {
    // 如果不是select类型，则不做验证
    if (storeValues.value.type !== "select") {
      return callback();
    }

    // 对于select类型，只要有值就可以了，不再要求必须选择
    if (value === undefined || value === null) {
      return callback(new Error("请选择值"));
    }

    callback();
  };

  const rules = computed<FormRules>(() => {
    const baseRules: FormRules = {
      key: [{ required: true, message: "请输入键名", trigger: "blur" }],
      type: [{ required: true, message: "请选择类型", trigger: "change" }],
      value:
        storeValues.value.type === "image"
          ? []
          : [
              { required: true, message: "请输入值", trigger: "blur" },
              { validator: validateSelectValue, trigger: ["blur", "change"] }
            ],
      weight: [{ required: true, message: "请输入权重", trigger: "blur" }]
    };

    // 仅当类型为 select 时添加 options 校验
    if (storeValues.value.type === "select") {
      baseRules.options = [
        { validator: validateOptions, trigger: ["change", "blur"] }
      ];
    }

    return baseRules;
  });

  // 打开表单
  function openStoreForm(id = 0, groupId = null) {
    showStore.value = true;

    if (id > 0) {
      // 编辑模式
      if (storeId.value !== id) {
        handleShow(id);
      }
    } else {
      // 新增模式 (锁定状态不影响新增时的默认值)
      storeRef.value?.formInstance?.resetFields();
      storeValues.value = {
        ...FORM_PARAMS_DEFAULT,
        is_multiple: false,
        options: [],
        group_id: groupId
      };
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
    show(id).then(({ data }) => {
      // 使用pickByKeys提取数据
      const formData = pickByKeys<FormParams>(data, [...FORM_PARAMS_KEYS]);

      // 确保options是数组
      if (typeof formData.options === "string") {
        try {
          formData.options = JSON.parse(formData.options);
        } catch {
          formData.options = [];
        }
      }

      // 确保多选值是数组
      if (
        formData.type === "select" &&
        formData.is_multiple &&
        typeof formData.value === "string"
      ) {
        try {
          formData.value = JSON.parse(formData.value);
        } catch {
          formData.value = [];
        }
      }

      storeValues.value = formData;
    });
  };

  const handleStore = () => {
    // 确保提交的数据包含 is_multiple 和 options (仅当类型为 select 时)
    const dataToSubmit = { ...storeValues.value };
    if (dataToSubmit.type !== "select") {
      delete dataToSubmit.is_multiple;
      delete dataToSubmit.options;
    }
    store(dataToSubmit).then(() => {
      message("添加成功", { type: "success" });
      showStore.value = false;
      onSuccess && onSuccess();
    });
  };

  const handleUpdate = () => {
    // 确保提交的数据包含 is_multiple 和 options (仅当类型为 select 时)
    const dataToSubmit = { ...storeValues.value };
    if (dataToSubmit.type !== "select") {
      delete dataToSubmit.is_multiple;
      delete dataToSubmit.options;
    }
    update(storeId.value, dataToSubmit).then(() => {
      message("更新成功", { type: "success" });
      showStore.value = false;
      onSuccess && onSuccess();
    });
  };

  // 类型选择组件的处理
  const handleTypeChange = (newType: string) => {
    // 重置值
    switch (newType) {
      case "integer":
        storeValues.value.value = "0";
        break;
      case "float":
        storeValues.value.value = "0.0";
        break;
      case "boolean":
        storeValues.value.value = "0";
        break;
      case "array":
        storeValues.value.value = [];
        break;
      case "select": // 添加 select 类型的默认值处理
        storeValues.value.value = storeValues.value.is_multiple ? [] : ""; // 多选默认为空数组，单选为空字符串
        break;
      default:
        storeValues.value.value = "";
    }

    // 下一个事件循环中清除校验
    setTimeout(() => {
      storeRef.value?.formInstance?.clearValidate("value");
    }, 0);

    // 当类型变为非 select 时，清空 options 和 is_multiple
    if (newType !== "select") {
      storeValues.value.options = [];
      storeValues.value.is_multiple = false;
    }
  };

  // 多选切换处理
  const handleMultipleChange = (newIsMultiple: boolean) => {
    if (storeValues.value.type === "select") {
      storeValues.value.value = newIsMultiple ? [] : "";
      // 下一个事件循环中清除校验
      setTimeout(() => {
        storeRef.value?.formInstance?.clearValidate("value");
      }, 0);
    }
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
}
