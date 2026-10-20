<script setup>
import { computed, onMounted, ref, watch } from 'vue'

import client, { unwrap } from '@/api/client'
import { useAuthStore } from '@/stores/auth'
import { draftFrom, emptyDraft, statusLabel } from '@/utils/content'
import { apiErrorMessage } from '@/utils/errors'

/*
 * Writing and publishing records of one type (docs/content.md): the list on
 * the left, the working copy of one on the right. Plain on purpose: the look
 * is the project's own. A type's own fields come in as `fields`.
 */

const props = defineProps({
  type: { type: String, required: true },
  label: { type: String, required: true },
  fields: { type: Array, default: () => [] }
})

const auth = useAuthStore()
const canPublish = computed(() => auth.permissions.includes('content-publish'))

const records = ref([])
const selected = ref(null)
const revisions = ref([])
const slugs = ref([])
const draft = ref(emptyDraft(props.fields))
const error = ref('')
const notice = ref('')
const busy = ref(false)

const base = computed(() => `/content/${props.type}`)

async function load() {
  try {
    records.value = unwrap(await client.get(base.value)).records
  } catch (e) {
    error.value = apiErrorMessage(e)
  }
}

function show(data) {
  selected.value = data.record
  revisions.value = data.revisions
  slugs.value = data.slugs
  draft.value = draftFrom(data.record, props.fields)
}

function startNew() {
  selected.value = null
  revisions.value = []
  slugs.value = []
  draft.value = emptyDraft(props.fields)
  error.value = ''
  notice.value = ''
}

async function run(action, message) {
  busy.value = true
  error.value = ''
  notice.value = ''

  try {
    show(unwrap(await action()))
    notice.value = message
    await load()
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    busy.value = false
  }
}

const open = (id) => run(() => client.get(`${base.value}/${id}`), '')
const save = () =>
  run(
    () => (selected.value ? client.put(`${base.value}/${selected.value.id}`, draft.value) : client.post(base.value, draft.value)),
    'Saved. The site still shows the last published version.'
  )
const publish = () => run(() => client.post(`${base.value}/${selected.value.id}/publish`, {}), 'Published.')
const retire = () => run(() => client.post(`${base.value}/${selected.value.id}/retire`, {}), 'Retired.')
const restore = (revision) =>
  run(() => client.post(`${base.value}/${selected.value.id}/revisions/${revision}/restore`, {}), 'Restored and published.')

watch(() => props.type, () => {
  startNew()
  load()
})
onMounted(load)
</script>

<template>
  <section class="content-editor">
    <h1>{{ label }}</h1>
    <p v-if="error" class="error" role="alert">{{ error }}</p>
    <p v-if="notice" role="status">{{ notice }}</p>

    <div class="columns">
      <nav aria-label="Records">
        <button type="button" @click="startNew">New</button>
        <ul>
          <li v-for="record in records" :key="record.id">
            <a href="#" @click.prevent="open(record.id)">{{ record.title }}</a>
            <small> {{ statusLabel(record.status) }}</small>
          </li>
        </ul>
        <p v-if="records.length === 0">None yet.</p>
      </nav>

      <form @submit.prevent="save">
        <p v-if="selected">
          {{ statusLabel(selected.status) }}<span v-if="selected.published_at">, first published {{ selected.published_at }} UTC</span>
        </p>
        <label>Title <input v-model="draft.title" maxlength="150" required /></label>
        <label>Slug <input v-model="draft.slug" maxlength="100" :placeholder="selected ? '' : 'made from the title'" :required="!!selected" /></label>
        <label>Summary <textarea v-model="draft.summary" maxlength="300" rows="2" /></label>
        <label v-for="field in fields" :key="field.name">
          {{ field.label }}
          <input v-model="draft[field.name]" :type="field.input ?? 'text'" />
        </label>
        <label>Body (Markdown) <textarea v-model="draft.body" rows="16" /></label>
        <label>SEO title <input v-model="draft.seo_title" maxlength="60" /></label>
        <label>SEO description <textarea v-model="draft.seo_description" maxlength="160" rows="2" /></label>
        <div class="actions">
          <button type="submit" :disabled="busy">Save</button>
          <button v-if="selected && canPublish" type="button" :disabled="busy" @click="publish">Publish</button>
          <button v-if="selected?.status === 'published' && canPublish" type="button" :disabled="busy" @click="retire">Retire</button>
        </div>

        <template v-if="selected">
          <h2>Published versions</h2>
          <p v-if="revisions.length === 0">Not published yet.</p>
          <ul>
            <li v-for="revision in revisions" :key="revision.id">
              {{ revision.published_at }} UTC: {{ revision.title }}
              <button v-if="canPublish" type="button" :disabled="busy" @click="restore(revision.id)">Restore</button>
            </li>
          </ul>
          <h2>Slugs</h2>
          <ul>
            <li v-for="slug in slugs" :key="slug.slug">{{ slug.slug }}<small v-if="slug.replaced_at"> (until {{ slug.replaced_at }} UTC; redirects)</small></li>
          </ul>
        </template>
      </form>
    </div>
  </section>
</template>

<style scoped>
.columns { display: grid; grid-template-columns: minmax(12rem, 1fr) 3fr; gap: 1.5rem; }
form label { display: block; margin-bottom: 0.75rem; }
form input, form textarea { display: block; width: 100%; }
@media (max-width: 700px) { .columns { grid-template-columns: 1fr; } }
</style>
