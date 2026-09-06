import { apiClient, initializeCsrf } from './client.js'

function userFrom(response) {
  return response.data.data.user
}

export async function getCurrentUser() {
  return userFrom(await apiClient.get('/auth/me'))
}

export async function registerAccount(payload) {
  await initializeCsrf()
  return userFrom(await apiClient.post('/auth/register', payload))
}

export async function loginAccount(payload) {
  await initializeCsrf()
  return userFrom(await apiClient.post('/auth/login', payload))
}

export async function logoutAccount() {
  await initializeCsrf()
  await apiClient.post('/auth/logout')
}

export async function persistLocale(preferredLocale) {
  await initializeCsrf()
  return userFrom(
    await apiClient.patch('/auth/locale', {
      preferred_locale: preferredLocale,
    }),
  )
}
