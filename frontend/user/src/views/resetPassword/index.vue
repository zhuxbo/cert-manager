<script setup lang="ts">
import { ref, reactive } from "vue";
import Motion from "../login/utils/motion";
import LoginAside from "../login/components/LoginAside.vue";
import { message } from "@shared/utils";
import { updateRules } from "../login/utils/rule";
import type { FormInstance } from "element-plus";
import { useVerifyCode } from "../login/utils/verifyCode";
import { useRouter } from "vue-router";
import { useRenderIcon } from "@shared/components/ReIcon/src/hooks";
import { sendEmailCode } from "@/api/verifyCode";
import { resetPassword } from "@/api/auth";
import { useDataThemeChange } from "@/layout/hooks/useDataThemeChange";
import { useLayout } from "@/layout/hooks/useLayout";
import { useNav } from "@/layout/hooks/useNav";
import dayIcon from "@/assets/svg/day.svg?component";
import darkIcon from "@/assets/svg/dark.svg?component";
import Lock from "~icons/ri/lock-fill";
import Email from "~icons/ep/message";

defineOptions({
  name: "ResetPassword"
});

const loading = ref(false);
const router = useRouter();
const ruleForm = reactive({
  email: "",
  verifyCode: "",
  password: "",
  repeatPassword: ""
});
const ruleFormRef = ref<FormInstance>();
const { isDisabled, text, start, end } = useVerifyCode();
const repeatPasswordRule = [
  {
    validator: (rule, value, callback) => {
      if (value === "") {
        callback(new Error("请再次输入密码"));
      } else if (ruleForm.password !== value) {
        callback(new Error("两次输入密码不一致"));
      } else {
        callback();
      }
    },
    trigger: "blur"
  }
];

const { initStorage } = useLayout();
initStorage();
const { dataTheme, overallStyle, dataThemeChange } = useDataThemeChange();
dataThemeChange(overallStyle.value);
const { title } = useNav();

const sendVerifyCode = async (formEl: FormInstance | undefined) => {
  if (!formEl) return;

  // 验证邮箱字段
  await formEl.validateField("email", valid => {
    if (valid) {
      sendEmailCode({
        email: ruleForm.email,
        type: "reset"
      })
        .then(res => {
          if (res.code === 1) {
            start();
            message("验证码已发送到邮箱", { type: "success" });
          } else {
            message("验证码发送失败：" + res.msg, { type: "error" });
          }
        })
        .catch(() => {
          message("验证码发送失败，请稍后重试", { type: "error" });
        });
    }
  });
};

const onReset = async (formEl: FormInstance | undefined) => {
  loading.value = true;
  if (!formEl) return;
  await formEl.validate(valid => {
    if (valid) {
      // 调用重置密码API
      resetPassword({
        email: ruleForm.email,
        code: ruleForm.verifyCode,
        password: ruleForm.password
      })
        .then(res => {
          if (res.code === 1) {
            message("密码重置成功", { type: "success" });
            // 重置密码成功后回到登录页
            goToLogin();
          } else {
            message("密码重置失败：" + res.msg, { type: "error" });
          }
          loading.value = false;
        })
        .catch(() => {
          loading.value = false;
          message("重置失败，请稍后重试", { type: "error" });
        });
    } else {
      loading.value = false;
    }
  });
};

function goToLogin() {
  end();
  router.push("/login");
}
</script>

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
            <h2 class="login-title outline-none">{{ title }} - 找回密码</h2>
          </Motion>

          <el-form
            ref="ruleFormRef"
            :model="ruleForm"
            :rules="updateRules"
            size="large"
          >
            <Motion>
              <el-form-item prop="email">
                <el-input
                  v-model="ruleForm.email"
                  clearable
                  placeholder="邮箱"
                  :prefix-icon="useRenderIcon(Email)"
                />
              </el-form-item>
            </Motion>

            <Motion :delay="100">
              <el-form-item prop="verifyCode">
                <div class="w-full flex justify-between">
                  <el-input
                    v-model="ruleForm.verifyCode"
                    clearable
                    placeholder="邮箱验证码"
                    :prefix-icon="useRenderIcon('ri:shield-keyhole-line')"
                  />
                  <el-button
                    :disabled="isDisabled"
                    class="ml-2!"
                    @click="sendVerifyCode(ruleFormRef)"
                  >
                    {{ text.length > 0 ? text + "秒后重新获取" : "获取验证码" }}
                  </el-button>
                </div>
              </el-form-item>
            </Motion>

            <Motion :delay="150">
              <el-form-item prop="password">
                <el-input
                  v-model="ruleForm.password"
                  clearable
                  show-password
                  placeholder="新密码"
                  :prefix-icon="useRenderIcon(Lock)"
                />
              </el-form-item>
            </Motion>

            <Motion :delay="200">
              <el-form-item :rules="repeatPasswordRule" prop="repeatPassword">
                <el-input
                  v-model="ruleForm.repeatPassword"
                  clearable
                  show-password
                  placeholder="确认密码"
                  :prefix-icon="useRenderIcon(Lock)"
                />
              </el-form-item>
            </Motion>

            <Motion :delay="250">
              <el-form-item>
                <el-button
                  class="w-full"
                  size="default"
                  type="primary"
                  :loading="loading"
                  @click="onReset(ruleFormRef)"
                >
                  确定
                </el-button>
              </el-form-item>
            </Motion>

            <Motion :delay="300">
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

<style scoped>
@import url("@/style/login.css");
</style>

<style lang="scss" scoped>
:deep(.el-input-group__append, .el-input-group__prepend) {
  padding: 0;
}
</style>
