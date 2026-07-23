<script setup lang="ts">
import { computed, ref, watch } from "vue";
import Camera from "~icons/ep/camera";
import ImageCropUpload from "@/components/ImageCropUpload/index.vue";
import { uploadSiteImage } from "@/api/setting";
import { defaultLogoPath, message, resolveSiteLogo } from "@shared/utils";

const props = defineProps<{
  modelValue?: string;
  kind: "logo" | "logo-expanded" | "qrcode";
}>();

const emit = defineEmits<{
  "update:modelValue": [value: string];
}>();

const logoFallbackActive = ref(false);
const isLogo = computed(() => props.kind !== "qrcode");
const isExpandedLogo = computed(() => props.kind === "logo-expanded");
const imageLabel = computed(() => {
  if (isExpandedLogo.value) return "展开版 Logo";
  return isLogo.value ? "Logo" : "二维码";
});
// 普通 Logo 和二维码锁定 1:1；展开版 Logo 允许横向自由比例。
const cropConfig = computed(() =>
  isLogo.value
    ? {
        accept: "image/jpeg,image/png,image/webp,image/svg+xml",
        allowSvg: true,
        aspectRatio: isExpandedLogo.value ? 0 : 1,
        maxWidth: 200,
        maxHeight: 200,
        maxFileSize: 200 * 1024,
        title: `裁剪${imageLabel.value}`
      }
    : {
        accept: "image/jpeg,image/png,image/webp",
        allowSvg: false,
        aspectRatio: 1,
        maxWidth: 800,
        maxHeight: 800,
        maxFileSize: 1024 * 1024,
        title: "裁剪客服二维码"
      }
);

const hasUploadedImage = computed(() => {
  const extension = isLogo.value ? "(?:jpg|png|webp|svg)" : "(?:jpg|png|webp)";
  return new RegExp(
    `^/api/meta/site-image/${props.kind}-[a-f0-9]{64}\\.${extension}$`
  ).test(props.modelValue || "");
});

const fallbackLogo = defaultLogoPath("/user/");
const previewUrl = computed(() => {
  if (props.kind !== "logo") return props.modelValue;
  return logoFallbackActive.value
    ? fallbackLogo
    : resolveSiteLogo(props.modelValue, fallbackLogo);
});

watch(
  () => props.modelValue,
  () => {
    logoFallbackActive.value = false;
  }
);

const handlePreviewError = () => {
  if (props.kind === "logo" && previewUrl.value !== fallbackLogo) {
    logoFallbackActive.value = true;
  }
};

const handleUpload = async (file: File) => {
  const { data } = await uploadSiteImage(props.kind, file);
  emit("update:modelValue", data.url);
  message(`${imageLabel.value}上传成功`, {
    type: "success"
  });
};
</script>

<template>
  <div class="site-image-upload">
    <ImageCropUpload v-bind="cropConfig" :upload="handleUpload">
      <template #default="{ select, uploading }">
        <!-- 已上传：悬停图片显示遮罩替换；未上传：显示上传按钮 -->
        <div
          v-if="hasUploadedImage && previewUrl"
          class="image-slot"
          :class="{ 'is-uploading': uploading }"
          :title="`点击替换${imageLabel}`"
          @click="select"
        >
          <img
            :src="previewUrl"
            :alt="imageLabel"
            :class="isLogo ? 'logo-preview' : 'qrcode-preview'"
            @error="handlePreviewError"
          />
          <div class="edit-overlay">
            <Camera />
            <span>更换</span>
          </div>
        </div>
        <el-button
          v-else
          size="small"
          type="primary"
          plain
          :loading="uploading"
          @click="select"
        >
          上传图片
        </el-button>
      </template>
    </ImageCropUpload>
    <span class="upload-tip">
      {{ isLogo ? "JPG、PNG、WebP 或 SVG" : "JPG、PNG 或 WebP" }}，{{
        isExpandedLogo
          ? "自由比例裁剪，输出不超过 200×200、200KB（SVG 直传）"
          : kind === "logo"
            ? "1:1 裁剪，输出不超过 200×200、200KB（SVG 需为正方形）"
            : "1:1 裁剪，输出不超过 800×800、1MB"
      }}
    </span>
  </div>
</template>

<style scoped lang="scss">
.site-image-upload {
  display: flex;
  gap: 12px;
  align-items: center;

  .image-slot {
    position: relative;
    display: inline-flex;
    overflow: hidden;
    cursor: pointer;
    border: 1px solid var(--el-border-color-lighter);
    border-radius: 4px;

    &:hover {
      border-color: var(--el-color-primary);

      .edit-overlay {
        opacity: 1;
      }
    }

    &.is-uploading {
      pointer-events: none;
      opacity: 0.6;
    }

    img {
      display: block;
      max-width: 160px;
      object-fit: contain;
      border-radius: 4px;
    }

    .logo-preview {
      width: auto;
      height: 40px;
    }

    .qrcode-preview {
      width: 72px;
      height: 72px;
    }

    .edit-overlay {
      position: absolute;
      inset: 0;
      display: flex;
      flex-direction: column;
      gap: 2px;
      align-items: center;
      justify-content: center;
      color: #fff;
      background: rgb(0 0 0 / 50%);
      opacity: 0;
      transition: opacity 0.2s;

      svg {
        font-size: 18px;
      }

      span {
        font-size: 11px;
      }
    }
  }

  .upload-tip {
    font-size: 12px;
    color: var(--el-text-color-secondary);
  }
}
</style>
