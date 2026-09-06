import { useCallback, useEffect, useState } from 'react'
import { Link, useNavigate, useSearchParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import {
  createWorkflow,
  deleteWorkflow,
  duplicateWorkflow,
  listWorkflows,
} from '../api/workflowApi.js'
import { createTemplateGraph, workflowTemplates } from '../workflows/workflowTemplates.js'

export default function WorkflowsPage() {
  const { t, i18n } = useTranslation()
  const navigate = useNavigate()
  const [searchParams, setSearchParams] = useSearchParams()
  const [workflows, setWorkflows] = useState([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [busyId, setBusyId] = useState(null)
  const [submitting, setSubmitting] = useState(false)
  const [formOpen, setFormOpen] = useState(searchParams.get('new') === 'sample')
  const [form, setForm] = useState({
    templateId: 'research_article',
    name: t('dashboard.sampleName'),
    description: t('dashboard.sampleCopy'),
  })

  const load = useCallback(async () => {
    setLoading(true)
    setError('')
    try {
      const result = await listWorkflows()
      setWorkflows(result.workflows)
    } catch {
      setError(t('workflows.loadError'))
    } finally {
      setLoading(false)
    }
  }, [t])

  useEffect(() => {
    load()
  }, [load])

  function openForm() {
    setFormOpen(true)
    setSearchParams({ new: 'sample' })
  }

  function closeForm() {
    setFormOpen(false)
    setSearchParams({})
  }

  function selectTemplate(templateId) {
    setForm({
      templateId,
      name: t(`templates.${templateId}.name`),
      description: t(`templates.${templateId}.description`),
    })
  }

  async function handleCreate(event) {
    event.preventDefault()
    const name = form.name.trim()
    if (!name) return

    setSubmitting(true)
    setError('')
    try {
      const description = form.description.trim() || null
      const workflow = await createWorkflow({
        name,
        description,
        status: 'draft',
        graph_json: createTemplateGraph(form.templateId, name, description),
      })
      navigate(`/workflows/${workflow.id}`)
    } catch {
      setError(t('workflows.createError'))
      setSubmitting(false)
    }
  }

  async function handleDuplicate(workflow) {
    setBusyId(workflow.id)
    setError('')
    try {
      const copy = await duplicateWorkflow(workflow.id)
      setWorkflows((current) => [copy, ...current])
    } catch {
      setError(t('workflows.duplicateError'))
    } finally {
      setBusyId(null)
    }
  }

  async function handleDelete(workflow) {
    if (!window.confirm(t('workflows.deleteConfirm', { name: workflow.name }))) return

    setBusyId(workflow.id)
    setError('')
    try {
      await deleteWorkflow(workflow.id)
      setWorkflows((current) => current.filter((item) => item.id !== workflow.id))
    } catch {
      setError(t('workflows.deleteError'))
    } finally {
      setBusyId(null)
    }
  }

  return (
    <div className="standard-page">
      <header className="workspace-heading compact-heading">
        <div>
          <h1>{t('workflows.title')}</h1>
          <p>{t('workflows.subtitle')}</p>
        </div>
        <button className="button button-primary" type="button" onClick={openForm}>
          <span aria-hidden="true">＋</span>
          {t('dashboard.createWorkflow')}
        </button>
      </header>

      {formOpen && (
        <section className="workspace-card workflow-create-panel" aria-labelledby="create-workflow-title">
          <div className="card-heading split-heading">
            <div>
              <p className="eyebrow">{t('workflows.quickStart')}</p>
              <h2 id="create-workflow-title">{t('workflows.newWorkflow')}</h2>
            </div>
            <button className="icon-button" type="button" onClick={closeForm} aria-label={t('common.close')}>
              ×
            </button>
          </div>
          <form className="workflow-create-form" onSubmit={handleCreate}>
            <fieldset className="template-picker">
              <legend>{t('workflows.chooseTemplate')}</legend>
              <div className="template-grid">
                {workflowTemplates.map((template) => (
                  <button
                    aria-pressed={form.templateId === template.id}
                    className={`template-card ${form.templateId === template.id ? 'selected' : ''}`}
                    key={template.id}
                    onClick={() => selectTemplate(template.id)}
                    type="button"
                  >
                    <strong>{t(`templates.${template.id}.name`)}</strong>
                    <span>{t(`templates.${template.id}.path`)}</span>
                    <small>{t('workflows.agentTemplateCount', { count: template.agents.length })}</small>
                  </button>
                ))}
              </div>
            </fieldset>
            <label className="form-field">
              <span>{t('workflows.nameLabel')}</span>
              <input
                value={form.name}
                maxLength={160}
                required
                onChange={(event) => setForm((current) => ({ ...current, name: event.target.value }))}
              />
            </label>
            <label className="form-field">
              <span>{t('workflows.descriptionLabel')}</span>
              <textarea
                value={form.description}
                maxLength={2000}
                rows={3}
                onChange={(event) => setForm((current) => ({ ...current, description: event.target.value }))}
              />
            </label>
            <div className="template-summary">
              <strong>{t(`templates.${form.templateId}.name`)}</strong>
              <span>{t(`templates.${form.templateId}.description`)}</span>
            </div>
            <div className="form-actions">
              <button className="button button-quiet" type="button" onClick={closeForm}>
                {t('common.cancel')}
              </button>
              <button className="button button-primary" type="submit" disabled={submitting}>
                {submitting ? t('common.saving') : t('workflows.createAction')}
              </button>
            </div>
          </form>
        </section>
      )}

      {error && <div className="form-alert workspace-alert" role="alert">{error}</div>}

      {loading ? (
        <div className="workspace-card loading-panel" role="status">{t('workflows.loading')}</div>
      ) : workflows.length === 0 ? (
        <div className="workspace-card empty-state large-empty">
          <span className="empty-icon" aria-hidden="true">◇</span>
          <h2>{t('workflows.emptyTitle')}</h2>
          <p>{t('workflows.emptyCopy')}</p>
          <button className="button button-primary" type="button" onClick={openForm}>
            {t('dashboard.createWorkflow')}
          </button>
        </div>
      ) : (
        <section className="workflow-grid" aria-label={t('workflows.savedLabel')}>
          {workflows.map((workflow) => (
            <article className="workspace-card workflow-card" key={workflow.id}>
              <div className="workflow-card-topline">
                <span className={`status-pill status-${workflow.status}`}>
                  {t(`status.${workflow.status}`)}
                </span>
                <span>{t('workflows.updated', { date: formatDate(workflow.updated_at, i18n.language) })}</span>
              </div>
              <div>
                <h2>{workflow.name}</h2>
                <p>{workflow.description || t('workflows.noDescription')}</p>
              </div>
              <dl className="workflow-facts">
                <div>
                  <dt>{t('workflows.nodes')}</dt>
                  <dd>{workflow.node_count}</dd>
                </div>
                <div>
                  <dt>{t('workflows.lastRun')}</dt>
                  <dd>{workflow.last_run_status ? t(`status.${workflow.last_run_status}`) : t('workflows.neverRun')}</dd>
                </div>
              </dl>
              <div className="workflow-card-actions">
                <Link className="button button-primary button-small" to={`/workflows/${workflow.id}`}>
                  {t('common.open')}
                </Link>
                <button
                  className="button button-quiet button-small"
                  type="button"
                  disabled={busyId === workflow.id}
                  onClick={() => handleDuplicate(workflow)}
                >
                  {t('workflows.duplicate')}
                </button>
                <button
                  className="button button-danger button-small"
                  type="button"
                  disabled={busyId === workflow.id}
                  onClick={() => handleDelete(workflow)}
                >
                  {t('workflows.delete')}
                </button>
              </div>
            </article>
          ))}
        </section>
      )}
    </div>
  )
}

function formatDate(value, locale) {
  return new Intl.DateTimeFormat(locale, {
    dateStyle: 'medium',
    timeStyle: 'short',
  }).format(new Date(value))
}
