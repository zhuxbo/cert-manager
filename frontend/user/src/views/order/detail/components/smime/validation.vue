<template>
  <div
    v-if="['unpaid', 'pending', 'processing'].includes(cert.status)"
    class="descriptions"
  >
    <div style="margin: 10px 0">
      <el-button
        v-if="['unpaid'].includes(cert.status)"
        type="warning"
        @click="pay"
      >
        支付
      </el-button>
      <el-button
        v-if="['pending'].includes(cert.status)"
        type="warning"
        @click="commit"
      >
        提交
      </el-button>
      <el-button
        v-if="['processing'].includes(cert.status)"
        type="primary"
        :loading="loading"
        @click="resendEmail"
      >
        重发验证邮件
      </el-button>
    </div>
  </div>
  <div class="descriptions">
    <table style="width: 100%">
      <tbody>
        <tr>
          <td class="domain">邮箱</td>
          <td :style="{ 'text-align': 'right' }">状态</td>
        </tr>
        <tr>
          <td>{{ order.contact?.email || cert.common_name }}</td>
          <td :style="{ 'text-align': 'right' }">
            <template v-if="['active', 'approving'].includes(cert.status)">
              <el-icon
                color="var(--el-color-success)"
                :size="18"
                style="vertical-align: middle"
                ><SuccessFilled
              /></el-icon>
            </template>
            <template
              v-else-if="
                ['unpaid', 'pending', 'processing'].includes(cert.status)
              "
            >
              <el-icon
                class="is-loading"
                :size="18"
                style="vertical-align: middle"
                ><Loading
              /></el-icon>
            </template>
          </td>
        </tr>
      </tbody>
    </table>
  </div>
</template>

<script setup lang="ts">
import { ref, inject } from "vue";
import { SuccessFilled, Loading } from "@element-plus/icons-vue";
import * as OrderApi from "@/api/order";
import { message } from "@shared/utils";
import { useRoute } from "vue-router";

const get = inject("get") as Function;
const order = inject("order") as any;
const cert = inject("cert") as any;

const loading = ref(false);

const route = useRoute();
const issue_verify = Boolean(
  Number(
    route.query.issue_verify ??
      route.query.verify ??
      (route.query.pay !== undefined ? 0 : 1)
  )
);

const pay = () => {
  OrderApi.pay(order.id, { commit: true, issue_verify: issue_verify }).then(
    res => {
      message(res.msg ? res.msg : "支付成功", { type: "success" });
      get();
    }
  );
};

const commit = () => {
  OrderApi.commit(order.id).then(res => {
    message(res.msg ? res.msg : "提交成功", { type: "success" });
    get();
  });
};

const resendEmail = () => {
  if (loading.value) return;
  loading.value = true;
  OrderApi.revalidate(order.id)
    .then(res => {
      message(res.msg ? res.msg : "验证邮件已重发，请检查邮箱", {
        type: "success"
      });
      get();
    })
    .finally(() => {
      loading.value = false;
    });
};
</script>

<style scoped lang="scss">
.descriptions {
  width: 100%;
  padding: 5px 0 5px 20px;
  margin-bottom: 10px;
  font-size: 14px;
  line-height: 28px;
  white-space: nowrap;
  border-left: 4px solid var(--el-border-color);

  td {
    height: 40px;
    padding: 0 5px;
  }
}

.hint {
  margin-left: 8px;
  font-size: 12px;
  color: var(--el-text-color-placeholder);
}
</style>
