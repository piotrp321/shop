import { onMounted, ref } from "vue";
import { getApiClient } from "@/api/client";
import type { components } from "@/api/schema";

type CategoryNode = components["schemas"]["CategoryNode"];

/** A category of the tree; the shape of a PrimeVue `TreeNode`, so the views still on PrimeVue pass it to `TreeSelect`. */
export interface CategoryTreeNode {
  key: string;
  label: string;
  children: CategoryTreeNode[];
}

function toTreeNode(category: CategoryNode): CategoryTreeNode {
  return { key: category.id, label: category.name, children: category.children.map(toTreeNode) };
}

/**
 * Loads the category tree for the filter once, on mount. A failure leaves the filter empty and sets `error`;
 * the rest of the view does not depend on it. `loaded` turns true when the attempt is over, whatever its result: a list
 * that checks a category of its address against the tree waits for it.
 */
export function useCategoryTree() {
  const nodes = ref<CategoryTreeNode[]>([]);
  const error = ref<string | null>(null);
  const loaded = ref(false);

  onMounted(async () => {
    try {
      const result = await getApiClient().GET("/api/v1/admin/categories");
      if (result.data) {
        nodes.value = result.data.map(toTreeNode);
      } else {
        error.value = `Nie udało się pobrać kategorii (HTTP ${result.response.status}). Filtr kategorii jest niedostępny.`;
      }
    } catch {
      error.value = "Nie udało się pobrać kategorii. Filtr kategorii jest niedostępny.";
    } finally {
      loaded.value = true;
    }
  });

  return { nodes, error, loaded };
}
