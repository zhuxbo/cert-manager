import { ref, reactive, computed, type Ref } from "vue";
import { ElMessage, type FormInstance, type FormRules } from "element-plus";
import type { TemplateItem, TemplateForm } from "@/api/notificationTemplate";
import * as templateApi from "@/api/notificationTemplate";

export function useNotificationTemplateStore(onSearch: () => void) {
  const formDialogVisible = ref(false);
  const formRef: Ref<FormInstance | undefined> = ref();
  const editingId = ref<number | null>(null);

  const formModel = reactive<TemplateForm>({
    name: "",
    code: "",
    content: "",
    variables: [],
    example: "",
    status: 1
  });

  const formRules: FormRules = {
    name: [{ required: true, message: "请输入模板名称", trigger: "blur" }],
    code: [{ required: true, message: "请输入模板标识", trigger: "blur" }],
    content: [{ required: true, message: "请输入模板内容", trigger: "blur" }]
  };

  const variableInput = computed(() => formModel.variables ?? []);

  // 重置表单
  const resetFormModel = () => {
    formModel.name = "";
    formModel.code = "";
    formModel.content = "";
    formModel.variables = [];
    formModel.example = "";
    formModel.status = 1;
  };

  // 打开创建表单
  const openCreate = () => {
    editingId.value = null;
    resetFormModel();
    formDialogVisible.value = true;
  };

  // 打开编辑表单
  const openEdit = (row: TemplateItem) => {
    editingId.value = row.id;
    formModel.name = row.name;
    formModel.code = row.code;
    formModel.content = row.content;
    formModel.variables = Array.isArray(row.variables)
      ? [...row.variables]
      : [];
    formModel.example = row.example ?? "";
    formModel.status = row.status;
    formDialogVisible.value = true;
  };

  // 提交表单
  const submitForm = () => {
    formRef.value?.validate(valid => {
      if (!valid) return;
      const payload: TemplateForm = {
        ...formModel,
        variables: (formModel.variables ?? []).filter(Boolean)
      };
      const action = editingId.value
        ? templateApi.update(editingId.value, payload)
        : templateApi.store(payload);
      action.then(() => {
        ElMessage.success(editingId.value ? "更新成功" : "创建成功");
        formDialogVisible.value = false;
        onSearch();
      });
    });
  };

  // 关闭表单
  const closeForm = () => {
    formDialogVisible.value = false;
  };

  return {
    formDialogVisible,
    formRef,
    editingId,
    formModel,
    formRules,
    variableInput,
    openCreate,
    openEdit,
    submitForm,
    closeForm
  };
}
