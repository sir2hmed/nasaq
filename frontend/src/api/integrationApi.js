import { apiClient, initializeCsrf } from './client.js'

export async function listIntegrations() {
  const response = await apiClient.get('/integrations')
  return response.data.data.integrations
}

export async function connectIntegration(provider, credentials) {
  await initializeCsrf()
  const response = await apiClient.post(`/integrations/${provider}/connect`, { credentials })
  return response.data.data.integration
}

export async function disconnectIntegration(provider) {
  await initializeCsrf()
  await apiClient.delete(`/integrations/${provider}`)
}
