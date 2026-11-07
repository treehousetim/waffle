<script setup>
import { ref } from 'vue'

import client, { unwrap } from '@/api/client'
import { apiErrorMessage } from '@/utils/errors'

/*
 * Asking for a password reset link (docs/password-reset.md). The answer is
 * the same whether or not the address has an account.
 */

const email = ref('')
const notice = ref('')
const error = ref('')
const busy = ref(false)

async function submit() {
  busy.value = true
  error.value = ''

  try {
    notice.value = unwrap(await client.post('/auth/password/forgot', { email: email.value })).message
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <main class="login">
    <form v-if="!notice" class="card" @submit.prevent="submit">
      <h1>Reset your password</h1>
      <p>Give the address you sign in with, and we will email it a link to choose a new password.</p>
      <label>Email address <input v-model="email" type="email" autocomplete="username" required autofocus /></label>
      <p v-if="error" class="error" role="alert">{{ error }}</p>
      <button type="submit" :disabled="busy">{{ busy ? 'Sending…' : 'Email me a link' }}</button>
      <p><RouterLink :to="{ name: 'login' }">Back to sign in</RouterLink></p>
    </form>
    <div v-else class="card" role="status">
      <h1>Check your email</h1>
      <p>{{ notice }} It works once, within an hour.</p>
      <p><RouterLink :to="{ name: 'login' }">Back to sign in</RouterLink></p>
    </div>
  </main>
</template>
