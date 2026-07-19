<template>
  <el-card shadow="never" :style="{ border: 'none' }">
    <h2 class="title">
      <span>证书详情</span>
    </h2>
    <table class="descriptions">
      <tbody>
        <tr>
          <td class="label">证书ID</td>
          <td class="content">{{ cert.id }}</td>
        </tr>
        <tr>
          <td class="label">通用名称</td>
          <td class="content">{{ cert.common_name }}</td>
        </tr>
        <tr>
          <td class="label">备用名称</td>
          <td class="content">
            <ul v-if="cert.alternative_names">
              <li v-for="name in cert.alternative_names.split(',')" :key="name">
                {{ name }}
              </li>
            </ul>
          </td>
        </tr>
        <tr>
          <td class="label">域名数量</td>
          <td class="content">
            {{ cert.standard_count ? cert.standard_count + "个标准域名" : "" }}
            {{ cert.standard_count && cert.wildcard_count ? "/" : "" }}
            {{ cert.wildcard_count ? cert.wildcard_count + "个通配符" : "" }}
          </td>
        </tr>
        <tr v-if="cert.api_id">
          <td class="label">接口ID</td>
          <td class="content">{{ cert.api_id }}</td>
        </tr>
        <tr v-if="cert.vendor_id">
          <td class="label">CA订单ID</td>
          <td class="content">{{ cert.vendor_id }}</td>
        </tr>
        <tr v-if="cert.action">
          <td class="label">动作</td>
          <td class="content">{{ action[cert.action] }}</td>
        </tr>
        <tr v-if="cert.channel">
          <td class="label">来源</td>
          <td class="content">{{ channel[cert.channel] }}</td>
        </tr>
        <tr>
          <td class="label">金额</td>
          <td class="content">{{ cert.amount }}</td>
        </tr>
        <tr v-if="cert.issuer">
          <td class="label">签发者</td>
          <td class="content">{{ cert.issuer }}</td>
        </tr>
        <tr v-if="cert.issuer">
          <td class="label">序列号</td>
          <td class="content">{{ cert.serial_number }}</td>
        </tr>
        <tr v-if="cert.issuer">
          <td class="label">指纹</td>
          <td class="content">{{ cert.fingerprint }}</td>
        </tr>
        <tr v-if="cert.issuer">
          <td class="label">加密算法</td>
          <td class="content">
            {{ cert.encryption_alg }} {{ cert.encryption_bits }} bits
          </td>
        </tr>
        <tr v-if="cert.issuer">
          <td class="label">摘要算法</td>
          <td class="content">{{ cert.signature_digest_alg }}</td>
        </tr>
        <tr v-if="cert.issued_at">
          <td class="label">签发时间</td>
          <td class="content">
            {{
              cert.issued_at
                ? dayjs(cert.issued_at).format("YYYY-MM-DD HH:mm:ss")
                : "-"
            }}
          </td>
        </tr>
        <tr v-if="cert.expires_at">
          <td class="label">到期时间</td>
          <td class="content">
            {{
              cert.expires_at
                ? dayjs(cert.expires_at).format("YYYY-MM-DD HH:mm:ss")
                : "-"
            }}
          </td>
        </tr>
        <tr>
          <td class="label">CSR</td>
          <td class="content">
            <el-button type="primary" link @click="view = 'csr'"
              >查看</el-button
            >
          </td>
        </tr>
        <tr v-if="cert.private_key">
          <td class="label">私钥</td>
          <td class="content">
            <el-button type="primary" link @click="view = 'private_key'"
              >查看</el-button
            >
          </td>
        </tr>
        <tr v-if="cert.cert">
          <td class="label">证书</td>
          <td class="content">
            <el-button type="primary" link @click="view = 'cert'"
              >查看</el-button
            >
          </td>
        </tr>
      </tbody>
    </table>
  </el-card>
  <el-dialog
    :model-value="['private_key', 'csr'].includes(view)"
    :close-on-click-modal="false"
    :destroy-on-close="true"
    align-center
    style="width: 408px"
    @close="view = ''"
  >
    <template #header>
      <div v-if="view == 'csr'" class="title" style="margin-bottom: 0">
        查看CSR
      </div>
      <div v-if="view == 'private_key'" class="title" style="margin-bottom: 0">
        查看私钥
      </div>
    </template>
    <el-row v-if="view == 'csr'">
      <el-col>
        <span>请求文件(CSR)</span>
        <span style="float: right"
          ><el-button type="primary" link size="small" @click="copy(cert.csr)"
            >点击复制</el-button
          ></span
        >
        <el-input
          type="textarea"
          :rows="20"
          :model-value="cert.csr"
          spellcheck="false"
          :input-style="{
            'font-size': '9px',
            'font-family': 'Consolas, Monaco, serif'
          }"
        />
      </el-col>
    </el-row>
    <el-row v-if="view == 'private_key'">
      <el-col>
        <span>私钥(KEY)</span>
        <span style="float: right"
          ><el-button
            type="primary"
            link
            size="small"
            @click="copy(cert.private_key)"
            >点击复制</el-button
          ></span
        >
        <el-input
          type="textarea"
          :rows="20"
          :model-value="cert.private_key"
          spellcheck="false"
          :input-style="{
            'font-size': '9px',
            'font-family': 'Consolas, Monaco, serif'
          }"
        />
      </el-col>
    </el-row>
  </el-dialog>
  <el-dialog
    :model-value="['cert', 'bt'].includes(view)"
    :close-on-click-modal="false"
    :destroy-on-close="true"
    align-center
    style="width: 796px"
    @close="view = ''"
  >
    <template v-if="view == 'cert'" #header>
      <div class="title" style="margin-bottom: 0">
        查看证书 (宝塔面板需要复制证书+证书链)
      </div>
    </template>
    <el-row v-if="view == 'cert'" :gutter="20">
      <el-col :span="12">
        <span>证书(CERT)</span>
        <span style="float: right"
          ><el-button type="primary" link size="small" @click="copy(cert.cert)"
            >点击复制</el-button
          ></span
        >
        <el-input
          type="textarea"
          :rows="20"
          :model-value="cert.cert"
          spellcheck="false"
          :input-style="{
            'font-size': '9px',
            'font-family': 'Consolas, Monaco, serif'
          }"
        />
      </el-col>
      <el-col :span="12">
        <span>证书链(CHAIN)</span>
        <span style="float: right"
          ><el-button
            type="primary"
            link
            size="small"
            @click="copy(cert.intermediate_cert)"
            >点击复制</el-button
          ></span
        >
        <el-input
          type="textarea"
          :rows="20"
          :model-value="cert.intermediate_cert"
          spellcheck="false"
          :input-style="{
            'font-size': '9px',
            'font-family': 'Consolas, Monaco, serif'
          }"
        />
      </el-col>
    </el-row>
  </el-dialog>
</template>

<script setup lang="ts">
import { inject, ref, watchEffect } from "vue";
import { action, channel } from "@/views/order/dictionary";
import { message } from "@shared/utils";
import dayjs from "dayjs";

const cert = inject("cert") as any;

const view = ref("");

const copy = (content: string) => {
  navigator.clipboard
    .writeText(content)
    .then(() => {
      message("复制成功", { type: "success" });
    })
    .catch(() => {
      message("复制失败", { type: "error" });
    });
};

watchEffect(() => {
  cert.csr = cert.csr || "";
  cert.private_key = cert.private_key || "";
  cert.cert = cert.cert || "";
  cert.intermediate_cert = cert.intermediate_cert || "";
});
</script>

<style scoped lang="scss">
@import url("../../styles/detail.scss");

:deep(.el-textarea__inner) {
  font-size: 12px;
  resize: none;
  scrollbar-width: none;
}

:deep(.el-textarea__inner::-webkit-scrollbar) {
  display: none;
}
</style>
