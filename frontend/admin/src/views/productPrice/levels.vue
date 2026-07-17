<template>
  <el-dialog
    v-model="dialogVisible"
    class="price-levels-dialog"
    :close-on-click-modal="false"
    width="80%"
    @close="dialogVisible = false"
  >
    <template #header>
      <div class="title">设置会员价格</div>
    </template>
    <el-scrollbar v-loading="loading" class="form-scrollbar">
      <div class="operate-form" :style="'width: calc(100% - ' + 80 / 2 + 'px)'">
        <el-form
          v-if="!loading"
          ref="formRef"
          :model="formData"
          label-position="right"
          :label-width="80 + 'px'"
          :rules="rules"
        >
          <el-form-item label="产品" prop="product_id">
            <ReRemoteSelect
              v-model="formData.product_id"
              uri="/product"
              value-field="id"
              label-field="name"
              search-field="quickSearch"
              clearable
              placeholder="请选择产品"
              :pageSize="100"
              :showPagination="false"
              @change="productSelected"
            />
          </el-form-item>

          <el-form-item label="类型" prop="user_level_type">
            <el-radio-group
              v-model="user_level_type"
              @change="userLevelTypeChanged"
            >
              <el-radio :value="'base'">基础级别</el-radio>
              <el-radio :value="'custom'">定制级别</el-radio>
            </el-radio-group>
          </el-form-item>

          <el-form-item label="会员级别">
            <ReRemoteSelect
              :key="user_level_refresh_key"
              ref="userLevelsRef"
              v-model="formData.level_codes"
              :queryParams="userLevelsParams"
              uri="/user-level"
              value-field="code"
              label-field="name"
              search-field="quickSearch"
              :multiple="true"
              :multiple-limit="10"
              :collapse-tags="false"
              :automatic-dropdown="true"
              :close-on-select="false"
              clearable
              placeholder="请选择会员级别"
              @change="userLevelsSelected"
            />
          </el-form-item>

          <template
            v-for="(level_item, level_index) in formData!.product_price"
            :key="level_index"
          >
            <el-form-item
              prop="product_price"
              :label="userLevelNames[level_index]"
            >
              <div>
                <div class="price_label">成本价</div>
                <div class="price">
                  <div class="lable">比例</div>
                  <div class="input">
                    <el-input v-model="userLevelCostRates[level_index]" />
                  </div>
                </div>
                <div class="clear" />
                <template
                  v-for="(price_type_item, price_type_index) in level_item"
                  :key="price_type_index"
                >
                  <div class="price_label">
                    {{ priceLabel[price_type_index] }}
                  </div>
                  <div
                    v-for="period_index in Object.keys(price_type_item)"
                    :key="period_index"
                    class="price"
                  >
                    <div class="lable">{{ productPeriods[period_index] }}</div>
                    <div class="input">
                      <el-input
                        v-model="
                          formData!.product_price[level_index][
                            price_type_index
                          ][period_index]
                        "
                        @input="
                          updateCostRate(
                            level_index,
                            price_type_index,
                            period_index
                          )
                        "
                      />
                    </div>
                  </div>
                  <div class="clear" />
                </template>
                <div class="clear" />
              </div>
            </el-form-item>
          </template>

          <el-form-item
            v-if="Object.keys(productCost).length > 0"
            label="成本价"
          >
            <div>
              <template
                v-for="(price_type_item, price_type_index) in productCost"
                :key="price_type_index"
              >
                <div class="price_label">
                  {{ priceLabel[price_type_index] }}
                </div>
                <div
                  v-for="period_index in Object.keys(price_type_item)"
                  :key="period_index"
                  class="price"
                >
                  <div class="lable">{{ productPeriods[period_index] }}</div>
                  <div class="input">
                    <el-input
                      v-model="productCost[price_type_index][period_index]"
                    />
                  </div>
                </div>
                <div class="clear" />
              </template>
              <div class="clear" />
            </div>
          </el-form-item>

          <el-form-item v-if="Object.keys(productCost).length > 0">
            <div class="cost-price-wrapper">
              <el-select
                v-model="selectedPriceType"
                placeholder="选择价格类型"
                style="width: 120px; margin-right: 10px"
              >
                <el-option label="全部类型" value="all" />
                <el-option
                  v-for="(label, key) in availablePriceTypes"
                  :key="key"
                  :label="label"
                  :value="key"
                />
              </el-select>
              <el-button type="primary" @click="setAllPricesByPercentage">
                按比例设置价格
              </el-button>
              <el-button type="info" @click="roundToYuan"> 取整(元) </el-button>
              <el-button type="info" @click="roundToJiao"> 取整(角) </el-button>
            </div>
          </el-form-item>
        </el-form>
      </div>
    </el-scrollbar>
    <template #footer>
      <div :style="'width: calc(100% - ' + 80 / 1.8 + 'px)'">
        <el-button @click="dialogVisible = false">关闭</el-button>
        <el-button :loading="submitLoading" type="primary" @click="onSubmit">
          保存
        </el-button>
      </div>
    </template>
  </el-dialog>
</template>

<script setup lang="ts">
import { computed, reactive, ref, watch } from "vue";
import ReRemoteSelect from "@shared/components/ReRemoteSelect";
import type { FormInstance, FormRules } from "element-plus";
import * as ProductApi from "@/api/product";
import * as ProductPriceApi from "@/api/productPrice";
import * as UserLevelApi from "@/api/userLevel";
import type { UserLevelDto } from "@/api/userLevel";
import { periodLabels } from "@/views/system/dictionary";
import { toCostRateNumber } from "@/views/userLevel/costRate";
import { message } from "@shared/utils";

// 定义组件属性（保持向后兼容，新增可选 productId）
const props = defineProps({
  modelValue: {
    type: Boolean,
    default: false
  },
  productId: {
    type: [Number, null] as unknown as () => number | null,
    default: null
  }
});

// 定义组件事件
const emit = defineEmits(["update:modelValue", "saved"]);

const user_level_refresh_key = ref(0);
const dialogVisible = ref(props.modelValue);
const priceLabel = {
  price: "价格",
  alternative_standard_price: "SAN标准域",
  alternative_wildcard_price: "SAN通配符"
};

// 监听对话框状态变化
watch(
  () => props.modelValue,
  newVal => {
    dialogVisible.value = newVal;
    // 当对话框打开时，如果是base类型，自动选中所有base类型级别
    if (newVal && user_level_type.value === "base") {
      userLevelTypeChanged();
    }
  }
);

watch(
  () => dialogVisible.value,
  newVal => {
    emit("update:modelValue", newVal);
  }
);

// 当弹窗打开或外部传入的 productId 变化时，自动选中产品并刷新相关数据
watch(
  () => [props.productId, dialogVisible.value],
  ([pid, visible]) => {
    if (visible && pid && Number(pid) > 0) {
      // 仅当没有已选产品或与传入的不同才更新，避免覆盖用户手动选择
      if (!formData.product_id || Number(formData.product_id) !== Number(pid)) {
        formData.product_id = Number(pid) as any;
        // 触发联动：加载产品成本、期限和现有价格
        productSelected();
      }
    }
  },
  { immediate: true }
);

const loading = ref(false);
const submitLoading = ref(false);

const formRef = ref<FormInstance>();

interface FormDataType {
  product_id: number;
  level_codes: string[];
  product_price: {
    [key: string]: {
      [key: string]: {
        [key: string]: string;
      };
    };
  };
}

const formData = reactive<FormDataType>({} as FormDataType);

formData.product_price = reactive<{
  [key: string]: { [key: string]: { [key: string]: string } };
}>({});

const productSelected = () => {
  getProduct();
  getProductPrice();
};

const userLevelsSelected = () => {
  getUserLevelInfo();
  getProductPrice();
};

const productPeriods: { [key: string]: string } = reactive({});
const productCost: { [key: string]: { [key: string]: number } } = reactive({});

const getProduct = () => {
  if (!formData!.product_id || parseInt(formData!.product_id) <= 0)
    return undefined;
  ProductApi.show(formData!.product_id).then((res: BaseResponse<any>) => {
    // 获取产品的所有期限
    res.data.periods = res.data.periods.sort((a: number, b: number) => a - b);

    // 先清空，再赋值
    Object.keys(productPeriods).forEach(key => {
      delete productPeriods[key];
    });
    res.data.periods.forEach((item: string) => {
      productPeriods[item] = periodLabels[item];
    });
    Object.keys(productCost).forEach(key => {
      delete productCost[key];
    });
    Object.assign(productCost, reactive(res.data.cost));
  });
};

const getProductPrice = () => {
  if (!formData!.product_id || parseInt(formData!.product_id) <= 0) {
    formData!.product_price = {};
    return;
  }
  if (!formData!.level_codes || formData!.level_codes.length <= 0) {
    formData!.product_price = {};
    return;
  }
  ProductPriceApi.get(formData!.product_id, formData!.level_codes).then(
    (res: BaseResponse<any>) => {
      if (res.data) {
        formData!.product_price = res.data;
      }
    }
  );
};

const userLevelsRef = ref();
const userLevelNames = ref<{ [key: string]: string }>({});
const userLevelCostRates = ref<{ [key: string]: number }>({});

// 价格类型选择
const selectedPriceType = ref("all");

// 获取当前产品可用的价格类型
const availablePriceTypes = computed(() => {
  const types: { [key: string]: string } = {};
  if (Object.keys(productCost).length > 0) {
    Object.keys(productCost).forEach(key => {
      types[key] = priceLabel[key] || key;
    });
  }
  return types;
});

// 获取用户级别信息（名称和成本比例）
const getUserLevelInfo = () => {
  setTimeout(() => {
    if (formData!.level_codes.length > 0) {
      UserLevelApi.batchShowInCodes(formData!.level_codes).then(res => {
        // 获取选中的会员级别
        res.data?.forEach((item: UserLevelDto) => {
          userLevelNames.value[item.code] = item.name;
          userLevelCostRates.value[item.code] = toCostRateNumber(
            item.cost_rate
          );
        });
      });
    } else {
      userLevelNames.value = {};
      userLevelCostRates.value = {};
    }
  }, 100);
};

const userLevelsParams = reactive({
  custom: 0
});

const user_level_type = ref("base");
const userLevelTypeChanged = () => {
  formData!.level_codes = [];
  formData!.product_price = {};
  // 先把参数清空再刷新
  user_level_refresh_key.value++;

  if (user_level_type.value === "base") {
    userLevelsParams["custom"] = 0;
    // 当选择base类型时，获取并选中所有base类型的级别
    UserLevelApi.index({ custom: 0 }).then((res: BaseResponse<any>) => {
      if (res.data && res.data.items) {
        formData.level_codes = res.data.items.map((item: any) => item.code);
        // 执行用户级别选择事件
        userLevelsSelected();
      }
    });
  } else {
    userLevelsParams["custom"] = 1;
  }
};

// 一键按成本价比例设置级别价格
const setAllPricesByPercentage = () => {
  if (!productCost) {
    message("成本价不存在，无法按比例设置", { type: "warning" });
    return;
  }

  if (!selectedPriceType.value) {
    message("请选择价格类型", { type: "warning" });
    return;
  }

  // 遍历所有会员级别价格并设置
  for (const levelIndex in formData.product_price) {
    if (!userLevelCostRates.value[levelIndex]) {
      message(userLevelNames.value[levelIndex] + "成本价比例不存在", {
        type: "warning"
      });
      return;
    }

    const rate = userLevelCostRates.value[levelIndex];
    const levelItem = formData.product_price[levelIndex];

    // 根据选择的价格类型设置价格
    if (selectedPriceType.value === "all") {
      // 设置所有类型的价格
      for (const priceTypeIndex in levelItem) {
        for (const periodIndex in levelItem[priceTypeIndex]) {
          if (
            productCost[priceTypeIndex] &&
            productCost[priceTypeIndex][periodIndex]
          ) {
            formData.product_price[levelIndex][priceTypeIndex][periodIndex] = (
              parseFloat(productCost[priceTypeIndex][periodIndex]) * rate
            ).toFixed(2);
          }
        }
      }
    } else {
      // 设置指定类型的价格
      if (
        levelItem[selectedPriceType.value] &&
        productCost[selectedPriceType.value]
      ) {
        for (const periodIndex in levelItem[selectedPriceType.value]) {
          if (productCost[selectedPriceType.value][periodIndex]) {
            formData.product_price[levelIndex][selectedPriceType.value][
              periodIndex
            ] = (
              parseFloat(productCost[selectedPriceType.value][periodIndex]) *
              rate
            ).toFixed(2);
          }
        }
      }
    }
  }

  const typeText =
    selectedPriceType.value === "all"
      ? "所有类型"
      : priceLabel[selectedPriceType.value] || selectedPriceType.value;
  message(`已设置${typeText}的级别价格`, { type: "success" });
};

// 取整到元（保留0位小数）
const roundToYuan = () => {
  if (Object.keys(formData.product_price).length === 0) {
    message("请先设置价格", { type: "warning" });
    return;
  }

  // 遍历所有会员级别价格并取整到元
  for (const levelIndex in formData.product_price) {
    const levelItem = formData.product_price[levelIndex];
    for (const priceTypeIndex in levelItem) {
      for (const periodIndex in levelItem[priceTypeIndex]) {
        const currentPrice = parseFloat(levelItem[priceTypeIndex][periodIndex]);
        if (!isNaN(currentPrice)) {
          formData.product_price[levelIndex][priceTypeIndex][periodIndex] =
            Math.round(currentPrice).toString();
        }
      }
    }
  }

  message("已取整到元", { type: "success" });
};

// 取整到角（保留1位小数）
const roundToJiao = () => {
  if (Object.keys(formData.product_price).length === 0) {
    message("请先设置价格", { type: "warning" });
    return;
  }

  // 遍历所有会员级别价格并取整到角
  for (const levelIndex in formData.product_price) {
    const levelItem = formData.product_price[levelIndex];
    for (const priceTypeIndex in levelItem) {
      for (const periodIndex in levelItem[priceTypeIndex]) {
        const currentPrice = parseFloat(levelItem[priceTypeIndex][periodIndex]);
        if (!isNaN(currentPrice)) {
          formData.product_price[levelIndex][priceTypeIndex][periodIndex] = (
            Math.round(currentPrice * 10) / 10
          ).toFixed(1);
        }
      }
    }
  }

  message("已取整到角", { type: "success" });
};

// 根据价格输入自动更新成本价倍率
const updateCostRate = (
  levelIndex: any,
  priceTypeIndex: any,
  periodIndex: any
) => {
  const levelKey = levelIndex.toString();
  const priceTypeKey = priceTypeIndex.toString();
  const periodKey = periodIndex.toString();

  // 获取当前输入的价格
  const currentPrice = parseFloat(
    formData.product_price[levelKey][priceTypeKey][periodKey]
  );

  // 获取对应的成本价
  const costPrice = parseFloat(productCost[priceTypeKey]?.[periodKey]);

  // 如果价格和成本价都有效，计算倍率
  if (!isNaN(currentPrice) && !isNaN(costPrice) && costPrice > 0) {
    const rate = currentPrice / costPrice;
    // 精确到四位小数
    userLevelCostRates.value[levelKey] = parseFloat(rate.toFixed(4));
  }
};

// 提交表单
const onSubmit = () => {
  if (!formRef.value) return;

  formRef.value.validate().then(valid => {
    if (valid) {
      submitLoading.value = true;
      ProductPriceApi.set(
        parseInt(formData.product_id.toString()),
        formData.product_price
      )
        .then((res: BaseResponse<any>) => {
          if (res.code === 1) {
            message("保存成功", { type: "success" });
            emit("saved");
            // 保存成功后不关闭对话框，方便继续操作
          }
        })
        .finally(() => {
          submitLoading.value = false;
        });
    }
  });
};

const rules = reactive<FormRules<FormDataType>>({
  product_id: [{ required: true, message: "请选择产品", trigger: "change" }],
  level_codes: [
    {
      required: true,
      message: "请选择会员级别",
      trigger: "change"
    }
  ]
});
</script>

<style scoped lang="scss">
/* 响应式设计 */
@media (height <= 800px) {
  .price-levels-dialog {
    :deep(.el-dialog) {
      max-height: calc(100vh - 40px);
    }

    :deep(.el-dialog__body) {
      height: 60vh;
      max-height: calc(100vh - 160px);
    }
  }
}

.price-levels-dialog {
  :deep(.el-overlay) {
    display: flex;
    align-items: center;
    justify-content: center;
  }

  :deep(.el-dialog) {
    display: flex;
    flex-direction: column;
    max-height: calc(100vh - 100px);
    margin: 0 !important;
    overflow: hidden;
    border-radius: 8px;
  }

  :deep(.el-dialog__header) {
    flex-shrink: 0;
    padding: 20px 20px 16px;
    margin: 0;
    border-bottom: 1px solid var(--el-border-color-light);
  }

  :deep(.el-dialog__body) {
    flex: 1;
    height: 70vh;
    max-height: calc(100vh - 200px);
    padding: 0;
    overflow: hidden;
  }

  :deep(.el-dialog__footer) {
    flex-shrink: 0;
    padding: 15px 20px;
    border-top: 1px solid var(--el-border-color-light);
  }
}

/* 表单滚动区域 */
.form-scrollbar {
  padding: 20px;
}

/* 表单容器 */
.operate-form {
  padding-top: 0;
}

/* 价格操作按钮组 */
.cost-price-wrapper {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
  align-items: center;
}

/* 价格布局相关样式 */
.price_label {
  float: left;
  width: 80px;
  margin-right: 6px;
  margin-bottom: 6px;
  line-height: 32px;
  text-align: right;
}

.price {
  float: left;
  display: flex;
  align-items: center;
  margin-bottom: 6px;
}

.lable {
  float: left;
  width: 32px;
  margin-right: 6px;
  line-height: 32px;
  text-align: right;
}

.input {
  float: left;
  width: 80px;
  margin-right: 12px;
}

.clear {
  clear: both;
}

/* 对话框整体样式 - 垂直居中 */
</style>
