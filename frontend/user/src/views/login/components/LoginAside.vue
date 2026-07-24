<script setup lang="ts">
import { ref } from "vue";
import { getConfig } from "@/config";
import { defaultLoginImagePath, resolveSiteLoginImage } from "@shared/utils";

defineOptions({
  name: "LoginAside"
});

// 登录配图三级回落：后台上传 → 默认 login.svg → 纯色面板
const configuredLoginImage = (getConfig("LoginImage") as string) || "";
const hasCustomImage = Boolean(configuredLoginImage);
const asideImage = resolveSiteLoginImage(
  configuredLoginImage,
  defaultLoginImagePath(import.meta.env.BASE_URL)
);
const asideSolid = ref(false);
// 仅默认 login.svg 缺失时降级纯色；后台上传地址加载失败不替换
const handleAsideImageError = () => {
  asideSolid.value = true;
};
</script>

<template>
  <div class="login-aside" :class="{ 'is-solid': asideSolid }">
    <!-- 已上传配图：整图展示，不覆盖文字 -->
    <img v-if="hasCustomImage" :src="asideImage" class="aside-cover" alt="" />
    <!-- 未配置：默认 login.svg + 标语；SVG 缺失时纯色 + 标语 -->
    <template v-else>
      <img
        v-if="!asideSolid"
        :src="asideImage"
        class="aside-illustration"
        alt=""
        @error="handleAsideImageError"
      />
      <div class="aside-slogan">
        <div class="aside-slogan-title">一站式 SSL 证书服务</div>
        <div class="aside-slogan-desc">申请 · 签发 · 部署 · 监控</div>
      </div>
    </template>
  </div>
</template>

<style scoped>
/* 左侧配图面板：与右侧登录区左右平分；默认浅蓝底，纯色回落时切主题色底 */
.login-aside {
  position: relative;
  display: flex;
  flex-direction: column;
  gap: 28px;
  align-items: center;
  justify-content: center;
  width: 50%;
  overflow: hidden;
  background: #ecf5ff;
}

html.dark .login-aside {
  background: #141e2c;
}

.login-aside.is-solid,
html.dark .login-aside.is-solid {
  background: var(--el-color-primary);
}

/* 后台上传配图：整图覆盖展示 */
.aside-cover {
  position: absolute;
  inset: 0;
  width: 100%;
  height: 100%;
  object-fit: cover;
}

/* 默认 login.svg 插画 */
.aside-illustration {
  width: 52%;
  min-width: 240px;
  max-width: 400px;
}

/* img 引用的静态 SVG 无法感知 html.dark，用滤镜整体压暗融入深色面板 */
html.dark .aside-illustration {
  opacity: 0.92;
  filter: brightness(0.8);
}

.aside-slogan {
  text-align: center;
}

.aside-slogan-title {
  font-size: 20px;
  font-weight: 500;
  color: var(--el-color-primary);
}

.aside-slogan-desc {
  margin-top: 8px;
  font-size: 14px;
  color: var(--el-text-color-secondary);
  letter-spacing: 2px;
}

.is-solid .aside-slogan-title {
  color: #fff;
}

.is-solid .aside-slogan-desc {
  color: rgb(255 255 255 / 75%);
}

/* stylelint-disable-next-line order/order -- 媒体查询覆盖须位于基础规则之后，否则同优先级被基础规则反超 */
@media screen and (width <= 968px) {
  .login-aside {
    display: none;
  }
}
</style>
