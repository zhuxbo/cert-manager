<template>
  <el-dialog
    :model-value="visible"
    title="创建订阅"
    :width="dialogSize"
    :before-close="handleClose"
    destroy-on-close
    append-to-body
    @update:model-value="$emit('update:visible', $event)"
  >
    <el-form
      ref="formRef"
      :model="formData"
      :label-position="dialogSize == '90%' ? 'top' : 'right'"
      label-width="110px"
    >
      <el-form-item label="用户" prop="user_id" :rules="rules.user_id">
        <re-remote-select
          v-model="formData.user_id"
          uri="/user"
          searchField="quickSearch"
          labelField="username"
          valueField="id"
          itemsField="items"
          totalField="total"
          placeholder="请选择用户"
          :queryParams="{ status: 1 }"
        />
      </el-form-item>

      <el-form-item label="产品" prop="product_id" :rules="rules.product_id">
        <re-remote-select
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
          :queryParams="{ product_type: 'acme', status: 1 }"
          @change="handleProductChange"
        />
      </el-form-item>

      <el-form-item label="有效期" prop="period" :rules="rules.period">
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

      <el-form-item v-if="showPlus" label="赠送时间" prop="plus">
        <el-select v-model="formData.plus" style="width: 100%">
          <el-option label="否" :value="0" />
          <el-option label="是" :value="1" />
        </el-select>
      </el-form-item>

      <el-form-item label="数量" prop="quantity" :rules="rules.quantity">
        <el-input-number
          v-model="formData.quantity"
          :min="1"
          :max="20"
          :precision="0"
          controls-position="right"
          style="width: 100%"
        />
      </el-form-item>
    </el-form>
    <template #footer>
      <el-button @click="handleClose">取消</el-button>
      <el-button type="primary" :loading="loading" @click="handleSubmit"
        >提交</el-button
      >
    </template>
  </el-dialog>
</template>

<script setup lang="ts">
import { ref, reactive, computed } from "vue";
import { createOrder } from "@/api/acme";
import { show as productShow } from "@/api/product";
import { message } from "@shared/utils";
import ReRemoteSelect from "@shared/components/ReRemoteSelect";
import { periodLabels } from "@/views/system/dictionary";
import type { FormInstance, FormRules } from "element-plus";
import { useDialogSize } from "@/views/system/dialog";

// 支持赠送时间的 CA（参考传统订单）
const PLUS_BRANDS = ["certum", "positive", "sectigo", "ssltrus"];

defineProps({
  visible: {
    type: Boolean,
    default: false
  }
});

const emit = defineEmits(["update:visible", "success"]);

const { dialogSize } = useDialogSize();
const formRef = ref<FormInstance>();
const loading = ref(false);

const formData = reactive({
  user_id: undefined as number | undefined,
  product_id: undefined as number | undefined,
  period: "" as number | string,
  plus: 1,
  quantity: 1
});

const periodOptions = ref<{ label: string; value: number }[]>([]);
const productBrand = ref<string>("");

const showPlus = computed(() =>
  PLUS_BRANDS.includes(productBrand.value.toLowerCase())
);

const rules = reactive<FormRules>({
  user_id: [{ required: true, message: "请选择用户", trigger: "change" }],
  product_id: [{ required: true, message: "请选择产品", trigger: "change" }],
  period: [{ required: true, message: "请选择有效期", trigger: "change" }],
  quantity: [{ required: true, message: "请输入数量", trigger: "blur" }]
});

const handleProductChange = (productId: number) => {
  if (!productId) return;

  productShow(productId).then(({ data }) => {
    formData.period = "";
    formData.plus = 1;
    periodOptions.value = [];
    productBrand.value = (data?.brand || "").toString();
    if (data.periods?.length > 0) {
      const sorted = [...data.periods].sort((a: number, b: number) => a - b);
      periodOptions.value = sorted.map(p => ({
        label: periodLabels[p],
        value: p
      }));
      formData.period = sorted[0];
    }
  });
};

const handleSubmit = async () => {
  if (!formRef.value) return;
  await formRef.value.validate();

  const total = Math.max(1, Math.min(20, Number(formData.quantity) || 1));
  const payload = {
    user_id: formData.user_id as number,
    product_id: formData.product_id as number,
    period: Number(formData.period),
    ...(showPlus.value ? { plus: formData.plus } : {})
  };

  loading.value = true;
  let success = 0;
  try {
    for (let i = 0; i < total; i++) {
      const res = await createOrder(payload);
      if (res.code === 1) success++;
    }
  } finally {
    loading.value = false;
  }

  if (success === total) {
    message(total > 1 ? `成功创建 ${total} 个订阅` : "创建成功", {
      type: "success"
    });
    emit("success");
    emit("update:visible", false);
  } else if (success > 0) {
    message(`部分成功：已创建 ${success} / ${total}`, { type: "warning" });
    emit("success");
  }
};

const handleClose = () => {
  emit("update:visible", false);
};
</script>
