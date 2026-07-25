import { h, ref, onMounted } from "vue";
import type { PlusColumn } from "plus-pro-components";
import { updateDeployToken, getDeployToken } from "@/api/setting";
import type { FormRules } from "element-plus";
import { message } from "@shared/utils";
import { ElButton } from "element-plus";
import { uuid } from "@pureadmin/utils";
import { IconifyIconOffline } from "@shared/components/ReIcon";
import IpArrayInput from "./IpArrayInput";
import { buildEndpointUrl } from "./endpointUrl";

const deployUrl = () => buildEndpointUrl("/api/deploy");

export const useDeploy = () => {
  const deployValues = ref<{
    deploy_url: string;
    token: string;
    allowed_ips: string[];
  }>({
    deploy_url: deployUrl(),
    token: "",
    allowed_ips: []
  });
  const ipArrayInputRef = ref<InstanceType<typeof IpArrayInput>>();

  const deployColumns: PlusColumn[] = [
    {
      label: "部署地址",
      prop: "deploy_url",
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
                  .writeText(deployValues.value.deploy_url)
                  .then(() => {
                    message("部署地址已复制到剪贴板", {
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
      label: "部署 Token",
      prop: "token",
      valueType: "input",
      fieldProps: {
        placeholder: "请输入部署 Token"
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
                deployValues.value.token = uuid(32);
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
                if (deployValues.value.token) {
                  navigator.clipboard
                    .writeText(deployValues.value.token)
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
    resetDeployToken();
  });

  const deployRules: FormRules = {
    token: [{ len: 32, message: "Token 必须为32个字符", trigger: "blur" }]
  };

  const handleDeployUpdate = () => {
    updateDeployToken(deployValues.value).then(() => {
      message("更新成功", {
        type: "success"
      });
    });
  };

  const resetDeployToken = () => {
    deployValues.value.token = "";
    getDeployToken().then(res => {
      deployValues.value.token = res.data?.token || "";
      deployValues.value.allowed_ips = res.data?.allowed_ips || [];
    });
  };

  return {
    deployValues,
    deployColumns,
    deployRules,
    handleDeployUpdate,
    resetDeployToken
  };
};
