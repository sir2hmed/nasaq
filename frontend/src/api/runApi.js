import { apiClient, applicationBaseUrl, initializeCsrf } from './client.js'

export async function listWorkflowRuns(perPage = 20) {
  const response = await apiClient.get('/runs', { params: { per_page: perPage } })
  return {
    runs: response.data.data.runs,
    meta: response.data.meta,
  }
}

export async function startWorkflowRun(workflowId, mode = 'demo') {
  await initializeCsrf()
  const response = await apiClient.post(`/workflows/${workflowId}/run`, {
    mode,
    idempotency_key: createIdempotencyKey(),
  })
  return response.data.data.run
}

export async function getRun(runId) {
  const response = await apiClient.get(`/runs/${runId}`)
  return response.data.data.run
}

export async function getRunLogs(runId) {
  const response = await apiClient.get(`/runs/${runId}/logs`)
  return response.data.data.logs
}

export async function getRunOutputs(runId) {
  const response = await apiClient.get(`/runs/${runId}/outputs`)
  return response.data.data.outputs
}

export async function cancelWorkflowRun(runId) {
  await initializeCsrf()
  const response = await apiClient.post(`/runs/${runId}/cancel`)
  return response.data.data.run
}

export async function approveWorkflowRun(runId, nodeKey, comment = '') {
  await initializeCsrf()
  const response = await apiClient.post(`/runs/${runId}/approval/${nodeKey}/approve`, {
    comment: comment.trim() || null,
  })
  return response.data.data.run
}

export async function rejectWorkflowRun(runId, nodeKey, comment = '') {
  await initializeCsrf()
  const response = await apiClient.post(`/runs/${runId}/approval/${nodeKey}/reject`, {
    comment: comment.trim() || null,
  })
  return response.data.data.run
}

export function absoluteDownloadUrl(relativeUrl) {
  return relativeUrl ? `${applicationBaseUrl}${relativeUrl}` : null
}

function createIdempotencyKey() {
  if (globalThis.crypto?.randomUUID) return globalThis.crypto.randomUUID()
  return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (character) => {
    const random = Math.floor(Math.random() * 16)
    const value = character === 'x' ? random : (random & 0x3) | 0x8
    return value.toString(16)
  })
}
