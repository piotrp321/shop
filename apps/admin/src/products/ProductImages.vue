<script setup lang="ts">
import Button from "primevue/button";
import InputText from "primevue/inputtext";
import Message from "primevue/message";
import Tag from "primevue/tag";
import { ref } from "vue";
import { MAX_IMAGES, type FormImage, type UploadEntry, type UploadStatus } from "./useImageUpload";
import { thumbnailSources } from "./thumbnail";

defineProps<{
  images: FormImage[];
  entries: UploadEntry[];
  canAddMore: boolean;
  readOnly: boolean;
  errorsAt: (path: string) => string[];
  /** Messages about the list as a whole, e.g. too many images. */
  errors: string[];
}>();

const emit = defineEmits<{
  add: [files: File[]];
  retry: [id: number];
  dismiss: [id: number];
  move: [index: number, direction: -1 | 1];
  remove: [index: number];
  alt: [index: number, value: string];
}>();

const input = ref<HTMLInputElement | null>(null);

const statusLabels: Record<UploadStatus, string> = {
  pending: "Oczekuje",
  uploading: "Wysyłanie",
  done: "Gotowe",
  failed: "Błąd",
};
const statusSeverities: Record<UploadStatus, string> = { pending: "secondary", uploading: "info", done: "success", failed: "danger" };

function chosen(event: Event): void {
  const target = event.target as HTMLInputElement;
  const files = Array.from(target.files ?? []);
  // The same file can be chosen again after a failure.
  target.value = "";
  if (files.length > 0) {
    emit("add", files);
  }
}
</script>

<template>
  <section
    class="images"
    aria-labelledby="images-heading"
  >
    <h2 id="images-heading">
      Zdjęcia
    </h2>
    <p class="hint">
      Pierwsze zdjęcie jest główne. Najwyżej {{ MAX_IMAGES }} zdjęć.
    </p>

    <Message
      v-for="title in errors"
      :key="title"
      data-testid="images-error"
      severity="error"
    >
      {{ title }}
    </Message>

    <template v-if="!readOnly">
      <input
        ref="input"
        data-testid="image-input"
        class="file-input"
        type="file"
        multiple
        accept="image/jpeg,image/png,image/webp,image/avif,image/heic"
        tabindex="-1"
        aria-hidden="true"
        @change="chosen"
      >
      <div>
        <Button
          data-testid="add-images"
          label="Dodaj zdjęcia"
          icon="pi pi-upload"
          severity="secondary"
          :disabled="!canAddMore"
          @click="input?.click()"
        />
        <small
          v-if="!canAddMore"
          class="hint"
        > Osiągnięto limit {{ MAX_IMAGES }} zdjęć.</small>
      </div>
    </template>

    <ul
      v-if="entries.length > 0"
      class="uploads"
    >
      <li
        v-for="entry in entries"
        :key="entry.id"
        data-testid="upload-entry"
        class="upload"
      >
        <span class="file-name">{{ entry.fileName }}</span>
        <Tag
          :severity="statusSeverities[entry.status]"
          :value="statusLabels[entry.status]"
        />
        <span
          v-if="entry.message"
          class="upload-message"
        >{{ entry.message }}</span>
        <Button
          v-if="entry.status === 'failed' && entry.retryable"
          data-testid="upload-retry"
          label="Ponów"
          icon="pi pi-refresh"
          severity="secondary"
          size="small"
          @click="emit('retry', entry.id)"
        />
        <Button
          v-if="entry.status === 'done' || entry.status === 'failed'"
          data-testid="upload-dismiss"
          icon="pi pi-times"
          severity="secondary"
          text
          size="small"
          aria-label="Ukryj"
          @click="emit('dismiss', entry.id)"
        />
      </li>
    </ul>

    <ul class="list">
      <li
        v-for="(image, index) in images"
        :key="image.key"
        data-testid="image-row"
        class="row"
      >
        <img
          v-if="thumbnailSources(image.variants)"
          class="thumbnail"
          :src="thumbnailSources(image.variants)!.src"
          :srcset="thumbnailSources(image.variants)!.srcset"
          sizes="64px"
          width="64"
          height="64"
          :alt="image.alt"
        >
        <div
          v-else
          class="thumbnail placeholder"
        />
        <div class="alt">
          <label :for="`image-alt-${index}`">Opis zdjęcia (alt)</label>
          <InputText
            :id="`image-alt-${index}`"
            data-testid="image-alt"
            :model-value="image.alt"
            :disabled="readOnly"
            :invalid="errorsAt(`images[${index}]`).length > 0"
            maxlength="200"
            @update:model-value="emit('alt', index, String($event ?? ''))"
          />
          <Message
            v-for="title in errorsAt(`images[${index}]`)"
            :key="title"
            data-testid="image-error"
            severity="error"
            size="small"
            variant="simple"
          >
            {{ title }}
          </Message>
        </div>
        <Tag
          v-if="index === 0"
          data-testid="image-main"
          value="Główne"
        />
        <div
          v-if="!readOnly"
          class="controls"
        >
          <Button
            data-testid="image-up"
            icon="pi pi-arrow-up"
            severity="secondary"
            text
            :disabled="index === 0"
            :aria-label="`Przesuń zdjęcie ${index + 1} w górę`"
            @click="emit('move', index, -1)"
          />
          <Button
            data-testid="image-down"
            icon="pi pi-arrow-down"
            severity="secondary"
            text
            :disabled="index === images.length - 1"
            :aria-label="`Przesuń zdjęcie ${index + 1} w dół`"
            @click="emit('move', index, 1)"
          />
          <Button
            data-testid="image-remove"
            icon="pi pi-trash"
            severity="danger"
            text
            :aria-label="`Usuń zdjęcie ${index + 1}`"
            @click="emit('remove', index)"
          />
        </div>
      </li>
    </ul>
    <small
      v-if="images.length === 0"
      data-testid="no-images"
      class="hint"
    >Produkt nie ma jeszcze zdjęć.</small>
  </section>
</template>

<style scoped>
.images {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
}

.hint {
  color: var(--p-text-muted-color);
  margin: 0;
}

.file-input {
  display: none;
}

.uploads,
.list {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
  margin: 0;
  padding: 0;
  list-style: none;
}

.upload,
.row {
  display: flex;
  align-items: center;
  gap: 0.75rem;
}

.file-name {
  font-weight: 600;
}

.upload-message {
  color: var(--p-text-muted-color);
}

.thumbnail {
  width: 4rem;
  height: 4rem;
  border-radius: var(--p-content-border-radius);
  object-fit: cover;
}

.placeholder {
  background: var(--p-surface-200);
}

.alt {
  display: flex;
  flex: 1;
  flex-direction: column;
  gap: 0.25rem;
}

.controls {
  display: flex;
}
</style>
