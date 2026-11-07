<script setup>
import { onMounted, ref } from 'vue'

import client, { unwrap } from '@/api/client'
import { apiErrorMessage } from '@/utils/errors'
import { invitationStatusLabel } from '@/utils/invitations'

/*
 * Inviting people (docs/invitations.md, `invitations-manage`). The link goes
 * to them by email, and is never shown here; "Send again" emails a new one.
 */

const invitations = ref([])
const levels = ref([])
const email = ref('')
const level = ref('')
const notice = ref('')
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
  notice.value = ''

  try {
    const data = unwrap(await client.post('/invitations', { email: email.value, level: level.value }))
    notice.value = `Invitation emailed to ${data.invitation.email}. The link works once, for seven days.`
    email.value = ''
    await load()
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    busy.value = false
  }
}

async function sendAgain(invitation) {
  busy.value = true
  error.value = ''
  notice.value = ''

  try {
    await client.post(`/invitations/${invitation.id}/send`, {})
    notice.value = `Emailed ${invitation.email} a new link. The earlier one no longer works.`
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
      <button type="submit" :disabled="busy || levels.length === 0">Email the invitation</button>
    </form>

    <p v-if="notice" class="card" role="status">{{ notice }}</p>

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
            <template v-if="invitation.status === 'pending'">
              <button type="button" :disabled="busy" @click="sendAgain(invitation)">Send again</button>
              <button type="button" :disabled="busy" @click="revoke(invitation.id)">Revoke</button>
            </template>
          </td>
        </tr>
      </tbody>
    </table>
  </section>
</template>
