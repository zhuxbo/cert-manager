<template>
  <el-select
    :model-value="modelValue"
    :placeholder="placeholder"
    filterable
    remote
    reserve-keyword
    remote-show-suffix
    :remote-method="handleSearch"
    :loading="loading"
    :multiple="multiple"
    v-bind="$attrs"
    @update:model-value="updateModelValue"
    @visible-change="handleVisibleChange"
    @focus="handleFocus"
  >
    <el-option
      v-for="item in options"
      :key="item.value"
      :label="item.label"
      :value="item.value"
    />
    <template v-if="showPagination && totalPages > 1" #footer>
      <el-pagination
        v-model:current-page="currentPage"
        :page-size="pageSize"
        :pager-count="5"
        :total="total"
        layout="total, prev, pager, next"
        @current-change="handleCurrentChange"
      />
    </template>
  </el-select>
</template>

<script setup lang="ts">
import { ref, watch, onMounted, computed } from "vue";
import { http } from "../../../utils";
import type {
  RemoteSelectProps,
  RemoteSelectOption,
  RemoteSelectEmits
} from "./types";

const props = withDefaults(defineProps<RemoteSelectProps>(), {
  placeholder: "请选择",
  pageSize: 7,
  showPagination: true,
  queryParams: () => ({}),
  itemsField: "items",
  totalField: "total",
  valueField: "id",
  labelField: "name",
  searchField: "keyword",
  multiple: false
});

const emit = defineEmits<RemoteSelectEmits>();

const options = ref<RemoteSelectOption[]>([]);
const loading = ref(false);
const currentPage = ref(1);
const pageSize = ref(props.pageSize);
const total = ref(0);
const currentQuery = ref("");
const lastRefreshKey = ref<string | number | undefined>(props.refreshKey);
const shouldFetch = ref(true);
const isFirstLoad = ref(true);

// 计算总页数
const totalPages = computed(() => {
  return Math.ceil(total.value / pageSize.value);
});

// 监听refreshKey变化
watch(
  () => props.refreshKey,
  (newKey, oldKey) => {
    // 只有当oldKey不是undefined时才重置（避免初始化时重置）
    if (newKey !== oldKey && oldKey !== undefined) {
      // refreshKey改变时，重置页码和搜索参数
      currentPage.value = 1;
      currentQuery.value = "";
      shouldFetch.value = true;
      fetchRemoteData();
    }
    lastRefreshKey.value = newKey;
  },
  { immediate: true }
);

// 监听modelValue变化
watch(
  () => props.modelValue,
  newValue => {
    // 只有当refreshKey不为空且大于0时才处理默认值
    if (
      newValue &&
      !hasOptionForValue(newValue) &&
      shouldProcessDefaultValue()
    ) {
      fetchOptionByValue(newValue);
    }
  },
  { immediate: true }
);

// 检查是否应该处理默认值
function shouldProcessDefaultValue(): boolean {
  // 如果没有设置refreshKey，则默认处理初始值
  if (props.refreshKey === undefined || props.refreshKey === null) {
    return true;
  }

  // 如果设置了refreshKey，则根据条件判断
  if (typeof props.refreshKey === "number") {
    return props.refreshKey >= 0;
  } else {
    return props.refreshKey !== "";
  }
}

// 检查是否已有对应值的选项
function hasOptionForValue(value: any): boolean {
  if (!value) return true;

  if (Array.isArray(value)) {
    return value.every(v => options.value.some(option => option.value === v));
  }

  return options.value.some(option => option.value === value);
}

// 如果有初始值，获取选项名
onMounted(async () => {
  if (props.modelValue && shouldProcessDefaultValue()) {
    await fetchOptionByValue(props.modelValue);
  }
});

// 根据值获取选项名
async function fetchOptionByValue(value: any) {
  if (!value) return;

  // 如果已经有了所有需要的选项，则不需要再次请求
  if (hasOptionForValue(value)) return;

  try {
    loading.value = true;
    // 构建查询路径，支持单个ID或数组ID的情况
    let path = "";
    let params = { ...props.queryParams };
    if (Array.isArray(value)) {
      // 过滤出尚未有选项的值
      const missingValues = value.filter(
        v => !options.value.some(option => option.value === v)
      );
      if (missingValues.length === 0) return;

      if (props.valueField === "id") {
        path = `${props.uri}/batch`;
        params = {
          ...params,
          ids: missingValues
        };
      } else {
        path = `${props.uri}`;
        params = {
          ...params,
          [props.valueField]: missingValues.join(",")
        };
      }
    } else {
      if (props.valueField === "id") {
        path = `${props.uri}/${value}`;
      } else {
        path = `${props.uri}`;
        params = {
          ...params,
          [props.valueField]: value
        };
      }
    }

    const res = await http.get<BaseResponse<any>, any>(path, { params });

    if (res.data) {
      // 处理返回的数据，可能是单个对象或数组
      const items = Array.isArray(res.data.items)
        ? res.data.items
        : Array.isArray(res.data)
          ? res.data
          : [res.data];

      // 转换为选项格式并添加到options中
      const newOptions = items.map(item => ({
        label: item[props.labelField],
        value: item[props.valueField]
      }));

      // 合并选项，避免重复
      options.value = mergeOptions(options.value, newOptions);
    }
  } finally {
    loading.value = false;
  }
}

// 合并选项，避免重复
function mergeOptions(
  oldOptions: RemoteSelectOption[],
  newOptions: RemoteSelectOption[]
): RemoteSelectOption[] {
  const result = [...oldOptions];

  for (const newOption of newOptions) {
    const exists = result.some(item => item.value === newOption.value);
    if (!exists) {
      result.push(newOption);
    }
  }

  return result;
}

// 更新modelValue
function updateModelValue(value: any) {
  emit("update:modelValue", value);
  emit("change", value);
}

// 处理下拉框获得焦点
function handleFocus() {
  if (isFirstLoad.value) {
    isFirstLoad.value = false;
    shouldFetch.value = true;
    fetchRemoteData();
  }
}

// 处理下拉框显示状态变化
function handleVisibleChange(visible: boolean) {
  if (visible && isFirstLoad.value) {
    isFirstLoad.value = false;
    shouldFetch.value = true;
    fetchRemoteData();
  }
}

// 获取远程数据
async function fetchRemoteData(params = {}) {
  if (loading.value || !shouldFetch.value) return;

  try {
    loading.value = true;
    const res = await http.get<BaseResponse<any>, any>(props.uri, {
      params: {
        currentPage: currentPage.value,
        pageSize: pageSize.value,
        ...props.queryParams,
        ...params
      }
    });

    if (res.data) {
      // 转换数据格式
      const newOptions = (res.data[props.itemsField] || []).map(item => ({
        label: item[props.labelField],
        value: item[props.valueField]
      }));

      // 保存当前已选中的选项
      const selectedOptions = getSelectedOptions();

      // 更新选项，合并已选中的选项和新获取的选项
      options.value = mergeOptions(selectedOptions, newOptions);

      // 更新总数
      total.value = res.data[props.totalField] || 0;
    }
    shouldFetch.value = false;
  } finally {
    loading.value = false;
  }
}

// 获取当前已选中的选项
function getSelectedOptions(): RemoteSelectOption[] {
  if (!props.modelValue) return [];

  const selectedValues = Array.isArray(props.modelValue)
    ? props.modelValue
    : [props.modelValue];

  return options.value.filter(option => selectedValues.includes(option.value));
}

// 暴露方法供外部使用
defineExpose({
  getSelectedOptions,
  options
});

// 搜索处理
function handleSearch(query: string) {
  if (currentQuery.value === "" && query === "") return;

  currentQuery.value = query; // 保存搜索关键词
  currentPage.value = 1; // 重置页码
  shouldFetch.value = true;

  const params: Record<string, any> = {};
  params[props.searchField] = query;

  fetchRemoteData(params);
}

// 页码变化处理
function handleCurrentChange(val: number) {
  currentPage.value = val;
  shouldFetch.value = true;

  const params: Record<string, any> = {};
  if (currentQuery.value) {
    params[props.searchField] = currentQuery.value;
  }

  fetchRemoteData(params);
}
</script>
