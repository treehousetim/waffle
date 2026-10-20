<script setup>
import { onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'

import client, { unwrap } from '@/api/client'
import { useAuthStore } from '@/stores/auth'
import { apiErrorMessage } from '@/utils/errors'

/*
 * Accepting an invitation (docs/invitations.md): the link's token is the
 * permission. Makes the account, signs it in, and goes home.
 */

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()

const invitation = ref(null)
const name = ref('')
const password = ref('')
const error = ref('')
const busy = ref(false)
const token = String(route.params.token)

onMounted(async () => {
  try {
    invitation.value = unwrap(await client.get(`/invite/${encodeURIComponent(token)}`))
  } catch (e) {
    error.value = apiErrorMessage(e)
  }
})

async function accept() {
  busy.value = true
  error.value = ''

  try {
    await client.post(`/invite/${encodeURIComponent(token)}/accept`, { name: name.value, password: password.value })
    await auth.refresh()
    router.push({ name: 'home' })
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <main class="login">
    <form v-if="invitation" class="card" @submit.prevent="accept">
      <h1>Join Waffle</h1>
      <p>You were invited as {{ invitation.level_label.toLowerCase() }}, with {{ invitation.email }}.</p>
      <label>Your name <input v-model="name" autocomplete="name" maxlength="100" required autofocus /></label>
      <label>Choose a password <input v-model="password" type="password" autocomplete="new-password" minlength="12" required /></label>
      <p v-if="error" class="error" role="alert">{{ error }}</p>
      <button type="submit" :disabled="busy">{{ busy ? 'Joining…' : 'Join' }}</button>
    </form>
    <div v-else class="card">
      <h1>Join Waffle</h1>
      <p class="error" role="alert">{{ error || 'Checking the link…' }}</p>
    </div>
  </main>
</template>
