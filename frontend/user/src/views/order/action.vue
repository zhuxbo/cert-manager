<template>
  <el-dialog
    :model-value="visible"
    :title="getTitle"
    :width="dialogSize"
    :before-close="handleClose"
    destroy-on-close
    append-to-body
    @update:model-value="$emit('update:visible', $event)"
  >
    <div class="order-action-form">
      <el-form
        ref="formRef"
        :model="formData"
        :label-position="dialogSize == '90%' ? 'top' : 'right'"
        label-width="90px"
      >
        <!-- CSR 选项：所有产品类型都可以选择 -->
        <el-form-item v-if="needCSR" label="CSR" prop="csr_generate">
          <el-radio-group
            v-model="formData.csr_generate"
            :disabled="isBatchApply"
          >
            <el-radio :value="1">{{ "自动生成" }}</el-radio>
            <el-radio :value="0">{{ "已有" }}</el-radio>
          </el-radio-group>
        </el-form-item>

        <el-form-item
          v-if="needCSR && !parseInt(formData.csr_generate)"
          label=" "
          prop="csr"
          :rules="rules.csr"
        >
          <el-input
            v-model="formData.csr"
            type="textarea"
            :rows="5"
            placeholder="请输入CSR"
          />
        </el-form-item>

        <el-form-item
          v-if="['apply', 'batchApply'].includes(actionType)"
          label="产品"
          prop="product_id"
          :rules="rules.product_id"
        >
          <re-remote-select
            ref="productSelectRef"
            v-model="formData.product_id"
            uri="/product"
            searchField="quickSearch"
            labelField="name"
            valueField="id"
            itemsField="items"
            totalField="total"
            placeholder="请选择产品"
            :pageSize="100"
            :showPagination="false"
            :disabled="disabledFields.includes('product_id')"
            :queryParams="productQueryParams"
            @change="productSelected"
          />
        </el-form-item>

        <!-- SSL 证书才需要域名 -->
        <el-form-item
          v-if="isSSL"
          label="域名"
          prop="domains"
          :rules="rules.domains"
        >
          <el-input
            v-model="formData.domains"
            :type="
              isBatchApply || formData.product?.total_max > 1
                ? 'textarea'
                : 'text'
            "
            :autosize="{ minRows: 3, maxRows: 10 }"
            :placeholder="
              '请输入域名' +
              (isBatchApply || formData.product?.total_max > 1
                ? '，一行一个'
                : '')
            "
          />
        </el-form-item>

        <!-- SMIME 证书需要邮箱 -->
        <el-form-item
          v-if="isSMIME"
          label="邮箱"
          prop="email"
          :rules="rules.email"
        >
          <el-input
            v-model="formData.email"
            type="text"
            placeholder="请输入邮箱地址"
          />
        </el-form-item>

        <el-row :gutter="20">
          <el-col :span="12">
            <el-form-item
              v-if="props.actionType !== 'reissue'"
              label="有效期"
              prop="period"
              :rules="rules.period"
            >
              <el-select
                v-model="formData.period"
                placeholder="请选择有效期"
                style="width: 100%"
              >
                <el-option
                  v-for="option in periodOptions"
                  :key="option.value"
                  :label="option.label"
                  :value="option.value"
                />
              </el-select>
            </el-form-item>
            <!-- 验证方式：SSL 需要 -->
            <el-form-item
              v-if="isSSL"
              label="验证方式"
              prop="validation_method"
              :rules="rules.validation_method"
            >
              <el-select
                v-model="formData.validation_method"
                placeholder="请选择验证方式"
                style="width: 100%"
              >
                <el-option
                  v-for="option in validationMethodOptions"
                  :key="option.value"
                  :label="option.label"
                  :value="option.value"
                />
              </el-select>
            </el-form-item>
          </el-col>
        </el-row>

        <!-- 组织：OV/EV、CodeSign/DocSign、SMIME(sponsor/organization) 需要 -->
        <el-form-item
          v-if="
            (isOrg || isCodeSign || isDocSign || smimeNeedOrganization) &&
            props.actionType !== 'reissue'
          "
          label="组织"
          prop="organization"
          :rules="rules.organization"
        >
          <div class="inline-field">
            <re-remote-select
              v-model="formData.organization"
              uri="/organization"
              searchField="name"
              labelField="name"
              valueField="id"
              itemsField="items"
              totalField="total"
              placeholder="请选择组织"
              clearable
              :disabled="!formData.product_id"
              :refresh-key="orgSelectRefreshKey"
              style="flex: 1"
            />
            <el-button
              :type="formData.organization ? 'default' : 'primary'"
              :disabled="!formData.product_id"
              class="ml-2"
              @click="openOrgEditor"
            >
              {{ formData.organization ? "编辑" : "添加" }}
            </el-button>
          </div>
        </el-form-item>

        <el-alert
          v-if="showCertumActivationNotice"
          class="certum-activation-alert"
          title="证书激活邮件将发送到联系人邮箱，请确保联系人邮箱正确。"
          type="warning"
          :closable="false"
          show-icon
        />

        <!-- 联系人：SMIME individual 仅需联系人 -->
        <el-form-item
          v-if="smimeNeedContactOnly && props.actionType !== 'reissue'"
          label="联系人"
          prop="contact"
          :rules="rules.contact"
        >
          <re-remote-select
            v-model="formData.contact"
            uri="/contact"
            search-field="first_name"
            label-field="full_name"
            value-field="id"
            items-field="items"
            total-field="total"
            placeholder="请选择联系人"
            :disabled="!formData.product_id"
          />
        </el-form-item>

        <!-- 加密选项折叠面板：自动生成 CSR 时显示 -->
        <re-collapse
          v-if="showEncryption"
          v-model="encryptionOpen"
          title="加密选项"
          :border="false"
        >
          <el-row :gutter="20">
            <el-col :span="12">
              <el-form-item label="加密算法" prop="encryption.alg">
                <el-select
                  v-model="formData.encryption.alg"
                  placeholder="请选择加密算法"
                  style="width: 100%"
                  @change="handleAlgChange"
                >
                  <el-option
                    v-for="option in encryptionAlgOptions"
                    :key="option.value"
                    :label="option.label"
                    :value="option.value"
                  />
                </el-select>
              </el-form-item>
              <el-form-item label="密钥长度" prop="encryption.bits">
                <el-select
                  v-model="formData.encryption.bits"
                  placeholder="请选择密钥长度"
                  style="width: 100%"
                >
                  <el-option
                    v-for="option in keyBitsOptions"
                    :key="option.value"
                    :label="option.label"
                    :value="option.value"
                  />
                </el-select>
              </el-form-item>
              <el-form-item label="摘要算法" prop="encryption.digest_alg">
                <el-select
                  v-model="formData.encryption.digest_alg"
                  placeholder="请选择摘要算法"
                  style="width: 100%"
                >
                  <el-option
                    v-for="option in signatureDigestAlgOptions"
                    :key="option.value"
                    :label="option.label"
                    :value="option.value"
                  />
                </el-select>
              </el-form-item>
            </el-col>
          </el-row>
        </re-collapse>
      </el-form>
    </div>
    <template #footer>
      <div class="order-action-footer">
        <el-button @click="handleClose">取消</el-button>
        <el-button type="primary" :loading="loading" @click="handleSubmit"
          >提交</el-button
        >
      </div>
    </template>
    <organization-editor
      v-model:visible="orgEditorVisible"
      role="user"
      :organization-id="formData.organization"
      :country-options="countryCodes"
      @success="onOrgSaved"
    />
  </el-dialog>
</template>

<script setup lang="ts">
import { ref, computed, watch, reactive } from "vue";
import {
  show,
  apply,
  batchApply,
  renew,
  reissue,
  ACTION_PARAMS_DEFAULT
} from "@/api/order";
import { message } from "@shared/utils";
import { show as productShow } from "@/api/product";
import ReRemoteSelect from "@shared/components/ReRemoteSelect";
import ReCollapse from "@shared/components/ReCollapse";
import isIP from "validator/lib/isIP";
import isDomain from "validator/lib/isFQDN";
import {
  periodLabels,
  validationMethodLabels
} from "@/views/system/dictionary";
import router from "@/router";
import type { FormInstance, FormRules } from "element-plus";
import { useDialogSize } from "@/views/system/dialog";
import { OrganizationEditor } from "@shared/components/OrganizationEditor";
import { countryCodes } from "@/views/system/country";
import { ElMessageBox } from "element-plus";
const props = defineProps({
  visible: {
    type: Boolean,
    default: false
  },
  actionType: {
    type: String,
    default: ""
  },
  orderId: {
    type: Number,
    default: 0
  }
});

const emit = defineEmits(["update:visible", "success", "close"]);

// 使用统一的响应式对话框宽度
const { dialogSize } = useDialogSize();

// 表单引用
const formRef = ref<FormInstance>();
// 产品选择组件引用
const productSelectRef = ref();
// 加载状态
const loading = ref(false);
// 产品查询参数
const productQueryParams = computed(() => {
  return {
    domains: isBatchApply.value ? "single" : "",
    product_type: isBatchApply.value
      ? ["ssl"]
      : ["ssl", "smime", "codesign", "docsign"]
  };
});
// 禁用字段列表
const disabledFields = ref<string[]>([]);
// 表单数据
const formData = reactive<any>({});

// 组织编辑器
const orgEditorVisible = ref(false);
const orgSelectRefreshKey = ref(0);

function openOrgEditor() {
  orgEditorVisible.value = true;
}

function onOrgSaved(org: { id: number }) {
  formData.organization = org.id;
  orgSelectRefreshKey.value = Date.now();
}

// 周期选项
const periodOptions = ref<{ label: string; value: any }[]>([]);
// 验证方法选项
const validationMethodOptions = ref<{ label: string; value: string }[]>([]);

// 加密算法选项
const encryptionAlgOptions = computed(() => {
  return (
    formData.product?.encryption_alg?.map((item: string) => ({
      label: item.toUpperCase(),
      value: item.toLowerCase()
    })) ?? []
  );
});

// 密钥长度选项
const keyBitsOptions = computed(() => {
  if (formData.encryption.alg === "rsa") {
    return [
      { label: "2048", value: 2048 },
      { label: "4096", value: 4096 }
    ];
  }
  // SM2 固定 256 位（与后端 CsrUtil 强制一致）
  if (formData.encryption.alg === "sm2") {
    return [{ label: "256", value: 256 }];
  }
  // ECDSA
  return [
    { label: "256", value: 256 },
    { label: "384", value: 384 },
    { label: "521", value: 521 }
  ];
});

// 摘要算法选项
const signatureDigestAlgOptions = computed(() => {
  return (
    formData.product?.signature_digest_alg?.map((item: string) => ({
      label: item.toUpperCase(),
      value: item.toLowerCase()
    })) ?? []
  );
});

// 是否为批量申请
const isBatchApply = computed(() => props.actionType === "batchApply");

// 是否需要组织信息（OV/EV证书）
const isOrg = computed(
  () => (formData.product?.validation_type || "dv") !== "dv"
);

// 产品类型判断
const productType = computed(() => formData.product?.product_type || "ssl");
const isSSL = computed(() => productType.value === "ssl");
const isSMIME = computed(() => productType.value === "smime");
const isCodeSign = computed(() => productType.value === "codesign");
const isDocSign = computed(() => productType.value === "docsign");
const showCertumActivationNotice = computed(
  () =>
    props.actionType === "apply" &&
    (isCodeSign.value || isDocSign.value) &&
    formData.product?.ca?.toString().toLowerCase() === "certum"
);

// SMIME 子类型检测（从产品 code 中提取）
const smimeType = computed(() => {
  if (!isSMIME.value) return "";
  const code = (formData.product?.code || "").toLowerCase();
  if (code.includes("mailbox")) return "mailbox";
  if (code.includes("individual")) return "individual";
  if (code.includes("sponsor")) return "sponsor";
  if (code.includes("organization")) return "organization";
  return "unknown";
});

// SMIME 是否需要组织（sponsor 可选, organization 必需）
const smimeNeedOrganization = computed(() =>
  ["sponsor", "organization"].includes(smimeType.value)
);

// SMIME 组织是否必填（sponsor 和 organization 类型必填）
const smimeOrganizationRequired = computed(() =>
  ["sponsor", "organization"].includes(smimeType.value)
);

// SMIME individual: 仅需要联系人，不需要组织（与 OrganizationEditor 互斥）
const smimeNeedContactOnly = computed(
  () => isSMIME.value && smimeType.value === "individual"
);

// 是否需要 CSR（所有产品类型都需要 CSR）
const needCSR = computed(() => true);

// 是否需要显示加密选项（自动生成 CSR 时显示）
const showEncryption = computed(() => formData.csr_generate === 1);

// 折叠面板状态
const encryptionOpen = ref(false);

// 标题
const getTitle = computed(() => {
  if (props.actionType === "apply") return "申请证书";
  if (props.actionType === "batchApply") return "批量申请";
  if (props.actionType === "renew") return "续费证书";
  if (props.actionType === "reissue") return "重签证书";
  return "";
});

// 单个域名验证函数
const checkDomain = (
  domain: string,
  allowTypes: string[],
  index: number,
  domainArray: string[]
) => {
  const is_wildcard = domain.slice(0, 2) == "*.";

  if (is_wildcard && !allowTypes.includes("wildcard")) {
    // 直接修改数组中的域名，去掉通配符前缀
    domainArray[index] = domain.slice(2);
    // 更新formData.domains
    formData.domains = domainArray.join("\n");
  } else if (isIP(domain, 4)) {
    if (!allowTypes.includes("ipv4")) {
      return domain + " 不能为IPV4";
    }
  } else if (isIP(domain, 6)) {
    if (!allowTypes.includes("ipv6")) {
      return domain + " 不能为IPV6";
    }
  } else if (isDomain(domain) && !allowTypes.includes("standard")) {
    // 直接修改数组中的域名，添加通配符前缀
    domainArray[index] = "*." + domain;
    // 更新formData.domains
    formData.domains = domainArray.join("\n");
  } else if (!isDomain(is_wildcard ? domain.slice(2) : domain)) {
    return domain + " 格式错误";
  }
  return "";
};

// 检查域名中是否包含 *.www. 模式
const checkWwwPattern = (domains: string): Promise<boolean> => {
  if (domains.includes("*.www.")) {
    return ElMessageBox.confirm(
      "检测到域名中包含 *.www. 通配符，通常情况下 www. 不需要包含在通配符证书中。是否继续？",
      "域名确认",
      {
        confirmButtonText: "继续",
        cancelButtonText: "取消",
        type: "warning"
      }
    )
      .then(() => true)
      .catch(() => false);
  }
  return Promise.resolve(true);
};

// 域名验证
const validateDomains = (
  _rule: any,
  value: string,
  callback: (error?: Error | string) => void
) => {
  if (!value) {
    return callback(new Error("请输入域名"));
  }

  // 规范化输入
  formData.domains = value
    .replaceAll(" ", "")
    .replaceAll("　", "")
    .replaceAll("\t", "")
    .toLowerCase();
  formData.domains = formData.domains
    .replace(/(\n[\s\t]*\r*\n)/g, "\n")
    .replace(/^[\n\r\t]*|[\n\r\t]*$/g, "");

  // 检查 *.www. 模式
  checkWwwPattern(formData.domains).then(wwwCheckPassed => {
    if (!wwwCheckPassed) {
      return callback(new Error("请删除 www."));
    }
    // 继续原有的验证逻辑
    performDomainValidation(callback);
  });
};

// 执行域名验证逻辑
const performDomainValidation = (
  callback: (error?: Error | string) => void
) => {
  if (formData.product?.total_max === 1 && !isBatchApply.value) {
    formData.domains = formData.domains.split("\n")[0];
  }

  const domainArray = formData.domains
    .split("\n")
    .filter((item: string) => item !== "");
  const domainArrayFilterRepeat = [...new Set(domainArray)];

  if (domainArray.length !== domainArrayFilterRepeat.length) {
    return callback(new Error("域名重复"));
  }

  if (!formData.product?.common_name_types) {
    return callback(new Error("请选择产品"));
  }

  const errors: string[] = [];
  if (domainArray.length > 0) {
    // 验证第一个域名 (Common Name)
    errors.push(
      checkDomain(
        domainArray[0],
        formData.product.common_name_types,
        0,
        domainArray
      ) || ""
    );

    // 如果为批量申请 全部使用 common_name_types
    if (isBatchApply.value) {
      domainArray.slice(1).forEach((domain: string, idx: number) => {
        errors.push(
          checkDomain(
            domain,
            formData.product.common_name_types,
            idx + 1,
            domainArray
          ) || ""
        );
      });
    } else {
      // 验证其余域名 (SANs)
      if (domainArray.length > 1 && !formData.product?.alternative_name_types) {
        return callback(new Error("请选择产品"));
      } else if (domainArray.length > 1) {
        domainArray.slice(1).forEach((domain: string, idx: number) => {
          errors.push(
            checkDomain(
              domain,
              formData.product.alternative_name_types,
              idx + 1,
              domainArray
            ) || ""
          );
        });
      }
    }
  }

  const filteredErrors = errors.filter(Boolean);
  if (filteredErrors.length > 0) {
    callback(new Error(filteredErrors.join("\n")));
  } else {
    callback();
  }
};

// 表单验证规则
const rules = reactive<FormRules>({
  csr_generate: [
    { required: true, message: "请选择CSR生成方式", trigger: "change" }
  ],
  csr: [{ required: true, message: "请输入CSR", trigger: "blur" }],
  product_id: [{ required: true, message: "请选择产品", trigger: "change" }],
  domains: [{ required: true, validator: validateDomains, trigger: "blur" }],
  email: [
    { required: true, message: "请输入邮箱地址", trigger: "blur" },
    { type: "email", message: "邮箱格式不正确", trigger: "blur" }
  ],
  validation_method: [
    { required: true, message: "请选择验证方式", trigger: "change" }
  ],
  period: [{ required: true, message: "请选择周期", trigger: "change" }]
});

// 动态更新验证规则（根据产品类型）
const updateValidationRules = () => {
  // 域名验证规则：只有 SSL 需要
  rules.domains = isSSL.value
    ? [{ required: true, validator: validateDomains, trigger: "blur" }]
    : [];
  // 邮箱验证规则：只有 SMIME 需要
  rules.email = isSMIME.value
    ? [
        { required: true, message: "请输入邮箱地址", trigger: "blur" },
        { type: "email", message: "邮箱格式不正确", trigger: "blur" }
      ]
    : [];
  // 验证方式规则：只有 SSL 需要
  rules.validation_method = isSSL.value
    ? [{ required: true, message: "请选择验证方式", trigger: "change" }]
    : [];
  // CSR 规则：SSL 和 CodeSign 需要
  rules.csr = needCSR.value
    ? [{ required: true, message: "请输入CSR", trigger: "blur" }]
    : [];
};

// 加密算法变更处理
const handleAlgChange = () => {
  // 根据加密算法设置默认密钥长度
  formData.encryption.bits = formData.encryption.alg === "rsa" ? 2048 : 256;
};

// 产品选择处理
const productSelected = (productId: any) => {
  if (!productId) return;

  productShow(productId).then(({ data }) => {
    // 更新产品相关信息
    formData.product = {
      ...formData.product,
      product_type: data.product_type || "ssl",
      ca: data.ca,
      code: data.code, // 用于 SMIME 类型检测
      total_max: data.total_max,
      validation_type: data.validation_type,
      common_name_types: data.common_name_types,
      alternative_name_types: data.alternative_name_types,
      encryption_alg: data.encryption_alg,
      signature_digest_alg: data.signature_digest_alg,
      name: data.name,
      brand: data.brand,
      add_san: data.add_san,
      replace_san: data.replace_san,
      renew: data.renew,
      reissue: data.reissue,
      reuse_csr: data.reuse_csr,
      gift_root_domain: data.gift_root_domain,
      period: { option: [] },
      validation_method: { option: [] }
    };

    // 处理周期选项
    formData.period = "";
    periodOptions.value = [];
    if (data.periods && data.periods.length > 0) {
      // 排序
      const sortedPeriods = data.periods.sort((a: any, b: any) => {
        return a - b;
      });
      periodOptions.value = sortedPeriods.map(period => ({
        label: periodLabels[period],
        value: period
      }));
      formData.period = sortedPeriods[0];
    }

    // 处理验证方法选项
    formData.validation_method = "";
    validationMethodOptions.value = [];
    if (data.validation_methods && data.validation_methods.length > 0) {
      // 根据validationMethodLabels key的顺序排序
      const sortedMethods = data.validation_methods.sort((a: any, b: any) => {
        return (
          Object.keys(validationMethodLabels).indexOf(a) -
          Object.keys(validationMethodLabels).indexOf(b)
        );
      });
      validationMethodOptions.value = sortedMethods.map(method => ({
        label: validationMethodLabels[method],
        value: method
      }));
      formData.validation_method = sortedMethods[0];
    }

    // CSR处理
    if (!formData.product.reuse_csr) {
      formData.csr = "";
    }

    // 重签时，CSR可重用则关闭自动生成
    if (props.actionType === "reissue" && formData.product.reuse_csr) {
      formData.csr_generate = 0;
    }

    // 加密选项随产品校正（仅新建/批量申请；续费、重签由 loadOrderInfo 回填原算法，不在此覆盖以防静默降级）
    if (["apply", "batchApply"].includes(props.actionType)) {
      const algMenu = (data.encryption_alg ?? []).map((item: string) =>
        item.toLowerCase()
      );
      // 不兼容才切：当前算法不在产品支持列表内时，切到产品首选并联动密钥长度
      if (algMenu.length && !algMenu.includes(formData.encryption.alg)) {
        formData.encryption.alg = algMenu[0];
        handleAlgChange();
      }
      const digestMenu = (data.signature_digest_alg ?? []).map((item: string) =>
        item.toLowerCase()
      );
      if (
        digestMenu.length &&
        !digestMenu.includes(formData.encryption.digest_alg)
      ) {
        formData.encryption.digest_alg = digestMenu[0];
      }
      // SM2 强制 256 位 + SM3 摘要（与后端 CsrUtil 对齐）
      if (formData.encryption.alg === "sm2") {
        formData.encryption.bits = 256;
        formData.encryption.digest_alg = "sm3";
      }
    }

    // 更新验证规则（根据产品类型）
    updateValidationRules();

    // 组织验证规则（根据产品类型和 SMIME 子类型）
    // 组织：OV/EV 必需，CodeSign/DocSign 必需，SMIME(sponsor/organization 必需)
    const needOrgRequired =
      isOrg.value ||
      isCodeSign.value ||
      isDocSign.value ||
      smimeOrganizationRequired.value;
    rules.organization = [
      {
        required:
          needOrgRequired &&
          ["apply", "batchApply", "renew"].includes(props.actionType),
        message: "请选择组织",
        trigger: "change"
      }
    ];

    rules.contact = [
      {
        required:
          smimeNeedContactOnly.value &&
          ["apply", "batchApply", "renew"].includes(props.actionType),
        message: "请选择联系人",
        trigger: "change"
      }
    ];
  });
};

// 加载订单信息（用于续费和重签）
const loadOrderInfo = (id: number) => {
  show(id).then(({ data }) => {
    // 设置产品信息，并禁用这些字段
    formData.product_id = data.product_id;
    disabledFields.value = ["product_id"];

    // 如果有域名信息，也设置
    if (data.latest_cert?.alternative_names) {
      formData.domains = data.latest_cert.alternative_names
        .replaceAll(",", "\n")
        .trim();
    }

    // 如果是重签，且有CSR，设置CSR信息
    if (props.actionType === "reissue" && data.latest_cert?.csr) {
      formData.csr = data.latest_cert.csr;
    }

    // 如果 alternative_name_types.length = 0，则 domains = data.latest_cert.common_name
    if (data.product?.alternative_name_types?.length === 0) {
      formData.domains = data.latest_cert.common_name;
    }

    // 续费/重签：回填原证书加密算法，避免默认 rsa 导致原 ECDSA/SM2 证书静默降级
    // （后端 initParams 亦有兜底；此处让 UI 默认值与实际算法一致，用户仍可主动改）
    const lastAlg = String(
      data.latest_cert?.encryption_alg ?? ""
    ).toLowerCase();
    const lastDigest = String(
      data.latest_cert?.signature_digest_alg ?? ""
    ).toLowerCase();
    if (lastAlg === "sm2") {
      formData.encryption = { alg: "sm2", bits: 256, digest_alg: "sm3" };
    } else if (lastAlg === "ecdsa") {
      formData.encryption = {
        alg: "ecdsa",
        bits: Number(data.latest_cert?.encryption_bits) || 256,
        digest_alg: lastDigest || "sha256"
      };
    } else if (lastAlg === "rsa") {
      formData.encryption = {
        alg: "rsa",
        bits: Number(data.latest_cert?.encryption_bits) || 2048,
        digest_alg: lastDigest || "sha256"
      };
    }

    // 设置order_id (原订单ID)
    formData.order_id = id;

    // 触发产品选择，加载产品相关配置
    productSelected(data.product_id);
  });
};

// 准备提交数据
const prepareOrderData = () => {
  const params: any = {};

  // 产品类型
  params.product_type = productType.value;

  // CSR 处理（SSL 和 CodeSign 需要，SMIME 不需要）
  if (needCSR.value) {
    params.csr_generate = formData.csr_generate;
    if (formData.csr_generate) {
      params.encryption = formData.encryption;
    } else {
      params.csr = formData.csr;
    }
  }

  // SSL 特有字段：域名和验证方式
  if (
    isSSL.value &&
    ["apply", "batchApply", "renew", "reissue"].includes(props.actionType)
  ) {
    params.domains = formData.domains?.replace(/\n/g, ",");
    params.validation_method = formData.validation_method;
  }

  // SMIME 特有字段：邮箱
  if (isSMIME.value) {
    params.email = formData.email;
  }

  // 申请、批量申请、续费
  if (["apply", "batchApply", "renew"].includes(props.actionType)) {
    params.period = formData.period;
  }

  // 组织：OV/EV、CodeSign/DocSign、SMIME(sponsor/organization) 需要
  if (
    (isOrg.value ||
      isCodeSign.value ||
      isDocSign.value ||
      smimeNeedOrganization.value) &&
    props.actionType !== "reissue"
  ) {
    params.organization = formData.organization;
  }

  // 联系人：SMIME individual 仅需联系人
  if (smimeNeedContactOnly.value && props.actionType !== "reissue") {
    params.contact = formData.contact;
  }

  // 申请、批量申请
  if (["apply", "batchApply"].includes(props.actionType)) {
    params.product_id = formData.product_id;
    params.plus = router.currentRoute.value.query.plus ?? 1;
  }

  // 续费、重签
  if (["renew", "reissue"].includes(props.actionType)) {
    params.order_id = formData.order_id;
  }

  return params;
};

// 表单提交
const handleSubmit = async () => {
  if (!formRef.value) return;

  try {
    await formRef.value.validate();

    loading.value = true;

    // 根据操作类型执行不同的API
    if (props.actionType === "apply") {
      await apply(prepareOrderData());
    } else if (props.actionType === "batchApply") {
      await batchApply(prepareOrderData());
    } else if (props.actionType === "renew") {
      await renew(prepareOrderData());
    } else if (props.actionType === "reissue") {
      await reissue(prepareOrderData());
    } else {
      message("未知的操作类型", { type: "error" });
      loading.value = false;
      return;
    }

    message("提交成功", { type: "success" });
    emit("success");
    emit("update:visible", false);
  } finally {
    loading.value = false;
  }
};

// 关闭抽屉
const handleClose = () => {
  emit("update:visible", false);
  emit("close");
};

// 初始化表单数据
const initFormData = () => {
  // 清空现有数据
  Object.keys(formData).forEach(key => {
    delete formData[key];
  });

  // 设置默认值
  Object.entries(ACTION_PARAMS_DEFAULT).forEach(([key, value]) => {
    if (typeof value === "object" && value !== null) {
      formData[key] = JSON.parse(JSON.stringify(value));
    } else {
      formData[key] = value;
    }
  });

  // 重置其他相关状态
  disabledFields.value = [];
  periodOptions.value = [];
  validationMethodOptions.value = [];

  // 重置表单验证状态
  setTimeout(() => {
    formRef.value?.clearValidate();
  }, 0);
};

// 初始化函数 - 在组件挂载后立即调用一次
initFormData();
// 获取是否传递product_id
const { product_id } = router.currentRoute.value.query;

// 初始化：监听visible、orderId和actionType的变化
watch(
  () => [props.visible, props.orderId, props.actionType],
  ([newVisible, newOrderId, newActionType], oldValues) => {
    // 处理初次加载时oldValues可能为undefined的情况
    const [oldVisible = false, oldOrderId = null, oldActionType = null] =
      Array.isArray(oldValues) ? oldValues : [];
    // 只有在抽屉打开时或者抽屉已打开但orderId/actionType发生变化时才执行初始化
    if (
      newVisible &&
      !oldVisible && // 抽屉从关闭变为打开
      (newOrderId !== oldOrderId || newActionType !== oldActionType) // orderId或actionType变化
    ) {
      // 首先重置表单
      initFormData();

      // 延迟一下再加载订单信息，确保表单已经重置完成
      setTimeout(() => {
        // 如果是续期或重签并且有orderId，加载订单信息
        if (
          newOrderId &&
          ["renew", "reissue"].includes(newActionType as string)
        ) {
          loadOrderInfo(newOrderId as number);
        }
        // 如果是申请并且有product_id，加载产品信息
        if (
          ["batchApply", "apply"].includes(newActionType as string) &&
          product_id
        ) {
          const productIdNum = Number(product_id);

          // 先预加载产品信息，然后注入选项，最后设置ID
          productShow(productIdNum).then(({ data }) => {
            // 等待组件完成初始化
            setTimeout(() => {
              // 直接注入产品选项到组件
              if (productSelectRef.value) {
                const productOption = {
                  label: data.name,
                  value: data.id
                };

                // 确保组件有 options 数组
                if (!productSelectRef.value.options) {
                  productSelectRef.value.options = [];
                }

                // 检查是否已存在，避免重复
                const exists = productSelectRef.value.options.some(
                  option => option.value === data.id
                );

                if (!exists) {
                  // 注入选项
                  productSelectRef.value.options.push(productOption);
                }

                // 选项注入完成后，设置产品ID
                setTimeout(() => {
                  formData.product_id = productIdNum;
                  // 程序化设置值不会触发@change，需要手动调用
                  productSelected(productIdNum);
                }, 100);
              } else {
                // 回退方案
                formData.product_id = productIdNum;
                productSelected(productIdNum);
              }
            }, 200); // 等待组件初始化
          });
        }
      }, 100);
    }
  }
);
</script>

<style scoped lang="scss">
.order-action-form {
  padding: 0 20px 20px;
}

.order-action-footer {
  padding-top: 10px;
  text-align: right;
}

.inline-field {
  display: flex;
  gap: 8px;
  align-items: center;
  width: 100%;
}

.certum-activation-alert {
  margin-bottom: 18px;
}

.ml-auto {
  margin-left: auto;
}
</style>
