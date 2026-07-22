<script setup lang="ts">
import Motion from "./utils/motion";
import { useRouter, useRoute } from "vue-router";
import { message, resolveCopyrightStart } from "@shared/utils";
import { loginRules } from "./utils/rule";
import { debounce } from "@pureadmin/utils";
import { useNav } from "@/layout/hooks/useNav";
import { useEventListener } from "@vueuse/core";
import type { FormInstance } from "element-plus";
import { useLayout } from "@/layout/hooks/useLayout";
import { bg, illustration } from "./utils/static";
import { ref, toRaw, reactive, watch, onMounted } from "vue";
import { useRenderIcon } from "@shared/components/ReIcon/src/hooks";
import { useDataThemeChange } from "@/layout/hooks/useDataThemeChange";
import { getConfig } from "@/config";

import { addPathMatch, getTopMenu } from "@/router/utils";
import { useUserStoreHook } from "@/store/modules/user";
import { usePermissionStoreHook } from "@/store/modules/permission";
import { setToken } from "@/utils/auth";
import { getProfile } from "@/api/auth";

import dayIcon from "@/assets/svg/day.svg?component";
import darkIcon from "@/assets/svg/dark.svg?component";
import Lock from "~icons/ri/lock-fill";
import User from "~icons/ri/user-3-fill";
import Info from "~icons/ri/information-line";

defineOptions({
  name: "Login"
});

const loginDay = ref(7);
const router = useRouter();
const route = useRoute();
const loading = ref(false);
const checked = ref(false);
const disabled = ref(false);
const ruleFormRef = ref<FormInstance>();

const { initStorage } = useLayout();
initStorage();
const { dataTheme, overallStyle, dataThemeChange } = useDataThemeChange();
dataThemeChange(overallStyle.value);
const { title } = useNav();
const currentYear = new Date().getFullYear();
const copyStart = resolveCopyrightStart(getConfig("CopyStart"), currentYear);

const ruleForm = reactive({
  account: "",
  password: ""
});

// 自动token登录功能
const handleAutoTokenLogin = async () => {
  const autoToken = route.query.auto_token as string;

  if (autoToken) {
    loading.value = true;
    try {
      // 计算过期时间（2小时后）
      const expiresTime = new Date(Date.now() + 2 * 60 * 60 * 1000);

      // 使用token设置认证信息
      const tokenData = {
        access_token: autoToken,
        refresh_token: "", // 管理员登录不需要refresh token
        expires_in: expiresTime, // 使用Date对象
        username: "",
        balance: "0.00",
        roles: [],
        permissions: []
      };

      // 先设置token
      setToken(tokenData);

      // 获取用户信息
      const userInfo = await getProfile();

      if (userInfo?.code === 1 && userInfo.data) {
        // 更新token数据包含用户信息
        const updatedTokenData = {
          ...tokenData,
          username: userInfo.data.username || "",
          balance: userInfo.data.balance || "0.00",
          roles: userInfo.data.roles || [],
          permissions: userInfo.data.permissions || []
        };
        setToken(updatedTokenData);
      }

      // 设置路由和跳转
      usePermissionStoreHook().handleWholeMenus([]);
      addPathMatch();

      // 移除URL中的auto_token参数
      const cleanQuery = { ...route.query };
      delete cleanQuery.auto_token;

      await router.replace({
        path: route.path,
        query: cleanQuery
      });

      // 跳转到主页
      router.push(getTopMenu(true).path);
      message("管理员代理登录成功", { type: "success" });
    } catch (error) {
      message("自动登录失败，请手动登录", { type: "error" });
    } finally {
      loading.value = false;
    }
  }
};

const onLogin = (formEl: FormInstance | undefined) => {
  if (!formEl) return;
  formEl.validate().then(valid => {
    if (valid) {
      loading.value = true;
      useUserStoreHook()
        .loginByAccount({
          account: ruleForm.account,
          password: ruleForm.password
        })
        .then(res => {
          if (res.code === 1) {
            // 全部采取静态路由模式
            usePermissionStoreHook().handleWholeMenus([]);
            addPathMatch();
            router.push(getTopMenu(true).path);
            message("登录成功", { type: "success" });
          } else {
            message(res.msg, { type: "error" });
          }
        })
        .catch(() => {
          message("登录失败", { type: "error" });
        })
        .finally(() => {
          loading.value = false;
        });
    }
  });
};

const goToRegister = () => {
  router.push("/register");
};

const goToResetPassword = () => {
  router.push("/reset-password");
};

const immediateDebounce: any = debounce(
  formRef => onLogin(formRef),
  1000,
  true
);

useEventListener(document, "keypress", ({ code }) => {
  if (
    ["Enter", "NumpadEnter"].includes(code) &&
    !disabled.value &&
    !loading.value
  )
    immediateDebounce(ruleFormRef.value);
});

watch(checked, bool => {
  useUserStoreHook().SET_ISREMEMBERED(bool);
});
watch(loginDay, value => {
  useUserStoreHook().SET_LOGINDAY(value);
});

// 页面挂载时检查自动登录
onMounted(() => {
  handleAutoTokenLogin();
});
</script>

<template>
  <div class="select-none">
    <img :src="bg" class="wave" />
    <div class="flex-c absolute right-5 top-3">
      <!-- 主题 -->
      <el-switch
        v-model="dataTheme"
        inline-prompt
        :active-icon="dayIcon"
        :inactive-icon="darkIcon"
        @change="dataThemeChange"
      />
    </div>
    <div class="login-container">
      <div class="img">
        <component :is="toRaw(illustration)" />
      </div>
      <div class="login-box">
        <div class="login-form">
          <Motion :delay="300">
            <h2 class="outline-none">{{ title }}</h2>
          </Motion>

          <el-form
            ref="ruleFormRef"
            :model="ruleForm"
            :rules="loginRules"
            size="large"
          >
            <Motion :delay="100">
              <el-form-item
                :rules="[
                  {
                    required: true,
                    message: '请输入用户名/邮箱/手机号',
                    trigger: 'blur'
                  }
                ]"
                prop="account"
              >
                <el-input
                  v-model="ruleForm.account"
                  clearable
                  placeholder="用户名/邮箱/手机号"
                  :prefix-icon="useRenderIcon(User)"
                />
              </el-form-item>
            </Motion>

            <Motion :delay="150">
              <el-form-item prop="password">
                <el-input
                  v-model="ruleForm.password"
                  clearable
                  show-password
                  placeholder="密码"
                  :prefix-icon="useRenderIcon(Lock)"
                />
              </el-form-item>
            </Motion>

            <Motion :delay="250">
              <el-form-item>
                <div class="w-full h-[20px] flex justify-between items-center">
                  <el-checkbox v-model="checked">
                    <span class="flex">
                      记住我
                      <select
                        v-model="loginDay"
                        :style="{
                          width: loginDay < 10 ? '10px' : '16px',
                          outline: 'none',
                          background: 'none',
                          appearance: 'none',
                          marginLeft: '5px'
                        }"
                      >
                        <option value="1">1</option>
                        <option value="7">7</option>
                        <option value="30">30</option>
                      </select>
                      天
                      <IconifyIconOffline
                        v-tippy="{
                          content: '登录信息保存天数',
                          placement: 'top'
                        }"
                        :icon="Info"
                        class="ml-1"
                      />
                    </span>
                  </el-checkbox>
                  <el-button link type="primary" @click="goToResetPassword">
                    忘记密码？
                  </el-button>
                </div>
                <el-button
                  class="w-full mt-4!"
                  size="default"
                  type="primary"
                  :loading="loading"
                  :disabled="disabled"
                  @click="onLogin(ruleFormRef)"
                >
                  登录
                </el-button>
              </el-form-item>
            </Motion>

            <Motion :delay="300">
              <el-form-item>
                <div class="w-full h-[20px] flex justify-between items-center">
                  <el-button
                    class="w-full mt-4"
                    size="default"
                    @click="goToRegister"
                  >
                    注册
                  </el-button>
                </div>
              </el-form-item>
            </Motion>
          </el-form>
        </div>
      </div>
    </div>
    <div
      class="w-full flex-c absolute bottom-3 text-sm text-[rgba(0,0,0,0.6)] dark:text-[rgba(220,220,242,0.8)]"
    >
      Copyright © {{ copyStart }}-{{ currentYear }}
      <a class="hover:text-primary" href="/" target="_blank">
        &nbsp;{{ title }}
      </a>
      <a
        class="hover:text-primary"
        href="https://beian.miit.gov.cn/"
        target="_blank"
      >
        &nbsp;{{ getConfig("Beian") }}
      </a>
    </div>
  </div>
</template>

<style scoped>
@import url("@/style/login.css");
</style>

<style lang="scss" scoped>
:deep(.el-input-group__append, .el-input-group__prepend) {
  padding: 0;
}
</style>
