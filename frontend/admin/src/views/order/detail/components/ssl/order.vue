<template>
  <el-card shadow="never" :style="{ border: 'none' }">
    <h2 class="title">
      <span>订单详情</span>
    </h2>
    <table class="descriptions">
      <tbody>
        <tr>
          <td class="label">用户</td>
          <td class="content">{{ order.user.username }}</td>
        </tr>
        <tr>
          <td class="label">订单ID</td>
          <td class="content">{{ order.id }}</td>
        </tr>
        <tr>
          <td class="label">品牌</td>
          <td class="content">
            {{ brandLabels[order.brand?.toLowerCase()] || order.brand }}
          </td>
        </tr>
        <tr>
          <td class="label">产品</td>
          <td class="content">{{ order.product.name }}</td>
        </tr>
        <tr>
          <td class="label">金额</td>
          <td class="content">
            {{ order.amount }}
            <el-button
              v-if="order.latest_cert?.status === 'unpaid'"
              style="padding: 0 5px 2px; margin: 0"
              type="primary"
              link
              size="small"
              @click="updateAmount"
            >
              点击修改
            </el-button>
          </td>
        </tr>
        <tr>
          <td class="label">购买时长</td>
          <td class="content">{{ periodLabels[order.period] }}</td>
        </tr>
        <tr>
          <td class="label">创建时间</td>
          <td class="content">
            {{
              order.created_at
                ? dayjs(order.created_at).format("YYYY-MM-DD HH:mm:ss")
                : ""
            }}
          </td>
        </tr>
        <tr>
          <td class="label">有效期从</td>
          <td class="content">
            {{
              order.period_from
                ? dayjs(order.period_from).format("YYYY-MM-DD HH:mm:ss")
                : ""
            }}
          </td>
        </tr>
        <tr>
          <td class="label">有效期到</td>
          <td class="content">
            {{
              order.period_till
                ? dayjs(order.period_till).format("YYYY-MM-DD HH:mm:ss")
                : ""
            }}
          </td>
        </tr>
        <tr>
          <td class="label">已购</td>
          <td class="content">
            {{
              order.purchased_standard_count
                ? order.purchased_standard_count + "个标准域名"
                : ""
            }}
            {{
              order.purchased_standard_count && order.purchased_wildcard_count
                ? "/"
                : ""
            }}
            {{
              order.purchased_wildcard_count
                ? order.purchased_wildcard_count + "个通配符"
                : ""
            }}
          </td>
        </tr>
        <tr v-if="order.organization">
          <td class="label">组织</td>
          <td class="content">
            {{ order.organization.name }},
            {{ order.organization.registration_number }}<br />
            {{ order.organization.phone }}, {{ order.organization.country }},
            {{ order.organization.state }}, {{ order.organization.city }},
            {{ order.organization.address }}, {{ order.organization.postcode
            }}<br />
            <el-button
              v-if="canEditApplicant"
              type="primary"
              link
              size="small"
              @click="editApplicant('organization')"
            >
              点击修改
            </el-button>
          </td>
        </tr>
        <tr v-if="order.contact">
          <td class="label">联系人</td>
          <td class="content">
            {{ order.contact.last_name }}
            {{ order.contact.first_name }}, {{ order.contact.title }},
            {{ order.contact.phone }}, {{ order.contact.email }}<br />
            <el-button
              v-if="canEditApplicant"
              type="primary"
              link
              size="small"
              @click="editApplicant('contact')"
            >
              点击修改
            </el-button>
          </td>
        </tr>
        <tr>
          <td class="label">会员备注</td>
          <td class="content">
            {{ order.remark }}
          </td>
        </tr>
        <tr>
          <td class="label">管理员备注</td>
          <td class="content">
            {{ order.admin_remark }}
            <el-button
              style="padding: 0 5px 2px; margin: 0"
              type="primary"
              link
              size="small"
              @click="remark"
            >
              {{ order.admin_remark ? "点击修改" : "点击添加" }}
            </el-button>
          </td>
        </tr>
        <tr v-if="!isApi">
          <td class="label">自动续费</td>
          <td class="content">
            <el-select
              :model-value="toSelectValue(order.auto_renew)"
              :loading="autoLoading"
              size="small"
              style="width: 115px"
              @change="(val: string) => updateAutoSetting('auto_renew', val)"
            >
              <el-option value="global" label="使用全局设置" />
              <el-option value="1" label="开启" />
              <el-option value="0" label="关闭" />
            </el-select>
          </td>
        </tr>
        <tr v-if="!isApi">
          <td class="label">自动重签</td>
          <td class="content">
            <el-select
              :model-value="toSelectValue(order.auto_reissue)"
              :loading="autoLoading"
              size="small"
              style="width: 115px"
              @change="(val: string) => updateAutoSetting('auto_reissue', val)"
            >
              <el-option value="global" label="使用全局设置" />
              <el-option value="1" label="开启" />
              <el-option value="0" label="关闭" />
            </el-select>
          </td>
        </tr>
      </tbody>
    </table>
    <ApplicantEditor
      v-model:visible="applicantEditorVisible"
      :order="order"
      :section="applicantSection"
      @success="onApplicantUpdated"
    />
  </el-card>
</template>
<script setup lang="ts">
import { computed, inject, reactive, ref } from "vue";
import { buildUUID } from "@pureadmin/utils";
import { ElMessageBox } from "element-plus";
import * as OrderApi from "@/api/order";
import { message } from "@shared/utils";
import { brandLabels, periodLabels } from "@/views/system/dictionary";
import dayjs from "dayjs";
import ApplicantEditor from "../applicantEditor.vue";

const order = inject("order") as any;
const isApi = computed(() => order.latest_cert?.channel === "api");
const canEditApplicant = computed(() =>
  ["unpaid", "pending"].includes(order.latest_cert?.status)
);
const applicantEditorVisible = ref(false);
const applicantSection = ref<"organization" | "contact">("organization");

const editApplicant = (section: "organization" | "contact") => {
  applicantSection.value = section;
  applicantEditorVisible.value = true;
};

const onApplicantUpdated = (data: any) => {
  data.organization && (order.organization = data.organization);
  data.contact && (order.contact = data.contact);
};

const autoLoading = ref(false);

// 将 boolean | null 转换为 select 的字符串值
const toSelectValue = (val: boolean | null | undefined): string => {
  if (val === true) return "1";
  if (val === false) return "0";
  return "global";
};

// 将 select 的字符串值转换为 API 发送值
// 注意：前端全局过滤 null，所以用 "global" 字符串代替
const fromSelectValue = (val: string): boolean | string => {
  if (val === "1") return true;
  if (val === "0") return false;
  return "global";
};

const updateAutoSetting = async (
  key: "auto_renew" | "auto_reissue",
  selectValue: string
) => {
  // 比较时统一转换为 select 值进行比较
  if (toSelectValue(order[key]) === selectValue) {
    return;
  }

  const apiValue = fromSelectValue(selectValue);
  // 存储值："global" -> null, true/false 保持原样
  const storeValue = apiValue === "global" ? null : apiValue;

  autoLoading.value = true;
  try {
    await OrderApi.updateAutoSettings(order.id, { [key]: apiValue });
    order[key] = storeValue;
    message("设置已更新", { type: "success" });
  } catch (e) {
    message("更新失败", { type: "error" });
  } finally {
    autoLoading.value = false;
  }
};

const updateAmount = () => {
  ElMessageBox.prompt("请输入新的价格", "修改价格", {
    confirmButtonText: "确定",
    cancelButtonText: "取消",
    inputValue: String(order.latest_cert?.amount ?? order.amount),
    inputPattern: /^\d+(\.\d{1,2})?$/,
    inputErrorMessage: "请输入有效的金额"
  }).then(({ value }) => {
    OrderApi.updateAmount(order.id, value).then(() => {
      message("价格已更新", { type: "success" });
      OrderApi.show(order.id).then(res => {
        res.data.sync = buildUUID();
        Object.assign(order, reactive(res.data));
      });
    });
  });
};

const remark = () => {
  ElMessageBox.prompt("请填写备注，删除备注留空即可", "备注", {
    confirmButtonText: "确定",
    cancelButtonText: "取消"
  }).then(({ value }) => {
    OrderApi.remark(order.id, value ?? "").then(() => {
      message("备注已更新", { type: "success" });
      OrderApi.show(order.id).then(res => {
        res.data.sync = buildUUID();
        Object.assign(order, reactive(res.data));
      });
    });
  });
};
</script>
<style scoped lang="scss">
@import url("../../styles/detail.scss");
</style>
