/**
 * 任务相关字典
 */

// 动作标签
export const actionLabels: Record<string, string> = {
  commit: "提交",
  sync: "同步",
  revalidate: "验证",
  cancel: "取消",
  callback: "回调",
  delegation: "委托",
  commit_acme: "提交ACME",
  sync_acme: "同步ACME",
  cancel_acme: "取消ACME"
};

export const actionOptions = Object.entries(actionLabels).map(
  ([key, value]) => ({
    label: value,
    value: key
  })
);

// 动作类型
export const actionTypes: Record<string, string> = {
  commit: "primary",
  sync: "primary",
  revalidate: "primary",
  cancel: "danger",
  commit_acme: "primary",
  sync_acme: "primary",
  cancel_acme: "danger",
  callback: "primary",
  delegation: "info"
};

// 状态标签
export const statusLabels: Record<string, string> = {
  executing: "执行中",
  successful: "成功",
  failed: "失败",
  stopped: "停止"
};

export const statusOptions = Object.entries(statusLabels).map(
  ([key, value]) => ({
    label: value,
    value: key
  })
);

// 状态类型
export const statusTypes: Record<string, string> = {
  executing: "primary",
  successful: "success",
  failed: "danger",
  stopped: "warning"
};

// 获取动作标签
export function getActionLabel(action: string): string {
  return actionLabels[action] || action;
}

// 获取动作类型
export function getActionType(action: string): string {
  return actionTypes[action] || "info";
}

// 获取状态标签
export function getStatusLabel(status: string): string {
  return statusLabels[status] || status;
}

// 获取状态类型
export function getStatusType(status: string): string {
  return statusTypes[status] || "info";
}
