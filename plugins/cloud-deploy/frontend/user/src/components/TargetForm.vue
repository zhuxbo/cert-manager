<script setup lang="ts">
import SharedTargetForm from "@cloud-deploy/shared/TargetForm.vue";
import {
  accessList,
  getProviders,
  targetStore,
  targetUpdate
} from "@/api/cloud-deploy";

const props = withDefaults(
  defineProps<{
    modelValue: boolean;
    target?: any | null;
    orderId?: number | string | null;
    hideOrder?: boolean;
  }>(),
  {
    target: null,
    orderId: null,
    hideOrder: false
  }
);

const emit = defineEmits<{
  (e: "update:modelValue", value: boolean): void;
  (e: "saved"): void;
}>();

const api = {
  accessList,
  getProviders,
  targetStore,
  targetUpdate
};
</script>

<template>
  <SharedTargetForm
    :model-value="props.modelValue"
    mode="user"
    :api="api"
    :target="props.target"
    :order-id="props.orderId"
    :hide-order="props.hideOrder"
    @update:model-value="emit('update:modelValue', $event)"
    @saved="emit('saved')"
  />
</template>
