import { createRouter, createWebHistory } from 'vue-router'

import { whenSessionLost } from '@/api/client'
import { useAuthStore } from '@/stores/auth'
import { canSee } from '@/utils/permissions'

/*
 * `meta.permission` names the permission value (docs/access-levels.md) a page
 * needs. The guard below sends anybody without it home, and the navigation in
 * App.vue reads the same field, so a link and its page cannot disagree.
 */
export const routes = [
  {
    path: '/login',
    name: 'login',
    component: () => import('@/views/LoginView.vue'),
    meta: { public: true, title: 'Sign in' }
  },
  {
    // An invitation link (docs/invitations.md): open to anybody holding it.
    path: '/invite/:token',
    name: 'invite',
    component: () => import('@/views/AcceptInviteView.vue'),
    meta: { public: true, title: 'Join' }
  },
  {
    path: '/',
    name: 'home',
    component: () => import('@/views/HelloView.vue'),
    meta: { title: 'Home', permission: 'home', nav: true }
  },
  {
    path: '/settings',
    name: 'settings',
    component: () => import('@/views/SettingsView.vue'),
    meta: { title: 'Settings', permission: 'settings-view', nav: true }
  },
  {
    path: '/posts',
    name: 'posts',
    component: () => import('@/views/ContentView.vue'),
    props: { type: 'posts', label: 'Posts' },
    meta: { title: 'Posts', permission: 'content-edit', nav: true }
  },
  {
    path: '/invitations',
    name: 'invitations',
    component: () => import('@/views/InvitationsView.vue'),
    meta: { title: 'Invitations', permission: 'invitations-manage', nav: true }
  },
  {
    path: '/queue',
    name: 'queue',
    component: () => import('@/views/QueueView.vue'),
    meta: { title: 'Queue', permission: 'queue-manage', nav: true }
  },
  {
    path: '/:pathMatch(.*)*',
    redirect: { name: 'home' }
  }
]

const router = createRouter({
  history: createWebHistory(),
  routes
})

router.beforeEach(async (to) => {
  const auth = useAuthStore()
  await auth.check()

  if (to.meta.public) {
    return auth.isAuthenticated && to.name === 'login' ? { name: 'home' } : true
  }

  if (!auth.isAuthenticated) {
    return { name: 'login', query: { next: to.fullPath } }
  }

  if (!canSee(to, auth.permissions)) {
    return to.name === 'home' ? true : { name: 'home' }
  }

  return true
})

router.afterEach((to) => {
  document.title = to.meta.title ? `${to.meta.title} · Waffle` : 'Waffle'
})

// A 401 from anything but an auth call: the session lapsed. Sign in again and
// come back to the same page.
whenSessionLost(() => {
  const auth = useAuthStore()
  auth.forget()

  const current = router.currentRoute.value

  if (!current.meta.public) {
    router.push({ name: 'login', query: { next: current.fullPath } })
  }
})

export default router
