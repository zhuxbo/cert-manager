export function isCurrentProductResponse(
  requestedProductId: unknown,
  currentProductId: unknown
): boolean {
  const requestedId = Number(requestedProductId);
  const currentId = Number(currentProductId);

  return (
    Number.isSafeInteger(requestedId) &&
    requestedId > 0 &&
    requestedId === currentId
  );
}

export function createAsyncGenerationGuard() {
  let generation = 0;

  return {
    invalidate(): number {
      generation += 1;
      return generation;
    },
    isCurrent(candidate: number): boolean {
      return candidate === generation;
    }
  };
}

interface ProductSelectionTarget<ProductId> {
  options?: Array<{
    label: string;
    value: ProductId;
  }>;
}

interface PreloadProductSelectionOptions<ProductId> {
  productId: ProductId;
  generation: number;
  generationGuard: ReturnType<typeof createAsyncGenerationGuard>;
  loadProduct: () => Promise<{
    id: ProductId;
    name: string;
  }>;
  schedule?: (callback: () => void, delay: number) => void;
  getProductSelect: () => ProductSelectionTarget<ProductId> | null | undefined;
  selectProduct: (productId: ProductId) => void;
}

export function preloadProductSelection<ProductId>({
  productId,
  generation,
  generationGuard,
  loadProduct,
  schedule = (callback, delay) => {
    setTimeout(callback, delay);
  },
  getProductSelect,
  selectProduct
}: PreloadProductSelectionOptions<ProductId>): void {
  schedule(() => {
    if (!generationGuard.isCurrent(generation)) return;

    loadProduct().then(product => {
      if (!generationGuard.isCurrent(generation)) return;

      schedule(() => {
        if (!generationGuard.isCurrent(generation)) return;

        const productSelect = getProductSelect();
        if (!productSelect) {
          selectProduct(productId);
          return;
        }

        productSelect.options ??= [];
        const exists = productSelect.options.some(
          option => option.value === product.id
        );
        if (!exists) {
          productSelect.options.push({
            label: product.name,
            value: product.id
          });
        }

        schedule(() => {
          if (!generationGuard.isCurrent(generation)) return;

          selectProduct(productId);
        }, 100);
      }, 200);
    });
  }, 100);
}
