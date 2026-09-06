import { apiClient, initializeCsrf } from './client.js'

export async function fetchAdminOverview() {
  const response = await apiClient.get('/admin/overview')
  return response.data.data.overview
}

export async function fetchAdminSystem() {
  const response = await apiClient.get('/admin/system')
  return response.data.data.system
}

export async function fetchAdminUsers(page = 1) {
  const response = await apiClient.get('/admin/users', { params: { page } })
  return response.data.data
}

export async function updateAdminUserRole(userId, role, confirmPassword) {
  const response = await apiClient.patch(`/admin/users/${userId}/role`, { role, confirm_password: confirmPassword })
  return response.data.data.user
}

export async function updateAdminUserStatus(userId, account_status, confirmPassword) {
  const response = await apiClient.patch(`/admin/users/${userId}/status`, { account_status, confirm_password: confirmPassword })
  return response.data.data.user
}

export async function fetchAdminProviders() {
  const response = await apiClient.get('/admin/providers')
  return response.data.data.providers
}

export async function fetchAdminRuns(page = 1) {
  const response = await apiClient.get('/admin/runs', { params: { page } })
  return response.data.data
}

export async function fetchAdminLogs(page = 1) {
  const response = await apiClient.get('/admin/logs', { params: { page } })
  return response.data.data
}

export async function fetchPlatformIntegrations() {
  const response = await apiClient.get('/admin/integrations')
  return response.data.data.integrations
}

export async function fetchPlatformIntegration(provider) {
  const response = await apiClient.get(`/admin/integrations/${provider}`)
  return response.data.data.integration
}

export async function updatePlatformIntegration(provider, credentials, configuration) {
  const response = await apiClient.put(`/admin/integrations/${provider}`, {
    credentials,
    configuration,
  })
  return response.data.data.integration
}

export async function testPlatformIntegration(provider) {
  const response = await apiClient.post(`/admin/integrations/${provider}/test`)
  return response.data.data
}

export async function enablePlatformIntegration(provider) {
  const response = await apiClient.post(`/admin/integrations/${provider}/enable`)
  return response.data.data.integration
}

export async function disablePlatformIntegration(provider) {
  const response = await apiClient.post(`/admin/integrations/${provider}/disable`)
  return response.data.data.integration
}

export async function removePlatformIntegrationCredentials(provider) {
  const response = await apiClient.delete(`/admin/integrations/${provider}/credentials`)
  return response.data.data.integration
}

export async function fetchAiProviderConfig() {
  const response = await apiClient.get('/admin/provider-config')
  return response.data.data
}

export async function updateAiProviderConfig(platformDefault, openaiDefaultModel, geminiDefaultModel) {
  const response = await apiClient.put('/admin/provider-config', {
    platform_default: platformDefault,
    openai_default_model: openaiDefaultModel,
    gemini_default_model: geminiDefaultModel,
  })
  return response.data.data
}

export async function testAiProviderConfig(provider) {
  const response = await apiClient.post(`/admin/provider-config/${provider}/test`)
  return response.data.data
}

export async function fetchProviderRouting() {
  const response = await apiClient.get('/admin/provider-routing')
  return response.data.data
}

export async function createProviderRoutingConfiguration(payload) {
  await initializeCsrf()
  const response = await apiClient.post('/admin/provider-routing/configurations', payload)
  return response.data.data.configuration
}

export async function testProviderRoutingConfiguration(id) {
  await initializeCsrf()
  const response = await apiClient.post(`/admin/provider-routing/configurations/${id}/test`)
  return response.data.data
}

export async function activateProviderRoutingConfiguration(id, payload) {
  await initializeCsrf()
  const response = await apiClient.post(`/admin/provider-routing/configurations/${id}/activate`, payload)
  return response.data.data.route
}

export async function deleteProviderRoutingConfiguration(id) {
  await initializeCsrf()
  await apiClient.delete(`/admin/provider-routing/configurations/${id}`)
}

export async function reorderProviderFallbacks(categoryId, routeIds) {
  await initializeCsrf()
  const response = await apiClient.put(`/admin/provider-routing/categories/${categoryId}/fallbacks`, { route_ids: routeIds })
  return response.data.data
}
