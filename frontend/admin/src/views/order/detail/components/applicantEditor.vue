<template>
  <el-dialog
    :model-value="visible"
    :title="section === 'organization' ? '修改企业信息' : '修改联系人信息'"
    width="620px"
    destroy-on-close
    @close="close"
  >
    <el-form ref="formRef" :model="formData" :rules="rules" label-width="110px">
      <template v-if="section === 'organization'">
        <el-form-item label="企业名称" prop="organization.name">
          <el-input v-model="formData.organization.name" />
        </el-form-item>
        <el-form-item
          label="统一信用代码"
          prop="organization.registration_number"
        >
          <el-input v-model="formData.organization.registration_number" />
        </el-form-item>
        <el-form-item label="国家" prop="organization.country">
          <el-select
            v-model="formData.organization.country"
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
        <el-row :gutter="12">
          <el-col :span="12">
            <el-form-item label="省份" prop="organization.state">
              <el-input v-model="formData.organization.state" />
            </el-form-item>
          </el-col>
          <el-col :span="12">
            <el-form-item label="城市" prop="organization.city">
              <el-input v-model="formData.organization.city" />
            </el-form-item>
          </el-col>
        </el-row>
        <el-form-item label="详细地址" prop="organization.address">
          <el-input v-model="formData.organization.address" />
        </el-form-item>
        <el-row :gutter="12">
          <el-col :span="12">
            <el-form-item label="邮政编码" prop="organization.postcode">
              <el-input v-model="formData.organization.postcode" />
            </el-form-item>
          </el-col>
          <el-col :span="12">
            <el-form-item label="电话" prop="organization.phone">
              <el-input v-model="formData.organization.phone" />
            </el-form-item>
          </el-col>
        </el-row>
      </template>

      <template v-else>
        <el-row :gutter="12">
          <el-col :span="12">
            <el-form-item label="姓氏" prop="contact.last_name">
              <el-input v-model="formData.contact.last_name" />
            </el-form-item>
          </el-col>
          <el-col :span="12">
            <el-form-item label="名字" prop="contact.first_name">
              <el-input v-model="formData.contact.first_name" />
            </el-form-item>
          </el-col>
        </el-row>
        <el-form-item label="职务" prop="contact.title">
          <el-input v-model="formData.contact.title" />
        </el-form-item>
        <el-form-item label="邮箱" prop="contact.email">
          <el-input v-model="formData.contact.email" />
        </el-form-item>
        <el-form-item label="电话" prop="contact.phone">
          <el-input v-model="formData.contact.phone" />
        </el-form-item>
      </template>
    </el-form>

    <template #footer>
      <el-button @click="close">取消</el-button>
      <el-button type="primary" :loading="saving" @click="save">保存</el-button>
    </template>
  </el-dialog>
</template>

<script setup lang="ts">
import { reactive, ref, watch, type PropType } from "vue";
import type { FormInstance, FormRules } from "element-plus";
import * as OrderApi from "@/api/order";
import { countryCodes } from "@/views/system/country";
import { message } from "@shared/utils";

type ApplicantSection = "organization" | "contact";

const props = defineProps({
  visible: { type: Boolean, required: true },
  order: { type: Object, required: true },
  section: {
    type: String as PropType<ApplicantSection>,
    required: true
  }
});

const emit = defineEmits<{
  "update:visible": [value: boolean];
  success: [data: any];
}>();

const formRef = ref<FormInstance>();
const saving = ref(false);
const formData = reactive({
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
    first_name: "",
    last_name: "",
    title: "",
    email: "",
    phone: ""
  }
});

const rules: FormRules = {
  "organization.name": [
    { required: true, message: "请输入企业名称" },
    { min: 2, max: 64, message: "企业名称长度应为2-64个字符" }
  ],
  "organization.registration_number": [
    { required: true, message: "请输入统一信用代码" },
    { min: 6, max: 32, message: "统一信用代码长度应为6-32个字符" }
  ],
  "organization.country": [
    { required: true, message: "请选择国家" },
    { len: 2, message: "国家代码必须为2个字符" }
  ],
  "organization.state": [
    { required: true, message: "请输入省份" },
    { min: 2, max: 64, message: "省份长度应为2-64个字符" }
  ],
  "organization.city": [
    { required: true, message: "请输入城市" },
    { min: 2, max: 64, message: "城市长度应为2-64个字符" }
  ],
  "organization.address": [
    { required: true, message: "请输入详细地址" },
    { min: 2, max: 64, message: "详细地址长度应为2-64个字符" }
  ],
  "organization.postcode": [
    { required: true, message: "请输入邮政编码" },
    { min: 4, max: 16, message: "邮政编码长度应为4-16个字符" }
  ],
  "organization.phone": [
    { required: true, message: "请输入电话" },
    { pattern: /^\d{5,15}$/, message: "电话必须为5-15位数字" }
  ],
  "contact.last_name": [
    { required: true, message: "请输入姓氏" },
    { min: 1, max: 40, message: "姓氏长度应为1-40个字符" }
  ],
  "contact.first_name": [
    { required: true, message: "请输入名字" },
    { min: 1, max: 16, message: "名字长度应为1-16个字符" }
  ],
  "contact.title": [
    { required: true, message: "请输入职务" },
    { min: 2, max: 16, message: "职务长度应为2-16个字符" }
  ],
  "contact.email": [
    { required: true, message: "请输入邮箱" },
    { type: "email", message: "请输入正确的邮箱地址" },
    { min: 6, max: 64, message: "邮箱长度应为6-64个字符" }
  ],
  "contact.phone": [
    { required: true, message: "请输入电话" },
    { pattern: /^\d{5,15}$/, message: "电话必须为5-15位数字" }
  ]
};

watch(
  () => [props.visible, props.section] as const,
  ([visible, section]) => {
    if (!visible) return;
    Object.assign(formData[section], props.order[section] ?? {});
    formRef.value?.clearValidate();
  },
  { immediate: true }
);

function close() {
  emit("update:visible", false);
}

async function save() {
  try {
    await formRef.value?.validate();
  } catch {
    return;
  }

  saving.value = true;
  try {
    const payload = { [props.section]: { ...formData[props.section] } };
    const response = await OrderApi.updateApplicant(props.order.id, payload);
    message("申请信息已更新", { type: "success" });
    emit("success", response.data);
    close();
  } finally {
    saving.value = false;
  }
}
</script>
