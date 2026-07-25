import { ref, onMounted, computed, h } from "vue";
import type { PlusColumn } from "plus-pro-components";
import { getCallback, updateCallback } from "@/api/setting";
import type { FormRules } from "element-plus";
import { ElButton } from "element-plus";
import { message } from "@shared/utils";
import { uuid } from "@pureadmin/utils";
import { IconifyIconOffline } from "@shared/components/ReIcon";

export const useCallback = () => {
  const callbackValues = ref<{ url: string; token: string; status: number }>({
    url: "",
    token: "",
    status: 0
  });

  const isClose = computed(() => callbackValues.value.status === 0);

  const callbackColumns: PlusColumn[] = [
    {
      label: "回调地址",
      prop: "url",
      valueType: "input",
      fieldProps: {
        placeholder: "请输入回调地址",
        get disabled() {
          return isClose.value;
        }
      }
    },
    {
      label: "回调 Token",
      prop: "token",
      valueType: "input",
      fieldProps: {
        placeholder: "请输入回调 Token",
        get disabled() {
          return isClose.value;
        }
      },
      fieldSlots: {
        suffix: () => [
          h(
            ElButton,
            {
              circle: true,
              type: "success",
              plain: true,
              link: true,
              disabled: isClose.value,
              onClick: () => {
                callbackValues.value.token = uuid(32);
              }
            },
            () => h(IconifyIconOffline, { icon: "ep/circle-plus" })
          ),
          h(
            ElButton,
            {
              circle: true,
              type: "primary",
              plain: true,
              link: true,
              disabled: isClose.value,
              onClick: () => {
                if (callbackValues.value.token) {
                  navigator.clipboard
                    .writeText(callbackValues.value.token)
                    .then(() => {
                      message("Token已复制到剪贴板", {
                        type: "success"
                      });
                    })
                    .catch(() => {
                      message("复制失败", {
                        type: "error"
                      });
                    });
                } else {
                  message("请先生成Token", {
                    type: "warning"
                  });
                }
              }
            },
            () => h(IconifyIconOffline, { icon: "ep/copy-document" })
          )
        ]
      }
    },
    {
      label: "状态",
      prop: "status",
      valueType: "switch",
      fieldProps: {
        activeValue: 1,
        inactiveValue: 0
      },
      onChange: (value: number) => {
        if (value === 0) {
          callbackValues.value.url = "";
          callbackValues.value.token = "";
          callbackValues.value.status = 0;
        }
      }
    }
  ];

  const callbackRules = computed(
    (): FormRules => ({
      url: [
        {
          required: !isClose.value,
          message: "请输入回调地址",
          trigger: "blur"
        },
        {
          pattern: /^https?:\/\/.+/,
          message: "请输入有效的URL地址（以http://或https://开头）",
          trigger: "blur"
        }
      ],
      token: [
        {
          required: !isClose.value,
          message: "请输入回调Token",
          trigger: "blur"
        },
        { min: 32, max: 128, message: "请输入32-128个字符", trigger: "blur" }
      ],
      status: [{ required: true, message: "请选择状态", trigger: "blur" }]
    })
  );

  onMounted(() => {
    resetCallback();
  });

  const handleCallbackUpdate = () => {
    updateCallback(callbackValues.value).then(() => {
      message("更新成功", {
        type: "success"
      });
    });
  };

  const resetCallback = () => {
    getCallback().then(res => {
      if (res?.data) {
        callbackValues.value.url = res.data.url;
        callbackValues.value.token = res.data.token;
        callbackValues.value.status = res.data.status;
      }
    });
  };

  return {
    callbackValues,
    callbackColumns,
    callbackRules,
    handleCallbackUpdate,
    resetCallback
  };
};
