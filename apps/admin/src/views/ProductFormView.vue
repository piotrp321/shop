<script setup lang="ts">
import Button from "primevue/button";
import InputText from "primevue/inputtext";
import Message from "primevue/message";
import Select from "primevue/select";
import Skeleton from "primevue/skeleton";
import Tag from "primevue/tag";
import Textarea from "primevue/textarea";
import TreeSelect from "primevue/treeselect";
import { computed, onBeforeUnmount, onMounted } from "vue";
import { RouterLink, useRoute, useRouter } from "vue-router";
import { useCategoryTree } from "@/composables/useCategoryTree";
import { canManageProducts } from "@/products/permissions";
import ProductImages from "@/products/ProductImages.vue";
import ProductVariants from "@/products/ProductVariants.vue";
import { useProductForm } from "@/products/useProductForm";
import { useAuthStore } from "@/stores/auth";

const SHORT_DESCRIPTION_LIMIT = 500;
const LEAVE_MESSAGE = "Masz niezapisane zmiany. Czy na pewno chcesz opuścić ten formularz?";

const route = useRoute();
const router = useRouter();
const auth = useAuthStore();

const {
  form: data,
  view,
  taxClasses,
  taxClassesError,
  loaded,
  loadError,
  busy,
  dirty,
  readOnly,
  canSave,
  canPublish,
  canUnpublish,
  message,
  error,
  conflict,
  imagesErrors,
  variantsErrors,
  errorsAt,
  upload,
  save,
  publish,
  unpublish,
  refresh,
  moveImage,
  removeImage,
} = useProductForm({
  productId: () => (typeof route.params.id === "string" ? route.params.id : null),
  // Only hides the actions: the API checks the permission. The roles come from /api/v1/admin/me.
  canManage: () => canManageProducts(auth.staff),
  onCreated: (id) => {
    void router.replace({ name: "product", params: { id } });
  },
});
const { nodes: categories, error: categoriesError } = useCategoryTree();

// TreeSelect holds the chosen nodes as { [key]: true }: one for the main category, any number for the additional ones.
const mainSelection = computed({
  get: () => (data.value.mainCategoryId === null ? null : { [data.value.mainCategoryId]: true }),
  set: (value: Record<string, boolean> | null) => {
    data.value.mainCategoryId = value ? (Object.keys(value)[0] ?? null) : null;
  },
});
const additionalSelection = computed({
  get: () => Object.fromEntries(data.value.additionalCategoryIds.map((id) => [id, true])),
  set: (value: Record<string, boolean> | null) => {
    data.value.additionalCategoryIds = Object.keys(value ?? {});
  },
});

const descriptionLength = computed(() => [...data.value.shortDescription].length);
const slugDisabled = computed(() => readOnly.value || (view.value?.slugLocked ?? false));

function setAlt(index: number, value: string): void {
  const image = data.value.images[index];
  if (image) {
    image.alt = value;
  }
}

// Leaving with unsaved changes asks first, whether the staff member goes to another page of the panel or closes the tab.
// A global guard rather than onBeforeRouteLeave: after a product is created the same instance moves from the "new"
// route to the route of the product, and a leave guard stays with the record it was registered for.
const removeGuard = router.beforeEach(() => !dirty.value || window.confirm(LEAVE_MESSAGE));

function warnBeforeUnload(event: BeforeUnloadEvent): void {
  // The sign-out (e.g. the idle one) leaves the page by itself; holding it back would keep the session alive.
  if (dirty.value && !auth.signingOut) {
    event.preventDefault();
    event.returnValue = "";
  }
}

onMounted(() => window.addEventListener("beforeunload", warnBeforeUnload));
onBeforeUnmount(() => {
  removeGuard();
  window.removeEventListener("beforeunload", warnBeforeUnload);
});

const statusLabels = { draft: "Szkic", published: "Opublikowany" } as const;
</script>

<template>
  <div class="product">
    <div class="header">
      <h1>{{ view ? data.name || "Produkt" : "Nowy produkt" }}</h1>
      <Tag
        v-if="view"
        data-testid="product-status"
        :severity="view.status === 'published' ? 'success' : 'secondary'"
        :value="statusLabels[view.status]"
      />
      <RouterLink
        class="back"
        :to="{ name: 'products' }"
      >
        Wróć do listy
      </RouterLink>
    </div>

    <Message
      v-if="loadError"
      data-testid="load-error"
      severity="error"
    >
      {{ loadError }}
    </Message>
    <Skeleton
      v-else-if="!loaded"
      height="20rem"
    />

    <template v-else>
      <Message
        v-if="readOnly"
        data-testid="read-only-note"
        severity="info"
      >
        Nie masz uprawnień do zmiany produktów. Formularz jest tylko do odczytu.
      </Message>
      <Message
        v-if="message"
        data-testid="success-message"
        severity="success"
      >
        {{ message }}
      </Message>
      <Message
        v-if="error"
        data-testid="form-error"
        severity="error"
      >
        {{ error }}
        <Button
          v-if="conflict"
          data-testid="refresh"
          label="Odśwież"
          icon="pi pi-refresh"
          severity="secondary"
          size="small"
          :disabled="busy"
          @click="refresh()"
        />
      </Message>
      <Message
        v-if="taxClassesError"
        data-testid="tax-classes-error"
        severity="warn"
      >
        {{ taxClassesError }}
      </Message>
      <Message
        v-if="categoriesError"
        data-testid="categories-error"
        severity="warn"
      >
        {{ categoriesError }}
      </Message>

      <section
        class="section"
        aria-labelledby="data-heading"
      >
        <h2 id="data-heading">
          Dane
        </h2>

        <div class="field">
          <label for="product-name">Nazwa</label>
          <InputText
            id="product-name"
            v-model="data.name"
            data-testid="name"
            maxlength="200"
            :disabled="readOnly"
            :invalid="errorsAt('name').length > 0"
          />
          <Message
            v-for="title in errorsAt('name')"
            :key="title"
            data-testid="error-name"
            severity="error"
            size="small"
            variant="simple"
          >
            {{ title }}
          </Message>
        </div>

        <div class="field">
          <label for="product-slug">Slug</label>
          <InputText
            id="product-slug"
            v-model="data.slug"
            data-testid="slug"
            maxlength="200"
            :disabled="slugDisabled"
            :invalid="errorsAt('slug').length > 0"
          />
          <small
            v-if="view?.slugLocked"
            data-testid="slug-locked-note"
            class="hint"
          >
            Slug jest zablokowany od pierwszej publikacji produktu. Zmiana adresu zepsułaby linki do niego.
          </small>
          <small
            v-else-if="!readOnly"
            data-testid="slug-hint"
            class="hint"
          >pusty: wyliczony z nazwy</small>
          <Message
            v-for="title in errorsAt('slug')"
            :key="title"
            data-testid="error-slug"
            severity="error"
            size="small"
            variant="simple"
          >
            {{ title }}
          </Message>
        </div>

        <div class="field">
          <label for="product-description">Krótki opis</label>
          <Textarea
            id="product-description"
            v-model="data.shortDescription"
            data-testid="short-description"
            rows="4"
            :maxlength="SHORT_DESCRIPTION_LIMIT"
            :disabled="readOnly"
            :invalid="errorsAt('shortDescription').length > 0"
          />
          <small
            data-testid="short-description-counter"
            class="hint"
          >{{ descriptionLength }}/{{ SHORT_DESCRIPTION_LIMIT }}</small>
          <Message
            v-for="title in errorsAt('shortDescription')"
            :key="title"
            data-testid="error-shortDescription"
            severity="error"
            size="small"
            variant="simple"
          >
            {{ title }}
          </Message>
        </div>

        <div class="field">
          <label>Kategoria główna</label>
          <TreeSelect
            v-model="mainSelection"
            data-testid="main-category"
            :options="categories"
            selection-mode="single"
            placeholder="Wybierz kategorię"
            filter
            :disabled="readOnly || categories.length === 0"
            :invalid="errorsAt('mainCategoryId').length > 0"
            aria-label="Kategoria główna"
          />
          <Message
            v-for="title in errorsAt('mainCategoryId')"
            :key="title"
            data-testid="error-mainCategoryId"
            severity="error"
            size="small"
            variant="simple"
          >
            {{ title }}
          </Message>
        </div>

        <div class="field">
          <label>Kategorie dodatkowe</label>
          <TreeSelect
            v-model="additionalSelection"
            data-testid="additional-categories"
            :options="categories"
            selection-mode="multiple"
            placeholder="Bez dodatkowych kategorii"
            filter
            :disabled="readOnly || categories.length === 0"
            :invalid="errorsAt('additionalCategoryIds').length > 0"
            aria-label="Kategorie dodatkowe"
          />
          <Message
            v-for="title in errorsAt('additionalCategoryIds')"
            :key="title"
            data-testid="error-additionalCategoryIds"
            severity="error"
            size="small"
            variant="simple"
          >
            {{ title }}
          </Message>
        </div>

        <div class="field">
          <label for="product-tax-class">Klasa podatkowa</label>
          <Select
            v-model="data.taxClass"
            input-id="product-tax-class"
            data-testid="tax-class"
            :options="taxClasses"
            option-label="name"
            option-value="code"
            placeholder="Wybierz klasę"
            :disabled="readOnly"
            :invalid="errorsAt('taxClass').length > 0"
          />
          <Message
            v-for="title in errorsAt('taxClass')"
            :key="title"
            data-testid="error-taxClass"
            severity="error"
            size="small"
            variant="simple"
          >
            {{ title }}
          </Message>
        </div>
      </section>

      <ProductImages
        class="section"
        :images="data.images"
        :entries="upload.entries.value"
        :can-add-more="upload.canAddMore.value"
        :read-only="readOnly"
        :errors-at="errorsAt"
        :errors="imagesErrors"
        @add="upload.addFiles($event)"
        @retry="upload.retry($event)"
        @dismiss="upload.dismiss($event)"
        @move="moveImage"
        @remove="removeImage"
        @alt="setAlt"
      />

      <ProductVariants
        class="section"
        :axes="data.variantAxes"
        :rows="data.variants"
        :errors="variantsErrors"
      />

      <div
        v-if="!readOnly"
        class="actions"
      >
        <Button
          data-testid="save"
          label="Zapisz"
          icon="pi pi-check"
          :loading="busy"
          :disabled="!canSave"
          @click="save()"
        />
        <Button
          v-if="view?.status === 'draft'"
          data-testid="publish"
          label="Opublikuj"
          icon="pi pi-eye"
          severity="secondary"
          :disabled="!canPublish"
          @click="publish()"
        />
        <Button
          v-if="view?.status === 'published'"
          data-testid="unpublish"
          label="Wycofaj z publikacji"
          icon="pi pi-eye-slash"
          severity="secondary"
          :disabled="!canUnpublish"
          @click="unpublish()"
        />
        <small
          v-if="dirty && view"
          class="hint"
        >Zapisz zmiany, aby opublikować albo wycofać produkt.</small>
      </div>
    </template>
  </div>
</template>

<style scoped>
.product {
  display: flex;
  flex-direction: column;
  gap: 1rem;
  max-width: 56rem;
}

.header {
  display: flex;
  align-items: center;
  gap: 1rem;
}

.back {
  margin-left: auto;
}

.section {
  display: flex;
  flex-direction: column;
  gap: 1rem;
  padding: 1rem;
  border: 1px solid var(--p-content-border-color);
  border-radius: var(--p-content-border-radius);
  background: var(--p-content-background);
}

.field {
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
}

.hint {
  color: var(--p-text-muted-color);
}

.actions {
  display: flex;
  align-items: center;
  gap: 0.75rem;
}
</style>
