<template>
  <div class="batch-buttons">
    <el-button
      v-if="canDownload()"
      type="primary"
      size="small"
      class="ml-2"
      @click="download()"
    >
      下载证书
    </el-button>
    <el-button
      v-if="canView()"
      type="primary"
      size="small"
      class="ml-2"
      @click="view()"
    >
      查看订单
    </el-button>
    <el-button
      v-if="canCopy()"
      type="primary"
      size="small"
      class="ml-2"
      @click="copy()"
    >
      复制解析
    </el-button>
    <el-button
      v-if="canPay()"
      type="primary"
      size="small"
      class="ml-2"
      @click="pay()"
    >
      支付订单
    </el-button>
    <el-button
      v-if="canCommit()"
      type="primary"
      size="small"
      class="ml-2"
      @click="commit()"
    >
      提交订单
    </el-button>
    <el-button
      v-if="canRevalidate()"
      type="primary"
      size="small"
      class="ml-2"
      @click="revalidate()"
    >
      重新验证
    </el-button>
    <el-button
      v-if="canSync()"
      type="primary"
      size="small"
      class="ml-2"
      @click="sync()"
    >
      同步订单
    </el-button>
    <el-dropdown v-if="canDeploy()" class="ml-2" @command="deploy">
      <el-button type="primary" size="small">批量部署</el-button>
      <template #dropdown>
        <el-dropdown-menu>
          <el-dropdown-item command="bt">宝塔面板</el-dropdown-item>
          <el-dropdown-item command="nginx">Nginx / Apache</el-dropdown-item>
          <el-dropdown-item command="iis">IIS</el-dropdown-item>
        </el-dropdown-menu>
      </template>
    </el-dropdown>
    <el-popconfirm
      v-if="canCommitCancel()"
      title="确定要取消订单吗？"
      width="160px"
      @confirm="commitCancel()"
    >
      <template #reference>
        <el-button type="danger" size="small" class="ml-2">
          取消订单
        </el-button>
      </template>
    </el-popconfirm>
    <el-button
      v-if="canRevokeCancel()"
      type="warning"
      size="small"
      class="ml-2"
      @click="revokeCancel()"
    >
      撤销取消
    </el-button>
  </div>
</template>

<script setup lang="ts">
import { message } from "@shared/utils";
import * as OrderApi from "@/api/order";
import { useDetail } from "./detail";
import dayjs from "dayjs";

const { toDetail } = useDetail();

// 定义props和emits
const props = defineProps<{
  selectedRows: any[];
  tableRef: any;
}>();

const emit = defineEmits<{
  (e: "refresh"): void;
}>();

// 获取选中的行数据
const getSelectedRows = () => {
  return props.selectedRows || [];
};

// 获取选中的IDs
const getSelectionIds = () => {
  return getSelectedRows().map(row => row.id);
};

// 判断按钮是否显示的方法
const canDownload = () => {
  return getSelectedRows().some(row => row.latest_cert?.status === "active");
};

const canView = () => {
  return getSelectedRows().length > 0;
};

const canCopy = () => {
  return getSelectedRows().some(
    row =>
      ["unpaid", "pending", "processing"].includes(row.latest_cert?.status) &&
      (row.latest_cert?.dcv?.dns || row.latest_cert?.dcv?.is_delegate)
  );
};

const canPay = () => {
  return getSelectedRows().some(row => row.latest_cert?.status === "unpaid");
};

const canCommit = () => {
  return getSelectedRows().some(row => row.latest_cert?.status === "pending");
};

const canRevalidate = () => {
  return getSelectedRows().some(
    row =>
      row.latest_cert?.status === "processing" &&
      row.latest_cert?.domain_verify_status !== 2
  );
};

const canSync = () => {
  return getSelectedRows().some(row =>
    ["processing", "active", "approving"].includes(row.latest_cert?.status)
  );
};

const canDeploy = () => {
  return getSelectedRows().some(
    row =>
      row.latest_cert?.status === "active" &&
      row.product?.product_type === "ssl"
  );
};

const canCommitCancel = () => {
  return getSelectedRows().some(row => {
    if (["unpaid", "pending"].includes(row.latest_cert?.status)) {
      return true;
    }
    return (
      ["processing", "approving", "active"].includes(row.latest_cert?.status) &&
      dayjs().diff(dayjs(row.created_at), "seconds") <
        row.product.refund_period * 86400
    );
  });
};

const canRevokeCancel = () => {
  return getSelectedRows().some(
    row => row.latest_cert?.status === "cancelling"
  );
};

const download = () => {
  props.tableRef.clearSelection();

  const filteredIds: number[] = [];

  getSelectedRows().forEach(row => {
    if (row.latest_cert.status == "active") {
      filteredIds.push(row.id);
      props.tableRef.toggleRowSelection(row);
    }
  });

  if (!filteredIds.length) {
    message("请至少选择一个已签发证书", {
      type: "error"
    });
    return;
  }

  OrderApi.download(filteredIds.toString());
};

const view = () => {
  const ids = getSelectionIds();
  toDetail({ ids: ids.join(",") }, "params");
};

const getDelegationPrefix = (ca?: string) => {
  const caLower = (ca || "").toLowerCase();
  switch (caLower) {
    case "sectigo":
    case "comodo":
      return "_pki-validation";
    case "certum":
      return "_certum";
    default:
      return "_dnsauth";
  }
};

const copy = () => {
  const filteredIds: number[] = [];

  props.tableRef.clearSelection();

  getSelectedRows().forEach(row => {
    if (
      ["unpaid", "pending", "processing"].includes(row.latest_cert.status) &&
      (row.latest_cert.dcv?.dns || row.latest_cert.dcv?.is_delegate)
    ) {
      filteredIds.push(row.id);
      props.tableRef.toggleRowSelection(row);
    }
  });

  if (!filteredIds.length) {
    message("请至少选择一个符合要求的证书 1.未签发 2.使用解析验证", {
      type: "error"
    });
    return;
  }

  OrderApi.batchShow(filteredIds)
    .then(res => {
      let copied = "";
      res.data.items.forEach((item: any) => {
        let cert = item.latest_cert;
        if (!["unpaid", "pending", "processing"].includes(cert.status)) return;

        // 委托验证
        if (cert.dcv?.is_delegate) {
          const prefix = getDelegationPrefix(cert.dcv.ca || item.product?.ca);
          const validation = cert.validation || [];
          const seen = new Map();
          const uniqueDelegations = validation.filter((v: any) => {
            if (!v.delegation_id) return false;
            if (seen.has(v.delegation_id)) return false;
            seen.set(v.delegation_id, true);
            return true;
          });
          uniqueDelegations.forEach((v: any) => {
            const zone =
              v.delegation_zone || (v.domain || "").replace(/^\*\./, "");
            copied += `域名：${zone}\n主机记录：${prefix}\n解析类型：CNAME\n记录值：${v.delegation_target}\n\n`;
          });
          return;
        }

        // 普通 DNS 验证
        let validation = cert.validation[0];
        if (!validation?.host) return;

        const hasMultiHosts = () => {
          const hostsSet = new Set();
          for (const item of cert.validation) {
            if (item.host) {
              if (hostsSet.size > 0 && !hostsSet.has(item.host)) {
                return true;
              }
              hostsSet.add(item.host);
            }
          }
          return false;
        };
        if (hasMultiHosts()) {
          copied +=
            validation.domain + "此证书多个域名解析记录不同，跳过复制\n\n";
        } else {
          let mult =
            cert.validation.length > 2 ? " 此证书多个域名解析记录相同" : "";
          copied +=
            "域名：" +
            validation.domain +
            mult +
            "\n主机记录：" +
            validation.host +
            "\n解析类型：" +
            validation.method +
            "\n记录值：" +
            validation.value +
            "\n\n";
        }
      });
      navigator.clipboard
        .writeText(copied)
        .then(() => {
          message("复制成功", {
            type: "success"
          });
        })
        .catch(() => {
          message("复制失败", {
            type: "error"
          });
        });
    })
    .catch(() => {
      message("复制失败", {
        type: "error"
      });
    });
};

const deploy = (type: string) => {
  const filteredIds: number[] = [];

  props.tableRef.clearSelection();

  getSelectedRows().forEach(row => {
    if (
      row.latest_cert.status === "active" &&
      row.product?.product_type === "ssl"
    ) {
      filteredIds.push(row.id);
      props.tableRef.toggleRowSelection(row);
    }
  });

  if (!filteredIds.length) {
    message("请至少选择一个已签发的 SSL 证书", { type: "error" });
    return;
  }

  OrderApi.deployCommands(filteredIds.join(",")).then(res => {
    if (res.code === 1) {
      const cmd =
        type === "iis"
          ? res.data.iis_deploy
          : type === "bt"
            ? res.data.bt_deploy
            : res.data.deploy;
      navigator.clipboard
        .writeText(cmd)
        .then(() => {
          message("部署命令已复制", { type: "success" });
        })
        .catch(() => {
          message("复制失败", { type: "error" });
        });
    }
  });
};

const pay = () => {
  const filteredIds: number[] = [];

  props.tableRef.clearSelection();

  getSelectedRows().forEach(row => {
    if (row.latest_cert.status == "unpaid") {
      filteredIds.push(row.id);
      props.tableRef.toggleRowSelection(row);
    }
  });

  if (!filteredIds.length) {
    message("请至少选择一个未支付的订单", {
      type: "error"
    });
    return;
  }

  OrderApi.batchPay(filteredIds).then(() => {
    message("支付成功", {
      type: "success"
    });
    emit("refresh");
  });
};

const commit = () => {
  const filteredIds: number[] = [];

  props.tableRef.clearSelection();

  getSelectedRows().forEach(row => {
    if (row.latest_cert.status == "pending") {
      filteredIds.push(row.id);
      props.tableRef.toggleRowSelection(row);
    }
  });

  if (!filteredIds.length) {
    message("请至少选择一个待提交的订单", {
      type: "error"
    });
    return;
  }

  OrderApi.batchCommit(filteredIds.toString()).then(() => {
    message("提交成功", {
      type: "success"
    });
    emit("refresh");
  });
};

const revalidate = () => {
  const filteredIds: number[] = [];

  props.tableRef.clearSelection();

  getSelectedRows().forEach(row => {
    if (
      row.latest_cert.status == "processing" &&
      row.latest_cert.domain_verify_status !== 2
    ) {
      filteredIds.push(row.id);
      props.tableRef.toggleRowSelection(row);
    }
  });

  if (!filteredIds.length) {
    message(
      "请至少选择一个符合要求的证书 1.状态是处理中 2.所有域名尚未完成验证",
      {
        type: "error"
      }
    );
    return;
  }

  OrderApi.batchRevalidate(filteredIds.toString()).then(() => {
    message("开始验证，请等待几分钟后刷新页面查看结果", {
      type: "success"
    });
    emit("refresh");
  });
};

const sync = () => {
  const filteredIds: number[] = [];

  props.tableRef.clearSelection();

  getSelectedRows().forEach(row => {
    if (
      ["processing", "active", "approving"].includes(row.latest_cert.status)
    ) {
      filteredIds.push(row.id);
      props.tableRef.toggleRowSelection(row);
    }
  });

  if (!filteredIds.length) {
    message("请至少选择一个待验证，已签发，待审核的证书", {
      type: "error"
    });
    return;
  }

  OrderApi.batchSync(filteredIds.toString()).then(() => {
    message("同步成功", {
      type: "success"
    });
    emit("refresh");
  });
};

const commitCancel = () => {
  const filteredIds: number[] = [];

  props.tableRef.clearSelection();

  getSelectedRows().forEach(row => {
    if (
      ["unpaid", "pending"].includes(row.latest_cert.status) ||
      (["processing", "approving", "active"].includes(row.latest_cert.status) &&
        dayjs().diff(dayjs(row.created_at), "seconds") <
          row.product.refund_period * 86400)
    ) {
      filteredIds.push(row.id);
      props.tableRef.toggleRowSelection(row);
    }
  });

  if (!filteredIds.length) {
    message(
      "只有待支付、待提交、待验证、待审核、已签发并在退款期限内的订单可以取消",
      {
        type: "error"
      }
    );
    return;
  }

  OrderApi.batchCommitCancel(filteredIds.toString()).then(() => {
    message("取消成功", {
      type: "success"
    });
    emit("refresh");
  });
};

const revokeCancel = () => {
  const filteredIds: number[] = [];

  props.tableRef.clearSelection();

  getSelectedRows().forEach(row => {
    if (["cancelling"].includes(row.latest_cert.status)) {
      filteredIds.push(row.id);
      props.tableRef.toggleRowSelection(row);
    }
  });

  if (!filteredIds.length) {
    message("只有取消中的证书可以撤销", {
      type: "error"
    });
    return;
  }

  OrderApi.batchRevokeCancel(filteredIds.toString()).then(() => {
    message("撤销取消成功", {
      type: "success"
    });
    emit("refresh");
  });
};
</script>
<style scoped lang="scss">
.batch-buttons {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}

.ml-2 {
  margin-left: 8px;
}
</style>
