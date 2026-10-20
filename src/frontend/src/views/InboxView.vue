<script setup>
import { onMounted, ref } from 'vue'

import client, { unwrap } from '@/api/client'
import { apiErrorMessage } from '@/utils/errors'

/*
 * What the site's forms sent (docs/forms.md, `forms-manage`): newest first,
 * by status. Each is marked handled or spam once.
 */

const status = ref('new')
const submissions = ref([])
const error = ref('')
const busy = ref(false)

async function load() {
  error.value = ''

  try {
    submissions.value = unwrap(await client.get('/forms/submissions', { params: { status: status.value } })).submissions
  } catch (e) {
    error.value = apiErrorMessage(e)
  }
}

async function mark(id, as) {
  busy.value = true

  try {
    await client.post(`/forms/submissions/${id}/${as}`, {})
    await load()
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    busy.value = false
  }
}

onMounted(load)
</script>

<template>
  <section>
    <h1>Inbox</h1>
    <p v-if="error" class="error" role="alert">{{ error }}</p>
    <label>
      Show
      <select v-model="status" @change="load">
        <option value="new">New</option>
        <option value="handled">Handled</option>
        <option value="spam">Spam</option>
      </select>
    </label>

    <p v-if="submissions.length === 0">Nothing here.</p>
    <article v-for="submission in submissions" :key="submission.id" class="card">
      <p><strong>{{ submission.form }}</strong> <small>{{ submission.created_at }} UTC</small></p>
      <dl>
        <template v-for="(value, field) in submission.fields" :key="field">
          <dt>{{ field }}</dt>
          <dd class="value">{{ value }}</dd>
        </template>
      </dl>
      <div v-if="submission.status === 'new'" class="actions">
        <button type="button" :disabled="busy" @click="mark(submission.id, 'handled')">Handled</button>
        <button type="button" :disabled="busy" @click="mark(submission.id, 'spam')">Spam</button>
      </div>
    </article>
  </section>
</template>
