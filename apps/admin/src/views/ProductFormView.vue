<script setup lang="ts">
import { Eye, EyeOff, RefreshCw } from "lucide-vue-next";
import { computed, nextTick, ref, useId } from "vue";
import { useRoute, useRouter } from "vue-router";
import { toast } from "vue-sonner";
import CategoryCombobox from "@/components/CategoryCombobox.vue";
import FormField from "@/components/FormField.vue";
import FormSection from "@/components/FormSection.vue";
import PageHeader from "@/components/PageHeader.vue";
import SaveBar from "@/components/SaveBar.vue";
import UnsavedChangesDialog from "@/components/UnsavedChangesDialog.vue";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { Skeleton } from "@/components/ui/skeleton";
import { Textarea } from "@/components/ui/textarea";
import { useCategoryTree } from "@/composables/useCategoryTree";
import { useUnsavedChangesGuard } from "@/composables/useUnsavedChangesGuard";
import { canManageProducts } from "@/products/permissions";
import ProductImages from "@/products/ProductImages.vue";
import ProductVariants from "@/products/ProductVariants.vue";
import { type ActionResult, useProductForm } from "@/products/useProductForm";
import { useAuthStore } from "@/stores/auth";

const SHORT_DESCRIPTION_LIMIT = 500;

// What a failed action moves the focus to: the first error mark or field marked invalid, in the order of the document;
// a disabled field cannot take focus.
const FIRST_ERROR = '[data-form-error], [aria-invalid="true"]:not(:disabled)';
// A field the staff member types in: a save or a publication that ends while the focus is in one leaves it there (#250 F16).
const EDITABLE_FIELD = 'input:not([type="file"]), textarea, select, [role="combobox"]';
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
  discard,
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
const header = ref<InstanceType<typeof PageHeader> | null>(null);
const status = ref<InstanceType<typeof FormSection> | null>(null);

// The form is long and the result of an action appears far from the button. A failure brings the first error into view
// and moves the focus there. A success says so in a toast and moves the focus to where the button was: the save bar goes
// away with its button, so the focus goes to the H1; publishing and withdrawing replace their button, so it goes to the
// heading of the card „Status”. Unless the staff member moved to a field while the request ran: then it stays there.
// This runs after the DOM is updated, also after the route moved from the "new" page to the page of the created product,
// because the same instance stays.
async function run(action: () => Promise<ActionResult>, after: "page" | "status"): Promise<void> {
  const result = await action();
  if (result === null) {
    return;
  }
  await nextTick();
  if (result === "success") {
    if (message.value) {
      toast.success(message.value);
    }
    const active = document.activeElement;
    if (active instanceof HTMLElement && root.value?.contains(active) && active.matches(EDITABLE_FIELD)) {
      return;
    }
    if (after === "status") {
      status.value?.focusHeading();
    } else {
      header.value?.focus();
    }
    return;
  }
  const target = root.value?.querySelector<HTMLElement>(FIRST_ERROR);
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

// The bar goes away with „Odrzuć”, so the focus goes to the H1.
async function discardChanges(): Promise<void> {
  discard();
  await nextTick();
  header.value?.focus();
}

const { open: leaveOpen, leave } = useUnsavedChangesGuard(() => dirty.value);

const { nodes: categories, error: categoriesError } = useCategoryTree();

const descriptionLength = computed(() => [...data.value.shortDescription].length);
const slugDisabled = computed(() => readOnly.value || (view.value?.slugLocked ?? false));
const taxClassName = computed(() => taxClasses.value.find((taxClass) => taxClass.code === data.value.taxClass)?.name);
const publishReasonId = useId();

function setAlt(index: number, value: string): void {
  const image = data.value.images[index];
  if (image) {
    image.alt = value;
  }
}

const title = computed(() => {
  if (loadError.value) {
    return "Nie udało się wczytać produktu";
  }
  return view.value ? data.value.name || "Produkt" : isExisting.value ? "Produkt" : "Nowy produkt";
});
const crumbs = computed(() => [{ label: "Katalog" }, { label: "Produkty", to: { name: "products" } }, { label: loadError.value ? "Produkt" : title.value }]);

const statusLabels = { draft: "Szkic", published: "Opublikowany" } as const;
</script>

<template>
  <div
    ref="root"
    class="flex flex-col gap-6"
  >
    <PageHeader
      ref="header"
      :title="title"
      :crumbs="crumbs"
    />

    <Alert
      v-if="loadError"
      data-testid="load-error"
      variant="destructive"
    >
      <AlertDescription>{{ loadError }}</AlertDescription>
    </Alert>
    <Skeleton
      v-else-if="!loaded"
      class="h-80 w-full"
    />

    <template v-else>
      <Alert
        v-if="readOnly"
        data-testid="read-only-note"
      >
        <AlertDescription>Nie masz uprawnień do zmiany produktów. Formularz jest tylko do odczytu.</AlertDescription>
      </Alert>
      <Alert
        v-if="error"
        data-testid="form-error"
        data-form-error
        variant="destructive"
      >
        <AlertDescription class="flex flex-wrap items-center gap-x-4 gap-y-2">
          <span>{{ error }}</span>
          <Button
            v-if="conflict"
            type="button"
            variant="outline"
            size="sm"
            data-testid="refresh"
            :disabled="busy"
            @click="refresh()"
          >
            <RefreshCw aria-hidden="true" />
            Odśwież
          </Button>
        </AlertDescription>
      </Alert>
      <Alert
        v-if="taxClassesError"
        data-testid="tax-classes-error"
      >
        <AlertDescription>{{ taxClassesError }}</AlertDescription>
      </Alert>
      <Alert
        v-if="categoriesError"
        data-testid="categories-error"
      >
        <AlertDescription>{{ categoriesError }}</AlertDescription>
      </Alert>

      <!-- From 1280 px two columns: the data on the left, the side column of 320 px on the right. Below that one column
           with the side cards above the data, which is the order of the source. -->
      <div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_20rem] xl:items-start">
        <div class="flex flex-col gap-6 xl:col-start-2 xl:row-start-1">
          <FormSection
            v-if="view"
            ref="status"
            title="Status"
          >
            <div class="flex flex-wrap items-center gap-2">
              <Badge
                data-testid="product-status"
                :variant="view.status === 'published' ? 'success' : 'warning'"
              >
                {{ statusLabels[view.status] }}
              </Badge>
            </div>
            <template v-if="!readOnly">
              <Button
                v-if="view.status === 'draft'"
                type="button"
                variant="outline"
                data-testid="publish"
                :disabled="!canPublish"
                :aria-describedby="dirty ? publishReasonId : undefined"
                @click="run(publish, 'status')"
              >
                <Eye aria-hidden="true" />
                Opublikuj
              </Button>
              <Button
                v-if="view.status === 'published'"
                type="button"
                variant="outline"
                data-testid="unpublish"
                :disabled="!canUnpublish"
                :aria-describedby="dirty ? publishReasonId : undefined"
                @click="run(unpublish, 'status')"
              >
                <EyeOff aria-hidden="true" />
                Wycofaj z publikacji
              </Button>
              <p
                v-if="dirty"
                :id="publishReasonId"
                data-testid="publish-reason"
                class="text-sm text-muted-foreground"
              >
                Najpierw zapisz zmiany.
              </p>
            </template>
          </FormSection>

          <FormSection title="Organizacja">
            <FormField
              control-id="product-main-category"
              :errors="errorsAt('mainCategoryId')"
              error-testid="error-mainCategoryId"
            >
              <template #default="{ describedBy, invalid }">
                <CategoryCombobox
                  id="product-main-category"
                  data-testid="main-category"
                  label="Kategoria główna"
                  placeholder="Wybierz kategorię"
                  :nodes="categories"
                  :model-value="data.mainCategoryId"
                  :disabled="readOnly || categories.length === 0"
                  :invalid="invalid"
                  :described-by="describedBy"
                  @update:model-value="data.mainCategoryId = ($event as string | null) ?? null"
                />
              </template>
            </FormField>
            <FormField
              control-id="product-additional-categories"
              :errors="errorsAt('additionalCategoryIds')"
              error-testid="error-additionalCategoryIds"
            >
              <template #default="{ describedBy, invalid }">
                <CategoryCombobox
                  id="product-additional-categories"
                  data-testid="additional-categories"
                  label="Kategorie dodatkowe"
                  placeholder="Bez dodatkowych kategorii"
                  multiple
                  :nodes="categories"
                  :model-value="data.additionalCategoryIds"
                  :disabled="readOnly || categories.length === 0"
                  :invalid="invalid"
                  :described-by="describedBy"
                  @update:model-value="data.additionalCategoryIds = ($event as string[] | null) ?? []"
                />
              </template>
            </FormField>
          </FormSection>
        </div>

        <div class="flex min-w-0 flex-col gap-6 xl:col-start-1 xl:row-start-1">
          <FormSection title="Dane produktu">
            <FormField
              control-id="product-name"
              label="Nazwa"
              :errors="errorsAt('name')"
              error-testid="error-name"
            >
              <template #default="{ describedBy, invalid }">
                <Input
                  id="product-name"
                  v-model="data.name"
                  data-testid="name"
                  maxlength="200"
                  :disabled="readOnly"
                  :aria-invalid="invalid ? 'true' : undefined"
                  :aria-describedby="describedBy"
                />
              </template>
            </FormField>

            <FormField
              control-id="product-slug"
              label="Slug"
              :errors="errorsAt('slug')"
              error-testid="error-slug"
            >
              <template #default="{ describedBy, invalid }">
                <Input
                  id="product-slug"
                  v-model="data.slug"
                  data-testid="slug"
                  maxlength="200"
                  :disabled="slugDisabled"
                  :aria-invalid="invalid ? 'true' : undefined"
                  :aria-describedby="describedBy"
                />
              </template>
              <template
                v-if="view?.slugLocked"
                #hint
              >
                <span data-testid="slug-locked-note">Slug jest zablokowany od pierwszej publikacji produktu. Zmiana adresu zepsułaby linki do niego.</span>
              </template>
              <template
                v-else-if="!readOnly"
                #hint
              >
                <span data-testid="slug-hint">pusty: wyliczony z nazwy</span>
              </template>
            </FormField>

            <FormField
              control-id="product-description"
              label="Krótki opis"
              :errors="errorsAt('shortDescription')"
              error-testid="error-shortDescription"
            >
              <template #default="{ describedBy, invalid }">
                <Textarea
                  id="product-description"
                  v-model="data.shortDescription"
                  data-testid="short-description"
                  rows="4"
                  :maxlength="SHORT_DESCRIPTION_LIMIT"
                  :disabled="readOnly"
                  :aria-invalid="invalid ? 'true' : undefined"
                  :aria-describedby="describedBy"
                />
              </template>
              <template #hint>
                <span data-testid="short-description-counter">{{ descriptionLength }}/{{ SHORT_DESCRIPTION_LIMIT }}</span>
              </template>
            </FormField>

            <FormField
              control-id="product-tax-class"
              label="Klasa podatkowa"
              :errors="errorsAt('taxClass')"
              error-testid="error-taxClass"
            >
              <template #default="{ describedBy, invalid }">
                <Select
                  :model-value="data.taxClass ?? undefined"
                  :disabled="readOnly"
                  @update:model-value="data.taxClass = String($event)"
                >
                  <SelectTrigger
                    id="product-tax-class"
                    data-testid="tax-class"
                    class="w-full"
                    :aria-invalid="invalid ? 'true' : undefined"
                    :aria-describedby="describedBy"
                  >
                    <SelectValue placeholder="Wybierz klasę">
                      {{ taxClassName ?? "Wybierz klasę" }}
                    </SelectValue>
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem
                      v-for="taxClass in taxClasses"
                      :key="taxClass.code"
                      :value="taxClass.code"
                    >
                      {{ taxClass.name }}
                    </SelectItem>
                  </SelectContent>
                </Select>
              </template>
            </FormField>
          </FormSection>

          <ProductImages
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
        </div>
      </div>

      <SaveBar
        v-if="!readOnly && (dirty || !isExisting)"
        :creating="!isExisting"
        new-label="Nowy produkt"
        :busy="busy"
        :save-disabled="!canSave"
        @save="run(save, 'page')"
        @discard="discardChanges"
        @cancel="router.push({ name: 'products' })"
      />
    </template>

    <UnsavedChangesDialog
      v-model:open="leaveOpen"
      :fallback-focus="header?.heading ?? null"
      @leave="leave"
    />
  </div>
</template>
