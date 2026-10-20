import { computed, ref } from 'vue'
import { defineStore } from 'pinia'

import client, { unwrap } from '@/api/client'

/**
 * Who is signed in, and what they may do.
 *
 * `user.permissions` is every permission value this person holds (their row
 * of the access grid, docs/access-levels.md), from /auth/me and the login
 * response.
 */
export const useAuthStore = defineStore('auth', () => {
  /** @type {import('vue').Ref<null | { id: number, email: string, username: string|null, role: string|null, role_label: string|null, permissions: string[] }>} */
  const user = ref(null)
  const checked = ref(false)

  const isAuthenticated = computed(() => user.value !== null)
  const permissions = computed(() => user.value?.permissions ?? [])

  /** Asks the server once per page load; the router guard awaits this. */
  async function check() {
    if (checked.value) {
      return
    }

    try {
      user.value = unwrap(await client.get('/auth/me'))
    } catch {
      user.value = null
    } finally {
      checked.value = true
    }
  }

  async function login(email, password) {
    user.value = unwrap(await client.post('/auth/login', { email, password }))
    checked.value = true
  }

  async function logout() {
    try {
      // An empty JSON body: the API takes no other kind (see api/client.js).
      await client.post('/auth/logout', {})
    } finally {
      forget()
    }
  }

  /** The session is gone: forget it here too, without asking the server. */
  function forget() {
    user.value = null
    checked.value = true
  }

  return { user, checked, isAuthenticated, permissions, check, login, logout, forget }
})
