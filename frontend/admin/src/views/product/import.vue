<script setup lang="ts">
import { ref, reactive, computed } from "vue";
import { brandOptionsAll } from "@/views/system/dictionary";
import { importProduct } from "@/api/product";
import { message } from "@shared/utils";
import { useDialogSize } from "@/views/system/dialog";

defineOptions({
  name: "ProductImport"
});

const props = defineProps({
  visible: {
    type: Boolean,
    default: false
  },
  sourcesList: {
    type: Array as () => Array<{ label: string; value: string }>,
    default: () => []
  }
});

const emit = defineEmits(["update:visible", "success"]);

const formRef = ref();
const loading = ref(false);

// 使用统一的响应式对话框宽度
const { dialogSize } = useDialogSize();

// 使用计算属性解决prop修改问题
const dialogVisible = computed({
  get: () => props.visible,
  set: val => emit("update:visible", val)
});

const formData = reactive({
  source: "default",
  brand: "",
  apiId: "",
  type: "update"
});

// 表单验证规则
const rules = { source: [{ required: true, message: "请选择来源" }] };

// 关闭弹窗
const handleClose = () => {
  emit("update:visible", false);
};

// 提交表单
const handleConfirm = () => {
  loading.value = true;

  importProduct(formData)
    .then(res => {
      if (res.code === 1) {
        message("导入产品成功", {
          type: "success"
        });
        handleClose();
        emit("success");
        // 重置表单
        formData.source = "default";
        formData.brand = "";
        formData.apiId = "";
        formData.type = "update";
      }
    })
    .finally(() => {
      loading.value = false;
    });
};
</script>

<template>
  <el-dialog
    v-model="dialogVisible"
    title="导入产品"
    :width="dialogSize"
    destroy-on-close
    @closed="handleClose"
  >
    <el-form ref="formRef" :model="formData" :rules="rules" label-width="80px">
      <el-form-item label="来源" prop="source">
        <el-select
          v-model="formData.source"
          placeholder="请选择来源"
          style="width: 100%"
        >
          <el-option
            v-for="item in props.sourcesList"
            :key="item.value"
            :label="item.label"
            :value="item.value"
          />
        </el-select>
      </el-form-item>
      <el-form-item label="品牌" prop="brand">
        <el-select
          v-model="formData.brand"
          placeholder="请选择品牌"
          clearable
          style="width: 100%"
        >
          <el-option
            v-for="item in brandOptionsAll"
            :key="item.value"
            :label="item.label"
            :value="item.value"
          />
        </el-select>
      </el-form-item>
      <el-form-item label="接口ID" prop="apiId">
        <el-input v-model="formData.apiId" placeholder="请输入接口ID" />
      </el-form-item>
      <el-form-item label="类型" prop="type">
        <el-select
          v-model="formData.type"
          placeholder="请选择类型"
          clearable
          style="width: 100%"
        >
          <el-option key="new" label="导入新产品" value="new" />
          <el-option key="update" label="更新产品" value="update" />
          <el-option key="all" label="导入所有产品" value="all" />
        </el-select>
      </el-form-item>
    </el-form>
    <template #footer>
      <el-button @click="handleClose">取消</el-button>
      <el-button type="primary" :loading="loading" @click="handleConfirm">
        确定
      </el-button>
    </template>
  </el-dialog>
</template>
