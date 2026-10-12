import axios from 'axios'

import { isAuthEndpoint } from '@/utils/errors'

/**
 * The one Axios instance every API module uses.
 *
 * The API envelope is `{ success: true, data }` or `{ success: false, error }`,
 * so callers read `response.data.data` (see `unwrap`) and show
 * `apiErrorMessage(error)` on failure.
 */
const client = axios.create({
  baseURL: '/api',
  withCredentials: true,
  headers: {
    Accept: 'application/json'
  }
})

/**
 * Called with no arguments when the API answers 401 to anything but an auth
 * call -- the session has lapsed. The router installs a handler that sends the
 * person to sign in and brings them back afterwards.
 */
let onSessionLost = () => {}

export function whenSessionLost(handler) {
  onSessionLost = handler
}

client.interceptors.response.use(
  (response) => response,
  (error) => {
    // Auth endpoints answer 401 as a normal part of their contract (a wrong
    // password, the logged-out /auth/me probe); that is never a lapse.
    if (error?.response?.status === 401 && !isAuthEndpoint(error?.config?.url)) {
      onSessionLost()
    }

    return Promise.reject(error)
  }
)

/** The `data` of a successful envelope. */
export function unwrap(response) {
  return response?.data?.data ?? null
}

export default client
