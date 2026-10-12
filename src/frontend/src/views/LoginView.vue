<script setup>
import { ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'

import { useAuthStore } from '@/stores/auth'
import { apiErrorMessage } from '@/utils/errors'
import { safeNext } from '@/utils/permissions'

const auth = useAuthStore()
const route = useRoute()
const router = useRouter()

const email = ref('')
const password = ref('')
const error = ref('')
const busy = ref(false)

async function submit() {
  error.value = ''
  busy.value = true

  try {
    await auth.login(email.value, password.value)

    // Back to where the person was going, but only somewhere inside the app.
    const next = safeNext(route.query.next)
    router.push(next)
  } catch (e) {
    error.value = apiErrorMessage(e, 'Could not sign in.')
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <main class="login">
    <form class="card" @submit.prevent="submit">
      <h1>Sign in to Waffle</h1>

      <label>
        Email address
        <input v-model="email" type="email" autocomplete="username" required autofocus />
      </label>

      <label>
        Password
        <input v-model="password" type="password" autocomplete="current-password" required />
      </label>

      <p v-if="error" class="error" role="alert">{{ error }}</p>

      <button type="submit" :disabled="busy">{{ busy ? 'Signing in…' : 'Sign in' }}</button>
    </form>
  </main>
</template>
