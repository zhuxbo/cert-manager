<script setup lang="ts">
import SharedTargetForm from "@cloud-deploy/shared/TargetForm.vue";
import {
  accessList,
  accessStore,
  accessUpdate,
  getProviders,
  targetShow,
  targetStore,
  targetUpdate
} from "@/api/cloud-deploy";

const props = withDefaults(
  defineProps<{
    modelValue: boolean;
    target?: any | null;
    orderId?: number | string | null;
    userId?: number | string | null;
    hideOrder?: boolean;
  }>(),
  {
    target: null,
    orderId: null,
    userId: null,
    hideOrder: false
  }
);

const emit = defineEmits<{
  (e: "update:modelValue", value: boolean): void;
  (e: "saved"): void;
}>();

const api = {
  accessList,
  accessStore,
  accessUpdate,
  getProviders,
  targetShow,
  targetStore,
  targetUpdate
};
</script>

<template>
  <SharedTargetForm
    :model-value="props.modelValue"
    mode="admin"
    :api="api"
    :target="props.target"
    :order-id="props.orderId"
    :user-id="props.userId"
    :hide-order="props.hideOrder"
    @update:model-value="emit('update:modelValue', $event)"
    @saved="emit('saved')"
  />
</template>
