<template>
  <div class="document-upload">
    <!-- 本地文档列表 -->
    <div
      v-if="documents.length > 0"
      style="margin: 10px 0 8px; font-size: 13px; font-weight: 500"
    >
      本地文档<span
        style="
          font-size: 12px;
          font-weight: normal;
          color: var(--el-text-color-secondary);
        "
        >（签发后 24 小时内删除）</span
      >
    </div>
    <el-table
      v-if="documents.length > 0"
      :data="documents"
      size="small"
      style="width: 100%"
    >
      <el-table-column prop="file_name" label="文件名" min-width="200">
        <template #default="{ row }">
          <el-link type="primary" @click="handlePreview(row)">
            {{ row.file_name }}
          </el-link>
        </template>
      </el-table-column>
      <el-table-column prop="type" label="类型" width="120">
        <template #default="{ row }">
          {{ documentTypes[row.type] || row.type }}
        </template>
      </el-table-column>
      <el-table-column prop="file_size" label="大小" width="80">
        <template #default="{ row }">
          {{ formatSize(row.file_size) }}
        </template>
      </el-table-column>
      <el-table-column prop="uploaded_by" label="来源" width="60">
        <template #default="{ row }">
          {{
            { user: "用户", admin: "管理", api: "API" }[
              row.uploaded_by as string
            ] || row.uploaded_by
          }}
        </template>
      </el-table-column>
      <el-table-column prop="submitted" label="状态" width="90">
        <template #default="{ row }">
          <el-tooltip
            v-if="!row.submitted && row.submit_error"
            :content="`${row.submit_error}（已尝试 ${row.submit_attempts || 0} 次）`"
            placement="top"
          >
            <el-tag type="danger" size="small">失败</el-tag>
          </el-tooltip>
          <el-tag
            v-else
            :type="row.submitted ? 'success' : 'warning'"
            size="small"
          >
            {{ row.submitted ? "已提交" : "待提交" }}
          </el-tag>
        </template>
      </el-table-column>
      <el-table-column label="操作" width="60">
        <template #default="{ row }">
          <el-button
            size="small"
            type="danger"
            link
            @click="handleDelete(row.id)"
          >
            删除
          </el-button>
        </template>
      </el-table-column>
    </el-table>

    <div style="display: flex; gap: 8px; margin-top: 8px">
      <el-button
        v-if="unsubmittedCount > 0"
        size="small"
        type="success"
        :loading="submitting"
        @click="handleSubmit"
      >
        提交 ({{ unsubmittedCount }})
      </el-button>
      <el-button size="small" type="primary" @click="showUploadDialog = true">
        上传文档
      </el-button>
    </div>

    <!-- 上传对话框 -->
    <el-dialog
      v-model="showUploadDialog"
      title="上传验证文档"
      width="560px"
      @closed="resetUploadForm"
    >
      <el-upload
        drag
        multiple
        :auto-upload="false"
        :show-file-list="false"
        :on-change="handleFileAdd"
        accept=".pdf,.jpg,.jpeg,.png,.xades"
      >
        <el-icon class="el-icon--upload"><Upload /></el-icon>
        <div class="el-upload__text">拖拽文件到此处，或<em>点击选择</em></div>
        <template #tip>
          <div class="el-upload__tip">
            支持 PDF/JPG/PNG/XADES，单个不超过 5MB
          </div>
        </template>
      </el-upload>

      <!-- 已选文件列表 -->
      <div v-if="fileList.length > 0" class="file-list">
        <div v-for="(item, idx) in fileList" :key="idx" class="file-list-item">
          <el-icon class="file-icon"><Document /></el-icon>
          <span class="file-name" :title="item.file.name">
            {{ item.file.name }}
          </span>
          <span class="file-size">{{ formatSize(item.file.size) }}</span>
          <el-select
            v-model="item.type"
            placeholder="类型"
            size="small"
            style="width: 130px"
          >
            <el-option
              v-for="(label, key) in documentTypes"
              :key="key"
              :label="label"
              :value="key"
            />
          </el-select>
          <el-button
            :icon="Close"
            size="small"
            type="danger"
            link
            @click="fileList.splice(idx, 1)"
          />
        </div>
      </div>

      <template #footer>
        <el-button @click="showUploadDialog = false">取消</el-button>
        <el-button
          type="primary"
          :loading="uploading"
          :disabled="fileList.length === 0 || !allTypesSelected"
          @click="handleUpload"
        >
          上传{{ fileList.length > 0 ? ` (${fileList.length})` : "" }}
        </el-button>
      </template>
    </el-dialog>

    <!-- 预览对话框 -->
    <el-dialog
      v-model="showPreviewDialog"
      title="文档预览"
      width="900px"
      :destroy-on-close="true"
    >
      <div style="display: flex; gap: 12px; margin-bottom: 12px">
        <el-input
          v-model="editBaseName"
          size="small"
          style="flex: 1"
          placeholder="文件名"
        >
          <template #append>{{ editExt }}</template>
        </el-input>
        <el-select
          v-model="editType"
          size="small"
          style="width: 150px"
          placeholder="类型"
        >
          <el-option
            v-for="(label, key) in documentTypes"
            :key="key"
            :label="label"
            :value="key"
          />
        </el-select>
        <el-button
          type="primary"
          size="small"
          :loading="saving"
          :disabled="!editBaseName || !editType"
          @click="handleSave"
        >
          保存
        </el-button>
        <el-button size="small" style="margin-left: 0" @click="handleDownload">
          下载
        </el-button>
      </div>

      <div
        style="
          min-height: 400px;
          overflow: hidden;
          border: 1px solid var(--el-border-color-lighter);
          border-radius: 4px;
        "
      >
        <iframe
          v-if="previewIsPdf"
          :src="previewUrl + '#navpanes=0'"
          style="width: 100%; height: 600px; border: none"
        />
        <div
          v-else-if="previewIsImage"
          style="padding: 16px; text-align: center"
        >
          <img
            :src="previewUrl"
            style="max-width: 100%; max-height: 600px"
            alt="preview"
          />
        </div>
        <div
          v-else-if="previewUrl"
          style="
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            height: 400px;
            color: var(--el-text-color-secondary);
          "
        >
          <el-icon style="margin-bottom: 12px; font-size: 48px"
            ><Document
          /></el-icon>
          <p>该类型文件不支持在线预览</p>
        </div>
      </div>
    </el-dialog>
  </div>
</template>

<script setup lang="ts">
import { ref, inject, computed, onMounted, onUnmounted, watch } from "vue";
import {
  uploadDocument,
  getDocuments,
  deleteDocument,
  submitDocuments,
  updateDocument
} from "@/api/order";
import { getToken } from "@/utils/auth";
import { getConfig } from "@/config";
import { ElMessage, ElMessageBox } from "element-plus";
import type { UploadFile } from "element-plus";
import { Upload, Close, Document } from "@element-plus/icons-vue";

const order = inject("order") as any;

const documentTypes: Record<string, string> = {
  APPLICANT: "申请人文档",
  ORGANIZATION: "企业文档",
  AUTHORIZATION: "授权文档",
  ADDITIONAL: "附加文档"
};

const documents = ref<any[]>([]);
const showUploadDialog = ref(false);
const uploading = ref(false);
const submitting = ref(false);

// 上传文件列表
const fileList = ref<Array<{ file: File; type: string }>>([]);

const allTypesSelected = computed(() =>
  fileList.value.every(item => item.type !== "")
);

const unsubmittedCount = computed(
  () => documents.value.filter(d => !d.submitted).length
);

// 预览相关
const showPreviewDialog = ref(false);
const previewDoc = ref<any>(null);
const previewUrl = ref("");
const editBaseName = ref("");
const editExt = ref("");
const editType = ref("");
const saving = ref(false);

const previewIsPdf = computed(() =>
  previewDoc.value?.file_name?.toLowerCase().endsWith(".pdf")
);
const previewIsImage = computed(() => {
  const name = previewDoc.value?.file_name?.toLowerCase() || "";
  return (
    name.endsWith(".jpg") || name.endsWith(".jpeg") || name.endsWith(".png")
  );
});

const formatSize = (bytes: number) => {
  if (bytes < 1024) return bytes + "B";
  if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + "KB";
  return (bytes / 1024 / 1024).toFixed(1) + "MB";
};

const buildDocUrl = (docId: number) => {
  const token = getToken()?.access_token || "";
  const base = getConfig()?.BaseUrlApi || "";
  return `${base}/order/document-preview/${docId}?token=${encodeURIComponent(token)}&_t=${Date.now()}`;
};

const handlePreview = async (row: any) => {
  await loadDocuments();
  previewDoc.value = row;
  previewUrl.value = buildDocUrl(row.id);
  const dotIdx = row.file_name.lastIndexOf(".");
  if (dotIdx > 0) {
    editBaseName.value = row.file_name.substring(0, dotIdx);
    editExt.value = row.file_name.substring(dotIdx);
  } else {
    editBaseName.value = row.file_name;
    editExt.value = "";
  }
  editType.value = row.type;
  showPreviewDialog.value = true;
};

const handleSave = async () => {
  if (!previewDoc.value) return;
  const fileName = editBaseName.value + editExt.value;
  if (!fileName.trim()) {
    ElMessage.warning("文件名不能为空");
    return;
  }
  saving.value = true;
  try {
    const res = await updateDocument(previewDoc.value.id, {
      file_name: fileName,
      type: editType.value
    });
    if (res.code === 1) {
      ElMessage.success("保存成功");
      showPreviewDialog.value = false;
      await loadDocuments();
    }
  } finally {
    saving.value = false;
  }
};

const handleDownload = () => {
  if (!previewDoc.value) return;
  const url = buildDocUrl(previewDoc.value.id);
  const a = document.createElement("a");
  a.href = url;
  a.download = previewDoc.value.file_name;
  a.click();
};

const loadDocuments = async () => {
  const res = await getDocuments(order.id);
  if (res.code === 1) {
    documents.value = res.data || [];
  }
};

const handleFileAdd = (uploadFile: UploadFile) => {
  if (uploadFile.raw) {
    fileList.value.push({ file: uploadFile.raw, type: "" });
  }
};

const resetUploadForm = () => {
  fileList.value = [];
};

const handleUpload = async () => {
  if (fileList.value.length === 0) return;

  uploading.value = true;
  try {
    for (const item of fileList.value) {
      const formData = new FormData();
      formData.append("file", item.file);
      formData.append("type", item.type);
      await uploadDocument(order.id, formData);
    }
    ElMessage.success("上传成功，正在自动提交上游");
    showUploadDialog.value = false;
    await loadDocuments();
    startPolling();
  } finally {
    uploading.value = false;
  }
};

const handleDelete = async (docId: number) => {
  await ElMessageBox.confirm("确定删除此文档？", "提示", { type: "warning" });
  const res = await deleteDocument(docId);
  if (res.code === 1) {
    ElMessage.success("删除成功");
    await loadDocuments();
  }
};

// 异步入队后轮询刷新状态（上传自动转发 / 手动兜底提交 共用）
let pollTimer: ReturnType<typeof setInterval> | null = null;
const stopPolling = () => {
  if (pollTimer) {
    clearInterval(pollTimer);
    pollTimer = null;
  }
};
// 轮询刷新，直到没有"待提交且未失败"的文档或达到上限
const startPolling = () => {
  stopPolling();
  let ticks = 0;
  pollTimer = setInterval(async () => {
    ticks += 1;
    await loadDocuments();
    const pending = documents.value.filter(
      d => !d.submitted && !d.submit_error
    ).length;
    if (pending === 0 || ticks >= 8) stopPolling();
  }, 2500);
};

// 兜底：自动转发失败 / 漏提交时手动重试未提交的文档
const handleSubmit = async () => {
  await ElMessageBox.confirm("确定提交所有未成功的文档到上游？", "提示", {
    type: "warning"
  });
  submitting.value = true;
  try {
    const res = await submitDocuments(order.id);
    if (res.code === 1) {
      ElMessage.success(
        `已加入提交队列（${res.data?.queued ?? 0} 个），正在处理`
      );
      await loadDocuments();
      startPolling();
    }
  } finally {
    submitting.value = false;
  }
};

onMounted(loadDocuments);
// 订单刷新（刷新按钮 / 同步 / 自动刷新）时，同步重载本地文档列表
watch(
  () => order.sync,
  () => loadDocuments()
);
onUnmounted(stopPolling);
</script>

<style scoped lang="scss">
.document-upload {
  margin: 10px 0;
}

.file-list {
  max-height: 240px;
  margin-top: 12px;
  overflow-y: auto;
  border: 1px solid var(--el-border-color-lighter);
  border-radius: 4px;
}

.file-list-item {
  display: flex;
  gap: 8px;
  align-items: center;
  padding: 6px 10px;
  border-bottom: 1px solid var(--el-border-color-lighter);

  &:last-child {
    border-bottom: none;
  }
}

.file-icon {
  flex-shrink: 0;
  color: var(--el-text-color-secondary);
}

.file-name {
  flex: 1;
  overflow: hidden;
  text-overflow: ellipsis;
  font-size: 13px;
  white-space: nowrap;
}

.file-size {
  flex-shrink: 0;
  width: 60px;
  font-size: 12px;
  color: var(--el-text-color-secondary);
  text-align: right;
}
</style>
