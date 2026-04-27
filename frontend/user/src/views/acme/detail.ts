import { isString, isEmpty } from "@pureadmin/utils";
import { useMultiTagsStoreHook } from "@/store/modules/multiTags";
import {
  useRouter,
  useRoute,
  type LocationQueryRaw,
  type RouteParamsRaw
} from "vue-router";

export function useDetail() {
  const route = useRoute();
  const router = useRouter();
  const getParameter = isEmpty(route.params) ? route.query : route.params;

  function toDetail(
    parameter: LocationQueryRaw | RouteParamsRaw,
    model: "query" | "params"
  ) {
    Object.keys(parameter).forEach(param => {
      if (!isString(parameter[param])) {
        parameter[param] = parameter[param].toString();
      }
    });
    if (model === "query") {
      useMultiTagsStoreHook().handleTags("push", {
        path: `/acme/details/${parameter.ids}`,
        name: "AcmeDetails",
        query: parameter,
        meta: {
          title: "ACME 详情",
          dynamicLevel: 10
        }
      });
      router.push({ name: "AcmeDetails", query: parameter });
    } else if (model === "params") {
      useMultiTagsStoreHook().handleTags("push", {
        path: `/acme/details/${parameter.ids}`,
        name: "AcmeDetails",
        params: parameter,
        meta: {
          title: "ACME 详情"
        }
      });
      router.push({ name: "AcmeDetails", params: parameter });
    }
  }

  const initToDetail = (model: "query" | "params") => {
    if (getParameter) toDetail(getParameter, model);
  };

  return { toDetail, initToDetail, getParameter, router };
}
