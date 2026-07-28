<script setup lang="ts">
import AutoDeployReportList from "@shared/components/AutoDeployReportList.vue";
import { index } from "@/api/autoDeployReport";

defineOptions({ name: "AutoDeployReports" });
</script>

<template>
  <AutoDeployReportList :load="index" show-user />
</template>
