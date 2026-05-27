import { ref, computed, onMounted } from "vue";
import {
  getNotificationPreferences,
  updateNotificationPreferences,
  type NotificationPreferences
} from "@/api/setting";
import { message } from "@shared/utils";

const typeLabels: Record<string, string> = {
  cert_issued: "证书签发通知",
  cert_expire: "证书到期提醒",
  security: "安全提醒"
};

export const useNotificationPreference = () => {
  const notificationValues = ref<NotificationPreferences>({});
  const notificationLoading = ref(false);

  const fetchPreferences = () => {
    notificationLoading.value = true;
    getNotificationPreferences()
      .then(res => {
        notificationValues.value = res.data ?? {};
      })
      .finally(() => {
        notificationLoading.value = false;
      });
  };

  onMounted(() => {
    fetchPreferences();
  });

  const mailNotificationItems = computed(() =>
    Object.entries(notificationValues.value).map(([type, enabled]) => ({
      type,
      label: typeLabels[type] ?? type,
      value: enabled
    }))
  );

  const handleToggle = (type: string, value: boolean) => {
    const previous = notificationValues.value[type];

    if (previous === value) return;

    notificationValues.value = {
      ...notificationValues.value,
      [type]: value
    };

    updateNotificationPreferences(notificationValues.value)
      .then(() => {
        message("通知设置已更新", { type: "success" });
      })
      .catch(() => {
        message("更新失败，请重试", { type: "error" });
        notificationValues.value = {
          ...notificationValues.value,
          [type]: previous
        };
      });
  };

  return {
    notificationValues,
    mailNotificationItems,
    notificationLoading,
    handleToggle
  };
};
