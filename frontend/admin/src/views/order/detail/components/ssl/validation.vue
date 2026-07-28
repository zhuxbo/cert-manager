<template>
  <!-- 委托验证：单条记录时与普通 DCV 格式统一 -->
  <div
    v-if="cert.dcv?.is_delegate && uniqueDelegations.length === 1"
    class="descriptions"
  >
    <div style="margin-top: 18px">
      <el-form-item label="主机记录：" label-width="82px">
        <el-input
          :model-value="getDelegationHostInput(uniqueDelegations[0])"
          spellcheck="false"
        >
          <template #suffix>
            <Copy :copied="getDelegationHostInput(uniqueDelegations[0])" />
          </template>
          <template #append
            >.{{ getDelegationHostSuffix(uniqueDelegations[0]) }}</template
          >
        </el-input>
      </el-form-item>
      <el-form-item label="解析类型：" label-width="82px">
        <el-input model-value="CNAME" :disabled="true" style="width: 73px" />
      </el-form-item>
      <el-form-item label="记录值：" label-width="82px">
        <el-input
          :model-value="uniqueDelegations[0].delegation_target"
          spellcheck="false"
        >
          <template #suffix>
            <Copy :copied="uniqueDelegations[0].delegation_target" />
          </template>
        </el-input>
      </el-form-item>
    </div>
  </div>
  <!-- 委托验证：多条记录时表格显示 -->
  <div
    v-else-if="cert.dcv?.is_delegate && uniqueDelegations.length > 1"
    class="descriptions"
  >
    <div style="margin-bottom: 10px">
      <table>
        <thead>
          <tr>
            <th style="padding: 8px 8px 8px 0; text-align: left">主机记录</th>
            <th style="padding: 8px; text-align: center">解析类型</th>
            <th style="width: 400px; padding: 8px 0 8px 8px; text-align: left">
              记录值
            </th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="(item, index) in uniqueDelegations" :key="index">
            <td style="padding: 4px 8px 4px 0">
              <el-input
                :model-value="getDelegationHostInput(item)"
                spellcheck="false"
              >
                <template #suffix>
                  <Copy :copied="getDelegationHostInput(item)" />
                </template>
                <template #append
                  >.{{ getDelegationHostSuffix(item) }}</template
                >
              </el-input>
            </td>
            <td style="padding: 4px 8px; text-align: center">
              <el-input
                model-value="CNAME"
                :disabled="true"
                style="width: 70px"
              />
            </td>
            <td style="padding: 4px 0 4px 8px">
              <el-input
                :model-value="item.delegation_target"
                spellcheck="false"
              >
                <template #suffix>
                  <Copy :copied="item.delegation_target" />
                </template>
              </el-input>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
  <!-- 普通 DNS 验证：显示 dns 记录信息 -->
  <div
    v-else-if="
      ['cname', 'txt'].includes(cert.dcv?.method) && cert.dcv?.dns?.value
    "
    class="descriptions"
  >
    <div style="margin-top: 18px">
      <el-form-item label="主机记录：" label-width="82px">
        <table v-if="hostIncludeSubDomain" style="width: 100%">
          <tbody>
            <tr v-for="(item, index) in cert.validation" :key="index">
              <td
                v-if="['cname', 'txt'].includes(item.method?.toLowerCase())"
                style="padding: 0"
              >
                <el-input
                  :model-value="getHostPrefix(item.host, item.domain)"
                  spellcheck="false"
                  :style="{ width: '100%' }"
                >
                  <template #suffix>
                    <Copy :copied="getHostPrefix(item.host, item.domain)" />
                  </template>
                  <template v-if="cert.validation?.length > 1" #append
                    >.{{
                      getRootDomain(item.domain.replace("*.", ""))
                    }}</template
                  >
                </el-input>
              </td>
            </tr>
          </tbody>
        </table>
        <el-input
          v-else
          :model-value="
            getHostPrefix(cert.dcv?.dns?.host, cert.validation?.[0]?.domain)
          "
          spellcheck="false"
        >
          <template #suffix>
            <Copy
              :copied="
                getHostPrefix(cert.dcv?.dns?.host, cert.validation?.[0]?.domain)
              "
            />
          </template>
        </el-input>
      </el-form-item>
      <el-form-item label="解析类型：" label-width="82px">
        <el-input
          :model-value="cert.dcv?.dns?.type.toUpperCase()"
          :disabled="true"
          style="width: 73px"
        />
      </el-form-item>
      <el-form-item label="记录值：" label-width="82px">
        <el-input :model-value="cert.dcv?.dns?.value" spellcheck="false">
          <template #suffix>
            <Copy :copied="cert.dcv?.dns?.value" />
          </template>
        </el-input>
      </el-form-item>
    </div>
  </div>
  <div
    v-if="
      ['file', 'http', 'https'].includes(cert.dcv?.method) &&
      cert.dcv?.file?.content
    "
    class="descriptions"
  >
    <div style="margin-top: 18px">
      <el-form-item label="文件名：" label-width="82px">
        <el-input :model-value="cert.dcv?.file?.name" spellcheck="false">
          <template #suffix>
            <Copy :copied="cert.dcv?.file?.name" />
          </template>
        </el-input>
      </el-form-item>
      <el-form-item label="文件内容：" label-width="82px">
        <el-input
          :rows="3"
          type="textarea"
          class="file-content"
          :model-value="cert.dcv?.file?.content"
          spellcheck="false"
        />
        <Copy
          :copied="cert.dcv?.file?.content"
          style="position: absolute; right: 12px; bottom: 7px"
        />
      </el-form-item>
      <el-form-item label="路径：" label-width="82px">
        <el-input :model-value="cert.dcv?.file?.path" spellcheck="false">
          <template #suffix>
            <Copy :copied="cert.dcv?.file?.path" />
          </template>
          <template #prepend>
            <el-button
              type="primary"
              @click="OrderApi.downloadValidateFile(order.id)"
              >下 载</el-button
            >
          </template>
        </el-input>
      </el-form-item>
    </div>
  </div>
  <div
    v-if="['unpaid', 'pending', 'processing'].includes(cert.status)"
    class="descriptions"
  >
    <div style="margin: 10px 0">
      <ValidationMethods
        v-model="validationMethod"
        :methods="order.product.validation_methods"
      />
      <el-button
        :disabled="
          !['unpaid', 'pending', 'processing'].includes(cert.status) ||
          allVerified
        "
        style="
          border-left: 0;
          border-top-left-radius: 0;
          border-bottom-left-radius: 0;
        "
        @click="updateValidationMethod(validationMethod)"
        >修改</el-button
      >
      <el-button
        v-if="
          ['processing'].includes(cert.status) &&
          ![
            'admin',
            'administrator',
            'postmaster',
            'hostmaster',
            'webmaster'
          ].includes(cert.dcv?.['method'])
        "
        :disabled="allVerified"
        type="primary"
        style="margin-left: 16px"
        @click="revalidate()"
        >验证</el-button
      >
      <el-button
        v-if="
          ((['cname', 'txt'].includes(cert.dcv?.method) &&
            cert.dcv?.dns?.value) ||
            cert.dcv?.is_delegate) &&
          ['unpaid', 'pending', 'processing'].includes(cert.status) &&
          !allVerified
        "
        type="primary"
        style="margin-left: 16px"
        @click="copyAllRecords()"
        >复制解析</el-button
      >
      <el-button
        v-if="
          cert.validation?.length > 1 &&
          (['cname', 'txt', 'file', 'http', 'https'].includes(
            cert.dcv?.method
          ) ||
            cert.dcv?.is_delegate) &&
          !allVerified &&
          (cert.dcv?.dns?.value ||
            cert.dcv?.file?.content ||
            cert.dcv?.is_delegate) &&
          ['unpaid', 'pending', 'processing'].includes(cert.status)
        "
        :disabled="isChecking"
        type="primary"
        :loading="isChecking"
        @click="startBatchVerify"
      >
        检测所有
      </el-button>
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
    </div>
  </div>
  <div class="descriptions">
    <table style="width: 100%">
      <tbody>
        <tr>
          <td v-if="cert.validation?.length > 1" class="index">序号</td>
          <td class="domain">域名</td>
          <td :style="{ 'text-align': 'right' }">状态</td>
        </tr>
        <tr v-for="(item, index) in cert.validation" :key="index">
          <td v-if="cert.validation?.length > 1">{{ Number(index) + 1 }}</td>
          <td>
            {{ item.domain }}
            <el-button
              v-if="
                ![
                  'admin',
                  'administrator',
                  'postmaster',
                  'hostmaster',
                  'webmaster'
                ].includes(item.method) &&
                ['unpaid', 'pending', 'processing'].includes(cert.status) &&
                (cert.dcv?.dns?.value ||
                  cert.dcv?.file?.content ||
                  cert.dcv?.is_delegate)
              "
              link
              @click="checkSingleValidation(item)"
            >
              <el-icon
                v-if="item.checked"
                color="var(--el-color-success)"
                :size="18"
                style="vertical-align: middle"
                ><Check
              /></el-icon>
              <el-button v-else type="primary" link>检测</el-button>
            </el-button>
          </td>
          <td :style="{ 'text-align': 'right' }">
            <template
              v-if="
                item.verified == 1 ||
                ['active', 'approving'].includes(cert.status)
              "
            >
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
              <el-tooltip
                v-if="item.verified == 2 && item.error"
                placement="top"
              >
                <template #content>
                  <div
                    v-for="(val, key) in typeof item.error === 'string'
                      ? { error: item.error }
                      : item.error"
                    :key="key"
                  >
                    {{ key }}: {{ val }}
                  </div>
                </template>
                <el-icon
                  color="var(--el-color-warning)"
                  :size="18"
                  style="vertical-align: middle"
                >
                  <WarningFilled />
                </el-icon>
              </el-tooltip>
              <el-icon
                v-else-if="item.verified == 2"
                color="var(--el-color-warning)"
                :size="18"
                style="vertical-align: middle"
              >
                <WarningFilled />
              </el-icon>
              <el-icon
                v-if="item.verified != 2"
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
  <el-dialog
    v-model="checkDialogVisible"
    title="验证检测结果"
    class="validation-check-dialog"
  >
    <div v-if="currentCheckItem" class="check-result-dialog">
      <el-descriptions :column="1" border>
        <el-descriptions-item label="域名" label-align="right">
          {{ currentCheckItem.domain }}
        </el-descriptions-item>
        <el-descriptions-item label="验证方式" label-align="right">
          {{
            currentCheckItem.delegation_id
              ? "委托验证 (CNAME)"
              : currentCheckItem.method
          }}
        </el-descriptions-item>
        <!-- 委托验证：CNAME 委托记录 + TXT 验证记录 -->
        <template v-if="currentCheckItem.delegation_id">
          <el-descriptions-item label="委托域" label-align="right">
            {{
              currentCheckItem.delegation_zone ||
              currentCheckItem.domain?.replace(/^\*\./, "")
            }}
          </el-descriptions-item>
          <!-- CNAME 委托记录检测 -->
          <el-descriptions-item label="CNAME 委托" label-align="right">
            <el-tag
              size="small"
              :type="
                currentCheckItem.delegation_cname_checked === true
                  ? 'success'
                  : currentCheckItem.delegation_cname_checked === false
                    ? 'danger'
                    : 'info'
              "
            >
              {{
                currentCheckItem.delegation_cname_checked === true
                  ? "正确"
                  : currentCheckItem.delegation_cname_checked === false
                    ? "异常"
                    : "待检测"
              }}
            </el-tag>
          </el-descriptions-item>
          <el-descriptions-item label="查询主机" label-align="right">
            {{ getDelegationHostInput(currentCheckItem) }}.{{
              getDelegationHostSuffix(currentCheckItem)
            }}
          </el-descriptions-item>
          <el-descriptions-item label="期望指向" label-align="right">
            {{ currentCheckItem.delegation_target }}
          </el-descriptions-item>
          <el-descriptions-item
            v-if="currentCheckItem.delegation_cname_checked !== undefined"
            label="实际指向"
            label-align="right"
          >
            <template v-if="currentCheckItem.delegation_cname_detected">
              {{ currentCheckItem.delegation_cname_detected }}
            </template>
            <span v-else style="color: var(--el-text-color-placeholder)">
              未检测到
            </span>
          </el-descriptions-item>
          <el-descriptions-item
            v-if="currentCheckItem.delegation_cname_error"
            label="错误"
            label-align="right"
          >
            <span style="color: var(--el-color-danger)">
              {{ currentCheckItem.delegation_cname_error }}
            </span>
          </el-descriptions-item>
          <!-- TXT 验证记录检测（仅在有 TXT 值时显示） -->
          <template v-if="currentCheckItem.value || cert.dcv?.dns?.value">
            <el-descriptions-item label="TXT 记录" label-align="right">
              <el-tag
                size="small"
                :type="
                  currentCheckItem.delegation_txt_checked === true
                    ? 'success'
                    : currentCheckItem.delegation_txt_checked === false
                      ? 'danger'
                      : 'info'
                "
              >
                {{
                  currentCheckItem.delegation_txt_checked === true
                    ? "正确"
                    : currentCheckItem.delegation_txt_checked === false
                      ? "异常"
                      : "待检测"
                }}
              </el-tag>
            </el-descriptions-item>
            <el-descriptions-item label="查询主机" label-align="right">
              {{ currentCheckItem.delegation_target }}
            </el-descriptions-item>
            <el-descriptions-item label="期望值" label-align="right">
              {{ currentCheckItem.value || cert.dcv?.dns?.value }}
            </el-descriptions-item>
            <el-descriptions-item
              v-if="currentCheckItem.delegation_txt_detected"
              label="检测到的值"
              label-align="right"
            >
              {{ currentCheckItem.delegation_txt_detected }}
            </el-descriptions-item>
            <el-descriptions-item
              v-if="currentCheckItem.delegation_txt_error"
              label="错误"
              label-align="right"
            >
              <span style="color: var(--el-color-danger)">
                {{ currentCheckItem.delegation_txt_error }}
              </span>
            </el-descriptions-item>
          </template>
          <!-- TXT 冲突警告 -->
          <el-descriptions-item
            v-if="currentCheckItem.delegation_txt_conflict"
            label="冲突警告"
            label-align="right"
          >
            <span style="color: var(--el-color-warning)">
              {{ currentCheckItem.delegation_txt_conflict }}
            </span>
          </el-descriptions-item>
        </template>
        <template
          v-else-if="
            ['cname', 'txt'].includes(currentCheckItem.method?.toLowerCase())
          "
        >
          <el-descriptions-item label="验证记录" label-align="right">
            {{
              currentCheckItem.query ||
              (currentCheckItem.host || cert.dcv?.dns?.host) +
                "." +
                getRootDomain(currentCheckItem.domain.replace("*.", ""))
            }}
          </el-descriptions-item>
          <el-descriptions-item label="需要的值" label-align="right">
            {{ currentCheckItem.value || cert.dcv?.dns?.value }}
          </el-descriptions-item>
          <el-descriptions-item
            v-if="currentCheckItem.checked !== undefined"
            label="检测到的值"
            label-align="right"
          >
            <template v-if="currentCheckItem.detected_value">
              {{ currentCheckItem.detected_value }}
            </template>
            <span v-else style="color: var(--el-text-color-placeholder)">
              未检测到
            </span>
          </el-descriptions-item>
          <el-descriptions-item
            v-if="currentCheckItem.query_sub"
            label="子域查询"
            label-align="right"
          >
            {{ currentCheckItem.query_sub }}
            <span v-if="currentCheckItem.value_sub">
              → {{ currentCheckItem.value_sub }}</span
            >
          </el-descriptions-item>
        </template>
        <template
          v-if="
            ['file', 'http', 'https'].includes(
              currentCheckItem.method?.toLowerCase()
            )
          "
        >
          <el-descriptions-item label="验证链接" label-align="right">
            {{
              currentCheckItem.link ||
              `${currentCheckItem.method === "file" ? "https:" : currentCheckItem.method + ":"}//${currentCheckItem.domain}/.well-known/pki-validation/${cert.dcv?.file?.name}`
            }}
          </el-descriptions-item>
          <el-descriptions-item label="需要的内容" label-align="right">
            {{ currentCheckItem.content || cert.dcv?.file?.content }}
          </el-descriptions-item>
          <el-descriptions-item
            v-if="currentCheckItem.detected_value"
            label="检测到的内容"
            label-align="right"
          >
            <div
              style="max-height: 200px; overflow-y: auto; word-break: break-all"
            >
              {{ currentCheckItem.detected_value }}
            </div>
          </el-descriptions-item>
        </template>
        <el-descriptions-item label="检测状态" label-align="right">
          <el-tag :type="currentCheckItem.checked ? 'success' : 'danger'">
            {{ currentCheckItem.checked ? "验证通过" : "验证失败" }}
          </el-tag>
        </el-descriptions-item>
        <el-descriptions-item
          v-if="currentCheckItem.error"
          label="错误信息"
          label-align="right"
        >
          <div style="color: var(--el-color-danger)">
            {{ currentCheckItem.error }}
          </div>
        </el-descriptions-item>
      </el-descriptions>
    </div>
    <template #footer>
      <el-button @click="checkDialogVisible = false">关闭</el-button>
      <el-button
        type="primary"
        :loading="isChecking"
        @click="debouncedVerifyItem"
        >重新检测</el-button
      >
    </template>
  </el-dialog>
</template>
<script setup lang="ts">
import { ref, inject, computed, onMounted, watch } from "vue";
import Copy from "./copy.vue";
import {
  SuccessFilled,
  WarningFilled,
  Loading,
  Check
} from "@element-plus/icons-vue";
import { get as getRootDomain } from "psl";
import * as OrderApi from "@/api/order";
import ValidationMethods from "./validationMethods.vue";
import axios, { AxiosResponse } from "axios";
import { debounce } from "lodash-es";
import { useRoute } from "vue-router";
import { message } from "@shared/utils";
import { getConfig } from "@/config";

const get = inject("get") as Function;
const order = inject("order") as any;
const cert = inject("cert") as any;
const hostIncludeSubDomain = computed(() => {
  let includeSubDomain = false;
  cert.value?.validation &&
    cert.value.validation.forEach((item: { host: string }) => {
      if (item.host && item.host !== cert.value.dcv?.dns?.host)
        includeSubDomain = true;
    });
  return includeSubDomain;
});

// 空列表时返回 true（无需验证的订单不禁用操作按钮）
const allVerified = computed(() => {
  let verified = true;
  cert.value.validation &&
    cert.value.validation.forEach((item: { verified: number }) => {
      if (item.verified != 1) verified = false;
    });
  return verified;
});

// 去除主机记录中的域名后缀，只保留 DNS 提供商需要的主机记录部分
const getHostPrefix = (host: string, domain: string) => {
  if (!host || !domain) return host || "";
  const root =
    getRootDomain(domain.replace("*.", "")) || domain.replace("*.", "");
  return host.endsWith("." + root) ? host.slice(0, -(root.length + 1)) : host;
};

// 获取委托验证前缀
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

// 委托验证前缀
const delegationPrefix = computed(() =>
  getDelegationPrefix(cert.value.dcv?.ca || order.product?.ca)
);

// 获取委托验证的域名部分
const getDelegationZone = (item: any) => {
  return item.delegation_zone || (item.domain || "").replace(/^\*\./, "");
};

// 获取委托验证的完整主机记录
const getDelegationHost = (item: any) => {
  return `${delegationPrefix.value}.${getDelegationZone(item)}`;
};

// 委托验证：输入框显示值（_dnsauth 精确匹配时包含子域前缀）
const getDelegationHostInput = (item: any) => {
  if (delegationPrefix.value !== "_dnsauth") return delegationPrefix.value;
  const zone = getDelegationZone(item);
  const root = getRootDomain(zone) || zone;
  if (zone === root) return delegationPrefix.value;
  const sub = zone.slice(0, -(root.length + 1));
  return `${delegationPrefix.value}.${sub}`;
};

// 委托验证：append 后缀显示（_dnsauth 时为根域，其他为完整 zone）
const getDelegationHostSuffix = (item: any) => {
  if (delegationPrefix.value !== "_dnsauth") return getDelegationZone(item);
  const zone = getDelegationZone(item);
  return getRootDomain(zone) || zone;
};

// 去重后的委托列表（按 delegation_id 去重）
const uniqueDelegations = computed(() => {
  if (!cert.value?.validation) return [];

  const seen = new Map();
  return cert.value.validation.filter((item: any) => {
    if (!item.delegation_id) return false;
    if (seen.has(item.delegation_id)) return false;
    seen.set(item.delegation_id, true);
    return true;
  });
});

// 获取显示用的验证方法（考虑委托验证标记）
const getDisplayMethod = (dcv: any) => {
  if (dcv?.is_delegate) return "delegation";
  return dcv?.method;
};

const validationMethod = ref(getDisplayMethod(cert.value.dcv));

// 添加监听器监听cert.value的变化
watch(
  () => cert.value,
  newVal => {
    if (newVal) {
      validationMethod.value = getDisplayMethod(newVal.dcv);
    }
  }
);

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

const revalidate = () => {
  if (loading.value) return;
  loading.value = true;
  OrderApi.revalidate(order.id)
    .then(res => {
      message(res.msg ? res.msg : "开始验证，请等待几分钟后刷新页面查看结果", {
        type: "success"
      });
    })
    .finally(() => {
      loading.value = false;
    });
};

const updateValidationMethod = (method: string) => {
  if (loading.value) return;
  loading.value = true;
  OrderApi.updateDCV(order.id, method)
    .then(res => {
      message(res.msg ? res.msg : "修改成功", { type: "success" });
      get();
    })
    .finally(() => {
      loading.value = false;
    });
};

// 复制所有解析记录（支持委托验证和普通 DNS 验证）
const copyAllRecords = () => {
  const records: string[] = [];

  // 委托验证
  if (cert.value.dcv?.is_delegate) {
    uniqueDelegations.value.forEach((item: any) => {
      const zone = getDelegationZone(item);
      records.push(
        `域名：${zone}\n主机记录：${getDelegationHostInput(item)}\n解析类型：CNAME\n记录值：${item.delegation_target}`
      );
    });
  }
  // 普通 DNS 验证
  else if (hostIncludeSubDomain.value) {
    cert.value.validation.forEach(
      (item: { method: string; host: string; domain: string }) => {
        if (item.method === "cname" || item.method === "txt") {
          records.push(
            `域名：${item.domain}\n主机记录：${getHostPrefix(item.host, item.domain)}\n解析类型：${cert.value.dcv.dns.type}\n记录值：${cert.value.dcv.dns.value}`
          );
        }
      }
    );
  } else {
    records.push(
      `域名：${cert.value.validation[0].domain}\n主机记录：${getHostPrefix(cert.value.dcv.dns.host, cert.value.validation[0].domain)}\n解析类型：${cert.value.dcv.dns.type}\n记录值：${cert.value.dcv.dns.value}`
    );
  }

  const text = records.join("\n\n");
  navigator.clipboard
    .writeText(text)
    .then(() => {
      message("解析记录已复制到剪贴板", { type: "success" });
    })
    .catch(() => {
      message("复制失败，请手动复制", { type: "error" });
    });
};

// 委托验证 CNAME 检测函数
async function verifyCname(
  domain: string,
  host: string,
  expectedTarget: string
) {
  const dnsToolsHosts = getConfig()?.DnsTools || [];

  let lastMsg = "";
  for (const baseUrl of dnsToolsHosts) {
    try {
      const response = await axios.post(
        `${baseUrl}/api/dcv/verify`,
        [{ domain, method: "cname", host, value: expectedTarget }],
        { timeout: 10000 }
      );

      // code=1 时数据在 data.results，code=0 时在 errors 数组
      let result = response.data?.data?.results?.[domain];
      if (!result && response.data?.errors?.length) {
        result = response.data.errors.find((e: any) => e.domain === domain);
      }
      if (result) {
        return {
          detected_value: result.value || "",
          checked: result.matched === "true",
          error: result.matched === "false" ? "验证未通过" : ""
        };
      }
      if (response.data?.msg)
        lastMsg = response.data.msg.replace(
          "批量验证失败：部分或全部验证未通过",
          "验证未通过"
        );
    } catch (error) {
      console.debug(`Failed to connect to ${baseUrl}, trying next...`);
      continue;
    }
  }
  return {
    checked: false,
    detected_value: "",
    error: lastMsg || "检测服务不可用"
  };
}

// 委托验证 TXT 记录检测函数（使用 /api/dns/query 原始查询）
async function verifyDelegationTxt(targetFqdn: string, expectedValue: string) {
  const dnsToolsHosts = getConfig()?.DnsTools || [];

  const expectedLower = expectedValue.toLowerCase().trim();
  for (const baseUrl of dnsToolsHosts) {
    try {
      const response = await axios.post(
        `${baseUrl}/api/dns/query`,
        { domain: targetFqdn, type: "TXT" },
        { timeout: 10000 }
      );

      if (response.data?.code !== 1) {
        return {
          detected_value: "",
          checked: false,
          error: "未检测到 TXT 记录"
        };
      }

      const records = response.data.data?.records || [];
      const txtValues = records
        .filter((r: any) => r.type === "TXT" && r.value)
        .map((r: any) => r.value.replace(/^"|"$/g, "").trim());

      if (txtValues.length === 0) {
        return {
          detected_value: "",
          checked: false,
          error: "未检测到 TXT 记录"
        };
      }

      const matched = txtValues.some(
        (v: string) => v.toLowerCase() === expectedLower
      );
      return {
        detected_value: txtValues.join(", "),
        checked: matched,
        error: matched ? "" : "TXT 记录不匹配"
      };
    } catch (error) {
      console.debug(`Failed to connect to ${baseUrl}, trying next...`);
      continue;
    }
  }
  return {
    checked: false,
    detected_value: "",
    error: "检测服务不可用"
  };
}

// 批量检测函数
async function batchVerifyValidation(validation: any[], ca?: string) {
  if (!validation?.length) return validation;

  // 分离委托验证和普通验证
  const delegationItems = validation.filter((item: any) => item.delegation_id);
  const normalItems = validation.filter((item: any) => !item.delegation_id);

  // 处理委托验证项
  const delegationResults: Map<string, any> = new Map();
  if (delegationItems.length > 0) {
    // 按 delegation_id 分组去重检测
    const uniqueDelegationsMap = new Map<string, any>();
    delegationItems.forEach((item: any) => {
      if (!uniqueDelegationsMap.has(item.delegation_id)) {
        uniqueDelegationsMap.set(item.delegation_id, item);
      }
    });

    for (const [delegationId, item] of uniqueDelegationsMap) {
      const zone =
        item.delegation_zone || (item.domain || "").replace(/^\*\./, "");
      const cnameResult = await verifyCname(
        zone,
        delegationPrefix.value,
        item.delegation_target
      );

      // 同时检测 TXT 记录
      const expectedTxtValue = item.value || cert.value.dcv?.dns?.value;
      const txtResult = expectedTxtValue
        ? await verifyDelegationTxt(item.delegation_target, expectedTxtValue)
        : { checked: true, detected_value: "", error: "" };

      // TXT 冲突检测：CNAME 和 TXT 不能共存于同一名称
      const cnameHost = `${delegationPrefix.value}.${zone}`;
      let txtConflict = "";
      try {
        const dnsToolsHosts = getConfig()?.DnsTools || [];
        for (const baseUrl of dnsToolsHosts) {
          try {
            const res = await axios.post(
              `${baseUrl}/api/dns/query`,
              { domain: cnameHost, type: "TXT" },
              { timeout: 10000 }
            );
            if (res.data?.code === 1) {
              const normalizedHost = cnameHost.toLowerCase().replace(/\.$/, "");
              // 通过 name 字段精确匹配：仅检测直接属于该主机名的 TXT 记录，排除 CNAME 链解析到的记录
              const directTxtRecords = (res.data.data?.records || []).filter(
                (r: any) =>
                  r.type === "TXT" &&
                  r.value &&
                  r.name?.toLowerCase().replace(/\.$/, "") === normalizedHost
              );
              if (directTxtRecords.length > 0) {
                txtConflict = `检测到 ${cnameHost} 存在 TXT 记录，TXT 和 CNAME 同名共存会导致委托不生效，请删除 TXT 记录`;
              }
            }
            break;
          } catch {
            continue;
          }
        }
      } catch {
        // 非关键检测，忽略错误
      }

      delegationResults.set(delegationId, {
        cname_checked: cnameResult.checked,
        cname_detected: cnameResult.detected_value || "",
        cname_error: cnameResult.error || "",
        txt_checked: txtResult.checked,
        txt_detected: txtResult.detected_value || "",
        txt_error: txtResult.error || "",
        txt_conflict: txtConflict,
        checked: cnameResult.checked && txtResult.checked,
        error: ""
      });
    }
  }

  // 准备普通验证的请求数据
  const requestData = normalItems.map((item: any) => {
    const baseData: any = {
      domain: item.domain,
      method: item.method?.toLowerCase()
    };

    // 根据验证方法添加相应字段
    if (["txt", "cname"].includes(baseData.method)) {
      baseData.host = item.host || cert.value.dcv?.dns?.host || "@";
      baseData.value = item.value || cert.value.dcv?.dns?.value;
    } else if (["file", "http", "https"].includes(baseData.method)) {
      const protocol = baseData.method === "file" ? "" : baseData.method + ":";
      baseData.link =
        item.link ||
        `${protocol}//${item.domain}/.well-known/pki-validation/${cert.value.dcv?.file?.name}`;
      baseData.name = item.name || cert.value.dcv?.file?.name;
      baseData.content = item.content || cert.value.dcv?.file?.content;
    }

    return baseData;
  });

  // 从配置文件获取 DNS Tools 基础地址，并拼接 API 路径
  const dnsToolsHosts = getConfig()?.DnsTools || [];
  const endpoints = dnsToolsHosts.map(host => `${host}/api/dcv/verify`);

  let response: AxiosResponse<any, any>;
  let lastError: any;

  if (normalItems.length > 0) {
    for (const endpoint of endpoints) {
      try {
        response = await axios.post(endpoint, requestData, {
          timeout: 10000
        });

        // code=1 表示 API 处理成功（含验证结果），code=0 表示 API 错误需尝试下一端点
        if (response.data?.code === 1) {
          break;
        }
      } catch (error) {
        lastError = error;
        console.debug(`Failed to connect to ${endpoint}, trying next...`);
        continue;
      }
    }
  }

  // 处理普通验证的返回数据
  const normalResults: { [key: string]: any } = {};
  if (response?.data && normalItems.length > 0) {
    // code=1 时数据在 data.results 中，code=0 时可能在 errors 中
    const results = response.data.data?.results || {};
    const errors = response.data.errors || [];

    // 如果有 errors 数组，将其转换为 results 格式
    if (errors.length > 0) {
      errors.forEach((err: any) => {
        if (err.domain) {
          results[err.domain] = err;
        }
      });
    }

    Object.assign(normalResults, results);
  }

  // 合并所有结果
  return validation.map((item: any) => {
    // 委托验证项使用委托验证结果
    if (item.delegation_id && delegationResults.has(item.delegation_id)) {
      const delegationResult = delegationResults.get(item.delegation_id);
      return {
        ...item,
        checked: delegationResult.checked,
        delegation_cname_checked: delegationResult.cname_checked,
        delegation_cname_detected: delegationResult.cname_detected,
        delegation_cname_error: delegationResult.cname_error,
        delegation_txt_checked: delegationResult.txt_checked,
        delegation_txt_detected: delegationResult.txt_detected,
        delegation_txt_error: delegationResult.txt_error,
        delegation_txt_conflict: delegationResult.txt_conflict || "",
        error: delegationResult.error || ""
      };
    }

    // 普通验证项使用普通验证结果
    const checkResult = normalResults[item.domain];
    if (checkResult) {
      const updateData: any = {
        ...item,
        checked: checkResult.matched === "true",
        error: checkResult.matched === "false" ? `验证失败` : ""
      };

      // 根据验证方法设置检测值和额外信息
      if (["txt", "cname"].includes(item.method?.toLowerCase())) {
        updateData.detected_value = checkResult.value || "";
        // 保存查询信息用于显示
        updateData.query = checkResult.query || "";
        updateData.query_sub = checkResult.query_sub || "";
        updateData.value_sub = checkResult.value_sub || "";
      } else if (
        ["file", "http", "https"].includes(item.method?.toLowerCase())
      ) {
        // 对于文件验证，content 可能已被截断
        updateData.detected_value = checkResult.content || "";
        // 保存链接信息
        updateData.link =
          checkResult.link ||
          checkResult.link_https ||
          checkResult.link_http ||
          item.link;
      }

      return updateData;
    }

    return item;
  });
}

// 添加状态变量
const checkDialogVisible = ref(false);
const currentCheckItem = ref<any>(null);
const isChecking = ref(false);

// 添加检测单条记录的函数
async function checkSingleValidation(item: any) {
  currentCheckItem.value = item;
  checkDialogVisible.value = true;

  // 如果检测失败，自动重新检测一次
  if (!item.checked) {
    await debouncedVerifyItem();
  }
}

// 单条验证检测函数
async function verifyItem() {
  if (!currentCheckItem.value) return;

  isChecking.value = true;
  try {
    const result = await batchVerifyValidation(
      [currentCheckItem.value],
      order.product.ca
    );

    if (result?.[0]) {
      const newItem = result[0];

      // 如果检测成功，清除错误相关字段
      if (newItem.checked) {
        newItem.error = "";
        newItem.detected_value = newItem.detected_value || "";
        newItem.errors = undefined;
      }

      // 更新数据
      const index = cert.value.validation.findIndex(
        (item: any) =>
          item.domain === currentCheckItem.value.domain &&
          item.method === currentCheckItem.value.method
      );
      if (index !== -1) {
        cert.value.validation[index] = newItem;
        currentCheckItem.value = newItem;
      }
    }
  } finally {
    isChecking.value = false;
  }
}

// 监听弹窗状态，关闭时清理当前项
watch(checkDialogVisible, newValue => {
  if (!newValue) {
    currentCheckItem.value = null;
    isChecking.value = false;
  }
});

const debouncedVerifyItem = debounce(verifyItem, 500);

// 启动批量检测
async function startBatchVerify() {
  if (
    !cert.value?.validation?.length ||
    (!["cname", "txt", "file", "http", "https"].includes(
      cert.value.dcv?.method
    ) &&
      !cert.value.dcv?.is_delegate) ||
    allVerified.value ||
    (!cert.value.dcv?.dns?.value &&
      !cert.value.dcv?.file?.content &&
      !cert.value.dcv?.is_delegate) ||
    !["unpaid", "pending", "processing"].includes(cert.value.status)
  ) {
    return;
  }

  try {
    isChecking.value = true;
    cert.value.validation = await batchVerifyValidation(
      cert.value.validation,
      order.product.ca
    );
  } catch {
  } finally {
    isChecking.value = false;
  }
}

onMounted(async () => {
  await startBatchVerify();
});
</script>
<style lang="scss">
.validation-check-dialog {
  .el-dialog {
    width: fit-content !important;
    min-width: 400px !important;
    max-width: 80vw !important;
  }

  .el-dialog__body {
    min-width: 400px;
    padding: 20px;
  }

  /* 优化长文本显示 */
  .el-descriptions__cell {
    word-break: break-all;
    white-space: pre-wrap;
  }
}
</style>
<style scoped lang="scss">
.box {
  float: left;
  padding: 20px;
  margin: 10px;
  border: 1px solid var(--ba-border-color);
}

.title {
  margin-bottom: 20px;
  color: var(--el-text-color-regular);

  span,
  button {
    vertical-align: middle;
  }
}

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

.index {
  width: 50px;
}

.type {
  width: 240px;
}

::v-deep(.el-input-group__prepend) .el-button--primary {
  color: #fff !important;
  background-color: var(--el-color-primary) !important;
  border-color: var(--el-color-primary) !important;
  border-radius: 4px 0 0 4px !important;
}

:deep(.el-textarea__inner) {
  resize: none;
  scrollbar-width: none;
}

:deep(.el-textarea__inner::-webkit-scrollbar) {
  display: none;
}

.check-result-dialog {
  max-height: 60vh;
  overflow-y: auto;
}
</style>
