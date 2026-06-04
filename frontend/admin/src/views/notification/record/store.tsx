import { ref, reactive, computed, watch } from "vue";
import { ElMessage } from "element-plus";
import { show as fetchUser } from "@/api/user";
import { show as fetchAdmin } from "@/api/admin";
import { index as fetchTemplates } from "@/api/notificationTemplate";
import { sendTest as sendTestNotification } from "@/api/notification";
import type { TemplateItem } from "@/api/notificationTemplate";
import type { NotifiableOption } from "./dictionary";
import { isMultilineField } from "./dictionary";

// 通知对象配置
export const notifiableOptions: NotifiableOption[] = [
  {
    label: "用户",
    value: "user",
    remote: {
      uri: "/user",
      searchField: "quickSearch",
      labelField: "username",
      valueField: "id",
      itemsField: "items",
      totalField: "total"
    },
    fetchDetail: fetchUser
  },
  {
    label: "管理员",
    value: "admin",
    remote: {
      uri: "/admin",
      searchField: "quickSearch",
      labelField: "username",
      valueField: "id",
      itemsField: "items",
      totalField: "total"
    },
    fetchDetail: fetchAdmin
  }
];

export function useNotificationRecordStore(onSearch: () => void) {
  const testDialogVisible = ref(false);
  const templateOptions = ref<TemplateItem[]>([]);
  const templateLoading = ref(false);

  const testForm = reactive({
    notifiable_type: notifiableOptions[0]?.value ?? "user",
    notifiable_id: null as number | null,
    template_type: ""
  });

  const testPayload = reactive<Record<string, any>>({});
  const selectedNotifiable = ref<Record<string, any> | null>(null);

  const currentNotifiableOption = computed(
    () =>
      notifiableOptions.find(item => item.value === testForm.notifiable_type) ??
      notifiableOptions[0]
  );

  const selectedTemplate = computed(() => {
    if (!testForm.template_type) return null;

    return (
      templateOptions.value.find(
        item => item.code === testForm.template_type
      ) ?? null
    );
  });

  const templateVariables = computed(
    () => selectedTemplate.value?.variables ?? []
  );

  // 加载模板选项
  const ensureTemplateOptions = async () => {
    if (templateOptions.value.length) return;
    templateLoading.value = true;
    try {
      const { data } = await fetchTemplates({
        currentPage: 1,
        pageSize: 200,
        status: 1
      });
      templateOptions.value = data.items ?? [];
    } finally {
      templateLoading.value = false;
    }
  };

  // 重置测试数据
  const resetTestPayload = () => {
    Object.keys(testPayload).forEach(key => delete testPayload[key]);
    templateVariables.value.forEach(field => {
      testPayload[field] = "";
    });
    prefillTestPayload();
  };

  // 预填充测试数据
  const prefillTestPayload = () => {
    if (!selectedNotifiable.value) return;
    const excludedFields = [
      "created_at",
      "executed_at",
      "updated_at",
      "deleted_at",
      "read_at",
      "sent_at"
    ];
    templateVariables.value.forEach(field => {
      if (excludedFields.includes(field)) {
        return;
      }
      const current = testPayload[field];
      const value = selectedNotifiable.value?.[field];
      if (
        (current === undefined || current === null || current === "") &&
        value !== undefined &&
        typeof value !== "object"
      ) {
        testPayload[field] = value ?? "";
      }
    });
  };

  // 打开测试对话框
  const openTestDialog = () => {
    testForm.notifiable_id = null;
    testForm.template_type = "";
    selectedNotifiable.value = null;
    resetTestPayload();
    testDialogVisible.value = true;
    ensureTemplateOptions();
  };

  // 确认测试发送
  const confirmTestSend = () => {
    if (!testForm.notifiable_id || !testForm.template_type) {
      ElMessage.warning("请选择通知对象和模板");
      return;
    }

    const payload = Object.fromEntries(
      Object.entries(testPayload).filter(
        ([, value]) => value !== "" && value !== null && value !== undefined
      )
    );

    sendTestNotification({
      notifiable_type: testForm.notifiable_type,
      notifiable_id: Number(testForm.notifiable_id),
      template_type: testForm.template_type,
      data: Object.keys(payload).length ? payload : undefined
    }).then(() => {
      ElMessage.success("测试通知已提交");
      testDialogVisible.value = false;
      onSearch();
    });
  };

  // 关闭测试对话框
  const closeTestDialog = () => {
    testDialogVisible.value = false;
  };

  // 监听模板变化
  watch(
    () => testForm.template_type,
    () => {
      resetTestPayload();
    }
  );

  // 监听对象类型变化
  watch(
    () => testForm.notifiable_type,
    () => {
      testForm.notifiable_id = null;
      selectedNotifiable.value = null;
      resetTestPayload();
    }
  );

  // 监听对象ID变化
  watch(
    () => testForm.notifiable_id,
    async id => {
      const option = currentNotifiableOption.value;
      if (!option || !id || !option.fetchDetail) {
        selectedNotifiable.value = null;
        return;
      }

      try {
        const response = await option.fetchDetail(Number(id));
        selectedNotifiable.value = response.data ?? null;
        prefillTestPayload();
      } catch {
        selectedNotifiable.value = null;
      }
    }
  );

  return {
    testDialogVisible,
    templateOptions,
    templateLoading,
    testForm,
    testPayload,
    selectedNotifiable,
    currentNotifiableOption,
    selectedTemplate,
    templateVariables,
    openTestDialog,
    confirmTestSend,
    closeTestDialog,
    isMultilineField
  };
}
