<template>
  <el-dialog
    :model-value="visible"
    title="企业 / 联系人"
    :width="720"
    :before-close="handleClose"
    destroy-on-close
    append-to-body
    @update:model-value="$emit('update:visible', $event)"
  >
    <el-form
      ref="formRef"
      :model="formData"
      :rules="rules"
      label-position="top"
    >
      <!-- 选择企业 -->
      <el-form-item label="选择企业（选择已有或清空新增）">
        <re-remote-select
          v-model="selectedOrgId"
          :uri="orgUri"
          search-field="name"
          label-field="name"
          value-field="id"
          items-field="items"
          total-field="total"
          placeholder="搜索已有企业，清空则新增"
          :query-params="orgQueryParams"
          style="width: 100%"
          clearable
          @change="onOrgSelect"
        />
      </el-form-item>

      <!-- 企业名称 + 查询 -->
      <el-form-item label="企业名称" prop="organization.name">
        <div class="inline-field">
          <el-input
            v-model="formData.organization.name"
            placeholder="请输入企业名称"
          />
          <template v-if="lookupStatusLoaded && lookupBtnVisible">
            <el-tooltip
              v-if="lookupBtnTooltip"
              :content="lookupBtnTooltip"
              placement="top"
            >
              <el-button :disabled="true" class="ml-2">查询</el-button>
            </el-tooltip>
            <el-button
              v-else
              type="primary"
              :loading="lookupLoading"
              class="ml-2"
              @click="onLookup"
            >
              查询
            </el-button>
          </template>
        </div>
      </el-form-item>

      <!-- 统一信用代码 -->
      <el-form-item
        label="统一信用代码"
        prop="organization.registration_number"
      >
        <el-input
          v-model="formData.organization.registration_number"
          placeholder="请输入统一信用代码"
        />
      </el-form-item>

      <!-- 国家 / 省份 / 城市 -->
      <el-row :gutter="16">
        <el-col :span="8">
          <el-form-item label="国家" prop="organization.country">
            <el-select
              v-model="formData.organization.country"
              placeholder="请选择国家"
              filterable
              style="width: 100%"
            >
              <el-option
                v-for="item in countryCodes"
                :key="item.value"
                :label="item.label"
                :value="item.value"
              />
            </el-select>
          </el-form-item>
        </el-col>
        <el-col :span="8">
          <el-form-item label="省份" prop="organization.state">
            <el-input
              v-model="formData.organization.state"
              placeholder="请输入省份"
            />
          </el-form-item>
        </el-col>
        <el-col :span="8">
          <el-form-item label="城市" prop="organization.city">
            <el-input
              v-model="formData.organization.city"
              placeholder="请输入城市"
            />
          </el-form-item>
        </el-col>
      </el-row>

      <!-- 详细地址 -->
      <el-form-item label="详细地址" prop="organization.address">
        <el-input
          v-model="formData.organization.address"
          type="textarea"
          :rows="2"
          placeholder="请输入详细地址"
        />
      </el-form-item>

      <!-- 邮编 / 电话 -->
      <el-row :gutter="16">
        <el-col :span="12">
          <el-form-item label="邮政编码" prop="organization.postcode">
            <el-input
              v-model="formData.organization.postcode"
              placeholder="请输入邮政编码"
            />
          </el-form-item>
        </el-col>
        <el-col :span="12">
          <el-form-item label="电话" prop="organization.phone">
            <el-input
              v-model="formData.organization.phone"
              placeholder="请输入电话"
            />
          </el-form-item>
        </el-col>
      </el-row>

      <el-divider />

      <!-- 选择联系人 -->
      <el-form-item label="选择联系人（选择已有或清空新增）">
        <re-remote-select
          v-model="selectedContactId"
          :uri="contactUri"
          search-field="first_name"
          label-field="full_name"
          value-field="id"
          items-field="items"
          total-field="total"
          placeholder="搜索已有联系人，清空则新增"
          :query-params="contactQueryParams"
          style="width: 100%"
          clearable
          @change="onContactSelect"
        />
      </el-form-item>

      <!-- 姓 / 名 -->
      <el-row :gutter="16">
        <el-col :span="8">
          <el-form-item label="姓氏" prop="contact.last_name">
            <el-input
              v-model="formData.contact.last_name"
              placeholder="请输入姓氏"
            />
          </el-form-item>
        </el-col>
        <el-col :span="16">
          <el-form-item label="名字" prop="contact.first_name">
            <el-input
              v-model="formData.contact.first_name"
              placeholder="请输入名字"
            />
          </el-form-item>
        </el-col>
      </el-row>

      <!-- 证件号 -->
      <el-form-item label="证件号" prop="contact.identification_number">
        <el-input
          v-model="formData.contact.identification_number"
          placeholder="请输入证件号"
        />
      </el-form-item>

      <!-- 职务 / 邮箱 / 电话 -->
      <el-row :gutter="16">
        <el-col :span="8">
          <el-form-item label="职务" prop="contact.title">
            <el-input
              v-model="formData.contact.title"
              placeholder="请输入职务"
            />
          </el-form-item>
        </el-col>
        <el-col :span="8">
          <el-form-item label="邮箱" prop="contact.email">
            <el-input
              v-model="formData.contact.email"
              placeholder="请输入邮箱"
            />
          </el-form-item>
        </el-col>
        <el-col :span="8">
          <el-form-item label="电话" prop="contact.phone">
            <el-input
              v-model="formData.contact.phone"
              placeholder="请输入电话"
            />
          </el-form-item>
        </el-col>
      </el-row>
    </el-form>

    <template #footer>
      <el-button @click="onCancel">取消</el-button>
      <el-button type="primary" :loading="saving" @click="onSave"
        >保存</el-button
      >
    </template>
  </el-dialog>
</template>

<script setup lang="ts">
import { ref, reactive, computed, watch, onMounted } from "vue";
import { ElMessage, type FormInstance, type FormRules } from "element-plus";
import { http } from "../../../utils";
import ReRemoteSelect from "../../ReRemoteSelect";
import type {
  OrganizationEditorProps,
  OrganizationFormData,
  ContactFormData
} from "./types";

const props = defineProps<OrganizationEditorProps>();
const countryCodes = computed(() => props.countryOptions);

const emit = defineEmits<{
  "update:visible": [value: boolean];
  success: [organization: any];
}>();

const formRef = ref<FormInstance>();
const selectedOrgId = ref<number | null>(null);
const selectedContactId = ref<number | null>(null);
const saving = ref(false);
const lookupLoading = ref(false);
const lookupEnabled = ref(false);
const lookupStatusLoaded = ref(false);

const formData = reactive<{
  organization: OrganizationFormData;
  contact: ContactFormData;
}>({
  organization: {
    name: "",
    registration_number: "",
    country: "CN",
    state: "",
    city: "",
    address: "",
    postcode: "",
    phone: ""
  },
  contact: {
    last_name: "",
    first_name: "",
    identification_number: "",
    title: "",
    email: "",
    phone: ""
  }
});

// http baseURL 已按 role 自动区分（user→/api，admin→/api/admin），uri 不重复前缀
const orgUri = "/organization";
const contactUri = "/contact";
const enterpriseLookupUri = "/enterprise-lookup";
const zipcodeLookupUri = "/zipcode-lookup";

const orgQueryParams = computed(() =>
  props.userId ? { user_id: props.userId } : {}
);
const contactQueryParams = computed(() =>
  props.userId ? { user_id: props.userId } : {}
);

const lookupBtnVisible = computed(() => {
  if (props.role === "user") return lookupEnabled.value;
  return true;
});
const lookupBtnTooltip = computed(() => {
  if (props.role === "admin" && !lookupEnabled.value) {
    return "请在系统设置-工商查询中配置 AppCode 并启用";
  }
  return "";
});

const rules: FormRules = {
  "organization.name": [
    { required: true, message: "请输入企业名称", trigger: "blur" }
  ],
  "organization.registration_number": [
    { required: true, message: "请输入统一信用代码", trigger: "blur" }
  ],
  "organization.country": [
    { required: true, message: "请选择国家", trigger: "change" }
  ],
  "organization.state": [
    { required: true, message: "请输入省份", trigger: "blur" }
  ],
  "organization.city": [
    { required: true, message: "请输入城市", trigger: "blur" }
  ],
  "organization.address": [
    { required: true, message: "请输入详细地址", trigger: "blur" }
  ],
  "organization.postcode": [
    { required: true, message: "请输入邮政编码", trigger: "blur" },
    {
      min: 3,
      max: 20,
      message: "邮政编码长度应为3-20个字符",
      trigger: "blur"
    }
  ],
  "organization.phone": [
    { required: true, message: "请输入电话", trigger: "blur" },
    {
      pattern: /^[0-9]{1,15}$/,
      message: "请输入正确的格式",
      trigger: "blur"
    }
  ],
  "contact.last_name": [
    { required: true, message: "请输入姓氏", trigger: "blur" },
    { max: 50, message: "姓氏长度不能超过50个字符", trigger: "blur" }
  ],
  "contact.first_name": [
    { required: true, message: "请输入名字", trigger: "blur" },
    { max: 50, message: "名字长度不能超过50个字符", trigger: "blur" }
  ],
  "contact.identification_number": [
    {
      pattern:
        /^[1-9]\d{5}(18|19|20)\d{2}(0[1-9]|1[0-2])(0[1-9]|[12]\d|3[01])\d{3}[\dXx]$/,
      message: "请输入正确的证件号",
      trigger: "blur"
    }
  ],
  "contact.title": [
    { required: true, message: "请输入职务", trigger: "blur" },
    { max: 20, message: "职务长度不能超过20个字符", trigger: "blur" }
  ],
  "contact.email": [
    { required: true, message: "请输入邮箱", trigger: "blur" },
    { type: "email", message: "请输入正确的邮箱地址", trigger: "blur" }
  ],
  "contact.phone": [
    { required: true, message: "请输入电话", trigger: "blur" },
    {
      pattern: /^[0-9]{1,15}$/,
      message: "请输入正确的格式",
      trigger: "blur"
    }
  ]
};

function resetOrgFields() {
  formData.organization.name = "";
  formData.organization.registration_number = "";
  formData.organization.country = "CN";
  formData.organization.state = "";
  formData.organization.city = "";
  formData.organization.address = "";
  formData.organization.postcode = "";
  formData.organization.phone = "";
}

function resetContactFields() {
  formData.contact.last_name = "";
  formData.contact.first_name = "";
  formData.contact.identification_number = "";
  formData.contact.title = "";
  formData.contact.email = "";
  formData.contact.phone = "";
}

function fillOrgFields(data: any) {
  formData.organization.name = data.name ?? "";
  formData.organization.registration_number = data.registration_number ?? "";
  formData.organization.country = data.country ?? "CN";
  formData.organization.state = data.state ?? "";
  formData.organization.city = data.city ?? "";
  formData.organization.address = data.address ?? "";
  formData.organization.postcode = data.postcode ?? "";
  formData.organization.phone = data.phone != null ? String(data.phone) : "";
}

function fillContactFields(data: any) {
  formData.contact.last_name = data.last_name ?? "";
  formData.contact.first_name = data.first_name ?? "";
  formData.contact.identification_number = data.identification_number ?? "";
  formData.contact.title = data.title ?? "";
  formData.contact.email = data.email ?? "";
  formData.contact.phone = data.phone != null ? String(data.phone) : "";
}

function mergeFill<T extends Record<string, any>>(
  target: T,
  source: Partial<T>
) {
  for (const k in source) {
    const v = source[k];
    if (v != null && v !== "" && !target[k]) {
      target[k] = v;
    }
  }
}

async function onOrgSelect(orgId: number | null) {
  selectedOrgId.value = orgId;
  if (!orgId) {
    resetOrgFields();
    return;
  }
  try {
    const res = await http.get<BaseResponse<any>, any>(`${orgUri}/${orgId}`);
    const data = res.data;
    fillOrgFields(data);
    if (data.contact_id) {
      selectedContactId.value = data.contact_id;
      fillContactFields(data.contact ?? {});
    } else {
      selectedContactId.value = null;
      resetContactFields();
    }
  } catch {
    ElMessage.error("获取企业详情失败");
  }
}

async function onContactSelect(cid: number | null) {
  selectedContactId.value = cid;
  if (!cid) {
    resetContactFields();
    return;
  }
  try {
    const res = await http.get<BaseResponse<any>, any>(`${contactUri}/${cid}`);
    fillContactFields(res.data);
  } catch {
    ElMessage.error("获取联系人详情失败");
  }
}

// 拆分法人姓名为 last_name / first_name：
// 1) 含空格（如 "John Smith"）→ 按空格分两段
// 2) 含 · 中点（如 "阿不都·热合曼"）→ 按 · 分两段
// 3) 其他（中文姓名）→ 第一个字符为姓，其余为名（复姓需手动调整）
function splitChineseName(raw: string): { last: string; first: string } {
  const name = raw.trim();
  if (!name) return { last: "", first: "" };
  if (/\s/.test(name)) {
    const [a, ...rest] = name.split(/\s+/);
    return { last: a, first: rest.join(" ") };
  }
  if (name.includes("·")) {
    const idx = name.indexOf("·");
    return { last: name.slice(0, idx), first: name.slice(idx + 1) };
  }
  const chars = Array.from(name);
  return { last: chars[0] ?? "", first: chars.slice(1).join("") };
}

// 调邮编查询接口:基于 regionname(行政区划路径)反查,公司名用于县级市识别。
// 失败静默不阻塞主流程。若服务返回的 city 比工商更精确(如县级市),覆盖回填。
async function lookupZipcode(regionname: string, companyName?: string) {
  try {
    const payload: Record<string, string> = { regionname };
    if (companyName) payload.name = companyName;
    const res = await http.post<BaseResponse<any>, any>(zipcodeLookupUri, {
      data: payload
    });
    const d = res?.data;
    if (d?.zipcode && !formData.organization.postcode) {
      formData.organization.postcode = String(d.zipcode);
    }
    // 服务返回的 city 是基于 regionname+公司名算出的最精确市名(可能是县级市),覆盖工商粗结果
    if (d?.city && d.city !== formData.organization.city) {
      formData.organization.city = String(d.city);
    }
  } catch {
    // 静默:邮编查询失败不影响工商查询流程
  }
}

async function onLookup() {
  if (!formData.organization.name) {
    ElMessage.warning("请先输入企业名称");
    return;
  }
  lookupLoading.value = true;
  try {
    const res = await http.post<BaseResponse<any>, any>(enterpriseLookupUri, {
      data: { name: formData.organization.name }
    });
    const d = res.data;
    // fieldMap 的标准 key 已与 organization 入库字段对齐,直接消费,无需二次映射
    mergeFill(formData.organization, {
      name: d.name ?? undefined,
      registration_number: d.registration_number ?? undefined,
      address: d.address ?? undefined,
      state: d.state ?? undefined,
      city: d.city ?? undefined
    });
    if (
      !selectedContactId.value &&
      d.legal_person &&
      !formData.contact.first_name &&
      !formData.contact.last_name
    ) {
      const { last, first } = splitChineseName(String(d.legal_person));
      formData.contact.last_name = last;
      formData.contact.first_name = first;
      // 法定代表人姓名成功填入时,职位默认填"法定代表人"(用户可改)
      if (!formData.contact.title) {
        formData.contact.title = "法定代表人";
      }
    }
    // 工商查询已成功，先提示（邮编是附属增强，不让它的耗时拖慢成功反馈）
    ElMessage.success("查询成功，已填入工商信息");
    // 若拿到 regionname 或省+市,查邮编(公司名用于县级市识别)。
    // await 等待回填完成再解除 loading：避免用户在 postcode/city 回填前点保存
    // （回填丢失竞态）。lookupZipcode 内部已 catch，失败静默、不冒泡到此处 catch。
    const regionname =
      d.regionname || [d.state, d.city].filter(Boolean).join("");
    if (regionname) {
      await lookupZipcode(regionname, d.name);
    }
  } catch (e: any) {
    ElMessage.error(e?.msg || "查询失败，请手动填写");
  } finally {
    lookupLoading.value = false;
  }
}

async function onSave() {
  try {
    await formRef.value?.validate();
  } catch {
    return;
  }
  saving.value = true;
  try {
    const payload: Record<string, any> = {
      ...formData.organization,
      phone: formData.organization.phone,
      contact_id: selectedContactId.value,
      contact: {
        ...formData.contact,
        phone: formData.contact.phone
      }
    };
    // admin 端必带 user_id（后端用来校验归属一致性 / 新增时归属用户）
    if (props.role === "admin" && props.userId) {
      payload.user_id = props.userId;
    }
    let res: any;
    if (selectedOrgId.value) {
      res = await http.put<BaseResponse<any>, any>(
        `${orgUri}/${selectedOrgId.value}`,
        { data: payload }
      );
    } else {
      res = await http.post<BaseResponse<any>, any>(orgUri, {
        data: payload
      });
    }
    ElMessage.success("保存成功");
    emit("success", res.data);
    emit("update:visible", false);
  } catch (e: any) {
    ElMessage.error(e?.msg || "保存失败");
  } finally {
    saving.value = false;
  }
}

function onCancel() {
  emit("update:visible", false);
}

function handleClose(done: () => void) {
  done();
  emit("update:visible", false);
}

onMounted(async () => {
  try {
    const res = await http.get<BaseResponse<any>, any>(
      "/enterprise-lookup/status"
    );
    lookupEnabled.value = !!res.data?.enabled;
  } catch {
    lookupEnabled.value = false;
  } finally {
    lookupStatusLoaded.value = true;
  }
});

watch(
  () => [props.visible, props.organizationId] as const,
  async ([visible, orgId]) => {
    if (!visible) return;
    if (orgId) {
      selectedOrgId.value = orgId;
      await onOrgSelect(orgId);
    } else {
      selectedOrgId.value = null;
      selectedContactId.value = null;
      resetOrgFields();
      resetContactFields();
    }
  },
  { immediate: true }
);
</script>

<style scoped lang="scss">
.inline-field {
  display: flex;
  gap: 8px;
  align-items: center;
  width: 100%;

  .el-input {
    flex: 1;
  }
}

.ml-2 {
  margin-left: 8px;
  flex-shrink: 0;
}
</style>
