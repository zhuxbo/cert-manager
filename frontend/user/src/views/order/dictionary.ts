import type { EpPropMergeType } from "element-plus/es/utils/vue/props/types";

export const statusSet: { [key: string]: string } = {
  all: "全部",
  activating: "活动中",
  archived: "已存档"
};

export const statusSetOptions: { label: string; value: string }[] = [
  {
    label: "全部",
    value: "all"
  },
  {
    label: "活动中",
    value: "activating"
  },
  { label: "已存档", value: "archived" }
];

export const ActivatingStatusOptions: { label: string; value: string }[] = [
  {
    label: "待支付",
    value: "unpaid"
  },
  {
    label: "待提交",
    value: "pending"
  },
  {
    label: "待验证",
    value: "processing"
  },
  {
    label: "待审核",
    value: "approving"
  },
  {
    label: "已签发",
    value: "active"
  },
  {
    label: "待取消",
    value: "cancelling"
  }
];

export const ArchivedStatusOptions: { label: string; value: string }[] = [
  {
    label: "已取消",
    value: "cancelled"
  },
  {
    label: "已续期",
    value: "renewed"
  },
  {
    label: "已过期",
    value: "expired"
  },
  {
    label: "已吊销",
    value: "revoked"
  },
  {
    label: "已失败",
    value: "failed"
  }
];
export const statusType: {
  [key: string]: EpPropMergeType<
    StringConstructor,
    "info" | "primary" | "success" | "warning" | "danger",
    unknown
  >;
} = {
  unpaid: "warning",
  pending: "primary",
  processing: "primary",
  approving: "primary",
  active: "success",
  failed: "danger",
  cancelling: "danger",
  cancelled: "danger",
  renewed: "info",
  replaced: "info",
  reissued: "info",
  expired: "info",
  revoked: "danger"
};

export const status: { [key: string]: string } = {
  unpaid: "待支付",
  pending: "待提交",
  processing: "待验证",
  approving: "待审核",
  active: "已签发",
  cancelling: "待取消",
  cancelled: "已取消",
  renewed: "已续期",
  replaced: "已替换",
  reissued: "已重签",
  expired: "已过期",
  revoked: "已吊销",
  failed: "已失败"
};

export const statusOptions: { label: string; value: string }[] = [
  {
    label: "待支付",
    value: "unpaid"
  },
  {
    label: "待提交",
    value: "pending"
  },
  {
    label: "待验证",
    value: "processing"
  },
  {
    label: "待审核",
    value: "approving"
  },
  {
    label: "已签发",
    value: "active"
  },
  {
    label: "待取消",
    value: "cancelling"
  },
  {
    label: "已取消",
    value: "cancelled"
  },
  {
    label: "已续期",
    value: "renewed"
  },
  {
    label: "已过期",
    value: "expired"
  },
  {
    label: "已吊销",
    value: "revoked"
  },
  {
    label: "已失败",
    value: "failed"
  }
];

export const action: { [key: string]: string } = {
  new: "新购",
  renew: "续期",
  reissue: "重签"
};

export const actionType: {
  [key: string]: EpPropMergeType<
    StringConstructor,
    "info" | "primary" | "success" | "warning" | "danger",
    unknown
  >;
} = {
  new: "success",
  renew: "success",
  reissue: "info"
};

export const actionOptions: { label: string; value: string }[] = [
  {
    label: "新购",
    value: "new"
  },
  {
    label: "续期",
    value: "renew"
  },
  {
    label: "重签",
    value: "reissue"
  }
];

export const channel: { [key: string]: string } = {
  admin: "后台",
  web: "网站",
  api: "API",
  deploy: "部署",
  auto: "自动"
};

export const channelType: {
  [key: string]: EpPropMergeType<
    StringConstructor,
    "info" | "primary" | "success" | "warning" | "danger",
    unknown
  >;
} = {
  admin: "warning",
  web: "info",
  api: "primary",
  deploy: "success",
  auto: "success"
};

export const channelOptions: { label: string; value: string }[] = [
  {
    label: "后台",
    value: "admin"
  },
  {
    label: "网站",
    value: "web"
  },
  {
    label: "API",
    value: "api"
  },
  {
    label: "部署",
    value: "deploy"
  },
  {
    label: "自动",
    value: "auto"
  }
];

export const productType: { [key: string]: string } = {
  ssl: "SSL证书",
  smime: "S/MIME",
  codesign: "代码签名"
};

export const productTypeOptions: { label: string; value: string }[] = [
  {
    label: "SSL证书",
    value: "ssl"
  },
  {
    label: "S/MIME",
    value: "smime"
  },
  {
    label: "代码签名",
    value: "codesign"
  }
];
