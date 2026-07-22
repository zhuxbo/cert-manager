<script setup lang="ts">
import { getConfig } from "@/config";
import { resolveCopyrightStart } from "@shared/utils";

const year = new Date().getFullYear();
const copyStart = resolveCopyrightStart(getConfig("CopyStart"), year);
const TITLE = getConfig("Title");
</script>

<template>
  <footer
    class="layout-footer text-[rgba(0,0,0,0.6)] dark:text-[rgba(220,220,242,0.8)]"
  >
    Copyright © {{ copyStart }}-{{ year }}
    <a class="hover:text-primary" href="/" target="_blank">
      &nbsp;{{ TITLE }}
    </a>
  </footer>
</template>

<style lang="scss" scoped>
.layout-footer {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 100%;
  padding: 0 0 8px;
  font-size: 14px;
}
</style>
