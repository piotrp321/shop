<script setup lang="ts">
import Button from "primevue/button";
import InputText from "primevue/inputtext";
import Message from "primevue/message";
import Select from "primevue/select";
import Skeleton from "primevue/skeleton";
import Tag from "primevue/tag";
import Textarea from "primevue/textarea";
import TreeSelect from "primevue/treeselect";
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from "vue";
import { RouterLink, useRoute, useRouter } from "vue-router";
import { useCategoryTree } from "@/composables/useCategoryTree";
import { canManageProducts } from "@/products/permissions";
import ProductImages from "@/products/ProductImages.vue";
import ProductVariants from "@/products/ProductVariants.vue";
import { type ActionResult, useProductForm } from "@/products/useProductForm";
import { useAuthStore } from "@/stores/auth";

const SHORT_DESCRIPTION_LIMIT = 500;
const LEAVE_MESSAGE = "Masz niezapisane zmiany. Czy na pewno chcesz opuścić ten formularz?";

// The first message of an error or field marked invalid, in the order of the document; a disabled field cannot take focus.
const FIRST_ERROR = '.p-message-error, [aria-invalid="true"]:not(:disabled)';
const SUCCESS = ".p-message-success";
const FOCUSABLE = "input, textarea, select, button, a[href], [tabindex]";

const route = useRoute();
const router = useRouter();
// The route of an existing record carries its id: a failed or pending load must not look like a new record.
const isExisting = computed(() => typeof route.params.id === "string");
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
  axesLocked,
  errorsAt,
  errorsExactly,
  upload,
  save,
  publish,
  unpublish,
  refresh,
  moveImage,
  removeImage,
  addAxis,
  setAxis,
  removeAxis,
  moveAxis,
  addVariant,
  removeVariant,
  setValue,
} = useProductForm({
  productId: () => (typeof route.params.id === "string" ? route.params.id : null),
  // Only hides the actions: the API checks the permission. The roles come from /api/v1/admin/me.
  canManage: () => canManageProducts(auth.staff),
  onCreated: (id) => {
    void router.replace({ name: "product", params: { id } });
  },
});
const root = ref<HTMLElement | null>(null);

// The form is long and the result of an action appears far from the button: bring the first error (or the
// confirmation) into view and move the focus there. This runs after the DOM is updated, also after the route moved
// from the "new" page to the page of the created product, because the same instance stays.
async function run(action: () => Promise<ActionResult>): Promise<void> {
  const result = await action();
  if (result === null) {
    return;
  }
  await nextTick();
  const target = root.value?.querySelector<HTMLElement>(result === "success" ? SUCCESS : FIRST_ERROR);
  if (!target) {
    return;
  }
  if (!target.matches(FOCUSABLE)) {
    // A message is not focusable by itself; -1 makes it a target for the script, not a stop for the keyboard.
    target.tabIndex = -1;
  }
  target.focus({ preventScroll: true });
  target.scrollIntoView({ block: "center" });
}

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
  <div
    ref="root"
    class="product"
  >
    <div class="header">
      <h1>{{ view ? data.name || "Produkt" : isExisting ? "Produkt" : "Nowy produkt" }}</h1>
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
        :read-only="readOnly"
        :axes-locked="axesLocked"
        :errors-at="errorsAt"
        :errors-exactly="errorsExactly"
        @add-axis="addAxis"
        @set-axis="setAxis"
        @remove-axis="removeAxis"
        @move-axis="moveAxis"
        @add-variant="addVariant"
        @remove-variant="removeVariant"
        @set-value="setValue"
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
          @click="run(save)"
        />
        <Button
          v-if="view?.status === 'draft'"
          data-testid="publish"
          label="Opublikuj"
          icon="pi pi-eye"
          severity="secondary"
          :disabled="!canPublish"
          @click="run(publish)"
        />
        <Button
          v-if="view?.status === 'published'"
          data-testid="unpublish"
          label="Wycofaj z publikacji"
          icon="pi pi-eye-slash"
          severity="secondary"
          :disabled="!canUnpublish"
          @click="run(unpublish)"
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
