<script setup>
import { computed, onMounted, ref } from 'vue'

import client, { unwrap } from '@/api/client'
import { useAuthStore } from '@/stores/auth'
import { apiErrorMessage } from '@/utils/errors'

/*
 * The Settings page renders itself from the rows: each setting carries its
 * label, description, type, options and group (docs/settings.md). Editing is
 * offered only to a level holding `settings-edit`; the server refuses the rest
 * anyway.
 */

const auth = useAuthStore()
const settings = ref([])
const draft = ref({})
const error = ref('')
const saved = ref(false)
const busy = ref(false)

const canEdit = computed(() => auth.permissions.includes('settings-edit'))

const groups = computed(() => {
  const byGroup = new Map()

  for (const setting of settings.value) {
    if (!byGroup.has(setting.group)) {
      byGroup.set(setting.group, [])
    }
    byGroup.get(setting.group).push(setting)
  }

  return [...byGroup.entries()]
})

function load(list) {
  settings.value = list
  draft.value = Object.fromEntries(list.map((s) => [s.key, s.type === 'bool' ? s.value === '1' : s.value]))
}

onMounted(async () => {
  try {
    load(unwrap(await client.get('/settings')).settings)
  } catch (e) {
    error.value = apiErrorMessage(e)
  }
})

async function save() {
  error.value = ''
  saved.value = false
  busy.value = true

  const values = Object.fromEntries(
    settings.value.filter((s) => s.editable).map((s) => [s.key, draft.value[s.key]])
  )

  try {
    load(unwrap(await client.put('/settings', { values })).settings)
    saved.value = true
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <section>
    <h1>Settings</h1>
    <p v-if="error" class="error" role="alert">{{ error }}</p>

    <form @submit.prevent="save">
      <fieldset v-for="[group, items] in groups" :key="group" class="card">
        <legend>{{ group }}</legend>

        <label v-for="setting in items" :key="setting.key" class="setting">
          <span>{{ setting.label }}</span>
          <small>{{ setting.description }}</small>

          <select v-if="setting.type === 'select'" v-model="draft[setting.key]" :disabled="!canEdit || !setting.editable">
            <option v-for="option in setting.options" :key="option" :value="option">{{ option }}</option>
          </select>
          <input
            v-else-if="setting.type === 'bool'"
            v-model="draft[setting.key]"
            type="checkbox"
            :disabled="!canEdit || !setting.editable"
          />
          <input
            v-else
            v-model="draft[setting.key]"
            :type="setting.type === 'int' ? 'number' : 'text'"
            :disabled="!canEdit || !setting.editable"
          />
        </label>
      </fieldset>

      <div v-if="canEdit" class="actions">
        <button type="submit" :disabled="busy">{{ busy ? 'Saving…' : 'Save' }}</button>
        <span v-if="saved">Saved.</span>
      </div>
    </form>
  </section>
</template>
