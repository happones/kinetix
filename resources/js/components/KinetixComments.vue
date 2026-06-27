<script setup lang="ts">
import { onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";
import { toast } from "vue-sonner";
import { useKinetixComments } from "@/composables/useKinetixComments";
import { buttonVariants } from "@/composables/useShadcnVariants";
import type { KinetixComment } from "@/types";

/**
 * Drop-in threaded comments for any commentable model. Pass the model's morph
 * type + id; the component loads, posts, replies, edits and deletes through the
 * Kinetix comments endpoints.
 */
const props = defineProps<{
  commentableType: string;
  commentableId: number | string;
}>();

const { t } = useI18n();
const { comments, loading, load, post, edit, remove } = useKinetixComments(
  props.commentableType,
  props.commentableId,
);

onMounted(load);

const draft = ref("");
const replyTo = ref<number | string | null>(null);
const replyDraft = ref("");
const editing = ref<number | string | null>(null);
const editDraft = ref("");
const busy = ref(false);

function initials(name: string | null): string {
  if (!name) {
    return "?";
  }
  return name
    .split(/\s+/)
    .slice(0, 2)
    .map((p) => p.charAt(0).toUpperCase())
    .join("");
}

function relativeTime(value: string | null): string {
  if (!value) {
    return "";
  }
  const mins = Math.round((Date.now() - new Date(value).getTime()) / 60000);
  if (mins < 1) {
    return t("kinetix.comment_just_now");
  }
  if (mins < 60) {
    return t("kinetix.minutes_ago", { minutes: mins });
  }
  const hours = Math.round(mins / 60);
  if (hours < 24) {
    return t("kinetix.hours_ago", { hours });
  }
  return new Date(value).toLocaleDateString();
}

async function run(fn: () => Promise<void>, errorKey = "save_failed"): Promise<void> {
  busy.value = true;
  try {
    await fn();
  } catch (e) {
    toast.error(e instanceof Error ? e.message : t(`kinetix.${errorKey}`));
  } finally {
    busy.value = false;
  }
}

async function submit(): Promise<void> {
  if (!draft.value.trim()) {
    return;
  }
  await run(async () => {
    await post(draft.value.trim());
    draft.value = "";
  });
}

async function submitReply(parentId: number | string): Promise<void> {
  if (!replyDraft.value.trim()) {
    return;
  }
  await run(async () => {
    await post(replyDraft.value.trim(), parentId);
    replyDraft.value = "";
    replyTo.value = null;
  });
}

function startEdit(comment: KinetixComment): void {
  editing.value = comment.id;
  editDraft.value = comment.body;
}

async function saveEdit(comment: KinetixComment): Promise<void> {
  await run(async () => {
    await edit(comment, editDraft.value.trim());
    editing.value = null;
  });
}

async function onDelete(comment: KinetixComment): Promise<void> {
  await run(async () => {
    await remove(comment);
    toast.success(t("kinetix.comment_deleted"));
  }, "delete_failed");
}
</script>

<template>
  <div class="space-y-5">
    <h3 class="text-sm font-semibold text-foreground">
      {{ t("kinetix.comments_title") }}
    </h3>

    <!-- Composer -->
    <form class="space-y-2" @submit.prevent="submit">
      <textarea
        v-model="draft"
        rows="3"
        :placeholder="t('kinetix.comment_placeholder')"
        class="w-full rounded-md border border-input bg-transparent p-3 text-sm text-foreground shadow-xs outline-none transition-[color,box-shadow] focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px] dark:bg-input/30"
      />
      <div class="flex justify-end">
        <button
          type="submit"
          :disabled="busy || !draft.trim()"
          :class="buttonVariants({ size: 'sm' })"
        >
          {{ t("kinetix.comment_post") }}
        </button>
      </div>
    </form>

    <!-- List -->
    <p v-if="!loading && comments.length === 0" class="text-sm text-muted-foreground">
      {{ t("kinetix.comment_empty") }}
    </p>

    <ul class="space-y-5">
      <li v-for="comment in comments" :key="String(comment.id)" class="space-y-3">
        <!-- Top-level comment -->
        <div class="flex gap-3">
          <span
            class="flex size-8 shrink-0 items-center justify-center overflow-hidden rounded-full bg-muted text-xs font-semibold text-foreground"
          >
            <img
              v-if="comment.authorAvatar"
              :src="comment.authorAvatar"
              alt=""
              class="size-full object-cover"
            />
            <template v-else>{{ initials(comment.authorName) }}</template>
          </span>
          <div class="min-w-0 flex-1 space-y-1">
            <div class="flex items-center gap-2 text-sm">
              <span class="font-medium text-foreground">{{
                comment.authorName ?? t("kinetix.comment_unknown_author")
              }}</span>
              <span class="text-xs text-muted-foreground">{{
                relativeTime(comment.createdAt)
              }}</span>
              <span v-if="comment.edited" class="text-xs text-muted-foreground"
                >· {{ t("kinetix.comment_edited") }}</span
              >
            </div>

            <!-- Edit mode -->
            <form
              v-if="editing === comment.id"
              class="space-y-2"
              @submit.prevent="saveEdit(comment)"
            >
              <textarea
                v-model="editDraft"
                rows="2"
                class="w-full rounded-md border border-input bg-transparent p-2 text-sm text-foreground outline-none focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px] dark:bg-input/30"
              />
              <div class="flex gap-2">
                <button type="submit" :disabled="busy" :class="buttonVariants({ size: 'sm' })">
                  {{ t("kinetix.save") }}
                </button>
                <button
                  type="button"
                  :class="buttonVariants({ variant: 'ghost', size: 'sm' })"
                  @click="editing = null"
                >
                  {{ t("kinetix.cancel") }}
                </button>
              </div>
            </form>

            <template v-else>
              <p class="text-sm whitespace-pre-wrap text-foreground">{{ comment.body }}</p>
              <div class="flex items-center gap-3 text-xs text-muted-foreground">
                <button type="button" class="hover:text-foreground" @click="replyTo = comment.id; replyDraft = ''">
                  {{ t("kinetix.comment_reply") }}
                </button>
                <template v-if="comment.editable">
                  <button type="button" class="hover:text-foreground" @click="startEdit(comment)">
                    {{ t("kinetix.edit") }}
                  </button>
                  <button type="button" class="hover:text-destructive" @click="onDelete(comment)">
                    {{ t("kinetix.delete") }}
                  </button>
                </template>
              </div>
            </template>

            <!-- Reply composer -->
            <form
              v-if="replyTo === comment.id"
              class="mt-2 space-y-2"
              @submit.prevent="submitReply(comment.id!)"
            >
              <textarea
                v-model="replyDraft"
                rows="2"
                :placeholder="t('kinetix.comment_reply_placeholder')"
                class="w-full rounded-md border border-input bg-transparent p-2 text-sm text-foreground outline-none focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px] dark:bg-input/30"
              />
              <div class="flex gap-2">
                <button type="submit" :disabled="busy" :class="buttonVariants({ size: 'sm' })">
                  {{ t("kinetix.comment_reply") }}
                </button>
                <button
                  type="button"
                  :class="buttonVariants({ variant: 'ghost', size: 'sm' })"
                  @click="replyTo = null"
                >
                  {{ t("kinetix.cancel") }}
                </button>
              </div>
            </form>

            <!-- Replies -->
            <ul v-if="comment.replies.length" class="mt-3 space-y-3 border-l border-border pl-4">
              <li v-for="reply in comment.replies" :key="String(reply.id)" class="flex gap-3">
                <span
                  class="flex size-7 shrink-0 items-center justify-center overflow-hidden rounded-full bg-muted text-xs font-semibold text-foreground"
                >
                  <img
                    v-if="reply.authorAvatar"
                    :src="reply.authorAvatar"
                    alt=""
                    class="size-full object-cover"
                  />
                  <template v-else>{{ initials(reply.authorName) }}</template>
                </span>
                <div class="min-w-0 flex-1 space-y-1">
                  <div class="flex items-center gap-2 text-sm">
                    <span class="font-medium text-foreground">{{
                      reply.authorName ?? t("kinetix.comment_unknown_author")
                    }}</span>
                    <span class="text-xs text-muted-foreground">{{
                      relativeTime(reply.createdAt)
                    }}</span>
                  </div>
                  <form
                    v-if="editing === reply.id"
                    class="space-y-2"
                    @submit.prevent="saveEdit(reply)"
                  >
                    <textarea
                      v-model="editDraft"
                      rows="2"
                      class="w-full rounded-md border border-input bg-transparent p-2 text-sm text-foreground outline-none focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px] dark:bg-input/30"
                    />
                    <div class="flex gap-2">
                      <button type="submit" :disabled="busy" :class="buttonVariants({ size: 'sm' })">
                        {{ t("kinetix.save") }}
                      </button>
                      <button
                        type="button"
                        :class="buttonVariants({ variant: 'ghost', size: 'sm' })"
                        @click="editing = null"
                      >
                        {{ t("kinetix.cancel") }}
                      </button>
                    </div>
                  </form>
                  <template v-else>
                    <p class="text-sm whitespace-pre-wrap text-foreground">{{ reply.body }}</p>
                    <div
                      v-if="reply.editable"
                      class="flex items-center gap-3 text-xs text-muted-foreground"
                    >
                      <button type="button" class="hover:text-foreground" @click="startEdit(reply)">
                        {{ t("kinetix.edit") }}
                      </button>
                      <button type="button" class="hover:text-destructive" @click="onDelete(reply)">
                        {{ t("kinetix.delete") }}
                      </button>
                    </div>
                  </template>
                </div>
              </li>
            </ul>
          </div>
        </div>
      </li>
    </ul>
  </div>
</template>
