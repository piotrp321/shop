<script setup lang="ts">
import Button from "primevue/button";
import Column from "primevue/column";
import DataTable from "primevue/datatable";
import InputText from "primevue/inputtext";
import Message from "primevue/message";
import Skeleton from "primevue/skeleton";
import Tag from "primevue/tag";
import ToggleSwitch from "primevue/toggleswitch";
import { computed, ref } from "vue";
import { RouterLink, useRoute, useRouter } from "vue-router";
import { formatMoney } from "@/prices/format";
import { CURRENCY, statusLabel, statusSeverity } from "@/promotions/format";
import { canManagePromotions } from "@/promotions/permissions";
import PromotionVariantPicker from "@/promotions/PromotionVariantPicker.vue";
import { usePromotionForm } from "@/promotions/usePromotionForm";
import { useAuthStore } from "@/stores/auth";

const route = useRoute();
const router = useRouter();
const auth = useAuthStore();

const {
  form: data,
  view,
  loaded,
  loadError,
  busy,
  status,
  readOnly,
  scheduleLocked,
  canSave,
  canEndNow,
  canDelete,
  message,
  error,
  errorsAt,
  isConflicting,
  save,
  endNow,
  remove,
  addVariants,
  removeVariant,
} = usePromotionForm({
  promotionId: () => (typeof route.params.id === "string" ? route.params.id : null),
  // Only hides the actions: the API checks the permission. The roles come from /api/v1/admin/me.
  canManage: () => canManagePromotions(auth.staff),
  onCreated: (id) => {
    void router.replace({ name: "promotion", params: { id } });
  },
  onDeleted: () => {
    void router.push({ name: "promotions" });
  },
});

// Ending and deleting cannot be undone, so they ask first.
const confirming = ref<"end" | "delete" | null>(null);
const confirmText = computed(() => (confirming.value === "end" ? "Zakończyć promocję teraz?" : "Usunąć promocję?"));

async function confirmAction(): Promise<void> {
  const action = confirming.value;
  confirming.value = null;
  if (action === "end") {
    await endNow();
  } else if (action === "delete") {
    await remove();
  }
}

const pickerOpen = ref(false);
const variantsLocked = computed(() => readOnly.value || scheduleLocked.value);
const chosenIds = computed(() => data.value.variants.map((variant) => variant.variantId));
const startDisabled = computed(() => variantsLocked.value || data.value.startNow);
</script>

<template>
  <div class="promotion">
    <div class="header">
      <h1>{{ view ? data.name || "Promocja" : "Nowa promocja" }}</h1>
      <Tag
        v-if="view"
        data-testid="promotion-status"
        :severity="statusSeverity(view.status)"
        :value="statusLabel(view.status)"
      />
      <RouterLink
        class="back"
        :to="{ name: 'promotions' }"
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
        v-if="!canManagePromotions(auth.staff)"
        data-testid="read-only-note"
        severity="info"
      >
        Nie masz uprawnień do zmiany promocji. Widok jest tylko do odczytu.
      </Message>
      <Message
        v-else-if="status === 'ended'"
        data-testid="read-only-note"
        severity="info"
      >
        Zakończonej promocji nie można zmienić. Widok jest tylko do odczytu.
      </Message>
      <Message
        v-else-if="scheduleLocked"
        data-testid="schedule-locked-note"
        severity="info"
      >
        Promocja trwa. Możesz zmienić jej nazwę i koniec; procent, początek i warianty są zablokowane.
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
      </Message>

      <form
        class="fields"
        novalidate
        @submit.prevent="save()"
      >
        <div class="field">
          <label for="promotion-name">Nazwa</label>
          <InputText
            id="promotion-name"
            v-model="data.name"
            data-testid="name-input"
            maxlength="100"
            autocomplete="off"
            :disabled="readOnly"
            :invalid="errorsAt('name').length > 0"
            :aria-invalid="errorsAt('name').length > 0"
          />
          <Message
            v-for="text in errorsAt('name')"
            :key="text"
            data-testid="name-error"
            severity="error"
            size="small"
            variant="simple"
          >
            {{ text }}
          </Message>
        </div>

        <div class="field">
          <label for="promotion-percent">Obniżka (%)</label>
          <InputText
            id="promotion-percent"
            v-model="data.percentOff"
            data-testid="percent-input"
            inputmode="numeric"
            autocomplete="off"
            :disabled="variantsLocked"
            :invalid="errorsAt('percentOff').length > 0"
            :aria-invalid="errorsAt('percentOff').length > 0"
          />
          <Message
            v-for="text in errorsAt('percentOff')"
            :key="text"
            data-testid="percent-error"
            severity="error"
            size="small"
            variant="simple"
          >
            {{ text }}
          </Message>
        </div>

        <div class="field">
          <label for="promotion-starts">Początek</label>
          <div class="start">
            <InputText
              id="promotion-starts"
              v-model="data.startsAt"
              data-testid="starts-input"
              type="datetime-local"
              :disabled="startDisabled"
              :invalid="errorsAt('startsAt').length > 0"
              :aria-invalid="errorsAt('startsAt').length > 0"
            />
            <label class="switch">
              <ToggleSwitch
                v-model="data.startNow"
                data-testid="start-now"
                :disabled="variantsLocked"
              />
              Od teraz
            </label>
          </div>
          <Message
            v-for="text in errorsAt('startsAt')"
            :key="text"
            data-testid="starts-error"
            severity="error"
            size="small"
            variant="simple"
          >
            {{ text }}
          </Message>
        </div>

        <div class="field">
          <label for="promotion-ends">Koniec</label>
          <InputText
            id="promotion-ends"
            v-model="data.endsAt"
            data-testid="ends-input"
            type="datetime-local"
            :disabled="readOnly"
            :invalid="errorsAt('endsAt').length > 0"
            :aria-invalid="errorsAt('endsAt').length > 0"
          />
          <Message
            v-for="text in errorsAt('endsAt')"
            :key="text"
            data-testid="ends-error"
            severity="error"
            size="small"
            variant="simple"
          >
            {{ text }}
          </Message>
        </div>
        <small class="hint">Czas w strefie Europe/Warsaw (czas polski).</small>
      </form>

      <section
        class="variants"
        aria-label="Warianty promocji"
      >
        <div class="variants-header">
          <h2>Warianty ({{ data.variants.length }})</h2>
          <Button
            v-if="!variantsLocked"
            data-testid="open-picker"
            label="Dodaj warianty"
            icon="pi pi-plus"
            severity="secondary"
            @click="pickerOpen = !pickerOpen"
          />
        </div>
        <Message
          v-for="text in errorsAt('variantIds')"
          :key="text"
          data-testid="variants-error"
          severity="error"
          size="small"
          variant="simple"
        >
          {{ text }}
        </Message>

        <PromotionVariantPicker
          v-if="pickerOpen && !variantsLocked"
          :chosen="chosenIds"
          @add="addVariants"
          @close="pickerOpen = false"
        />

        <DataTable
          :value="data.variants"
          data-key="variantId"
          :pt="{ bodyRow: ({ props }: { props: { rowData?: { variantId: string } } }) => ({ 'data-testid': 'variant-row', 'data-variant-id': props.rowData?.variantId }) }"
        >
          <template #empty>
            <span data-testid="variants-empty">Nie wybrano wariantów.</span>
          </template>
          <Column header="Produkt">
            <template #body="{ data: row }">
              <Tag
                v-if="row.productName === null"
                data-testid="variant-deleted"
                severity="warn"
                value="Wariant usunięty"
              />
              <span
                v-else
                data-testid="variant-name"
              >{{ row.productName }}</span>
              <Tag
                v-if="isConflicting(row.variantId)"
                data-testid="variant-conflict"
                severity="danger"
                icon="pi pi-exclamation-triangle"
                value="Koliduje z inną promocją"
              />
            </template>
          </Column>
          <Column header="Wariant">
            <template #body="{ data: row }">
              <span data-testid="variant-label">{{ row.label ?? "" }}</span>
            </template>
          </Column>
          <Column
            header="Cena katalogowa"
            class="amount"
          >
            <template #body="{ data: row }">
              <span data-testid="variant-regular">{{ row.regularGross === null ? "—" : formatMoney(row.regularGross, CURRENCY) }}</span>
            </template>
          </Column>
          <Column
            header="Cena promocyjna"
            class="amount"
          >
            <template #body="{ data: row }">
              <span data-testid="variant-promotional">{{ row.promotionalGross === null ? "—" : formatMoney(row.promotionalGross, CURRENCY) }}</span>
            </template>
          </Column>
          <Column
            v-if="!variantsLocked"
            header="Akcje"
          >
            <template #body="{ data: row }">
              <Button
                data-testid="remove-variant"
                label="Usuń"
                icon="pi pi-times"
                severity="secondary"
                size="small"
                @click="removeVariant(row.variantId)"
              />
            </template>
          </Column>
        </DataTable>
      </section>

      <Message
        v-if="confirming"
        data-testid="confirm-box"
        severity="warn"
      >
        {{ confirmText }}
        <div class="actions">
          <Button
            data-testid="confirm-action"
            :label="confirming === 'end' ? 'Zakończ teraz' : 'Usuń'"
            severity="danger"
            size="small"
            :disabled="busy"
            @click="confirmAction()"
          />
          <Button
            data-testid="cancel-confirm"
            label="Anuluj"
            severity="secondary"
            size="small"
            @click="confirming = null"
          />
        </div>
      </Message>

      <div
        v-if="!readOnly"
        class="actions"
      >
        <Button
          v-if="!readOnly"
          data-testid="save"
          label="Zapisz"
          icon="pi pi-check"
          :loading="busy"
          :disabled="!canSave"
          @click="save()"
        />
        <Button
          v-if="status === 'active' && !readOnly"
          data-testid="end-now"
          label="Zakończ teraz"
          icon="pi pi-stop-circle"
          severity="secondary"
          :disabled="!canEndNow"
          @click="confirming = 'end'"
        />
        <Button
          v-if="status === 'planned' && !readOnly"
          data-testid="delete"
          label="Usuń"
          icon="pi pi-trash"
          severity="danger"
          :disabled="!canDelete"
          @click="confirming = 'delete'"
        />
      </div>
    </template>
  </div>
</template>

<style scoped>
.promotion {
  display: flex;
  flex-direction: column;
  gap: 1rem;
  max-width: 64rem;
}

.header {
  display: flex;
  align-items: center;
  gap: 1rem;
}

.back {
  margin-left: auto;
}

.fields {
  display: flex;
  flex-direction: column;
  gap: 1rem;
  max-width: 32rem;
}

.field {
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
}

.start {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 1rem;
}

.switch {
  display: flex;
  align-items: center;
  gap: 0.5rem;
}

.hint {
  color: var(--p-text-muted-color);
}

.variants {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
}

.variants-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
}

.amount {
  text-align: right;
  white-space: nowrap;
}

.actions {
  display: flex;
  gap: 0.5rem;
  margin-top: 0.5rem;
}
</style>
