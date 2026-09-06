import { apiClient, initializeCsrf } from './client.js'

export async function listWorkflows(perPage = 20) {
  const response = await apiClient.get('/workflows', {
    params: { per_page: perPage },
  })

  return {
    workflows: response.data.data.workflows,
    meta: response.data.meta,
  }
}

export async function getWorkflow(workflowId) {
  const response = await apiClient.get(`/workflows/${workflowId}`)
  return response.data.data.workflow
}

export async function createWorkflow(payload) {
  await initializeCsrf()
  const response = await apiClient.post('/workflows', payload)
  return response.data.data.workflow
}

export async function updateWorkflow(workflowId, payload) {
  await initializeCsrf()
  const response = await apiClient.put(`/workflows/${workflowId}`, payload)
  return response.data.data.workflow
}

export async function deleteWorkflow(workflowId) {
  await initializeCsrf()
  await apiClient.delete(`/workflows/${workflowId}`)
}

export async function duplicateWorkflow(workflowId) {
  await initializeCsrf()
  const response = await apiClient.post(`/workflows/${workflowId}/duplicate`)
  return response.data.data.workflow
}

export async function validateWorkflow(workflowId) {
  await initializeCsrf()
  const response = await apiClient.post(`/workflows/${workflowId}/validate`)
  return response.data.data.validation
}
