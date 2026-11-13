<script setup lang="ts">
import { ArrowDown, ArrowUp, RefreshCw, Trash2, Upload, X } from "lucide-vue-next";
import { nextTick, ref } from "vue";
import FormSection from "@/components/FormSection.vue";
import { Alert } from "@/components/ui/alert";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { focusAfterAction } from "@/lib/focus";
import { thumbnailSources } from "./thumbnail";
import { type FormImage, MAX_IMAGES, type UploadEntry, type UploadStatus } from "./useImageUpload";

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

const section = ref<InstanceType<typeof FormSection> | null>(null);
const input = ref<HTMLInputElement | null>(null);
const addButton = ref<InstanceType<typeof Button> | null>(null);
const list = ref<HTMLElement | null>(null);
const uploads = ref<HTMLElement | null>(null);

const statusLabels: Record<UploadStatus, string> = {
  pending: "Oczekuje",
  uploading: "Wysyłanie",
  done: "Gotowe",
  failed: "Błąd",
};
const statusVariants = { pending: "neutral", uploading: "info", done: "success", failed: "danger" } as const;

function chosen(event: Event): void {
  const target = event.target as HTMLInputElement;
  const files = Array.from(target.files ?? []);
  // The same file can be chosen again after a failure.
  target.value = "";
  if (files.length > 0) {
    emit("add", files);
  }
}

/** The button that adds files, or the heading of the card when it cannot take the focus (the limit is reached). */
function afterRemoval(target: HTMLElement | null | undefined): void {
  const add = addButton.value?.$el as HTMLElement | undefined;
  focusAfterAction(target ?? add ?? null, section.value?.heading ?? null);
}

// The button of the removed image goes away with it: the focus goes to the same button of the image that took its place
// (the next one) and, without a next image, to the button that adds images, never to the body (#250 F8).
async function remove(index: number): Promise<void> {
  emit("remove", index);
  await nextTick();
  afterRemoval(list.value?.querySelectorAll<HTMLElement>("[data-testid=image-remove]")[index]);
}

// The same for the result of an upload that is hidden: the next result, else the button that adds images.
async function dismiss(index: number, id: number): Promise<void> {
  emit("dismiss", id);
  await nextTick();
  afterRemoval(uploads.value?.querySelectorAll<HTMLElement>("[data-testid=upload-dismiss]")[index]);
}
</script>

<template>
  <FormSection
    ref="section"
    title="Zdjęcia"
  >
    <p class="text-sm text-muted-foreground">
      Pierwsze zdjęcie jest główne. Najwyżej {{ MAX_IMAGES }} zdjęć.
    </p>

    <Alert
      v-for="title in errors"
      :key="title"
      data-testid="images-error"
      data-form-error
      variant="destructive"
    >
      {{ title }}
    </Alert>

    <template v-if="!readOnly">
      <input
        ref="input"
        data-testid="image-input"
        class="hidden"
        type="file"
        multiple
        accept="image/jpeg,image/png,image/webp,image/avif,image/heic"
        tabindex="-1"
        aria-hidden="true"
        @change="chosen"
      >
      <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
        <Button
          ref="addButton"
          type="button"
          variant="outline"
          data-testid="add-images"
          :disabled="!canAddMore"
          @click="input?.click()"
        >
          <Upload aria-hidden="true" />
          Dodaj zdjęcia
        </Button>
        <p
          v-if="!canAddMore"
          class="text-sm text-muted-foreground"
        >
          Osiągnięto limit {{ MAX_IMAGES }} zdjęć.
        </p>
      </div>
    </template>

    <ul
      v-if="entries.length > 0"
      ref="uploads"
      class="flex flex-col gap-2"
    >
      <li
        v-for="(entry, index) in entries"
        :key="entry.id"
        data-testid="upload-entry"
        class="flex flex-wrap items-center gap-x-3 gap-y-1"
      >
        <span class="font-semibold break-all">{{ entry.fileName }}</span>
        <Badge :variant="statusVariants[entry.status]">
          {{ statusLabels[entry.status] }}
        </Badge>
        <span
          v-if="entry.message"
          class="text-sm"
          :class="entry.status === 'failed' ? 'text-status-danger' : 'text-muted-foreground'"
          :role="entry.status === 'failed' ? 'alert' : undefined"
        >{{ entry.message }}</span>
        <Button
          v-if="entry.status === 'failed' && entry.retryable"
          type="button"
          variant="outline"
          size="sm"
          data-testid="upload-retry"
          @click="emit('retry', entry.id)"
        >
          <RefreshCw aria-hidden="true" />
          Ponów
        </Button>
        <Button
          v-if="entry.status === 'done' || entry.status === 'failed'"
          type="button"
          variant="ghost"
          size="icon-sm"
          class="border-0 bg-transparent"
          data-testid="upload-dismiss"
          :aria-label="`Ukryj: ${entry.fileName}`"
          @click="dismiss(index, entry.id)"
        >
          <X aria-hidden="true" />
        </Button>
      </li>
    </ul>

    <ul
      ref="list"
      class="flex flex-col gap-3"
    >
      <li
        v-for="(image, index) in images"
        :key="image.key"
        data-testid="image-row"
        class="flex flex-wrap items-start gap-3"
      >
        <img
          v-if="thumbnailSources(image.variants)"
          class="size-16 shrink-0 rounded-md object-cover"
          :src="thumbnailSources(image.variants)!.src"
          :srcset="thumbnailSources(image.variants)!.srcset"
          sizes="64px"
          width="64"
          height="64"
          :alt="image.alt"
        >
        <div
          v-else
          class="size-16 shrink-0 rounded-md bg-muted"
        />
        <div class="flex min-w-48 flex-1 flex-col gap-1.5">
          <Label :for="`image-alt-${index}`">
            Opis zdjęcia (alt)
          </Label>
          <Input
            :id="`image-alt-${index}`"
            data-testid="image-alt"
            :model-value="image.alt"
            :disabled="readOnly"
            :aria-invalid="errorsAt(`images[${index}]`).length > 0 ? 'true' : undefined"
            :aria-describedby="errorsAt(`images[${index}]`).length > 0 ? `image-alt-${index}-error` : undefined"
            maxlength="200"
            @update:model-value="emit('alt', index, String($event ?? ''))"
          />
          <p
            v-for="title in errorsAt(`images[${index}]`)"
            :id="`image-alt-${index}-error`"
            :key="title"
            data-testid="image-error"
            data-form-error
            class="text-sm text-status-danger"
          >
            {{ title }}
          </p>
        </div>
        <Badge
          v-if="index === 0"
          variant="info"
          data-testid="image-main"
        >
          Główne
        </Badge>
        <div
          v-if="!readOnly"
          class="flex items-center gap-1"
        >
          <Button
            type="button"
            variant="ghost"
            size="icon-sm"
            class="border-0 bg-transparent"
            data-testid="image-up"
            :disabled="index === 0"
            :aria-label="`Przesuń zdjęcie ${index + 1} w górę`"
            @click="emit('move', index, -1)"
          >
            <ArrowUp aria-hidden="true" />
          </Button>
          <Button
            type="button"
            variant="ghost"
            size="icon-sm"
            class="border-0 bg-transparent"
            data-testid="image-down"
            :disabled="index === images.length - 1"
            :aria-label="`Przesuń zdjęcie ${index + 1} w dół`"
            @click="emit('move', index, 1)"
          >
            <ArrowDown aria-hidden="true" />
          </Button>
          <Button
            type="button"
            variant="ghost"
            size="icon-sm"
            class="border-0 bg-transparent text-destructive"
            data-testid="image-remove"
            :aria-label="`Usuń zdjęcie ${index + 1}`"
            @click="remove(index)"
          >
            <Trash2 aria-hidden="true" />
          </Button>
        </div>
      </li>
    </ul>
    <p
      v-if="images.length === 0"
      data-testid="no-images"
      class="text-sm text-muted-foreground"
    >
      Produkt nie ma jeszcze zdjęć.
    </p>
  </FormSection>
</template>
