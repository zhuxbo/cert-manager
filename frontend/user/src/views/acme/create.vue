<template>
  <el-dialog
    :model-value="visible"
    title="创建订阅"
    :width="dialogSize"
    :before-close="handleClose"
    destroy-on-close
    append-to-body
    @update:model-value="$emit('update:visible', $event)"
    @open="handleOpen"
  >
    <el-form
      ref="formRef"
      :model="formData"
      :label-position="dialogSize == '90%' ? 'top' : 'right'"
      label-width="120px"
    >
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
import { ref, reactive } from "vue";
import { useRoute } from "vue-router";
import { createOrder } from "@/api/acme";
import { show as productShow } from "@/api/product";
import { message } from "@shared/utils";
import ReRemoteSelect from "@shared/components/ReRemoteSelect";
import { periodLabels } from "@/views/system/dictionary";
import type { FormInstance, FormRules } from "element-plus";
import { useDialogSize } from "@/views/system/dialog";

// 与传统订单申请一致：?plus=0 可取消赠送时间，默认 1
const route = useRoute();

const props = defineProps({
  visible: {
    type: Boolean,
    default: false
  },
  productId: {
    type: Number,
    default: 0
  }
});

const emit = defineEmits(["update:visible", "success"]);

const { dialogSize } = useDialogSize();
const formRef = ref<FormInstance>();
const loading = ref(false);

const formData = reactive({
  product_id: undefined as number | undefined,
  period: "" as number | string,
  quantity: 1
});

const periodOptions = ref<{ label: string; value: number }[]>([]);

const rules = reactive<FormRules>({
  product_id: [{ required: true, message: "请选择产品", trigger: "change" }],
  period: [{ required: true, message: "请选择有效期", trigger: "change" }],
  quantity: [{ required: true, message: "请输入数量", trigger: "blur" }]
});

const handleOpen = () => {
  formData.product_id = props.productId > 0 ? props.productId : undefined;
  formData.period = "";
  formData.quantity = 1;
  periodOptions.value = [];
  if (formData.product_id) {
    handleProductChange(formData.product_id);
  }
};

const handleProductChange = (productId: number) => {
  if (!productId) return;

  productShow(productId).then(({ data }) => {
    formData.period = "";
    periodOptions.value = [];
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
    product_id: formData.product_id as number,
    period: Number(formData.period),
    plus: Number(route.query.plus ?? 1)
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
