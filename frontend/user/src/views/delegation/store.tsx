import { ref, h } from "vue";
import type { PlusColumn } from "plus-pro-components";
import {
  store,
  batchStore,
  FORM_PARAMS_DEFAULT,
  type FormParams,
  type BatchStoreParams
} from "@/api/delegation";
import type { FormRules } from "element-plus";
import { message } from "@shared/utils";
import { caOptionsAll } from "@/views/system/dictionary";
import type { CnameGuideOptions } from "@/views/delegation/CnameGuide";
import isDomain from "validator/lib/isFQDN";
import { createDomainValidator } from "@shared/utils/domain";

const validateZone = createDomainValidator(isDomain);

export const useDelegationStore = (
  onSearch: () => void,
  showCnameGuide: (options: CnameGuideOptions) => void
) => {
  const showStore = ref(false);
  const storeRef = ref();
  const storeId = ref(0);
  const storeValues = ref<FormParams>({});

  const storeColumns: PlusColumn[] = [
    {
      label: "委托域",
      prop: "zone",
      valueType: "input",
      fieldProps: {
        placeholder: "例如: example.com"
      },
      renderExtra: () =>
        h("div", {
          style: {
            fontSize: "12px",
            color: "#909399",
            marginTop: "5px"
          }
        })
    },
    {
      label: "CA",
      prop: "ca",
      valueType: "select",
      options: caOptionsAll,
      fieldProps: {
        placeholder: "请选择 CA"
      }
    }
  ];

  const rules: FormRules = {
    zone: [
      { required: true, message: "请输入委托域", trigger: "blur" },
      { validator: validateZone, trigger: "blur" }
    ],
    ca: [{ required: true, message: "请选择 CA", trigger: "change" }]
  };

  // 打开表单
  function openStoreForm(id = 0) {
    showStore.value = true;

    if (id > 0) {
      // 委托记录不支持编辑
      message("委托记录不支持编辑，请删除后重新创建", {
        type: "warning"
      });
      showStore.value = false;
      return;
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
    handleStore();
  }

  // 关闭表单
  function closeStoreForm() {
    showStore.value = false;
  }

  const handleStore = () => {
    store(storeValues.value).then(res => {
      onSearch();
      showStore.value = false;

      // 显示 CNAME 配置指引
      if (res.data && res.data.cname_to) {
        showCnameGuide(res.data);
      }
    });
  };

  // 批量创建相关
  const showBatchStore = ref(false);
  const batchStoreValues = ref<BatchStoreParams>({ zones: "", ca: "" });

  const batchStoreColumns: PlusColumn[] = [
    {
      label: "域名列表",
      prop: "zones",
      valueType: "textarea",
      fieldProps: {
        placeholder: "每行一个域名，例如:\nexample.com\ntest.com",
        rows: 6
      },
      renderExtra: () =>
        h(
          "div",
          {
            style: {
              fontSize: "12px",
              color: "#909399",
              marginTop: "5px"
            }
          },
          "支持逗号、换行符分隔多个域名"
        )
    },
    {
      label: "CA",
      prop: "ca",
      valueType: "select",
      options: caOptionsAll,
      fieldProps: {
        placeholder: "请选择 CA"
      }
    }
  ];

  const batchStoreRules: FormRules = {
    zones: [{ required: true, message: "请输入域名列表", trigger: "blur" }],
    ca: [{ required: true, message: "请选择 CA", trigger: "change" }]
  };

  function openBatchStoreForm() {
    showBatchStore.value = true;
    batchStoreValues.value = { zones: "", ca: "" };
  }

  function confirmBatchStoreForm() {
    handleBatchStore();
  }

  function closeBatchStoreForm() {
    showBatchStore.value = false;
  }

  const handleBatchStore = () => {
    batchStore(batchStoreValues.value).then(res => {
      onSearch();
      showBatchStore.value = false;

      if (res.data) {
        const { success_count, fail_count } = res.data;
        if (fail_count > 0) {
          message(`创建完成：成功 ${success_count} 个，失败 ${fail_count} 个`, {
            type: "warning"
          });
        } else {
          message(`成功创建 ${success_count} 个委托记录`, { type: "success" });
        }
      }
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
    closeStoreForm,
    showBatchStore,
    batchStoreValues,
    batchStoreColumns,
    batchStoreRules,
    openBatchStoreForm,
    confirmBatchStoreForm,
    closeBatchStoreForm
  };
};
