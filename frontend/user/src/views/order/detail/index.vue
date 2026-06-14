<template>
  <el-scrollbar class="horizontal-scrollbar" wrap-class="scroll-wrapper">
    <div class="box">
      <!-- 根据产品类型渲染不同组件 -->
      <SslDetail v-if="productType === 'ssl'" />
      <SmimeDetail v-else-if="productType === 'smime'" />
      <CodesignDetail v-else-if="productType === 'codesign'" />
      <DocsignDetail v-else-if="productType === 'docsign'" />
      <!-- 兜底：未知类型使用 SSL -->
      <SslDetail v-else />
    </div>
  </el-scrollbar>
</template>
<script setup lang="ts">
import { computed, provide, reactive, toRefs } from "vue";
import { buildUUID } from "@pureadmin/utils";
import SslDetail from "./components/ssl/index.vue";
import SmimeDetail from "./components/smime/index.vue";
import CodesignDetail from "./components/codesign/index.vue";
import DocsignDetail from "./components/docsign/index.vue";
import * as OrderApi from "@/api/order";
import { message } from "@shared/utils";

const props = defineProps(["modelValue"]);

const order = reactive(props.modelValue);
provide("order", order);

// 产品类型
const productType = computed(() => order.product?.product_type || "ssl");
provide("productType", productType);

const { latest_cert: cert } = toRefs(order);
provide("cert", cert);

const sync = (notification = false) => {
  OrderApi.sync(order.id).then(() => {
    OrderApi.show(order.id).then(res => {
      res.data.sync = buildUUID();
      Object.assign(order, reactive(res.data));
      notification && message("同步成功", { type: "success" });
    });
  });
};
const get = (notification = false) => {
  OrderApi.show(order.id).then(res => {
    res.data.sync = buildUUID();
    Object.assign(order, reactive(res.data));
    notification && message("刷新成功", { type: "success" });
  });
};
provide("sync", sync);
provide("get", get);

// 定时刷新已上提到父级 details.vue（单个 batchShow 批量刷新），此处不再各自轮询，
// 消除多卡片并发请求风暴。get / sync 保留，供子组件操作后单卡刷新。
</script>
<style scoped lang="scss">
@import url("./styles/detail.scss");
</style>
