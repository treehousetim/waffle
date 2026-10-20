<script setup>
import { onMounted, ref } from 'vue'

import client, { unwrap } from '@/api/client'
import { usesText } from '@/utils/content'
import { apiErrorMessage } from '@/utils/errors'
import { fitWithin } from '@/utils/images'

/*
 * The media library (docs/content.md, "Media"): upload an image (shrunk here
 * first, then re-encoded by the app), and describe it. A record using an
 * image cannot be published until the image has its alt text, and its credit
 * when it is not your own.
 */

const media = ref([])
const drafts = ref({})
const error = ref('')
const notice = ref('')
const busy = ref(false)

async function load() {
  try {
    media.value = unwrap(await client.get('/content/media')).media
    drafts.value = Object.fromEntries(
      media.value.map((item) => [
        item.id,
        { alt: item.alt, caption: item.caption ?? '', credit_name: item.credit_name ?? '', credit_url: item.credit_url ?? '', licence: item.licence }
      ])
    )
  } catch (e) {
    error.value = apiErrorMessage(e)
  }
}

/** Draws the chosen file onto a canvas no larger than 2400 px, as a JPEG. */
async function shrink(file) {
  const bitmap = await createImageBitmap(file)
  const { width, height } = fitWithin(bitmap.width, bitmap.height, 2400)
  const canvas = document.createElement('canvas')
  canvas.width = width
  canvas.height = height
  canvas.getContext('2d').drawImage(bitmap, 0, 0, width, height)

  return new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', 0.9))
}

async function run(action, message) {
  busy.value = true
  error.value = ''
  notice.value = ''

  try {
    await action()
    notice.value = message
    await load()
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    busy.value = false
  }
}

const upload = (event) =>
  run(async () => {
    const file = event.target.files?.[0]
    event.target.value = ''

    if (file) {
      await client.post('/content/media', await shrink(file), { headers: { 'Content-Type': 'image/jpeg' } })
    }
  }, 'Uploaded. Describe it before using it on a published page.')

const save = (id) => run(() => client.put(`/content/media/${id}`, drafts.value[id]), 'Saved.')

onMounted(load)
</script>

<template>
  <section>
    <h1>Media</h1>
    <p v-if="error" class="error" role="alert">{{ error }}</p>
    <p v-if="notice" role="status">{{ notice }}</p>
    <label>Upload an image (JPEG, PNG or WebP) <input type="file" accept="image/jpeg,image/png,image/webp" :disabled="busy" @change="upload" /></label>

    <p v-if="media.length === 0">No images yet.</p>
    <form v-for="item in media" :key="item.id" class="card media-item" @submit.prevent="save(item.id)">
      <img :src="`/api/content/media/${item.id}/file`" :alt="item.alt" width="160" />
      <div>
        <p><strong>Image {{ item.id }}</strong> <small>{{ item.width }} × {{ item.height }}<span v-if="item.public">, on the site</span></small></p>
        <p v-for="problem in item.problems" :key="problem" class="error">{{ problem }}</p>
        <p v-if="item.used_by.length === 0"><small>Not used yet.</small></p>
        <p v-else>
          <small>Used by {{ usesText(item.used_by) }}.<template v-if="item.public"> Saving a change here rebuilds the published ones on the site.</template></small>
        </p>
        <label>What it shows (alt text) <input v-model="drafts[item.id].alt" maxlength="300" /></label>
        <label>Caption <input v-model="drafts[item.id].caption" maxlength="300" /></label>
        <label>
          Whose it is
          <select v-model="drafts[item.id].licence">
            <option value="own">Our own</option>
            <option value="permission">Used with permission</option>
            <option value="licensed">Licensed, credit required</option>
          </select>
        </label>
        <label>Credit <input v-model="drafts[item.id].credit_name" maxlength="150" /></label>
        <label>Credit link <input v-model="drafts[item.id].credit_url" type="url" maxlength="500" /></label>
        <button type="submit" :disabled="busy">Save</button>
      </div>
    </form>
  </section>
</template>

<style scoped>
.media-item { display: grid; grid-template-columns: 160px 1fr; gap: 1rem; }
.media-item label { display: block; margin-bottom: 0.5rem; }
.media-item input, .media-item select { display: block; width: 100%; }
</style>
