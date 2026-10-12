<script setup>
import { onMounted, ref } from 'vue'

import client, { unwrap } from '@/api/client'
import { apiErrorMessage } from '@/utils/errors'

const hello = ref(null)
const error = ref('')

onMounted(async () => {
  try {
    hello.value = unwrap(await client.get('/hello'))
  } catch (e) {
    error.value = apiErrorMessage(e)
  }
})
</script>

<template>
  <section>
    <h1>Home</h1>
    <p v-if="error" class="error" role="alert">{{ error }}</p>
    <p v-else-if="hello">
      {{ hello.message }} You are signed in as <strong>{{ hello.email }}</strong>.
    </p>
    <p v-else>Loading…</p>
  </section>
</template>
