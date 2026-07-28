<script setup lang="ts">
import Motion from "./utils/motion";
import { useRouter } from "vue-router";
import { message } from "@shared/utils";
import { useNav } from "@/layout/hooks/useNav";
import type { FormInstance } from "element-plus";
import { useLayout } from "@/layout/hooks/useLayout";
import { useRenderIcon } from "@shared/components/ReIcon/src/hooks";
import { ref, reactive, onMounted, onBeforeUnmount, watch } from "vue";
import { useDataThemeChange } from "@/layout/hooks/useDataThemeChange";

import { addPathMatch, getTopMenu } from "@/router/utils";
import { useUserStoreHook } from "@/store/modules/user";
import { usePermissionStoreHook } from "@/store/modules/permission";

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
const loading = ref(false);
const ruleFormRef = ref<FormInstance>();
const checked = ref(false);
const disabled = ref(false);

const { initStorage } = useLayout();
initStorage();

const { dataTheme, overallStyle, dataThemeChange } = useDataThemeChange();
dataThemeChange(overallStyle.value);
const { title } = useNav();

const ruleForm = reactive({
  account: "",
  password: ""
});

const onLogin = (formEl: FormInstance | undefined) => {
  if (!formEl) return;
  formEl.validate(valid => {
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
          loading.value = false;
        })
        .finally(() => {
          loading.value = false;
        });
    }
  });
};

watch(checked, bool => {
  useUserStoreHook().SET_ISREMEMBERED(bool);
});
watch(loginDay, value => {
  useUserStoreHook().SET_LOGINDAY(value);
});

/** 使用公共函数，避免`removeEventListener`失效 */
function onkeypress({ code }: KeyboardEvent) {
  if (["Enter", "NumpadEnter"].includes(code)) {
    onLogin(ruleFormRef.value);
  }
}

onMounted(() => {
  window.document.addEventListener("keypress", onkeypress);
});

onBeforeUnmount(() => {
  window.document.removeEventListener("keypress", onkeypress);
});
</script>

<template>
  <div class="select-none login-page">
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
    <div class="login-card">
      <div class="login-form">
        <Motion>
          <h2 class="login-title outline-none">{{ title }}</h2>
        </Motion>

        <el-form ref="ruleFormRef" :model="ruleForm" size="large">
          <Motion :delay="100">
            <el-form-item
              :rules="[
                {
                  required: true,
                  message: '请输入账号',
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
            <el-form-item
              :rules="[
                {
                  required: true,
                  message: '请输入密码',
                  trigger: 'blur'
                }
              ]"
              prop="password"
            >
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
                        width: loginDay < 10 ? '12px' : '20px',
                        border: 'none',
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
        </el-form>
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
