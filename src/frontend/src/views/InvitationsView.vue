<script setup>
import { onMounted, ref } from 'vue'

import client, { unwrap } from '@/api/client'
import { apiErrorMessage } from '@/utils/errors'
import { invitationStatusLabel, inviteLink } from '@/utils/invitations'

/*
 * Inviting people (docs/invitations.md, `invitations-manage`). The link is
 * shown once, when the invitation is made: copy it and send it yourself.
 */

const invitations = ref([])
const levels = ref([])
const email = ref('')
const level = ref('')
const link = ref('')
const error = ref('')
const busy = ref(false)

async function load() {
  try {
    const data = unwrap(await client.get('/invitations'))
    invitations.value = data.invitations
    levels.value = data.levels
    level.value ||= data.levels.at(-1)?.value ?? ''
  } catch (e) {
    error.value = apiErrorMessage(e)
  }
}

async function invite() {
  busy.value = true
  error.value = ''
  link.value = ''

  try {
    const data = unwrap(await client.post('/invitations', { email: email.value, level: level.value }))
    link.value = inviteLink(window.location.origin, data.token)
    email.value = ''
    await load()
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    busy.value = false
  }
}

async function revoke(id) {
  busy.value = true
  error.value = ''

  try {
    await client.post(`/invitations/${id}/revoke`, {})
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
    <h1>Invitations</h1>
    <p v-if="error" class="error" role="alert">{{ error }}</p>

    <form class="card" @submit.prevent="invite">
      <h2>Invite someone</h2>
      <label>Email address <input v-model="email" type="email" required /></label>
      <label>
        As
        <select v-model="level">
          <option v-for="option in levels" :key="option.value" :value="option.value">{{ option.label }}</option>
        </select>
      </label>
      <button type="submit" :disabled="busy || levels.length === 0">Make the link</button>
    </form>

    <div v-if="link" class="card" role="status">
      <p>Send this link to them yourself. It works once, for seven days, and is not shown again:</p>
      <p><input :value="link" readonly @focus="$event.target.select()" /></p>
    </div>

    <h2>Sent</h2>
    <p v-if="invitations.length === 0">No invitations yet.</p>
    <table v-else>
      <thead><tr><th>Address</th><th>As</th><th>Status</th><th>Expires (UTC)</th><th /></tr></thead>
      <tbody>
        <tr v-for="invitation in invitations" :key="invitation.id">
          <td>{{ invitation.email }}</td>
          <td>{{ invitation.level_label }}</td>
          <td>{{ invitationStatusLabel(invitation.status) }}</td>
          <td>{{ invitation.expires_at }}</td>
          <td>
            <button v-if="invitation.status === 'pending'" type="button" :disabled="busy" @click="revoke(invitation.id)">Revoke</button>
          </td>
        </tr>
      </tbody>
    </table>
  </section>
</template>
