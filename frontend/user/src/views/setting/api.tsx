import { h, ref, onMounted } from "vue";
import type { PlusColumn } from "plus-pro-components";
import { updateApiToken, getApiToken } from "@/api/setting";
import type { FormRules } from "element-plus";
import { message } from "@shared/utils";
import { ElButton } from "element-plus";
import { uuid } from "@pureadmin/utils";
import { IconifyIconOffline } from "@shared/components/ReIcon";
import IpArrayInput from "./IpArrayInput";
import { buildEndpointUrl } from "./endpointUrl";

const apiUrl = () => buildEndpointUrl("/api/v2");

export const useApi = () => {
  const apiValues = ref<{
    api_url: string;
    token: string;
    allowed_ips: string[];
  }>({
    api_url: apiUrl(),
    token: "",
    allowed_ips: []
  });
  const ipArrayInputRef = ref<InstanceType<typeof IpArrayInput>>();

  const apiColumns: PlusColumn[] = [
    {
      label: "API地址",
      prop: "api_url",
      valueType: "input",
      fieldProps: {
        readonly: true
      },
      fieldSlots: {
        suffix: () =>
          h(
            ElButton,
            {
              circle: true,
              type: "primary",
              plain: true,
              link: true,
              onClick: () => {
                navigator.clipboard
                  .writeText(apiValues.value.api_url)
                  .then(() => {
                    message("API地址已复制到剪贴板", {
                      type: "success"
                    });
                  })
                  .catch(() => {
                    message("复制失败", {
                      type: "error"
                    });
                  });
              }
            },
            () => h(IconifyIconOffline, { icon: "ep/copy-document" })
          )
      }
    },
    {
      label: "API Token",
      prop: "token",
      valueType: "input",
      fieldProps: {
        placeholder: "请输入API Token"
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
              onClick: () => {
                apiValues.value.token = uuid(64);
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
              onClick: () => {
                if (apiValues.value.token) {
                  navigator.clipboard
                    .writeText(apiValues.value.token)
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
      label: "IP白名单",
      prop: "allowed_ips",
      renderField: (value, onChange) => {
        const arrayValue = Array.isArray(value) ? value : value ? [value] : [];
        return (
          <IpArrayInput
            ref={ipArrayInputRef}
            modelValue={arrayValue}
            onUpdate:modelValue={onChange}
            maxItems={10}
          />
        );
      }
    }
  ];

  onMounted(() => {
    resetApiToken();
  });

  const apiRules: FormRules = {
    token: [
      { min: 32, max: 128, message: "请输入32-128个字符", trigger: "blur" }
    ]
  };

  const handleApiUpdate = () => {
    updateApiToken(apiValues.value).then(() => {
      message("更新成功", {
        type: "success"
      });
    });
  };

  const resetApiToken = () => {
    apiValues.value.token = "";
    getApiToken().then(res => {
      apiValues.value.allowed_ips = res.data?.allowed_ips || [];
    });
  };

  return {
    apiValues,
    apiColumns,
    apiRules,
    handleApiUpdate,
    resetApiToken
  };
};
