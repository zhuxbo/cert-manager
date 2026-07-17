<template>
  <el-dialog
    v-model="dialogVisible"
    class="product-price-initialization-dialog"
    width="900px"
    :close-on-click-modal="false"
    :close-on-press-escape="!submitting"
    :show-close="!submitting"
    @closed="onClosed"
  >
    <template #header>
      <div class="dialog-title">初始化产品价格</div>
    </template>

    <el-scrollbar v-loading="loadingLevels" max-height="68vh">
      <el-form label-width="110px" class="initialization-form">
        <el-form-item label="级别类型">
          <el-radio-group
            v-model="selectionType"
            :disabled="submitting || loadingLevels"
          >
            <el-radio value="base">基础级别</el-radio>
            <el-radio value="custom">定制级别</el-radio>
          </el-radio-group>
        </el-form-item>

        <el-form-item label="会员级别" required>
          <el-select
            v-if="selectionType === 'base'"
            v-model="baseCodes"
            multiple
            filterable
            :disabled="submitting || baseLevelsLoading"
            clearable
            placeholder="请选择基础级别"
          >
            <el-option
              v-for="level in baseOptions"
              :key="level.code"
              :label="level.name"
              :value="level.code"
            />
          </el-select>
          <el-select
            v-else
            v-model="customCodes"
            multiple
            filterable
            :disabled="submitting || customLevelsLoading"
            clearable
            placeholder="请选择定制级别"
          >
            <el-option
              v-for="level in customOptions"
              :key="level.code"
              :label="level.name"
              :value="level.code"
            />
          </el-select>
          <div class="selection-summary">
            已选基础级别 {{ baseCodes.length }} 个，定制级别
            {{ customCodes.length }} 个；切换类型不会清空另一类选择。
          </div>
        </el-form-item>

        <el-form-item label="级别倍率" required>
          <el-table
            :data="selectedLevels"
            border
            empty-text="请至少选择一个会员级别"
            max-height="310"
            class="level-rate-table"
          >
            <el-table-column prop="name" label="级别名称" min-width="160" />
            <el-table-column prop="code" label="编码" min-width="140" />
            <el-table-column label="类型" width="90">
              <template #default="{ row }">
                {{ row.custom === 1 ? "定制" : "基础" }}
              </template>
            </el-table-column>
            <el-table-column label="成本倍率" min-width="180">
              <template #default="{ row }">
                <el-input-number
                  :model-value="Number(row.cost_rate)"
                  :aria-label="`会员级别${row.name}（${row.code}）成本倍率`"
                  :min="1"
                  :max="99.9999"
                  :precision="4"
                  :step="0.1"
                  :controls="false"
                  :disabled="submitting"
                  @update:model-value="value => updateCostRate(row.code, value)"
                />
              </template>
            </el-table-column>
          </el-table>
        </el-form-item>

        <el-form-item label="价格精度">
          <el-radio-group v-model="precision" :disabled="submitting">
            <el-radio-button :value="0">元</el-radio-button>
            <el-radio-button :value="1">角</el-radio-button>
            <el-radio-button :value="2">分</el-radio-button>
          </el-radio-group>
        </el-form-item>

        <el-form-item label="执行选项">
          <div class="option-list">
            <div class="option-row">
              <el-checkbox
                v-model="force"
                aria-label="强制清空并重建选中级别的全部价格"
                :disabled="submitting"
              />
              <span>强制清空并重建选中级别的全部价格</span>
            </div>
            <div class="option-row">
              <el-checkbox
                v-model="syncCostRates"
                aria-label="执行成功后同步会员级别倍率"
                :disabled="submitting"
              />
              <span>执行成功后同步会员级别倍率</span>
            </div>
            <el-alert
              v-if="syncHint"
              :title="syncHint"
              type="warning"
              :closable="false"
              show-icon
            />
          </div>
        </el-form-item>
      </el-form>

      <section v-if="previewResult" class="preview-section">
        <div class="section-title">预览结果</div>
        <el-row :gutter="12" class="statistics">
          <el-col v-for="item in statistics" :key="item.label" :xs="12" :sm="6">
            <el-statistic :title="item.label" :value="item.value" />
          </el-col>
        </el-row>

        <el-alert
          v-if="previewReason"
          :title="previewReason"
          type="error"
          :closable="false"
          show-icon
          class="reason-alert"
        />

        <div class="warning-title">
          成本告警（{{ previewResult.warnings.length }}）
        </div>
        <el-table
          :data="previewResult.warnings"
          border
          empty-text="无成本告警"
          max-height="260"
        >
          <el-table-column prop="product_name" label="产品" min-width="280">
            <template #default="{ row }">
              {{ row.product_name }}（{{ row.product_id }}）
            </template>
          </el-table-column>
          <el-table-column prop="period" label="周期" width="70" />
          <el-table-column prop="field" label="字段" min-width="170" />
          <el-table-column prop="message" label="消息" min-width="170" />
        </el-table>
      </section>
    </el-scrollbar>

    <template #footer>
      <el-button :disabled="submitting" @click="dialogVisible = false">
        关闭
      </el-button>
      <el-button
        type="primary"
        :loading="previewLoading"
        :disabled="loadingLevels || executing"
        @click="previewInitialization"
      >
        预览
      </el-button>
      <el-button
        :type="force ? 'danger' : 'success'"
        :loading="executing"
        :disabled="!canExecute || previewLoading"
        @click="executeInitialization"
      >
        正式执行
      </el-button>
    </template>
  </el-dialog>
</template>

<script setup lang="ts">
import { computed, ref, watch } from "vue";
import { ElMessageBox } from "element-plus";
import { message } from "@shared/utils";
import * as ProductPriceApi from "@/api/productPrice";
import type {
  InitializationRequest,
  InitializationResult
} from "@/api/productPrice";
import * as UserLevelApi from "@/api/userLevel";
import {
  buildForceConfirmation,
  canAcceptLevelList,
  canAcceptExecutionResult,
  canExecuteCurrentPreview,
  canExecutePreview,
  cloneExecutionRequest,
  createPreviewState,
  loadAllLevels,
  makeInitializationRevision,
  makeSelectedCodesRevision,
  mapSelectedLevelDetails,
  normalizeInitializationParams,
  syncCostRatesHint
} from "./initializationState";
import type { InitializationLevelDetails } from "./initializationState";
import type { InitializationLevelProfile } from "./initializationState";

defineOptions({
  name: "ProductPriceInitialization"
});

const props = defineProps<{
  modelValue: boolean;
}>();

const emit = defineEmits<{
  (event: "update:modelValue", value: boolean): void;
  (event: "saved"): void;
}>();

const dialogVisible = computed({
  get: () => props.modelValue,
  set: value => emit("update:modelValue", value)
});

const selectionType = ref<"base" | "custom">("base");
const baseCodes = ref<string[]>([]);
const customCodes = ref<string[]>([]);
const baseOptions = ref<InitializationLevelProfile[]>([]);
const customOptions = ref<InitializationLevelProfile[]>([]);
const selectedLevels = ref<InitializationLevelDetails[]>([]);
const precision = ref<0 | 1 | 2>(2);
const force = ref(false);
const syncCostRates = ref(false);
const baseLevelsLoading = ref(false);
const customLevelsLoading = ref(false);
const previewLoading = ref(false);
const executing = ref(false);
const previewResult = ref<InitializationResult | null>(null);
const executionRequest = ref<Readonly<InitializationRequest> | null>(null);
const previewState = createPreviewState();
let openGeneration = 0;
let customLevelsLoaded = false;
const costRateOverrides = new Map<string, string | number>();

const submitting = computed(() => previewLoading.value || executing.value);
const loadingLevels = computed(
  () => baseLevelsLoading.value || customLevelsLoading.value
);

const editingRequest = (): InitializationRequest => ({
  levels: selectedLevels.value.map(level => ({
    code: level.code,
    cost_rate: level.cost_rate
  })),
  precision: precision.value,
  force: force.value,
  sync_cost_rates: syncCostRates.value,
  preview: true
});

const editingRevision = computed(() => {
  try {
    return makeInitializationRevision(editingRequest());
  } catch {
    return `invalid:${JSON.stringify(editingRequest())}`;
  }
});

const selectedCodesRevision = computed(() =>
  makeSelectedCodesRevision(baseCodes.value, customCodes.value)
);

const syncHint = computed(() =>
  syncCostRatesHint(force.value, syncCostRates.value)
);

const canExecute = computed(() =>
  canExecuteCurrentPreview(
    previewResult.value,
    executionRequest.value,
    baseCodes.value,
    customCodes.value,
    selectedLevels.value,
    editingRevision.value
  )
);

const previewReason = computed(() => {
  if (!previewResult.value) return "";
  if (previewResult.value.reason === "stale_preview") {
    return "预览已失效，请按当前参数重新预览。";
  }
  if (previewResult.value.reason === "cost_validation") {
    return "存在成本配置问题，请处理全部告警后重新预览。";
  }
  if (!previewResult.value.can_execute) {
    return "当前预览不允许执行，请检查告警后重新预览。";
  }
  return "";
});

const statistics = computed(() => {
  const result = previewResult.value;
  if (!result) return [];

  return [
    { label: "产品数", value: result.product_count },
    { label: "级别数", value: result.level_count },
    { label: "目标数", value: result.target_count },
    { label: "新增数", value: result.created_count },
    { label: "保留数", value: result.preserved_count },
    { label: "删除数", value: result.deleted_count },
    { label: "重建数", value: result.rebuilt_count },
    { label: "同步级别数", value: result.synced_level_count }
  ];
});

watch(
  () => props.modelValue,
  visible => {
    if (visible) void openDialog();
  },
  { immediate: true }
);

watch(
  selectedCodesRevision,
  () => {
    invalidatePreview();
    if (props.modelValue) refreshSelectedLevels();
  },
  { flush: "sync" }
);

watch(selectionType, type => {
  if (type === "custom" && props.modelValue && !customLevelsLoaded) {
    void loadCustomLevels();
  }
});

watch(editingRevision, () => invalidatePreview());

async function openDialog() {
  const generation = ++openGeneration;
  baseLevelsLoading.value = false;
  customLevelsLoading.value = false;
  selectionType.value = "base";
  baseCodes.value = [];
  customCodes.value = [];
  baseOptions.value = [];
  customOptions.value = [];
  customLevelsLoaded = false;
  selectedLevels.value = [];
  costRateOverrides.clear();
  precision.value = 2;
  force.value = false;
  syncCostRates.value = false;
  previewResult.value = null;
  executionRequest.value = null;
  previewState.invalidate();
  baseLevelsLoading.value = true;

  try {
    const levels = await loadAllLevels(async (currentPage, pageSize) => {
      const response = await UserLevelApi.index({
        currentPage,
        pageSize,
        custom: 0
      });

      return {
        items: response.data?.items ?? [],
        total: response.data?.total ?? 0
      };
    });

    if (!canAcceptLevelList(generation, openGeneration, props.modelValue))
      return;
    baseOptions.value = levels;
    baseCodes.value = levels.map(level => level.code);
  } catch {
    if (canAcceptLevelList(generation, openGeneration, props.modelValue)) {
      message("加载基础会员级别失败", { type: "error" });
    }
  } finally {
    if (generation === openGeneration) baseLevelsLoading.value = false;
  }
}

async function loadCustomLevels() {
  const generation = openGeneration;
  customLevelsLoading.value = true;

  try {
    const levels = await loadAllLevels(async (currentPage, pageSize) => {
      const response = await UserLevelApi.index({
        currentPage,
        pageSize,
        custom: 1
      });

      return {
        items: response.data?.items ?? [],
        total: response.data?.total ?? 0
      };
    });

    if (!canAcceptLevelList(generation, openGeneration, props.modelValue))
      return;
    customOptions.value = levels;
    customLevelsLoaded = true;
  } catch {
    if (canAcceptLevelList(generation, openGeneration, props.modelValue)) {
      message("加载定制会员级别失败", { type: "error" });
    }
  } finally {
    if (generation === openGeneration) customLevelsLoading.value = false;
  }
}

function refreshSelectedLevels() {
  try {
    selectedLevels.value = mapSelectedLevelDetails(
      baseCodes.value,
      customCodes.value,
      [...baseOptions.value, ...customOptions.value],
      costRateOverrides
    );
  } catch (error) {
    selectedLevels.value = [];
    message(error instanceof Error ? error.message : "会员级别资料不完整", {
      type: "error"
    });
  }
}

function updateCostRate(code: string, value: number | undefined) {
  const level = selectedLevels.value.find(item => item.code === code);
  if (!level) return;

  const costRate = value === undefined ? "" : String(value);
  level.cost_rate = costRate;
  costRateOverrides.set(code, costRate);
}

function invalidatePreview() {
  previewState.invalidate();
  previewResult.value = null;
  executionRequest.value = null;
}

async function previewInitialization() {
  const selectedCodeCount = new Set([...baseCodes.value, ...customCodes.value])
    .size;
  if (selectedCodeCount === 0) {
    message("请至少选择一个会员级别", { type: "warning" });
    return;
  }
  if (
    selectedLevels.value.length !== selectedCodeCount ||
    makeSelectedCodesRevision(baseCodes.value, customCodes.value) !==
      makeSelectedCodesRevision(
        selectedLevels.value.map(level => level.code),
        []
      )
  ) {
    message("会员级别完整资料尚未加载完成", { type: "warning" });
    return;
  }

  let request: InitializationRequest;
  try {
    request = normalizeInitializationParams(editingRequest());
  } catch (error) {
    message(error instanceof Error ? error.message : "请检查会员倍率", {
      type: "warning"
    });
    return;
  }

  previewResult.value = null;
  executionRequest.value = null;
  const previewRequest = previewState.begin(request);
  previewLoading.value = true;

  try {
    const response = await ProductPriceApi.initialize(request);
    if (!response.data || !previewState.accept(previewRequest, response.data))
      return;

    previewResult.value = response.data;
    if (canExecutePreview(response.data) && response.data.preview_token) {
      executionRequest.value = cloneExecutionRequest(
        request,
        response.data.preview_token
      );
    }
  } finally {
    if (previewState.isLatest(previewRequest)) previewLoading.value = false;
  }
}

async function executeInitialization() {
  const request = executionRequest.value;
  const result = previewResult.value;
  if (!canExecute.value || !request || !result || !canExecutePreview(result))
    return;

  if (request.force) {
    try {
      await ElMessageBox.confirm(
        buildForceConfirmation(selectedLevels.value, result),
        "危险操作确认",
        {
          type: "error",
          dangerouslyUseHTMLString: true,
          confirmButtonText: "确认强制重建",
          cancelButtonText: "取消",
          confirmButtonClass: "el-button--danger"
        }
      );
    } catch {
      return;
    }
  }

  executing.value = true;
  try {
    const response = await ProductPriceApi.initialize(request);
    if (!response.data) return;

    const decision = canAcceptExecutionResult(response.data);
    if (decision.accepted) {
      message("产品价格初始化成功", { type: "success" });
      emit("saved");
      dialogVisible.value = false;
      return;
    }

    previewResult.value = response.data;
    if (decision.invalidate) {
      previewState.invalidate();
      executionRequest.value = null;
    }
    message(
      response.data.reason === "stale_preview"
        ? "预览已失效，请重新预览"
        : "初始化未执行，请检查告警后重新预览",
      { type: "warning" }
    );
  } finally {
    executing.value = false;
  }
}

function onClosed() {
  openGeneration += 1;
  baseLevelsLoading.value = false;
  customLevelsLoading.value = false;
  previewState.invalidate();
  previewResult.value = null;
  executionRequest.value = null;
}
</script>

<style scoped lang="scss">
.dialog-title,
.section-title {
  font-size: 16px;
  font-weight: 600;
  color: var(--el-text-color-primary);
}

.initialization-form {
  padding: 4px 20px 0;
}

.selection-summary {
  width: 100%;
  margin-top: 6px;
  line-height: 20px;
  color: var(--el-text-color-secondary);
}

.level-rate-table {
  width: 100%;
}

.option-list {
  display: flex;
  flex-direction: column;
  gap: 8px;
  width: 100%;
}

.option-row {
  display: flex;
  gap: 8px;
  align-items: center;
}

.preview-section {
  padding: 18px 20px 4px;
  border-top: 1px solid var(--el-border-color-light);
}

.statistics {
  margin-top: 12px;
}

.statistics :deep(.el-statistic) {
  padding: 12px;
  margin-bottom: 12px;
  text-align: center;
  background: var(--el-fill-color-light);
  border-radius: 6px;
}

.reason-alert {
  margin: 4px 0 14px;
}

.warning-title {
  margin: 12px 0 8px;
  font-weight: 600;
  color: var(--el-text-color-primary);
}

:deep(.el-select) {
  width: 100%;
}

:deep(.el-input-number) {
  width: 160px;
}
</style>
