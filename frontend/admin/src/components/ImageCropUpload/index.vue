<script setup lang="ts">
import { nextTick, ref } from "vue";
import { ElButton, ElDialog } from "element-plus";
import { VueCropper } from "vue-cropper";
import "vue-cropper/dist/index.css";
import { message } from "@shared/utils";

/**
 * 通用图片裁剪上传组件：选图 → 弹窗裁剪（可锁定比例）→ 输出压到最大尺寸内 → 交给 upload 回调。
 * SVG（矢量，无像素裁剪意义）在 allowSvg 时跳过裁剪直接上传。
 * directUpload 时位图也跳过裁剪，保留原始构图，仅超限时等比缩小。
 */
const props = withDefaults(
  defineProps<{
    /** input accept 的 MIME 列表 */
    accept: string;
    /** 允许 SVG 直传（不裁剪） */
    allowSvg?: boolean;
    /** 位图免裁剪直传（仍按 maxWidth/maxHeight 等比缩小、校验体积上限） */
    directUpload?: boolean;
    /** 裁剪比例（宽/高），0 表示自由比例 */
    aspectRatio?: number;
    /** 输出最大宽/高（超出等比缩小） */
    maxWidth: number;
    maxHeight: number;
    /** 上传后文件大小上限（字节） */
    maxFileSize: number;
    /** 弹窗标题 */
    title?: string;
    /** 按钮文案 */
    buttonText?: string;
    /** 上传实现，由调用方提供 */
    upload: (file: File) => Promise<void>;
  }>(),
  {
    allowSvg: false,
    directUpload: false,
    aspectRatio: 0,
    title: "裁剪图片",
    buttonText: "上传图片"
  }
);

const uploading = ref(false);
const cropVisible = ref(false);
const cropSource = ref("");
const cropperRef = ref();
const fileInputRef = ref<HTMLInputElement>();
// 裁剪输出格式跟随源图（png 保透明，jpeg/webp 保体积）
const outputType = ref<"png" | "jpeg" | "webp">("png");
const outputName = ref("image.png");

const selectFile = () => {
  if (uploading.value) return;
  fileInputRef.value?.click();
};

const handleFileChange = async (event: Event) => {
  const input = event.target as HTMLInputElement;
  const file = input.files?.[0];
  input.value = "";
  if (!file) return;

  if (file.type === "image/svg+xml") {
    if (!props.allowSvg) {
      message("不支持 SVG 格式", { type: "warning" });
      return;
    }
    await doUpload(file);
    return;
  }

  if (!["image/jpeg", "image/png", "image/webp"].includes(file.type)) {
    message("不支持的图片格式", { type: "warning" });
    return;
  }

  outputType.value =
    file.type === "image/png"
      ? "png"
      : file.type === "image/webp"
        ? "webp"
        : "jpeg";
  outputName.value = `crop.${outputType.value === "jpeg" ? "jpg" : outputType.value}`;

  if (props.directUpload) {
    uploading.value = true;
    try {
      const blob = await fitToMaxSize(file);
      if (blob.size > props.maxFileSize) {
        message(
          `图片超过 ${Math.round(props.maxFileSize / 1024)}KB，请压缩后重试`,
          { type: "warning" }
        );
        return;
      }
      await doUpload(
        blob === file
          ? file
          : new File([blob], outputName.value, {
              type: `image/${outputType.value}`
            })
      );
    } catch (error) {
      message(error instanceof Error ? error.message : "图片处理失败，请重试", {
        type: "error"
      });
    } finally {
      uploading.value = false;
    }
    return;
  }

  cropSource.value = await readAsDataUrl(file);
  cropVisible.value = true;
  await nextTick();
};

const readAsDataUrl = (file: File): Promise<string> =>
  new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onload = () => resolve(reader.result as string);
    reader.onerror = () => reject(reader.error);
    reader.readAsDataURL(file);
  });

const getCropBlob = (): Promise<Blob> =>
  new Promise((resolve, reject) => {
    cropperRef.value?.getCropBlob((blob: Blob | null) => {
      blob ? resolve(blob) : reject(new Error("裁剪失败"));
    });
  });

/** 裁剪结果超出最大尺寸时等比缩小重绘 */
const fitToMaxSize = async (blob: Blob): Promise<Blob> => {
  const bitmap = await createImageBitmap(blob);
  const scale = Math.min(
    props.maxWidth / bitmap.width,
    props.maxHeight / bitmap.height,
    1
  );
  if (scale === 1) return blob;

  const canvas = document.createElement("canvas");
  canvas.width = Math.max(1, Math.round(bitmap.width * scale));
  canvas.height = Math.max(1, Math.round(bitmap.height * scale));
  const context = canvas.getContext("2d");
  if (!context) return blob;
  context.drawImage(bitmap, 0, 0, canvas.width, canvas.height);

  return new Promise((resolve, reject) => {
    canvas.toBlob(
      result => (result ? resolve(result) : reject(new Error("图片压缩失败"))),
      `image/${outputType.value}`,
      0.92
    );
  });
};

const confirmCrop = async () => {
  uploading.value = true;
  try {
    const blob = await fitToMaxSize(await getCropBlob());
    if (blob.size > props.maxFileSize) {
      message(
        `图片超过 ${Math.round(props.maxFileSize / 1024)}KB，请缩小裁剪范围`,
        {
          type: "warning"
        }
      );
      return;
    }
    await doUpload(
      new File([blob], outputName.value, { type: `image/${outputType.value}` })
    );
    cropVisible.value = false;
  } catch (error) {
    // 裁剪/压缩/上传任一失败都给出可见提示，不让弹窗静默停在原地
    message(error instanceof Error ? error.message : "图片处理失败，请重试", {
      type: "error"
    });
  } finally {
    uploading.value = false;
  }
};

const doUpload = async (file: File) => {
  uploading.value = true;
  try {
    await props.upload(file);
  } finally {
    uploading.value = false;
  }
};
</script>

<template>
  <div class="image-crop-upload">
    <input
      ref="fileInputRef"
      type="file"
      class="hidden"
      :accept="accept"
      @change="handleFileChange"
    />
    <!-- 默认渲染上传按钮；调用方可用具名插槽自定义触发器（如点击图片替换），select 触发选图 -->
    <slot :select="selectFile" :uploading="uploading">
      <el-button type="primary" plain :loading="uploading" @click="selectFile">
        {{ buttonText }}
      </el-button>
    </slot>

    <el-dialog
      v-model="cropVisible"
      :title="title"
      width="640px"
      append-to-body
      destroy-on-close
    >
      <div class="cropper-box">
        <VueCropper
          ref="cropperRef"
          :img="cropSource"
          :auto-crop="true"
          :fixed="aspectRatio > 0"
          :fixed-number="aspectRatio > 0 ? [aspectRatio, 1] : [1, 1]"
          :center-box="true"
          :output-type="outputType"
          :auto-crop-width="maxWidth"
          :auto-crop-height="maxHeight"
        />
      </div>
      <template #footer>
        <el-button @click="cropVisible = false">取消</el-button>
        <el-button type="primary" :loading="uploading" @click="confirmCrop">
          确认上传
        </el-button>
      </template>
    </el-dialog>
  </div>
</template>

<style scoped lang="scss">
.image-crop-upload {
  display: inline-flex;

  .hidden {
    display: none;
  }
}

.cropper-box {
  width: 100%;
  height: 420px;
}
</style>
