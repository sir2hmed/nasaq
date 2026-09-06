import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import PropTypes from 'prop-types'
import { Link, useParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import {
  addEdge,
  Background,
  BackgroundVariant,
  Controls,
  MarkerType,
  MiniMap,
  ReactFlow,
  useEdgesState,
  useNodesState,
} from '@xyflow/react'
import '@xyflow/react/dist/style.css'
import { getWorkflow, updateWorkflow, validateWorkflow } from '../api/workflowApi.js'
import {
  absoluteDownloadUrl,
  approveWorkflowRun,
  cancelWorkflowRun,
  getRun,
  getRunLogs,
  getRunOutputs,
  rejectWorkflowRun,
  startWorkflowRun,
} from '../api/runApi.js'
import AgentLibrary from '../workflows/AgentLibrary.jsx'
import AgentNode from '../workflows/AgentNode.jsx'
import NodeConfigurationPanel from '../workflows/NodeConfigurationPanel.jsx'
import {
  defaultConfig,
  isCompatible,
  validateGraph,
  wouldCreateCycle,
} from '../workflows/graphValidation.js'

const nodeTypes = { agent: AgentNode }

export default function WorkflowDetailPage() {
  const { workflowId } = useParams()
  const { t } = useTranslation()
  const canvasRef = useRef(null)
  const [workflow, setWorkflow] = useState(null)
  const [metadata, setMetadata] = useState({ name: '', description: '' })
  const [nodes, setNodes, applyNodeChanges] = useNodesState([])
  const [edges, setEdges, applyEdgeChanges] = useEdgesState([])
  const [instance, setInstance] = useState(null)
  const [selectedNodeId, setSelectedNodeId] = useState(null)
  const [validation, setValidation] = useState(null)
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [validating, setValidating] = useState(false)
  const [dirty, setDirty] = useState(false)
  const [error, setError] = useState('')
  const [saved, setSaved] = useState(false)
  const [run, setRun] = useState(null)
  const [runLogs, setRunLogs] = useState([])
  const [runOutputs, setRunOutputs] = useState([])
  const [startingMode, setStartingMode] = useState(null)
  const [runError, setRunError] = useState('')
  const [cancellingRun, setCancellingRun] = useState(false)
  const [decidingApproval, setDecidingApproval] = useState(null)
  const [moreOpen, setMoreOpen] = useState(false)

  const selectedNode = useMemo(
    () => nodes.find((node) => node.id === selectedNodeId) ?? null,
    [nodes, selectedNodeId],
  )

  useEffect(() => {
    let active = true
    async function load() {
      try {
        const result = await getWorkflow(workflowId)
        if (!active) return
        setWorkflow(result)
        setMetadata({ name: result.name, description: result.description ?? '' })
        setNodes(toFlowNodes(result.graph_json))
        setEdges(toFlowEdges(result.graph_json))
        setValidation(validateGraph(result.graph_json))
        if (result.latest_run_id) {
          try {
            const [latestRun, logs, outputs] = await Promise.all([
              getRun(result.latest_run_id),
              getRunLogs(result.latest_run_id),
              getRunOutputs(result.latest_run_id),
            ])
            if (!active) return
            setRun(latestRun)
            setRunLogs(logs)
            setRunOutputs(outputs)
            setNodes((current) => current.map((node) => ({
              ...node,
              data: {
                ...node.data,
                status: latestRun.node_statuses?.[node.id] ?? 'pending',
              },
            })))
          } catch {
            if (active) setRunError(t('execution.pollError'))
          }
        }
      } catch {
        if (active) setError(t('workflows.detailLoadError'))
      } finally {
        if (active) setLoading(false)
      }
    }
    load()
    return () => { active = false }
  }, [setEdges, setNodes, t, workflowId])

  useEffect(() => {
    if (!dirty) return undefined

    const beforeUnload = (event) => {
      event.preventDefault()
      event.returnValue = ''
    }
    const protectNavigation = (event) => {
      const anchor = event.target.closest?.('a')
      if (!anchor || anchor.origin !== window.location.origin) return
      if (!window.confirm(t('canvas.unsavedConfirm'))) {
        event.preventDefault()
        event.stopImmediatePropagation()
      }
    }
    window.addEventListener('beforeunload', beforeUnload)
    document.addEventListener('click', protectNavigation, true)
    return () => {
      window.removeEventListener('beforeunload', beforeUnload)
      document.removeEventListener('click', protectNavigation, true)
    }
  }, [dirty, t])

  useEffect(() => {
    if (!run?.id) return undefined
    let active = true
    let timer

    async function poll() {
      try {
        const [latestRun, logs, outputs] = await Promise.all([
          getRun(run.id),
          getRunLogs(run.id),
          getRunOutputs(run.id),
        ])
        if (!active) return
        setRun(latestRun)
        setRunLogs(logs)
        setRunOutputs(outputs)
        setRunError('')
        setNodes((current) => current.map((node) => ({
          ...node,
          data: {
            ...node.data,
            status: latestRun.node_statuses?.[node.id] ?? 'pending',
          },
        })))
        if (!['success', 'failed', 'cancelled'].includes(latestRun.status)) {
          timer = window.setTimeout(poll, 1000)
        }
      } catch {
        if (active) {
          setRunError(t('execution.pollError'))
          timer = window.setTimeout(poll, 2000)
        }
      }
    }

    void poll()
    return () => {
      active = false
      window.clearTimeout(timer)
    }
  }, [run?.id, setNodes, t])

  const currentGraph = useCallback(() => ({
    version: 1,
    name: metadata.name.trim(),
    description: metadata.description.trim() || null,
    nodes: nodes.map((node) => ({
      id: node.id,
      type: node.data.agentType,
      position: { x: node.position.x, y: node.position.y },
      config: node.data.config,
    })),
    edges: edges.map((edge) => ({
      id: edge.id,
      source: edge.source,
      target: edge.target,
    })),
  }), [edges, metadata, nodes])

  function markChanged() {
    setDirty(true)
    setSaved(false)
  }

  function handleNodeChanges(changes) {
    applyNodeChanges(changes)
    if (changes.some((change) => !['select', 'dimensions'].includes(change.type))) markChanged()
  }

  function handleEdgeChanges(changes) {
    applyEdgeChanges(changes)
    if (changes.some((change) => change.type !== 'select')) markChanged()
  }

  function connectionAllowed(connection) {
    if (!connection.source || !connection.target || connection.source === connection.target) return false
    const source = nodes.find((node) => node.id === connection.source)
    const target = nodes.find((node) => node.id === connection.target)
    if (!source || !target || !isCompatible(source.data.agentType, target.data.agentType)) return false
    return !wouldCreateCycle(
      nodes.map(toGraphNode),
      edges.map(toGraphEdge),
      connection,
    )
  }

  function handleConnect(connection) {
    if (!connectionAllowed(connection)) {
      setError(t('canvas.connectionRejected'))
      return
    }

    setEdges((current) => addEdge({
      ...connection,
      id: createIdentifier('edge'),
      markerEnd: { type: MarkerType.ArrowClosed },
    }, current))
    setError('')
    markChanged()
  }

  function addAgent(agentType, position) {
    const id = createIdentifier(agentType)
    const fallbackIndex = nodes.length
    const node = {
      id,
      type: 'agent',
      position: position ?? { x: 120 + fallbackIndex * 70, y: 120 + fallbackIndex * 45 },
      data: { agentType, config: defaultConfig(agentType), status: 'idle' },
    }
    setNodes((current) => [...current, node])
    setSelectedNodeId(id)
    markChanged()
  }

  function handleDrop(event) {
    event.preventDefault()
    const agentType = event.dataTransfer.getData('application/nasaq-agent')
    if (!agentType || !instance) return
    addAgent(agentType, instance.screenToFlowPosition({ x: event.clientX, y: event.clientY }))
  }

  function updateSelectedConfig(config) {
    setNodes((current) => current.map((node) => (
      node.id === selectedNodeId ? { ...node, data: { ...node.data, config } } : node
    )))
    markChanged()
  }

  function deleteSelectedNode() {
    if (!selectedNodeId) return
    setNodes((current) => current.filter((node) => node.id !== selectedNodeId))
    setEdges((current) => current.filter(
      (edge) => edge.source !== selectedNodeId && edge.target !== selectedNodeId,
    ))
    setSelectedNodeId(null)
    markChanged()
  }

  function duplicateSelectedNode() {
    if (!selectedNode) return
    const id = createIdentifier(selectedNode.data.agentType)
    setNodes((current) => [...current, {
      ...selectedNode,
      id,
      selected: false,
      position: { x: selectedNode.position.x + 48, y: selectedNode.position.y + 48 },
      data: { ...selectedNode.data, config: structuredClone(selectedNode.data.config) },
    }])
    setSelectedNodeId(id)
    markChanged()
  }

  function runLocalValidation() {
    const result = validateGraph(currentGraph())
    setValidation(result)
    return result
  }

  async function handleValidate() {
    setValidating(true)
    setError('')
    const local = runLocalValidation()
    try {
      if (!dirty && local.errors.length === 0) {
        setValidation(await validateWorkflow(workflow.id))
      }
    } catch {
      setError(t('canvas.validationError'))
    } finally {
      setValidating(false)
    }
  }

  async function handleSave() {
    const graph = currentGraph()
    const result = validateGraph(graph)
    setValidation(result)
    if (result.errors.length > 0) {
      setError(t('canvas.fixErrorsBeforeSave'))
      return
    }

    setSaving(true)
    setSaved(false)
    setError('')
    try {
      const updated = await updateWorkflow(workflow.id, {
        name: graph.name,
        description: graph.description,
        status: workflow.status,
        graph_json: graph,
      })
      setWorkflow(updated)
      setMetadata({ name: updated.name, description: updated.description ?? '' })
      setDirty(false)
      setSaved(true)
      setValidation(validateGraph(updated.graph_json))
    } catch {
      setError(t('workflows.saveError'))
    } finally {
      setSaving(false)
    }
  }

  async function handleRun(mode) {
    const result = runLocalValidation()
    if (dirty) {
      setRunError(t('execution.saveFirst'))
      return
    }
    if (!result.valid) {
      setRunError(t('execution.configureFirst'))
      return
    }

    setStartingMode(mode)
    setRunError('')
    setRunLogs([])
    setRunOutputs([])
    try {
      const started = await startWorkflowRun(workflow.id, mode)
      setRun(started)
      setNodes((current) => current.map((node) => ({
        ...node,
        data: { ...node.data, status: started.node_statuses?.[node.id] ?? 'pending' },
      })))
    } catch {
      setRunError(t('execution.startError'))
    } finally {
      setStartingMode(null)
    }
  }

  async function handleCancelRun() {
    if (!run?.id || ['success', 'failed', 'cancelled'].includes(run.status)) return
    setCancellingRun(true)
    setRunError('')
    try {
      setRun(await cancelWorkflowRun(run.id))
    } catch {
      setRunError(t('execution.cancelError'))
    } finally {
      setCancellingRun(false)
    }
  }

  async function handleApprovalDecision(decision, nodeKey, comment) {
    if (!run?.id) return
    setDecidingApproval(decision)
    setRunError('')
    try {
      const decide = decision === 'approve' ? approveWorkflowRun : rejectWorkflowRun
      setRun(await decide(run.id, nodeKey, comment))
    } catch {
      setRunError(t('execution.approvalError'))
    } finally {
      setDecidingApproval(null)
    }
  }

  if (loading) {
    return <div className="workspace-card loading-panel" role="status">{t('workflows.loadingDetail')}</div>
  }

  if (!workflow) {
    return (
      <div className="workspace-card empty-state large-empty">
        <span className="empty-icon" aria-hidden="true">!</span>
        <h1>{t('workflows.unavailableTitle')}</h1>
        <p>{error}</p>
        <Link className="button button-primary" to="/workflows">{t('workflows.backToList')}</Link>
      </div>
    )
  }

  return (
    <div className="workflow-editor-page">
      <header className="workflow-toolbar">
        <div className="toolbar-identity">
          <Link className="icon-button toolbar-back" to="/workflows" aria-label={t('workflows.backToList')}>←</Link>
          <label className="toolbar-name-field">
            <span>{t('workflows.nameLabel')}</span>
            <input
              value={metadata.name}
              maxLength={160}
              required
              onChange={(event) => {
                setMetadata((current) => ({ ...current, name: event.target.value }))
                markChanged()
              }}
            />
          </label>
          <span className={`save-state ${dirty ? 'is-dirty' : ''}`}>
            {dirty ? t('canvas.unsaved') : t('canvas.saved')}
          </span>
        </div>
        <div className="toolbar-actions">
          <button className="button button-quiet button-small" type="button" onClick={() => instance?.fitView({ padding: 0.2 })}>
            {t('canvas.fitView')}
          </button>
          <button className="button button-secondary button-small" type="button" disabled={validating} onClick={handleValidate}>
            {validating ? t('canvas.validating') : t('canvas.validate')}
          </button>
          <button className="button button-primary button-small" type="button" disabled={saving || !metadata.name.trim()} onClick={handleSave}>
            {saving ? t('common.saving') : t('workflows.saveWorkflow')}
          </button>
          <button
            className="button button-run button-small"
            type="button"
            disabled={startingMode !== null || dirty}
            onClick={() => handleRun('demo')}
          >
            {startingMode === 'demo' ? t('execution.starting') : t('execution.runDemo')}
          </button>
          <button
            className="button button-secondary button-small"
            type="button"
            disabled={startingMode !== null || dirty}
            onClick={() => handleRun('real')}
          >
            {startingMode === 'real' ? t('execution.starting') : t('execution.runReal')}
          </button>
          <div className="toolbar-more">
            <button
              className="button button-quiet button-small toolbar-more-toggle"
              type="button"
              aria-expanded={moreOpen}
              aria-haspopup="true"
              onClick={() => setMoreOpen((current) => !current)}
            >
              {t('canvas.more')}
            </button>
            {moreOpen && (
              <div className="toolbar-more-menu">
                <button
                  type="button"
                  disabled={!selectedNode}
                  onClick={() => { duplicateSelectedNode(); setMoreOpen(false) }}
                >
                  {t('canvas.duplicateSelected')}
                </button>
                <button
                  type="button"
                  disabled={!selectedNode}
                  onClick={() => { setSelectedNodeId(null); setMoreOpen(false) }}
                >
                  {t('canvas.clearSelection')}
                </button>
                <button
                  type="button"
                  disabled={!selectedNode}
                  onClick={() => { deleteSelectedNode(); setMoreOpen(false) }}
                >
                  {t('canvas.deleteSelected')}
                </button>
                {run ? (
                  <a href="#execution-title" onClick={() => setMoreOpen(false)}>
                    {t('canvas.openExecution')}
                  </a>
                ) : (
                  <button type="button" disabled>{t('canvas.openExecution')}</button>
                )}
              </div>
            )}
          </div>
        </div>
      </header>

      {error && <div className="form-alert canvas-alert" role="alert">{error}</div>}
      {saved && <div className="success-alert canvas-alert" role="status">{t('workflows.savedMessage')}</div>}

      <div className="workflow-builder">
        <AgentLibrary onAdd={addAgent} />

        <section
          className="workflow-canvas"
          ref={canvasRef}
          aria-label={t('canvas.workflowCanvas')}
          onDrop={handleDrop}
          onDragOver={(event) => {
            event.preventDefault()
            event.dataTransfer.dropEffect = 'move'
          }}
        >
          <ReactFlow
            nodes={nodes}
            edges={edges}
            nodeTypes={nodeTypes}
            onInit={setInstance}
            onNodesChange={handleNodeChanges}
            onEdgesChange={handleEdgeChanges}
            onConnect={handleConnect}
            isValidConnection={connectionAllowed}
            onNodeClick={(_event, node) => setSelectedNodeId(node.id)}
            onPaneClick={() => setSelectedNodeId(null)}
            onNodesDelete={(deleted) => {
              const ids = new Set(deleted.map((node) => node.id))
              setEdges((current) => current.filter((edge) => !ids.has(edge.source) && !ids.has(edge.target)))
              setSelectedNodeId(null)
              markChanged()
            }}
            deleteKeyCode={['Backspace', 'Delete']}
            fitView
            minZoom={0.25}
            maxZoom={1.8}
          >
            <Background color="rgba(174, 192, 187, 0.14)" gap={24} variant={BackgroundVariant.Dots} />
            <Controls showInteractive={false} />
            <MiniMap
              nodeColor={(node) => ({
                researcher: '#d8ad5f',
                writer: '#7aa79b',
                video: '#6f8eb4',
                approval: '#c58c8c',
                publisher: '#8ab49e',
                email: '#a88fb8',
                export: '#91aaa4',
              }[node.data.agentType])}
              maskColor="rgba(5, 14, 13, 0.72)"
              pannable
              zoomable
            />
          </ReactFlow>
        </section>

        <NodeConfigurationPanel
          node={selectedNode}
          onChange={updateSelectedConfig}
          onDelete={deleteSelectedNode}
          onDuplicate={duplicateSelectedNode}
        />
      </div>

      <ValidationDrawer validation={validation} t={t} />
      <ExecutionPanel
        run={run}
        logs={runLogs}
        outputs={runOutputs}
        error={runError}
        cancelling={cancellingRun}
        decidingApproval={decidingApproval}
        onCancel={handleCancelRun}
        onApprovalDecision={handleApprovalDecision}
        t={t}
      />
    </div>
  )
}

function ExecutionPanel({
  run,
  logs,
  outputs,
  error,
  cancelling,
  decidingApproval,
  onCancel,
  onApprovalDecision,
  t,
}) {
  if (!run && !error) return null

  return (
    <section
      aria-busy={Boolean(run && !['success', 'failed', 'cancelled', 'waiting_for_approval'].includes(run.status))}
      aria-labelledby="execution-title"
      className="execution-panel"
    >
      <header className="execution-heading">
        <div>
          <span className="eyebrow">
            {run?.demo_mode ? t('execution.demoMode') : t('execution.realMode')}
          </span>
          <h2 id="execution-title">{t('execution.title')}</h2>
        </div>
        {run && (
          <div className="execution-status-actions" aria-live="polite">
            <span className={`run-status status-${run.status}`}>{t(`status.${run.status}`)}</span>
            {!['success', 'failed', 'cancelled', 'waiting_for_approval'].includes(run.status) && (
              <button
                className="button button-secondary button-small"
                type="button"
                disabled={cancelling || run.cancellation_requested}
                onClick={onCancel}
              >
                {run.cancellation_requested
                  ? t('execution.cancellationRequested')
                  : cancelling ? t('execution.cancelling') : t('execution.cancelRun')}
              </button>
            )}
          </div>
        )}
      </header>

      {error && <div className="form-alert" role="alert">{error}</div>}
      {run?.approvals?.find((approval) => approval.status === 'pending') && (
        <ApprovalReview
          approval={run.approvals.find((item) => item.status === 'pending')}
          deciding={decidingApproval}
          onDecision={onApprovalDecision}
          t={t}
        />
      )}
      {run && (
        <div className="execution-summary">
          <span>{t('execution.runId')}</span>
          <code dir="ltr">{run.id}</code>
          {run.current_node_key && (
            <><span>{t('execution.currentNode')}</span><code dir="ltr">{run.current_node_key}</code></>
          )}
        </div>
      )}

      <div className="execution-columns">
        <div className="execution-section">
          <h3>{t('execution.logs')}</h3>
          {logs.length === 0 ? <p>{t('execution.waitingForLogs')}</p> : (
            <ol className="execution-log-list" role="log" aria-live="polite" aria-relevant="additions">
              {logs.map((log) => (
                <li key={log.id} className={`log-${log.level}`}>
                  <time>{formatTime(log.occurred_at)}</time>
                  <span>{log.message}</span>
                  {log.node_key && <code dir="ltr">{log.node_key}</code>}
                </li>
              ))}
            </ol>
          )}
        </div>

        <div className="execution-section">
          <h3>{t('execution.outputs')}</h3>
          {outputs.length === 0 ? <p>{t('execution.waitingForOutputs')}</p> : (
            <div className="output-list" aria-live="polite" aria-relevant="additions">
              {outputs.map((output) => (
                <article className="output-card" key={output.id}>
                  <div>
                    <strong>{t(`agents.${output.agent_type}`)}</strong>
                    <code dir="ltr">{output.node_key}</code>
                  </div>
                  {output.text_content && <p className="output-preview">{output.text_content}</p>}
                  {!output.text_content && output.content?.summary && <p>{output.content.summary}</p>}
                  {output.agent_type === 'researcher' && output.content && (
                    <div className="research-output">
                      {output.content.demo_notice && (
                        <span className="output-badge">{t('execution.simulated')}</span>
                      )}
                      {output.content.key_points?.length > 0 && (
                        <ul>{output.content.key_points.map((point) => <li key={point}>{point}</li>)}</ul>
                      )}
                      {output.content.sources?.length > 0 && (
                        <div className="source-card-list">
                          {output.content.sources.map((source, index) => {
                            const sourceUrl = safeExternalUrl(source.url)
                            const contents = (
                              <>
                                <strong>{source.title}</strong>
                                <span>{source.snippet}</span>
                              </>
                            )
                            return sourceUrl ? (
                              <a href={sourceUrl} key={`${sourceUrl}-${index}`} rel="noreferrer" target="_blank">
                                {contents}
                              </a>
                            ) : (
                              <div className="source-card" key={`${source.title}-${index}`}>{contents}</div>
                            )
                          })}
                        </div>
                      )}
                    </div>
                  )}
                  {output.text_content && (
                    <div className="output-actions">
                      <button
                        className="button button-quiet button-small"
                        onClick={() => copyText(output.text_content)}
                        type="button"
                      >
                        {t('execution.copyText')}
                      </button>
                      <a
                        className="button button-secondary button-small"
                        download={`${output.node_key}.txt`}
                        href={`data:text/plain;charset=utf-8,${encodeURIComponent(output.text_content)}`}
                      >
                        {t('execution.downloadText')}
                      </a>
                    </div>
                  )}
                  {output.agent_type === 'publisher' && output.content && (
                    <dl className="output-details">
                      <div><dt>{t('execution.provider')}</dt><dd>{output.content.provider}</dd></div>
                      <div><dt>{t('execution.providerId')}</dt><dd><code>{output.content.resource_id}</code></dd></div>
                      <div>
                        <dt>{t('execution.externalUrl')}</dt>
                        <dd>
                          {safeExternalUrl(output.public_url ?? output.content.url) && (
                            <a
                              href={safeExternalUrl(output.public_url ?? output.content.url)}
                              rel="noreferrer"
                              target="_blank"
                            >
                              {t('execution.openPublishedOutput')}
                            </a>
                          )}
                        </dd>
                      </div>
                      {output.content.simulated && <span className="output-badge">{t('execution.simulated')}</span>}
                    </dl>
                  )}
                  {output.agent_type === 'email' && output.content && (
                    <dl className="output-details">
                      <div>
                        <dt>{t('execution.recipients')}</dt>
                        <dd>{output.content.recipients?.join(', ')}</dd>
                      </div>
                      <div><dt>{t('execution.subject')}</dt><dd>{output.content.subject}</dd></div>
                      <div>
                        <dt>{t('execution.deliveryStatus')}</dt>
                        <dd>{t(`execution.delivery.${output.content.delivery_status}`)}</dd>
                      </div>
                      {output.content.simulated && <span className="output-badge">{t('execution.simulated')}</span>}
                    </dl>
                  )}
                  {output.mime_type === 'video/mp4' && output.stream_url && (
                    <video
                      className="output-video"
                      controls
                      preload="metadata"
                      src={absoluteDownloadUrl(output.stream_url)}
                      aria-label={t('execution.videoPreview')}
                    />
                  )}
                  {output.download_url && (
                    <>
                      <div className="file-facts">
                        <span>{output.mime_type}</span>
                        {output.file_size && <span>{formatBytes(output.file_size)}</span>}
                      </div>
                      <a className="button button-secondary button-small" href={absoluteDownloadUrl(output.download_url)}>
                        {t('execution.download', { file: output.file_name })}
                      </a>
                    </>
                  )}
                </article>
              ))}
            </div>
          )}
        </div>
      </div>
    </section>
  )
}

ExecutionPanel.propTypes = {
  run: PropTypes.shape({
    id: PropTypes.string.isRequired,
    status: PropTypes.string.isRequired,
    current_node_key: PropTypes.string,
    cancellation_requested: PropTypes.bool,
    demo_mode: PropTypes.bool,
    approvals: PropTypes.arrayOf(PropTypes.shape({
      node_key: PropTypes.string.isRequired,
      status: PropTypes.string.isRequired,
      preview_output: PropTypes.shape({ text_content: PropTypes.string }),
    })),
  }),
  logs: PropTypes.arrayOf(PropTypes.object).isRequired,
  outputs: PropTypes.arrayOf(PropTypes.object).isRequired,
  error: PropTypes.string.isRequired,
  cancelling: PropTypes.bool.isRequired,
  decidingApproval: PropTypes.string,
  onCancel: PropTypes.func.isRequired,
  onApprovalDecision: PropTypes.func.isRequired,
  t: PropTypes.func.isRequired,
}

ExecutionPanel.defaultProps = {
  run: null,
  decidingApproval: null,
}

function ApprovalReview({ approval, deciding, onDecision, t }) {
  const [comment, setComment] = useState('')
  const preview = approval.preview_output

  return (
    <section className="approval-review" aria-labelledby="approval-review-title">
      <div>
        <span className="eyebrow">{t('execution.humanReview')}</span>
        <h3 id="approval-review-title">{t('execution.approvalTitle')}</h3>
        <p>{t('execution.approvalHelp', { node: approval.node_key })}</p>
      </div>
      {preview?.text_content && <p className="approval-preview">{preview.text_content}</p>}
      <label className="form-field compact-field">
        <span>{t('execution.reviewComment')}</span>
        <textarea
          maxLength={2000}
          value={comment}
          onChange={(event) => setComment(event.target.value)}
        />
      </label>
      <div className="approval-actions">
        <button
          className="button button-primary button-small"
          type="button"
          disabled={deciding !== null}
          onClick={() => onDecision('approve', approval.node_key, comment)}
        >
          {deciding === 'approve' ? t('execution.approving') : t('execution.approve')}
        </button>
        <button
          className="button button-danger button-small"
          type="button"
          disabled={deciding !== null}
          onClick={() => onDecision('reject', approval.node_key, comment)}
        >
          {deciding === 'reject' ? t('execution.rejecting') : t('execution.reject')}
        </button>
      </div>
    </section>
  )
}

ApprovalReview.propTypes = {
  approval: PropTypes.shape({
    node_key: PropTypes.string.isRequired,
    preview_output: PropTypes.shape({ text_content: PropTypes.string }),
  }).isRequired,
  deciding: PropTypes.string,
  onDecision: PropTypes.func.isRequired,
  t: PropTypes.func.isRequired,
}

ApprovalReview.defaultProps = {
  deciding: null,
}

function formatTime(value) {
  if (!value) return '—'
  return new Intl.DateTimeFormat(undefined, {
    hour: '2-digit',
    minute: '2-digit',
    second: '2-digit',
  }).format(new Date(value))
}

async function copyText(value) {
  if (navigator.clipboard?.writeText) {
    await navigator.clipboard.writeText(value)
    return
  }
  const field = document.createElement('textarea')
  field.value = value
  field.style.position = 'fixed'
  field.style.opacity = '0'
  document.body.appendChild(field)
  field.select()
  document.execCommand('copy')
  field.remove()
}

function formatBytes(value) {
  if (value < 1024) return `${value} B`
  if (value < 1024 * 1024) return `${(value / 1024).toFixed(1)} KB`
  return `${(value / (1024 * 1024)).toFixed(1)} MB`
}

function safeExternalUrl(value) {
  try {
    const url = new URL(value)
    return ['http:', 'https:'].includes(url.protocol) ? url.toString() : null
  } catch {
    return null
  }
}

function ValidationDrawer({ validation, t }) {
  const errors = validation?.errors ?? []
  const warnings = validation?.warnings ?? []

  return (
    <section className="validation-drawer" aria-labelledby="validation-title" aria-live="polite">
      <div>
        <span className={`validation-indicator ${errors.length ? 'has-errors' : warnings.length ? 'has-warnings' : 'is-valid'}`} aria-hidden="true" />
        <span>
          <strong id="validation-title">{t('canvas.validationTitle')}</strong>
          <small>{errors.length || warnings.length
            ? t('canvas.issueCount', { count: errors.length + warnings.length })
            : t('canvas.ready')}</small>
        </span>
      </div>
      <ul>
        {[...errors, ...warnings].map((item, index) => (
          <li className={index < errors.length ? 'validation-error' : 'validation-warning'} key={`${item.code}-${item.nodeId ?? item.edgeId ?? index}-${item.field ?? index}`}>
            <span>{index < errors.length ? '!' : '△'}</span>
            <span>
              {t(`canvas.validationIssues.${item.code}`, {
                node: item.nodeId,
                field: item.field ? t(`canvas.fieldNames.${item.field}`) : '',
              })}
              {item.nodeId && <code dir="ltr">{item.nodeId}</code>}
            </span>
          </li>
        ))}
      </ul>
    </section>
  )
}

ValidationDrawer.propTypes = {
  validation: PropTypes.shape({
    errors: PropTypes.arrayOf(PropTypes.object).isRequired,
    warnings: PropTypes.arrayOf(PropTypes.object).isRequired,
  }),
  t: PropTypes.func.isRequired,
}

ValidationDrawer.defaultProps = {
  validation: null,
}

function toFlowNodes(graph) {
  return graph.nodes.map((node) => ({
    id: node.id,
    type: 'agent',
    position: node.position,
    data: { agentType: node.type, config: node.config, status: 'idle' },
  }))
}

function toFlowEdges(graph) {
  return graph.edges.map((edge) => ({
    ...edge,
    markerEnd: { type: MarkerType.ArrowClosed },
  }))
}

function toGraphNode(node) {
  return { id: node.id, type: node.data.agentType, position: node.position, config: node.data.config }
}

function toGraphEdge(edge) {
  return { id: edge.id, source: edge.source, target: edge.target }
}

function createIdentifier(prefix) {
  return `${prefix}_${Date.now().toString(36)}_${Math.random().toString(36).slice(2, 7)}`
}
