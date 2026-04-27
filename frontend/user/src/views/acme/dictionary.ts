import type { EpPropMergeType } from "element-plus/es/utils/vue/props/types";

export const statusType: {
  [key: string]: EpPropMergeType<
    StringConstructor,
    "info" | "primary" | "success" | "warning" | "danger",
    unknown
  >;
} = {
  unpaid: "warning",
  pending: "primary",
  active: "success",
  cancelling: "danger",
  cancelled: "danger",
  revoked: "danger",
  expired: "info"
};

export const status: { [key: string]: string } = {
  unpaid: "待支付",
  pending: "待提交",
  active: "已激活",
  cancelling: "待取消",
  cancelled: "已取消",
  revoked: "已吊销",
  expired: "已过期"
};

export const statusOptions: { label: string; value: string }[] = [
  { label: "待支付", value: "unpaid" },
  { label: "待提交", value: "pending" },
  { label: "已激活", value: "active" },
  { label: "待取消", value: "cancelling" },
  { label: "已取消", value: "cancelled" },
  { label: "已吊销", value: "revoked" },
  { label: "已过期", value: "expired" }
];

export const statusSet: { [key: string]: string } = {
  all: "全部",
  activating: "活动中",
  archived: "已归档"
};

export const statusSetOptions: { label: string; value: string }[] = [
  { label: "全部", value: "all" },
  { label: "活动中", value: "activating" },
  { label: "已归档", value: "archived" }
];

export const ActivatingStatusOptions: { label: string; value: string }[] = [
  { label: "待支付", value: "unpaid" },
  { label: "待提交", value: "pending" },
  { label: "已激活", value: "active" },
  { label: "待取消", value: "cancelling" }
];

export const ArchivedStatusOptions: { label: string; value: string }[] = [
  { label: "已取消", value: "cancelled" },
  { label: "已吊销", value: "revoked" },
  { label: "已过期", value: "expired" }
];
