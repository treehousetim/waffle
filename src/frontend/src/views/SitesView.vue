<script setup>
import { onMounted, ref } from 'vue'

import client, { unwrap } from '@/api/client'
import { apiErrorMessage } from '@/utils/errors'

/*
 * The sites that send forms, and their keys (docs/forms.md, `sites-manage`).
 * A key is shown once: put it in the site server's Doppler config as
 * WAFFLE_SITE_KEY. Issuing another replaces it at once.
 */

const sites = ref([])
const draft = ref({ slug: '', name: '', origin: 'https://' })
const issued = ref(null)
const error = ref('')
const busy = ref(false)

async function load() {
  try {
    sites.value = unwrap(await client.get('/sites')).sites
  } catch (e) {
    error.value = apiErrorMessage(e)
  }
}

async function run(action) {
  busy.value = true
  error.value = ''

  try {
    await action()
    await load()
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    busy.value = false
  }
}

const add = () =>
  run(async () => {
    await client.post('/sites', draft.value)
    draft.value = { slug: '', name: '', origin: 'https://' }
  })
const issue = (site) =>
  run(async () => {
    issued.value = { name: site.name, key: unwrap(await client.post(`/sites/${site.id}/key`, {})).key }
  })
const toggle = (site) => run(() => client.post(`/sites/${site.id}/${site.status === 'active' ? 'disable' : 'enable'}`, {}))

onMounted(load)
</script>

<template>
  <section>
    <h1>Sites</h1>
    <p v-if="error" class="error" role="alert">{{ error }}</p>

    <div v-if="issued" class="card" role="status">
      <p>The key for {{ issued.name }}. Put it in the site server's Doppler config as <code>WAFFLE_SITE_KEY</code> now: it is not shown again.</p>
      <p><input :value="issued.key" readonly @focus="$event.target.select()" /></p>
    </div>

    <table v-if="sites.length > 0">
      <thead><tr><th>Site</th><th>Origin</th><th>Status</th><th>Key</th><th /></tr></thead>
      <tbody>
        <tr v-for="site in sites" :key="site.id">
          <td>{{ site.name }} <small>({{ site.slug }})</small></td>
          <td>{{ site.origin }}</td>
          <td>{{ site.status }}</td>
          <td>{{ site.has_key ? `issued ${site.key_issued_at} UTC` : 'none yet' }}</td>
          <td>
            <button type="button" :disabled="busy" @click="issue(site)">{{ site.has_key ? 'Replace the key' : 'Issue a key' }}</button>
            <button type="button" :disabled="busy" @click="toggle(site)">{{ site.status === 'active' ? 'Disable' : 'Enable' }}</button>
          </td>
        </tr>
      </tbody>
    </table>

    <form class="card" @submit.prevent="add">
      <h2>Add a site</h2>
      <label>Slug <input v-model="draft.slug" required /></label>
      <label>Name <input v-model="draft.name" maxlength="100" required /></label>
      <label>Origin <input v-model="draft.origin" required /></label>
      <button type="submit" :disabled="busy">Add</button>
    </form>
  </section>
</template>
