import { ref, computed } from "vue";
import type { PlusColumn } from "plus-pro-components";
import {
  show,
  store,
  update,
  FORM_PARAMS_DEFAULT,
  FORM_PARAMS_KEYS,
  type FormParams
} from "@/api/product";
import type { FormRules } from "element-plus";
import { pickByKeys } from "@/views/system/utils";
import {
  brandOptionsAll,
  caOptions,
  warrantyCurrencyOptions,
  encryptionStandardOptions,
  encryptionAlgOptions,
  signatureDigestAlgOptions,
  validationTypeOptions,
  productTypeOptions,
  nameTypeOptions,
  validationMethodOptions,
  periodOptions,
  switchOptions
} from "@/views/system/dictionary";
import dayjs from "dayjs";
import { filterFormFieldsByConfig } from "./filter";

export const useProductStore = (onSearch: () => void, sourcesList: any) => {
  const showStore = ref(false);
  const storeRef = ref();
  const storeId = ref(0);
  const storeValues = ref<FormParams>({});

  // 判断是否为 SSL 产品
  const isSSL = computed(
    () =>
      !storeValues.value.product_type ||
      storeValues.value.product_type === "ssl"
  );

  // 判断是否为代码签名产品
  const isCodeSign = computed(
    () => storeValues.value.product_type === "codesign"
  );

  // 判断是否为文档签名产品
  const isDocSign = computed(
    () => storeValues.value.product_type === "docsign"
  );

  // 判断是否为 SMIME 产品
  const isSMIME = computed(() => storeValues.value.product_type === "smime");

  // 判断是否为 ACME 产品
  const isACME = computed(() => storeValues.value.product_type === "acme");

  // 需要域名数量配置的产品类型（SSL 和 ACME）
  const needsDomainConfig = computed(() => isSSL.value || isACME.value);

  // SMIME 产品 code 必须包含的类型标记
  const SMIME_CODE_TYPES = ["mailbox", "individual", "sponsor", "organization"];

  // SMIME code 校验函数
  const validateSMIMECode = (_rule: any, value: string, callback: any) => {
    if (!isSMIME.value) {
      callback();
      return;
    }
    if (!value) {
      callback(new Error("请输入编码"));
      return;
    }
    const lowerCode = value.toLowerCase();
    const hasValidType = SMIME_CODE_TYPES.some(type =>
      lowerCode.includes(type)
    );
    if (!hasValidType) {
      callback(
        new Error(
          "S/MIME 产品编码必须包含以下标记之一: mailbox, individual, sponsor, organization"
        )
      );
      return;
    }
    callback();
  };

  // checkbox 字段过滤配置
  const checkboxFieldFilters = computed(() => [
    { field: "encryption_alg", options: encryptionAlgOptions },
    { field: "signature_digest_alg", options: signatureDigestAlgOptions },
    { field: "common_name_types", options: nameTypeOptions },
    { field: "alternative_name_types", options: nameTypeOptions },
    { field: "validation_methods", options: validationMethodOptions },
    { field: "periods", options: periodOptions }
  ]);

  // 动态表单列配置
  const storeColumns = computed((): PlusColumn[] => [
    {
      label: "名称",
      prop: "name",
      valueType: "input",
      fieldProps: {
        placeholder: "请输入名称"
      }
    },
    {
      label: "编码",
      prop: "code",
      valueType: "input",
      fieldProps: {
        placeholder: "请输入编码"
      }
    },
    {
      label: "API ID",
      prop: "api_id",
      valueType: "input",
      fieldProps: {
        placeholder: "请输入API ID"
      }
    },
    {
      label: "来源",
      prop: "source",
      valueType: "select",
      fieldProps: {
        placeholder: "请选择来源"
      },
      options: sourcesList
    },
    {
      label: "品牌",
      prop: "brand",
      valueType: "select",
      fieldProps: {
        placeholder: "请选择品牌"
      },
      options: brandOptionsAll
    },
    {
      label: "签发机构",
      prop: "ca",
      valueType: "select",
      fieldProps: {
        placeholder: "请选择签发机构"
      },
      options: caOptions
    },
    {
      label: "产品类型",
      prop: "product_type",
      valueType: "select",
      fieldProps: {
        placeholder: "请选择产品类型"
      },
      options: productTypeOptions
    },
    {
      label: "保险币种",
      prop: "warranty_currency",
      valueType: "select",
      fieldProps: {
        placeholder: "请选择保险币种"
      },
      options: warrantyCurrencyOptions,
      hideInForm: !isSSL.value
    },
    {
      label: "保险金额",
      prop: "warranty",
      valueType: "input-number",
      fieldProps: {
        placeholder: "请输入保险金额",
        min: 0,
        precision: 2,
        controlsPosition: "right"
      },
      hideInForm: !isSSL.value
    },
    {
      label: "服务器数量",
      prop: "server",
      valueType: "input-number",
      fieldProps: {
        placeholder: "请输入限制服务器数量",
        min: 0,
        precision: 0,
        controlsPosition: "right"
      },
      tooltip: "0为不限制",
      hideInForm: !isSSL.value
    },
    {
      label: "加密标准",
      prop: "encryption_standard",
      valueType: "radio",
      fieldProps: {
        placeholder: "请选择加密标准"
      },
      options: encryptionStandardOptions
    },
    {
      label: "加密算法",
      prop: "encryption_alg",
      valueType: "checkbox",
      fieldProps: {
        placeholder: "请选择加密算法",
        multiple: true
      },
      options: encryptionAlgOptions,
      hideInForm: isACME.value
    },
    {
      label: "签名摘要算法",
      prop: "signature_digest_alg",
      valueType: "checkbox",
      fieldProps: {
        placeholder: "请选择签名摘要算法",
        multiple: true
      },
      options: signatureDigestAlgOptions,
      hideInForm: isACME.value
    },
    {
      label: "验证类型",
      prop: "validation_type",
      valueType: "radio",
      fieldProps: {
        placeholder: "请选择验证类型"
      },
      options: validationTypeOptions
    },
    {
      label: "通用名称类型",
      prop: "common_name_types",
      valueType: "checkbox",
      fieldProps: {
        placeholder: "请选择通用名称类型",
        multiple: true
      },
      options: nameTypeOptions,
      hideInForm: !isSSL.value && !isACME.value
    },
    {
      label: "备用名称类型",
      prop: "alternative_name_types",
      valueType: "checkbox",
      fieldProps: {
        placeholder: "请选择备用名称类型",
        multiple: true
      },
      options: nameTypeOptions,
      hideInForm: !isSSL.value && !isACME.value
    },
    {
      label: "验证方法",
      prop: "validation_methods",
      valueType: "checkbox",
      fieldProps: {
        placeholder: "请选择验证方法",
        multiple: true
      },
      options: validationMethodOptions,
      hideInForm: !isSSL.value
    },
    {
      label: "周期",
      prop: "periods",
      valueType: "checkbox",
      fieldProps: {
        placeholder: "请选择周期",
        multiple: true
      },
      options: periodOptions
    },
    {
      label: "标准域名起始个数",
      prop: "standard_min",
      valueType: "input-number",
      fieldProps: {
        placeholder: "请输入标准域名起始个数",
        min: 0,
        precision: 0,
        controlsPosition: "right"
      },
      hideInForm: !needsDomainConfig.value
    },
    {
      label: "标准域名最大个数",
      prop: "standard_max",
      valueType: "input-number",
      fieldProps: {
        placeholder: "请输入标准域名最大个数",
        min: 0,
        precision: 0,
        controlsPosition: "right"
      },
      hideInForm: !needsDomainConfig.value
    },
    {
      label: "通配符起始个数",
      prop: "wildcard_min",
      valueType: "input-number",
      fieldProps: {
        placeholder: "请输入通配符起始个数",
        min: 0,
        precision: 0,
        controlsPosition: "right"
      },
      hideInForm: !needsDomainConfig.value
    },
    {
      label: "通配符最大个数",
      prop: "wildcard_max",
      valueType: "input-number",
      fieldProps: {
        placeholder: "请输入通配符最大个数",
        min: 0,
        precision: 0,
        controlsPosition: "right"
      },
      hideInForm: !needsDomainConfig.value
    },
    {
      label: "总域名起始个数",
      prop: "total_min",
      valueType: "input-number",
      fieldProps: {
        placeholder: "请输入总域名起始个数",
        min: 0,
        precision: 0,
        controlsPosition: "right"
      },
      hideInForm: !needsDomainConfig.value
    },
    {
      label: "总域名最大个数",
      prop: "total_max",
      valueType: "input-number",
      fieldProps: {
        placeholder: "请输入总域名最大个数",
        min: 0,
        precision: 0,
        controlsPosition: "right"
      },
      hideInForm: !needsDomainConfig.value
    },
    {
      label: "添加SAN",
      prop: "add_san",
      valueType: "switch",
      fieldProps: switchOptions,
      hideInForm: !isSSL.value
    },
    {
      label: "替换SAN",
      prop: "replace_san",
      valueType: "switch",
      fieldProps: switchOptions,
      hideInForm: !isSSL.value
    },
    {
      label: "重新签发",
      prop: "reissue",
      valueType: "switch",
      fieldProps: switchOptions,
      hideInForm: isCodeSign.value || isDocSign.value || isACME.value // CodeSign、DocSign、ACME 不支持重签
    },
    {
      label: "续期",
      prop: "renew",
      valueType: "switch",
      fieldProps: switchOptions,
      hideInForm: isACME.value
    },
    {
      label: "重用CSR",
      prop: "reuse_csr",
      valueType: "switch",
      fieldProps: switchOptions,
      hideInForm: isACME.value
    },
    {
      label: "赠送根域名",
      prop: "gift_root_domain",
      valueType: "switch",
      fieldProps: switchOptions,
      hideInForm: !isSSL.value
    },
    {
      label: "退款期限",
      prop: "refund_period",
      valueType: "input-number",
      fieldProps: {
        placeholder: "请输入退款期限",
        min: 0,
        precision: 0,
        controlsPosition: "right"
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
      label: "排序权重",
      prop: "weight",
      valueType: "input-number",
      fieldProps: {
        placeholder: "请输入排序权重",
        min: 0,
        precision: 0,
        controlsPosition: "right"
      },
      tooltip: "排序权重越小，越靠前"
    },
    {
      label: "状态",
      prop: "status",
      valueType: "switch",
      fieldProps: switchOptions
    },
    {
      label: "创建时间",
      prop: "created_at",
      hideInForm: storeId.value === 0,
      renderField: value =>
        value && typeof value === "string"
          ? dayjs(value).format("YYYY-MM-DD HH:mm:ss")
          : ""
    },
    {
      label: "更新时间",
      prop: "updated_at",
      hideInForm: storeId.value === 0,
      renderField: value =>
        value && typeof value === "string"
          ? dayjs(value).format("YYYY-MM-DD HH:mm:ss")
          : ""
    }
  ]);

  // 动态验证规则
  const rules = computed((): FormRules => {
    const baseRules: FormRules = {
      code: [
        { required: true, message: "请输入编码" },
        { max: 50, message: "编码长度不能超过50个字符", trigger: "blur" },
        { validator: validateSMIMECode, trigger: "blur" }
      ],
      name: [
        { required: true, message: "请输入名称" },
        { max: 100, message: "名称长度不能超过100个字符", trigger: "blur" }
      ],
      api_id: [
        { required: true, message: "请输入API ID" },
        { max: 128, message: "API ID长度不能超过128个字符", trigger: "blur" }
      ],
      source: [{ required: true, message: "请选择来源" }],
      brand: [{ required: true, message: "请选择品牌" }],
      ca: [{ required: true, message: "请选择签发机构" }],
      product_type: [{ required: true, message: "请选择产品类型" }],
      validation_type: [{ required: true, message: "请选择验证类型" }],
      periods: [{ required: true, message: "请选择周期" }],
      refund_period: [{ required: true, message: "请输入退款期限" }],
      weight: [{ required: true, message: "请输入排序权重" }],
      remark: [
        { max: 500, message: "备注长度不能超过500个字符", trigger: "blur" }
      ],
      status: [{ required: true, message: "请选择状态" }]
    };

    // 所有产品类型都需要加密标准
    baseRules.encryption_standard = [
      { required: true, message: "请选择加密标准" }
    ];

    // ACME 产品不需要加密算法和签名摘要算法（由 ACME 客户端自行决定）
    if (!isACME.value) {
      baseRules.encryption_alg = [
        { required: true, message: "请选择加密算法" }
      ];
      baseRules.signature_digest_alg = [
        { required: true, message: "请选择签名摘要算法" }
      ];
    }

    // SSL 和 ACME 产品共用的域名数量验证规则
    if (needsDomainConfig.value) {
      baseRules.standard_min = [
        { required: true, message: "请输入标准域名起始个数" }
      ];
      baseRules.standard_max = [
        { required: true, message: "请输入标准域名最大个数" }
      ];
      baseRules.wildcard_min = [
        { required: true, message: "请输入通配符域名起始个数" }
      ];
      baseRules.wildcard_max = [
        { required: true, message: "请输入通配符域名最大个数" }
      ];
      baseRules.total_min = [
        { required: true, message: "请输入总域名起始个数" }
      ];
      baseRules.total_max = [
        { required: true, message: "请输入总域名最大个数" }
      ];
    }

    // SSL 产品特有的验证规则
    if (isSSL.value) {
      baseRules.common_name_types = [
        { required: true, message: "请选择通用名称类型" }
      ];
      baseRules.warranty_currency = [
        { required: true, message: "请选择保险币种" }
      ];
      baseRules.warranty = [
        { required: true, message: "请输入保险金额" },
        { type: "number", min: 0, message: "保险金额不能小于0" }
      ];
      baseRules.validation_methods = [
        { required: true, message: "请选择验证方法" }
      ];
    }

    return baseRules;
  });

  // 打开表单
  function openStoreForm(id = 0) {
    showStore.value = true;
    if (id > 0) {
      storeId.value !== id && handleShow(id);
    } else {
      storeRef.value?.formInstance?.resetFields();
      // 获取默认值
      storeValues.value = Object.fromEntries(
        Object.entries(FORM_PARAMS_DEFAULT)
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

  // 在提交前过滤所有 checkbox 字段的值，并根据产品类型过滤不适用字段
  function filterFormValues(values: FormParams): FormParams {
    const filtered = filterFormFieldsByConfig(
      values,
      checkboxFieldFilters.value
    );

    // 非 SSL 产品，清除不适用字段
    if (filtered.product_type && filtered.product_type !== "ssl") {
      // 清除 SSL 专用字段（非域名数量相关）
      filtered.validation_methods = [];
      filtered.add_san = 0;
      filtered.replace_san = 0;
      filtered.gift_root_domain = 0;
      filtered.server = 0;
      filtered.warranty_currency = "$";
      filtered.warranty = 0;

      // ACME 产品保留域名数量字段及名称类型（成本页据此渲染附加域名价格列），其他非 SSL 产品清零
      if (filtered.product_type !== "acme") {
        filtered.common_name_types = [];
        filtered.alternative_name_types = [];
        filtered.standard_min = 0;
        filtered.standard_max = 0;
        filtered.wildcard_min = 0;
        filtered.wildcard_max = 0;
        filtered.total_min = 0;
        filtered.total_max = 0;
      }
    }

    // CodeSign、DocSign、ACME 不支持重签
    if (["codesign", "docsign", "acme"].includes(filtered.product_type ?? "")) {
      filtered.reissue = 0;
    }

    // ACME 以 EAB 为交付产物，这 4 个字段均由 ACME 客户端自行决定
    if (filtered.product_type === "acme") {
      filtered.encryption_alg = [];
      filtered.signature_digest_alg = [];
      filtered.renew = 0;
      filtered.reuse_csr = 0;
    }

    return filtered;
  }

  const handleShow = (id: number) => {
    show(id).then(({ data }) => {
      // 使用预定义的键和工具函数从 data 中提取属性
      storeValues.value = pickByKeys<FormParams>(data, FORM_PARAMS_KEYS);

      // 将字符串类型的数值字段转换为数字类型
      storeValues.value["warranty"] = Number(
        storeValues.value["warranty"] ?? 0
      );
    });
  };

  const handleStore = () => {
    delete storeValues.value["created_at"];
    delete storeValues.value["updated_at"];
    const filteredValues = filterFormValues(storeValues.value);
    store(filteredValues).then(() => {
      onSearch();
      showStore.value = false;
    });
  };

  const handleUpdate = () => {
    delete storeValues.value["created_at"];
    delete storeValues.value["updated_at"];
    const filteredValues = filterFormValues(storeValues.value);
    update(storeId.value, filteredValues).then(() => {
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
