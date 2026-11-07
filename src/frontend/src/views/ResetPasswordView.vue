<script setup>
import { onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'

import client, { unwrap } from '@/api/client'
import { apiErrorMessage } from '@/utils/errors'

/*
 * Choosing a new password from an emailed reset link
 * (docs/password-reset.md): the link's token is the permission.
 */

const route = useRoute()
const token = String(route.params.token)

const email = ref('')
const password = ref('')
const confirm = ref('')
const done = ref('')
const error = ref('')
const busy = ref(false)
const checked = ref(false)

onMounted(async () => {
  try {
    email.value = unwrap(await client.get(`/password-reset/${encodeURIComponent(token)}`)).email
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    checked.value = true
  }
})

async function submit() {
  error.value = ''

  if (password.value !== confirm.value) {
    error.value = 'The two passwords are not the same.'
    return
  }

  busy.value = true

  try {
    done.value = unwrap(await client.post(`/password-reset/${encodeURIComponent(token)}`, { password: password.value })).message
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <main class="login">
    <div v-if="done" class="card" role="status">
      <h1>Password changed</h1>
      <p>{{ done }}</p>
      <p><RouterLink :to="{ name: 'login' }">Sign in</RouterLink></p>
    </div>
    <form v-else-if="email" class="card" @submit.prevent="submit">
      <h1>Choose a new password</h1>
      <p>For {{ email }}. At least 12 characters.</p>
      <label>New password <input v-model="password" type="password" autocomplete="new-password" minlength="12" required autofocus /></label>
      <label>The same again <input v-model="confirm" type="password" autocomplete="new-password" minlength="12" required /></label>
      <p v-if="error" class="error" role="alert">{{ error }}</p>
      <button type="submit" :disabled="busy">{{ busy ? 'Saving…' : 'Save the new password' }}</button>
    </form>
    <div v-else class="card">
      <h1>Choose a new password</h1>
      <p v-if="checked" class="error" role="alert">{{ error }}</p>
      <p v-else>Checking the link…</p>
      <p v-if="checked"><RouterLink :to="{ name: 'forgot-password' }">Ask for a new link</RouterLink></p>
    </div>
  </main>
</template>
