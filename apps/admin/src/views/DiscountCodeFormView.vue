<script setup lang="ts">
import Button from "primevue/button";
import InputText from "primevue/inputtext";
import Message from "primevue/message";
import SelectButton from "primevue/selectbutton";
import Skeleton from "primevue/skeleton";
import Tag from "primevue/tag";
import ToggleSwitch from "primevue/toggleswitch";
import { computed, nextTick, useTemplateRef } from "vue";
import { RouterLink, useRoute, useRouter } from "vue-router";
import { statusLabel, statusSeverity } from "@/discount-codes/format";
import { canManageDiscountCodes } from "@/discount-codes/permissions";
import { useDiscountCodeForm } from "@/discount-codes/useDiscountCodeForm";
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
  readOnly,
  codeLocked,
  termsLocked,
  limitLocked,
  canSave,
  message,
  error,
  errorsAt,
  save,
} = useDiscountCodeForm({
  discountCodeId: () => (typeof route.params.id === "string" ? route.params.id : null),
  // Only hides the actions: the API checks the permission. The roles come from /api/v1/admin/me.
  canManage: () => canManageDiscountCodes(auth.staff),
  onCreated: (id) => {
    void router.replace({ name: "discount-code", params: { id } });
  },
});

const KIND_OPTIONS = [
  { label: "Procent", value: "percent" },
  { label: "Kwota", value: "amount" },
];

const termsDisabled = computed(() => readOnly.value || termsLocked.value);
const limitDisabled = computed(() => readOnly.value || limitLocked.value);
const valueLabel = computed(() => (data.value.kind === "percent" ? "Rabat (%)" : "Kwota rabatu (zł)"));

const formElement = useTemplateRef<HTMLFormElement>("form");
const errorElement = useTemplateRef<{ $el: HTMLElement }>("errorBox");
const successElement = useTemplateRef<{ $el: HTMLElement }>("successBox");

// A save moves the focus to what needs attention: the success message (the save button goes off, and a new code moves
// to its own page with this same instance), or after a failure the first field with an error, else the message.
async function submit(): Promise<void> {
  const result = await save();
  if (result === "success") {
    await nextTick();
    successElement.value?.$el.focus();
    return;
  }
  if (result !== "failure") {
    return;
  }
  await nextTick();
  const field = formElement.value?.querySelector<HTMLElement>("[aria-invalid=true]");
  (field ?? errorElement.value?.$el)?.focus();
}
</script>

<template>
  <div class="discount-code">
    <div class="header">
      <h1>{{ view ? view.code : "Nowy kod rabatowy" }}</h1>
      <Tag
        v-if="view"
        data-testid="code-status"
        :severity="statusSeverity(view.status)"
        :value="statusLabel(view.status)"
      />
      <RouterLink
        class="back"
        :to="{ name: 'discount-codes' }"
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
        Nie masz uprawnień do zmiany kodów rabatowych. Widok jest tylko do odczytu.
      </Message>
      <Message
        v-else-if="termsLocked"
        data-testid="terms-locked-note"
        severity="info"
      >
        Kod już obowiązuje: rodzaj, wartość, minimum i początek są zablokowane. Możesz zmienić koniec, włączenie
        i limit.
      </Message>
      <Message
        v-if="message"
        ref="successBox"
        data-testid="success-message"
        severity="success"
        tabindex="-1"
      >
        {{ message }}
      </Message>
      <Message
        v-if="error"
        ref="errorBox"
        data-testid="form-error"
        severity="error"
        tabindex="-1"
      >
        {{ error }}
      </Message>

      <p
        v-if="view"
        class="uses"
      >
        Aktywne użycia: <strong data-testid="active-uses">{{ view.activeUses }}</strong>
      </p>

      <form
        ref="form"
        class="fields"
        novalidate
        @submit.prevent="submit()"
      >
        <div class="field">
          <label for="discount-code-code">Kod</label>
          <InputText
            id="discount-code-code"
            v-model="data.code"
            data-testid="code-input"
            maxlength="32"
            autocomplete="off"
            autocapitalize="characters"
            aria-describedby="discount-code-code-hint"
            :disabled="readOnly || codeLocked"
            :invalid="errorsAt('code').length > 0"
            :aria-invalid="errorsAt('code').length > 0"
          />
          <small
            id="discount-code-code-hint"
            class="hint"
          >3–32 znaki: litery, cyfry i myślnik; wielkość liter nie ma znaczenia. Kodu nie można później zmienić.</small>
          <Message
            v-for="text in errorsAt('code')"
            :key="text"
            data-testid="code-error"
            severity="error"
            size="small"
            variant="simple"
          >
            {{ text }}
          </Message>
        </div>

        <div class="field">
          <span>Rodzaj rabatu</span>
          <SelectButton
            v-model="data.kind"
            data-testid="kind-input"
            :options="KIND_OPTIONS"
            option-label="label"
            option-value="value"
            :allow-empty="false"
            :disabled="termsDisabled"
            aria-label="Rodzaj rabatu"
          />
        </div>

        <div class="field">
          <label for="discount-code-value">{{ valueLabel }}</label>
          <InputText
            id="discount-code-value"
            v-model="data.value"
            data-testid="value-input"
            inputmode="decimal"
            autocomplete="off"
            :disabled="termsDisabled"
            :invalid="errorsAt('value').length > 0"
            :aria-invalid="errorsAt('value').length > 0"
          />
          <Message
            v-for="text in errorsAt('value')"
            :key="text"
            data-testid="value-error"
            severity="error"
            size="small"
            variant="simple"
          >
            {{ text }}
          </Message>
        </div>

        <div class="field">
          <label for="discount-code-minimum">Minimalna wartość zamówienia (zł)</label>
          <InputText
            id="discount-code-minimum"
            v-model="data.minimum"
            data-testid="minimum-input"
            inputmode="decimal"
            autocomplete="off"
            aria-describedby="discount-code-minimum-hint"
            :disabled="termsDisabled"
            :invalid="errorsAt('minimumOrderGross').length > 0"
            :aria-invalid="errorsAt('minimumOrderGross').length > 0"
          />
          <small
            id="discount-code-minimum-hint"
            class="hint"
          >Puste: bez minimum. Kod kwotowy wymaga minimum większego od kwoty rabatu.</small>
          <Message
            v-for="text in errorsAt('minimumOrderGross')"
            :key="text"
            data-testid="minimum-error"
            severity="error"
            size="small"
            variant="simple"
          >
            {{ text }}
          </Message>
        </div>

        <div class="field">
          <label for="discount-code-starts">Początek</label>
          <InputText
            id="discount-code-starts"
            v-model="data.startsAt"
            data-testid="starts-input"
            type="datetime-local"
            :disabled="termsDisabled"
            :invalid="errorsAt('startsAt').length > 0"
            :aria-invalid="errorsAt('startsAt').length > 0"
          />
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
          <label for="discount-code-ends">Koniec</label>
          <InputText
            id="discount-code-ends"
            v-model="data.endsAt"
            data-testid="ends-input"
            type="datetime-local"
            aria-describedby="discount-code-ends-hint"
            :disabled="readOnly"
            :invalid="errorsAt('endsAt').length > 0"
            :aria-invalid="errorsAt('endsAt').length > 0"
          />
          <small
            id="discount-code-ends-hint"
            class="hint"
          >Puste: bez końca.</small>
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

        <div class="field">
          <label for="discount-code-limit">Limit użyć</label>
          <InputText
            id="discount-code-limit"
            v-model="data.usageLimit"
            data-testid="limit-input"
            inputmode="numeric"
            autocomplete="off"
            aria-describedby="discount-code-limit-hint"
            :disabled="limitDisabled"
            :invalid="errorsAt('usageLimit').length > 0"
            :aria-invalid="errorsAt('usageLimit').length > 0"
          />
          <small
            id="discount-code-limit-hint"
            class="hint"
          >Puste: bez limitu.</small>
          <small
            v-if="limitLocked"
            data-testid="limit-locked-note"
            class="hint"
          >Limitu nie można dodać kodowi, który już obowiązuje.</small>
          <Message
            v-for="text in errorsAt('usageLimit')"
            :key="text"
            data-testid="limit-error"
            severity="error"
            size="small"
            variant="simple"
          >
            {{ text }}
          </Message>
        </div>

        <div class="field switch">
          <ToggleSwitch
            v-model="data.enabled"
            data-testid="enabled"
            input-id="discount-code-enabled"
            :disabled="readOnly"
          />
          <label for="discount-code-enabled">Włączony</label>
        </div>

        <div
          v-if="!readOnly"
          class="actions"
        >
          <Button
            data-testid="save"
            type="submit"
            label="Zapisz"
            icon="pi pi-check"
            :loading="busy"
            :disabled="!canSave"
          />
        </div>
      </form>
    </template>
  </div>
</template>

<style scoped>
.discount-code {
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

.uses {
  margin: 0;
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

.switch {
  flex-direction: row;
  align-items: center;
  gap: 0.5rem;
}

.hint {
  color: var(--p-text-muted-color);
}

.actions {
  display: flex;
  gap: 0.5rem;
  margin-top: 0.5rem;
}
</style>
