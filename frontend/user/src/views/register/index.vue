<template>
  <div class="select-none login-page">
    <LoginAside />
    <div class="login-main">
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
      <div class="login-box">
        <div class="login-form">
          <Motion :delay="300">
            <h2 class="login-title outline-none">{{ title }}</h2>
          </Motion>

          <el-form
            ref="formRef"
            :model="registerForm"
            :rules="rules"
            size="large"
          >
            <Motion :delay="100">
              <el-form-item prop="username">
                <el-input
                  v-model="registerForm.username"
                  clearable
                  placeholder="用户名"
                  :prefix-icon="useRenderIcon(User)"
                />
              </el-form-item>
            </Motion>

            <Motion :delay="150">
              <el-form-item prop="password">
                <el-input
                  v-model="registerForm.password"
                  clearable
                  show-password
                  placeholder="密码"
                  :prefix-icon="useRenderIcon(Lock)"
                />
              </el-form-item>
            </Motion>

            <Motion :delay="200">
              <el-form-item prop="confirmPassword">
                <el-input
                  v-model="registerForm.confirmPassword"
                  clearable
                  show-password
                  placeholder="确认密码"
                  :prefix-icon="useRenderIcon(Lock)"
                />
              </el-form-item>
            </Motion>

            <Motion :delay="250">
              <el-form-item prop="email">
                <el-input
                  v-model="registerForm.email"
                  clearable
                  placeholder="邮箱"
                  :prefix-icon="useRenderIcon(Email)"
                />
              </el-form-item>
            </Motion>

            <Motion :delay="300">
              <el-form-item prop="verifyCode">
                <div class="w-full flex justify-between">
                  <el-input
                    v-model="registerForm.verifyCode"
                    clearable
                    placeholder="邮箱验证码"
                    :prefix-icon="useRenderIcon('ri:shield-keyhole-line')"
                  />
                  <el-button
                    :disabled="isDisabled"
                    class="ml-2!"
                    @click="sendVerifyCode"
                  >
                    {{ text.length > 0 ? text + "秒后重新获取" : "获取验证码" }}
                  </el-button>
                </div>
              </el-form-item>
            </Motion>

            <Motion :delay="350">
              <el-form-item>
                <el-button
                  class="w-full"
                  size="default"
                  type="primary"
                  :loading="loading"
                  @click="handleRegister"
                >
                  注册
                </el-button>
              </el-form-item>
            </Motion>

            <Motion :delay="400">
              <el-form-item>
                <el-button class="w-full" size="default" @click="goToLogin">
                  返回登录
                </el-button>
              </el-form-item>
            </Motion>
          </el-form>
        </div>
      </div>
      <div
        class="w-full flex-c absolute bottom-3 text-sm text-[rgba(0,0,0,0.6)] dark:text-[rgba(220,220,242,0.8)]"
      >
        Copyright © 2020-present
        <a class="hover:text-primary" href="/" target="_blank">
          &nbsp;{{ title }}
        </a>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, reactive, onMounted } from "vue";
import { useRouter, useRoute } from "vue-router";
import { message } from "@shared/utils";
import type { FormInstance } from "element-plus";
import { useRenderIcon } from "@shared/components/ReIcon/src/hooks";
import { register } from "@/api/auth";
import { sendEmailCode } from "@/api/verifyCode";
import { useVerifyCode } from "@/views/login/utils/verifyCode";
import Motion from "@/views/login/utils/motion";
import LoginAside from "@/views/login/components/LoginAside.vue";
import { useNav } from "@/layout/hooks/useNav";
import { useLayout } from "@/layout/hooks/useLayout";
import { useDataThemeChange } from "@/layout/hooks/useDataThemeChange";
import Lock from "~icons/ri/lock-fill";
import Email from "~icons/ep/message";
import User from "~icons/ri/user-3-fill";
import dayIcon from "@/assets/svg/day.svg?component";
import darkIcon from "@/assets/svg/dark.svg?component";

defineOptions({
  name: "Register"
});

interface RegisterForm {
  username: string;
  password: string;
  confirmPassword: string;
  email: string;
  verifyCode: string;
}

const router = useRouter();
const route = useRoute();
const formRef = ref<FormInstance | null>(null);
const loading = ref(false);
const { isDisabled, text, start } = useVerifyCode();

const { initStorage } = useLayout();
initStorage();
const { dataTheme, overallStyle, dataThemeChange } = useDataThemeChange();
dataThemeChange(overallStyle.value);
const { title } = useNav();

const registerForm = reactive<RegisterForm>({
  username: "",
  password: "",
  confirmPassword: "",
  email: "",
  verifyCode: ""
});

// Source参数处理
const SOURCE_STORAGE_KEY = "registration_source";

// 保存source到localStorage，有效期3天
const saveSourceToStorage = (source: string) => {
  const expiryTime = Date.now() + 3 * 24 * 60 * 60 * 1000; // 3天
  const data = {
    value: source,
    expiry: expiryTime
  };
  localStorage.setItem(SOURCE_STORAGE_KEY, JSON.stringify(data));
};

// 从localStorage获取source
const getSourceFromStorage = (): string | null => {
  const item = localStorage.getItem(SOURCE_STORAGE_KEY);
  if (!item) return null;

  try {
    const data = JSON.parse(item);
    if (Date.now() > data.expiry) {
      localStorage.removeItem(SOURCE_STORAGE_KEY);
      return null;
    }
    return data.value;
  } catch {
    localStorage.removeItem(SOURCE_STORAGE_KEY);
    return null;
  }
};

// 清除localStorage中的source
const clearSourceFromStorage = () => {
  localStorage.removeItem(SOURCE_STORAGE_KEY);
};

// 组件挂载时检测source参数
onMounted(() => {
  const source = route.query.source as string;
  if (source) {
    saveSourceToStorage(source);
  }
});

const rules = {
  username: [
    {
      required: true,
      message: "请输入用户名",
      trigger: "blur"
    }
  ],
  password: [
    {
      required: true,
      message: "请输入密码",
      trigger: "blur"
    },
    {
      min: 6,
      message: "密码长度不能少于6位",
      trigger: "blur"
    }
  ],
  confirmPassword: [
    {
      required: true,
      message: "请确认密码",
      trigger: "blur"
    },
    {
      validator: (rule, value, callback) => {
        if (value === "") {
          callback(new Error("请再次输入密码"));
        } else if (registerForm.password !== value) {
          callback(new Error("两次输入密码不一致"));
        } else {
          callback();
        }
      },
      trigger: "blur"
    }
  ],
  email: [
    {
      required: true,
      message: "请输入邮箱",
      trigger: "blur"
    },
    {
      pattern: /^[a-zA-Z0-9._-]+@[a-zA-Z0-9_-]+(\.[a-zA-Z0-9_-]+)+$/,
      message: "请输入正确的邮箱格式",
      trigger: "blur"
    }
  ],
  verifyCode: [
    {
      required: true,
      message: "请输入验证码",
      trigger: "blur"
    }
  ]
};

const sendVerifyCode = async () => {
  if (!registerForm.email) {
    message("请先输入邮箱", { type: "warning" });
    return;
  }

  try {
    await sendEmailCode({
      email: registerForm.email,
      type: "register"
    });
    message("验证码已发送，请查收邮件", { type: "success" });
    start();
  } catch (error) {
    message("验证码发送失败，请稍后重试", { type: "error" });
  }
};

function goToLogin() {
  router.push("/login");
}

async function handleRegister() {
  if (!formRef.value) return;

  formRef.value.validate(async valid => {
    if (!valid) return;

    loading.value = true;

    try {
      // 从localStorage获取source参数
      const source = getSourceFromStorage();

      // 构建注册参数，如果有source则包含进去
      const registerParams = {
        username: registerForm.username,
        password: registerForm.password,
        email: registerForm.email,
        code: registerForm.verifyCode,
        ...(source && { source }) // 如果source存在则添加到参数中
      };

      await register(registerParams);

      message("注册成功，请登录", { type: "success" });

      // 注册成功后清除localStorage中的source
      clearSourceFromStorage();

      router.push("/login");
    } catch (error) {
      message("注册失败，请重试", { type: "error" });
    } finally {
      loading.value = false;
    }
  });
}
</script>

<style scoped>
@import url("@/style/login.css");
</style>

<style lang="scss" scoped>
:deep(.el-input-group__append, .el-input-group__prepend) {
  padding: 0;
}
</style>
