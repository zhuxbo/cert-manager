import assert from "node:assert/strict";
import test from "node:test";
import {
  createAsyncGenerationGuard,
  isCurrentProductResponse,
  preloadProductSelection
} from "../src/utils/orderProductResponse.ts";

function createControlledScheduler() {
  const callbacks: Array<() => void> = [];

  return {
    schedule(callback: () => void) {
      callbacks.push(callback);
    },
    runNext() {
      const callback = callbacks.shift();
      assert.ok(callback, "应存在待执行的异步阶段");
      callback();
    },
    get pendingCount() {
      return callbacks.length;
    }
  };
}

function createDeferredProduct() {
  let resolve!: (product: { id: number; name: string }) => void;
  const promise = new Promise<{ id: number; name: string }>(resolver => {
    resolve = resolver;
  });

  return { promise, resolve };
}

test("产品响应只允许更新当前仍被选中的产品", () => {
  assert.equal(isCurrentProductResponse(12, 12), true);
  assert.equal(isCurrentProductResponse("12", 12), true);
  assert.equal(isCurrentProductResponse(12, 13), false);
  assert.equal(isCurrentProductResponse(12, null), false);
});

test("快速切换产品时拒绝后返回的旧请求", async () => {
  let currentProductId = 1;
  let acceptedCa = "";
  let resolveOldRequest!: (ca: string) => void;

  const oldRequest = new Promise<string>(resolve => {
    resolveOldRequest = resolve;
  }).then(ca => {
    if (isCurrentProductResponse(1, currentProductId)) {
      acceptedCa = ca;
    }
  });

  currentProductId = 2;
  if (isCurrentProductResponse(2, currentProductId)) {
    acceptedCa = "sectigo";
  }

  resolveOldRequest("certum");
  await oldRequest;

  assert.equal(acceptedCa, "sectigo");
});

test("路由预加载链在每个异步边界都会拒绝失效任务", async () => {
  const createHarness = (withSelect = true) => {
    const generationGuard = createAsyncGenerationGuard();
    const preloadGeneration = generationGuard.invalidate();
    const scheduler = createControlledScheduler();
    const product = createDeferredProduct();
    const options: Array<{ label: string; value: number }> = [];
    let loadCalls = 0;
    let selectedProductId = 2;
    let selectedCalls = 0;

    preloadProductSelection({
      productId: 1,
      generation: preloadGeneration,
      generationGuard,
      loadProduct: () => {
        loadCalls += 1;
        return product.promise;
      },
      schedule: scheduler.schedule,
      getProductSelect: () => (withSelect ? { options } : null),
      selectProduct: productId => {
        selectedProductId = productId;
        selectedCalls += 1;
      }
    });

    return {
      generationGuard,
      scheduler,
      product,
      options,
      get loadCalls() {
        return loadCalls;
      },
      get selectedProductId() {
        return selectedProductId;
      },
      get selectedCalls() {
        return selectedCalls;
      }
    };
  };

  const beforeStart = createHarness();
  beforeStart.generationGuard.invalidate();
  beforeStart.scheduler.runNext();
  assert.equal(beforeStart.loadCalls, 0);

  const beforeResponse = createHarness();
  beforeResponse.scheduler.runNext();
  beforeResponse.generationGuard.invalidate();
  beforeResponse.product.resolve({ id: 1, name: "旧 Certum 产品" });
  await Promise.resolve();
  assert.equal(beforeResponse.scheduler.pendingCount, 0);
  assert.deepEqual(beforeResponse.options, []);
  assert.equal(beforeResponse.selectedCalls, 0);

  const beforeOptionStage = createHarness();
  beforeOptionStage.scheduler.runNext();
  beforeOptionStage.product.resolve({ id: 1, name: "旧 Certum 产品" });
  await Promise.resolve();
  beforeOptionStage.generationGuard.invalidate();
  beforeOptionStage.scheduler.runNext();
  assert.deepEqual(beforeOptionStage.options, []);
  assert.equal(beforeOptionStage.selectedCalls, 0);

  const beforeFinalWrite = createHarness();
  beforeFinalWrite.scheduler.runNext();
  beforeFinalWrite.product.resolve({ id: 1, name: "旧 Certum 产品" });
  await Promise.resolve();
  beforeFinalWrite.scheduler.runNext();
  beforeFinalWrite.generationGuard.invalidate();
  beforeFinalWrite.scheduler.runNext();
  assert.deepEqual(beforeFinalWrite.options, [
    { label: "旧 Certum 产品", value: 1 }
  ]);
  assert.equal(beforeFinalWrite.selectedProductId, 2);
  assert.equal(beforeFinalWrite.selectedCalls, 0);

  const fallback = createHarness(false);
  fallback.scheduler.runNext();
  fallback.product.resolve({ id: 1, name: "旧 Certum 产品" });
  await Promise.resolve();
  fallback.generationGuard.invalidate();
  fallback.scheduler.runNext();
  assert.equal(fallback.selectedProductId, 2);
  assert.equal(fallback.selectedCalls, 0);
});

test("有效的路由预加载只注入并选择一次产品", async () => {
  const generationGuard = createAsyncGenerationGuard();
  const preloadGeneration = generationGuard.invalidate();
  const scheduler = createControlledScheduler();
  const product = createDeferredProduct();
  const options: Array<{ label: string; value: number }> = [];
  let selectedProductId = 0;
  let selectedCalls = 0;

  preloadProductSelection({
    productId: 1,
    generation: preloadGeneration,
    generationGuard,
    loadProduct: () => product.promise,
    schedule: scheduler.schedule,
    getProductSelect: () => ({ options }),
    selectProduct: productId => {
      selectedProductId = productId;
      selectedCalls += 1;
    }
  });

  scheduler.runNext();
  product.resolve({ id: 1, name: "Certum 产品" });
  await Promise.resolve();
  scheduler.runNext();
  scheduler.runNext();

  assert.deepEqual(options, [{ label: "Certum 产品", value: 1 }]);
  assert.equal(selectedProductId, 1);
  assert.equal(selectedCalls, 1);
  assert.equal(scheduler.pendingCount, 0);
});
