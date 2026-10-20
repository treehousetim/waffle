<script setup>
import { onMounted, ref } from 'vue'

import client, { unwrap } from '@/api/client'
import { BLOCK_TYPES, moveBlock, newBlock, tableToText, textToTable } from '@/utils/blocks'

/*
 * Editing a body of blocks (docs/content.md, "Blocks"): one plain form per
 * block, in order, with add, move and remove. The app checks every block
 * when the record is saved and names the one it refuses.
 */

const blocks = defineModel({ type: Array, required: true })
const adding = ref('text')
/** The media library, for image and gallery blocks to choose from. */
const library = ref([])
const imageLabel = (item) => `${item.id}: ${item.alt || '(no description yet)'}`

onMounted(async () => {
  try {
    library.value = unwrap(await client.get('/content/media')).media
  } catch {
    library.value = []
  }
})

const label = (type) => BLOCK_TYPES.find((option) => option.type === type)?.label ?? type
const add = () => (blocks.value = [...blocks.value, newBlock(adding.value)])
const remove = (index) => (blocks.value = blocks.value.filter((_, at) => at !== index))
const move = (index, by) => (blocks.value = moveBlock(blocks.value, index, by))

function setTable(block, text) {
  Object.assign(block, textToTable(text))
}
</script>

<template>
  <fieldset class="blocks">
    <legend>Body</legend>
    <p v-if="blocks.length === 0">No blocks yet.</p>

    <section v-for="(block, index) in blocks" :key="index" class="block">
      <header>
        <strong>{{ index + 1 }}. {{ label(block.type) }}</strong>
        <button type="button" :disabled="index === 0" @click="move(index, -1)">Up</button>
        <button type="button" :disabled="index === blocks.length - 1" @click="move(index, 1)">Down</button>
        <button type="button" @click="remove(index)">Remove</button>
      </header>

      <template v-if="block.type === 'heading'">
        <label>Level <select v-model.number="block.level"><option :value="2">2</option><option :value="3">3</option><option :value="4">4</option></select></label>
        <label>Text <input v-model="block.text" maxlength="150" /></label>
      </template>
      <label v-else-if="block.type === 'text'">Text (Markdown, no headings) <textarea v-model="block.markdown" rows="6" /></label>
      <template v-else-if="block.type === 'quote'">
        <label>Quote <textarea v-model="block.text" rows="3" /></label>
        <label>Who said it <input v-model="block.cite" maxlength="150" /></label>
      </template>
      <template v-else-if="block.type === 'callout'">
        <label>Tone <select v-model="block.tone"><option value="note">Note</option><option value="warning">Warning</option></select></label>
        <label>Text (Markdown) <textarea v-model="block.markdown" rows="3" /></label>
      </template>
      <template v-else-if="block.type === 'faq'">
        <div v-for="(item, at) in block.items" :key="at" class="faq-item">
          <label>Question {{ at + 1 }} <input v-model="item.question" maxlength="300" /></label>
          <label>Answer (Markdown) <textarea v-model="item.answer" rows="3" /></label>
          <button type="button" :disabled="block.items.length === 1" @click="block.items.splice(at, 1)">Remove this question</button>
        </div>
        <button type="button" @click="block.items.push({ question: '', answer: '' })">Add a question</button>
      </template>
      <template v-else-if="block.type === 'cta'">
        <label>Button text <input v-model="block.label" maxlength="60" /></label>
        <label>Page on this site <input v-model="block.path" placeholder="/events/" /></label>
        <label>Note <input v-model="block.note" maxlength="200" /></label>
      </template>
      <template v-else-if="block.type === 'image'">
        <label>
          Image (from Media)
          <select v-model.number="block.media_id">
            <option :value="null" disabled>Choose an image</option>
            <option v-for="item in library" :key="item.id" :value="item.id">{{ imageLabel(item) }}</option>
          </select>
        </label>
        <img v-if="block.media_id" class="chosen" :src="`/api/content/media/${block.media_id}/file`" alt="" width="160" />
        <label>Caption <input v-model="block.caption" maxlength="300" /></label>
      </template>
      <template v-else-if="block.type === 'gallery'">
        <fieldset>
          <legend>Images (2 to 24, from Media)</legend>
          <label v-for="item in library" :key="item.id"><input v-model="block.media_ids" type="checkbox" :value="item.id" /> <img :src="`/api/content/media/${item.id}/file`" alt="" width="48" /> {{ imageLabel(item) }}</label>
        </fieldset>
        <label>Caption <input v-model="block.caption" maxlength="300" /></label>
      </template>
      <label v-else-if="block.type === 'table'">
        Columns on the first line, a row on each line after, cells separated by |
        <textarea :value="tableToText(block)" rows="5" @change="setTable(block, $event.target.value)" />
      </label>
    </section>

    <div class="add">
      <select v-model="adding">
        <option v-for="option in BLOCK_TYPES" :key="option.type" :value="option.type">{{ option.label }}</option>
      </select>
      <button type="button" @click="add">Add a block</button>
    </div>
  </fieldset>
</template>

<style scoped>
.block { border: 1px solid currentColor; padding: 0.75rem; margin-bottom: 0.75rem; }
.block header { display: flex; gap: 0.5rem; align-items: center; margin-bottom: 0.5rem; }
.block header strong { flex: 1; }
.faq-item { margin-bottom: 0.75rem; }
</style>
