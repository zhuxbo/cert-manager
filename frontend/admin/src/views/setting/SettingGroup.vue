<script setup lang="tsx">
import { ref, onMounted, computed } from "vue";
import {
  ElTable,
  ElTableColumn,
  ElButton,
  ElPopconfirm,
  ElInput,
  ElInputNumber,
  ElSwitch,
  ElSelect,
  ElOption
} from "element-plus";
import { PlusDrawerForm } from "plus-pro-components";
import { getGroupSettings, destroy, batchUpdateSettings } from "@/api/setting";
import { useSettingFormStore } from "./settingFormStore";
import ArrayInput from "./ArrayInput.vue";
import SiteImageUpload from "./SiteImageUpload.vue";
import { message } from "@shared/utils";
import { useDrawerSize } from "@/views/system/drawer";

const props = defineProps({
  group: {
    type: Object,
    required: true
  },
  onRefresh: {
    type: Function,
    default: () => {}
  }
});

const emit = defineEmits(["edit-group", "delete-group"]);

// 使用统一的响应式抽屉宽度
const { drawerSize } = useDrawerSize();

const loading = ref(false);
const settings = ref([]);
const editableSettings = ref([]);
const isEditing = ref(false);

// 创建设置项表单
const {
  storeRef,
  showStore,
  storeId,
  storeValues,
  storeColumns,
  rules,
  openStoreForm,
  confirmStoreForm,
  closeStoreForm
} = useSettingFormStore(() => loadSettings());

// 加载当前组的设置项
const loadSettings = () => {
  loading.value = true;
  getGroupSettings(props.group.id).then(({ data }) => {
    settings.value = data.group.settings || [];
    // 确保数据类型正确
    settings.value.forEach(item => {
      if (
        item.type === "select" &&
        item.is_multiple &&
        typeof item.value === "string"
      ) {
        try {
          item.value = JSON.parse(item.value);
        } catch (e) {
          item.value = [];
        }
      }
      if (item.type === "array" && typeof item.value === "string") {
        try {
          item.value = JSON.parse(item.value);
        } catch (e) {
          item.value = [];
        }
      }
      if (typeof item.options === "string") {
        try {
          item.options = JSON.parse(item.options);
        } catch (e) {
          item.options = [];
        }
      }
    });
    loading.value = false;
  });
};

// 编辑设置组
const handleEditGroup = () => {
  emit("edit-group", props.group.id);
};

// 删除设置组
const handleDeleteGroup = () => {
  emit("delete-group", props.group.id);
};

// 添加设置项
const handleAddSetting = () => {
  openStoreForm(0, props.group.id);
};

// 编辑设置项
const handleEditSetting = id => {
  openStoreForm(id);
};

// 删除设置项
const handleDeleteSetting = id => {
  destroy(id).then(() => {
    message("删除成功", { type: "success" });
    loadSettings();
  });
};

// 设置项类型映射
const typeMap = {
  string: "字符串",
  integer: "整数",
  float: "浮点数",
  boolean: "布尔值",
  array: "数组",
  select: "选择",
  base64: "文本",
  image: "图片"
};

// 确保值是数组
const ensureArray = value => {
  if (Array.isArray(value)) return value;
  if (typeof value === "string") {
    try {
      const parsed = JSON.parse(value);
      return Array.isArray(parsed) ? parsed : [];
    } catch (e) {
      return [];
    }
  }
  return [];
};

// 辅助函数：根据值和选项获取标签
const getSelectLabels = (value, options, isMultiple) => {
  if (!Array.isArray(options) || options.length === 0) return value; // 选项无效则直接返回值

  if (isMultiple) {
    const values = ensureArray(value);
    return values
      .map(val => options.find(opt => opt.value === val)?.label || val)
      .join(", ");
  } else {
    return options.find(opt => opt.value === value)?.label || value;
  }
};

// 批量编辑时使用的选项缓存
const editableOptionsCache = computed(() => {
  const cache = {};
  if (isEditing.value) {
    editableSettings.value.forEach(item => {
      if (item.type === "select") {
        cache[item.id] = Array.isArray(item.options) ? item.options : [];
      }
    });
  }
  return cache;
});

// 开始批量编辑
const startBatchEdit = () => {
  editableSettings.value = JSON.parse(JSON.stringify(settings.value));
  isEditing.value = true;
};

// 取消批量编辑
const cancelBatchEdit = () => {
  editableSettings.value = [];
  isEditing.value = false;
};

// 保存批量编辑
const saveBatchEdit = () => {
  batchUpdateSettings(editableSettings.value).then(() => {
    message("批量更新成功", { type: "success" });
    isEditing.value = false;
    loadSettings();
  });
};

// 处理设置项值的变更
const handleValueChange = (row, value) => {
  const index = editableSettings.value.findIndex(item => item.id === row.id);
  if (index > -1) {
    editableSettings.value[index].value = value;
  }
};

const isSiteImage = row =>
  row.type === "image" &&
  props.group.name === "site" &&
  ["favicon", "logo", "logoExpanded", "qrcode", "loginImage"].includes(row.key);

const siteImageKind = (
  key: string
): "favicon" | "logo" | "logo-expanded" | "qrcode" | "login-image" => {
  if (key === "favicon") return "favicon";
  if (key === "logoExpanded") return "logo-expanded";
  if (key === "loginImage") return "login-image";
  return key === "qrcode" ? "qrcode" : "logo";
};

const handleSiteImageChange = (row, value: string) => {
  row.value = value;
  const setting = settings.value.find(item => item.id === row.id);
  if (setting) setting.value = value;
  handleValueChange(row, value);
};

// 格式化布尔值显示
const formatBoolean = value => {
  return value === "1" || value === "true" || value === true ? "是" : "否";
};

// 格式化数组显示
const formatArray = value => {
  if (typeof value === "object" || Array.isArray(value)) {
    return JSON.stringify(value, null, 2);
  }
  if (typeof value === "string") {
    try {
      const arr = JSON.parse(value);
      return Array.isArray(arr) ? JSON.stringify(arr, null, 2) : value;
    } catch (e) {
      return value;
    }
  }
  return String(value);
};

onMounted(() => {
  loadSettings();
});
</script>

<template>
  <div class="setting-group mb-4 rounded p-4">
    <div class="group-header flex justify-between items-center mb-4">
      <div class="group-title">
        <h4 class="text-lg font-bold text-gray-600">{{ group.title }}</h4>
        <p class="text-gray-500 mt-1">{{ group.description }}</p>
      </div>
      <div class="group-actions flex gap-2">
        <template v-if="!isEditing">
          <el-button type="primary" @click="handleAddSetting"
            >添加设置项</el-button
          >
          <el-button type="warning" @click="handleEditGroup">编辑组</el-button>
          <el-popconfirm
            title="确定要删除此设置组吗？这将同时删除组内所有设置项！"
            width="300"
            @confirm="handleDeleteGroup"
          >
            <template #reference>
              <el-button type="danger">删除组</el-button>
            </template>
          </el-popconfirm>
          <el-button
            v-if="settings.length > 0"
            type="primary"
            plain
            @click="startBatchEdit"
          >
            批量编辑
          </el-button>
        </template>
        <template v-else>
          <el-button type="success" @click="saveBatchEdit">保存</el-button>
          <el-button @click="cancelBatchEdit">取消</el-button>
        </template>
      </div>
    </div>

    <div v-loading="loading" class="settings-table">
      <template v-if="settings.length > 0">
        <!-- 非编辑模式 -->
        <el-table v-if="!isEditing" :data="settings" style="width: 100%">
          <el-table-column prop="key" label="键名" width="150" />
          <el-table-column prop="type" label="类型" width="100">
            <template #default="{ row }">
              {{ typeMap[row.type] }}
            </template>
          </el-table-column>
          <el-table-column prop="value" label="值" min-width="300">
            <template #default="{ row }">
              <SiteImageUpload
                v-if="isSiteImage(row)"
                :model-value="row.value"
                :kind="siteImageKind(row.key)"
                @update:model-value="val => handleSiteImageChange(row, val)"
              />
              <template v-else-if="row.type === 'boolean'">
                {{ formatBoolean(row.value) }}
              </template>
              <template v-else-if="row.type === 'array'">
                {{ formatArray(row.value) }}
              </template>
              <template v-else-if="row.type === 'select'">
                {{ getSelectLabels(row.value, row.options, row.is_multiple) }}
              </template>
              <template v-else>
                {{ String(row.value).slice(0, 200) }}
                {{ String(row.value).length > 200 ? " ..." : "" }}
              </template>
            </template>
          </el-table-column>
          <el-table-column prop="description" label="描述" min-width="100" />
          <el-table-column label="操作" width="110" fixed="right">
            <template #default="{ row }">
              <el-button type="primary" link @click="handleEditSetting(row.id)">
                编辑
              </el-button>
              <el-popconfirm
                title="确定要删除此设置项吗？"
                width="200"
                @confirm="handleDeleteSetting(row.id)"
              >
                <template #reference>
                  <el-button type="danger" link>删除</el-button>
                </template>
              </el-popconfirm>
            </template>
          </el-table-column>
        </el-table>

        <!-- 编辑模式 -->
        <el-table v-else :data="editableSettings" style="width: 100%">
          <el-table-column prop="key" label="键名" width="150" />
          <el-table-column prop="type" label="类型" width="100">
            <template #default="{ row }">
              {{ typeMap[row.type] }}
            </template>
          </el-table-column>
          <el-table-column prop="value" label="值" min-width="200">
            <template #default="{ row }">
              <SiteImageUpload
                v-if="isSiteImage(row)"
                :model-value="row.value"
                :kind="siteImageKind(row.key)"
                @update:model-value="val => handleSiteImageChange(row, val)"
              />
              <template
                v-else-if="row.type === 'string' || row.type === 'base64'"
              >
                <el-input
                  :model-value="row.value"
                  :type="row.type === 'base64' ? 'textarea' : 'text'"
                  :rows="row.type === 'base64' ? 3 : 1"
                  @update:model-value="val => handleValueChange(row, val)"
                />
              </template>
              <template v-else-if="row.type === 'integer'">
                <el-input-number
                  :model-value="
                    row.value !== '' ? Number(row.value) : undefined
                  "
                  :precision="0"
                  :controls="false"
                  style="width: 100%"
                  @update:model-value="
                    val =>
                      handleValueChange(
                        row,
                        val !== undefined ? String(val) : ''
                      )
                  "
                />
              </template>
              <template v-else-if="row.type === 'float'">
                <el-input-number
                  :model-value="
                    row.value !== '' ? Number(row.value) : undefined
                  "
                  :precision="2"
                  :controls="false"
                  style="width: 100%"
                  @update:model-value="
                    val =>
                      handleValueChange(
                        row,
                        val !== undefined ? String(val) : ''
                      )
                  "
                />
              </template>
              <template v-else-if="row.type === 'boolean'">
                <el-switch
                  :model-value="row.value === '1' || row.value === true"
                  :active-value="true"
                  :inactive-value="false"
                  @update:model-value="
                    val => handleValueChange(row, val ? '1' : '0')
                  "
                />
              </template>
              <template v-else-if="row.type === 'array'">
                <ArrayInput
                  :model-value="row.value"
                  @update:model-value="val => handleValueChange(row, val)"
                />
              </template>
              <template v-else-if="row.type === 'select'">
                <el-select
                  :model-value="
                    row.is_multiple ? ensureArray(row.value) : row.value
                  "
                  :multiple="row.is_multiple"
                  filterable
                  clearable
                  style="width: 100%"
                  :placeholder="`请选择${row.is_multiple ? '(可多选)' : ''}`"
                  @update:model-value="val => handleValueChange(row, val)"
                >
                  <el-option
                    v-for="option in editableOptionsCache[row.id]"
                    :key="option.value"
                    :label="option.label"
                    :value="option.value"
                  />
                </el-select>
              </template>
              <template v-else>
                <el-input
                  :model-value="row.value"
                  @update:model-value="val => handleValueChange(row, val)"
                />
              </template>
            </template>
          </el-table-column>
          <el-table-column prop="description" label="描述" min-width="100" />
        </el-table>
      </template>

      <div v-else class="empty-settings text-center py-8">
        <p class="text-gray-500 mb-4">此组暂无设置项</p>
        <el-button type="primary" @click="handleAddSetting"
          >添加设置项</el-button
        >
      </div>
    </div>

    <!-- 添加/编辑设置项的抽屉 -->
    <PlusDrawerForm
      ref="storeRef"
      v-model="storeValues"
      :visible="showStore"
      :form="{
        columns: storeColumns,
        rules,
        labelPosition: 'right',
        labelSuffix: ''
      }"
      :size="drawerSize"
      :closeOnClickModal="true"
      :title="storeId > 0 ? '编辑设置项' : '新增设置项'"
      confirmText="提交"
      cancelText="取消"
      @confirm="confirmStoreForm"
      @cancel="closeStoreForm"
    />
  </div>
</template>

<style scoped lang="scss">
.setting-group {
  background-color: var(--el-bg-color);
}

:deep(.el-table__cell) {
  .cell {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }
}
</style>
