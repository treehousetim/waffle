<script setup>
import { computed } from 'vue'
import { RouterLink, RouterView, useRouter } from 'vue-router'

import { routes } from '@/router'
import { useAuthStore } from '@/stores/auth'
import { canSee } from '@/utils/permissions'

const auth = useAuthStore()
const router = useRouter()

/* eslint-disable-next-line no-undef */
const version = __APP_VERSION__

// The same rule as the router guard: a page the person may not open is not
// linked to either.
const links = computed(() =>
  routes.filter((route) => route.meta?.nav && canSee(route, auth.permissions))
)

async function signOut() {
  await auth.logout()
  router.push({ name: 'login' })
}
</script>

<template>
  <div v-if="auth.isAuthenticated" class="shell">
    <nav class="sidebar">
      <strong class="brand">Waffle</strong>
      <RouterLink v-for="link in links" :key="link.name" :to="{ name: link.name }">
        {{ link.meta.title }}
      </RouterLink>
      <div class="spacer" />
      <div class="who">
        <span>{{ auth.user.username || auth.user.email }}</span>
        <small>{{ auth.user.role_label || 'No access level' }}</small>
      </div>
      <button type="button" @click="signOut">Sign out</button>
      <small class="version">v{{ version }}</small>
    </nav>
    <main class="content">
      <RouterView />
    </main>
  </div>
  <RouterView v-else />
</template>
