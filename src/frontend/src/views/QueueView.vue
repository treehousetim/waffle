<script setup>
import { onMounted, ref } from 'vue'

import client, { unwrap } from '@/api/client'
import { duration } from '@/utils/duration'
import { apiErrorMessage } from '@/utils/errors'

/*
 * The queue, for the people who look after it (docs/queue.md, failure 20):
 * how much is waiting, and every job that failed its last attempt, with each
 * attempt's error. A failed job can be retried (it runs again from its first
 * attempt) or discarded. Behind `queue-manage`.
 */

const stats = ref(null)
const dead = ref([])
const error = ref('')
const busy = ref(null)
const notice = ref('')

async function load() {
  error.value = ''

  try {
    const data = unwrap(await client.get('/queue'))
    stats.value = data.stats
    dead.value = data.dead
  } catch (e) {
    error.value = apiErrorMessage(e)
  }
}

async function act(job, action) {
  busy.value = job.id
  notice.value = ''
  error.value = ''

  try {
    if (action === 'retry') {
      await client.post(`/queue/jobs/${job.id}/retry`)
      notice.value = `Job ${job.id} will run again shortly.`
    } else {
      await client.delete(`/queue/jobs/${job.id}`)
      notice.value = `Job ${job.id} was discarded.`
    }
    await load()
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    busy.value = null
  }
}

onMounted(load)
</script>

<template>
  <section>
    <h1>Queue</h1>
    <p v-if="error" class="error" role="alert">{{ error }}</p>
    <p v-if="notice" role="status">{{ notice }}</p>

    <div v-if="stats" class="card">
      <p>
        <strong>{{ stats.pending }}</strong> waiting ·
        <strong>{{ stats.running }}</strong> running ·
        <strong>{{ stats.dead }}</strong> failed
      </p>
      <p v-if="stats.oldest_due_seconds > 0">
        The oldest job due has waited {{ duration(stats.oldest_due_seconds) }}.
      </p>
    </div>

    <h2>Failed jobs</h2>
    <p v-if="stats && dead.length === 0">No failed jobs.</p>

    <article v-for="job in dead" :key="job.id" class="card">
      <h3>{{ job.type }} <small>#{{ job.id }}</small></h3>
      <p>Failed {{ job.failed_at }} after {{ job.attempts }} attempt(s). Queued {{ job.created_at }}.</p>
      <p class="error">{{ job.error }}</p>

      <details>
        <summary>Attempts</summary>
        <ol>
          <li v-for="run in job.runs" :key="run.attempt + run.started_at">
            {{ run.started_at }} · {{ run.outcome }} · {{ run.worker }}
            <span v-if="run.error"> · {{ run.error }}</span>
          </li>
        </ol>
      </details>

      <div class="actions">
        <button type="button" :disabled="busy === job.id" @click="act(job, 'retry')">Retry</button>
        <button type="button" :disabled="busy === job.id" @click="act(job, 'discard')">Discard</button>
      </div>
    </article>
  </section>
</template>
