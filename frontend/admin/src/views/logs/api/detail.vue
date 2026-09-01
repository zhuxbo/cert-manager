<script setup lang="tsx">
import { ref } from "vue";
import PureDescriptions from "@pureadmin/descriptions";
import "vue-json-pretty/lib/styles.css";
import VueJsonPretty from "vue-json-pretty";
import dayjs from "dayjs";

const props = defineProps({
  data: {
    type: Object,
    default: () => ({})
  }
});

const columns = [
  { label: "模块", prop: "module" },
  { label: "操作", prop: "action" },
  {
    label: "用户",
    prop: "user.username",
    cellRenderer: () => {
      return props.data.user?.username || "-";
    }
  },
  {
    label: "版本",
    prop: "version"
  },
  {
    label: "请求地址",
    prop: "url"
  },
  {
    label: "请求方法",
    prop: "method"
  },
  {
    label: "请求耗时",
    prop: "duration"
  },
  {
    label: "请求IP",
    prop: "ip"
  },
  {
    label: "状态码",
    prop: "status_code"
  },
  {
    label: "状态",
    prop: "status",
    cellRenderer: () => {
      return props.data.status === 1 ? "成功" : "失败";
    }
  },
  {
    label: "请求时间",
    prop: "created_at",
    cellRenderer: () => {
      return props.data.created_at
        ? dayjs(props.data.created_at).format("YYYY-MM-DD HH:mm:ss")
        : "-";
    }
  }
];

const dataList = ref([
  {
    title: "响应",
    name: "response",
    data: props.data.response
  },
  {
    title: "参数",
    name: "params",
    data: props.data.params
  }
]);
</script>

<template>
  <div>
    <el-scrollbar>
      <PureDescriptions border :data="[data]" :columns="columns" :column="5" />
    </el-scrollbar>
    <el-tabs :modelValue="dataList[0].name" type="border-card" class="mt-4">
      <el-tab-pane
        v-for="(item, index) in dataList"
        :key="index"
        :name="item.name"
        :label="item.title"
      >
        <el-scrollbar max-height="calc(100vh - 240px)">
          <vue-json-pretty v-model:data="item.data" />
        </el-scrollbar>
      </el-tab-pane>
    </el-tabs>
  </div>
</template>
