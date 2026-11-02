import type { TreeNode } from "primevue/treenode";
import { onMounted, ref } from "vue";
import { getApiClient } from "@/api/client";
import type { components } from "@/api/schema";

type CategoryNode = components["schemas"]["CategoryNode"];

function toTreeNode(category: CategoryNode): TreeNode {
  return { key: category.id, label: category.name, children: category.children.map(toTreeNode) };
}

/**
 * Loads the category tree for the filter once, on mount. A failure leaves the filter empty and sets `error`;
 * the rest of the view does not depend on it.
 */
export function useCategoryTree() {
  const nodes = ref<TreeNode[]>([]);
  const error = ref<string | null>(null);

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
    }
  });

  return { nodes, error };
}
