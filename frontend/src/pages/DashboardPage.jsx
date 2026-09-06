import { useCallback, useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { useAuth } from '../auth/AuthContext.jsx'
import { listWorkflows } from '../api/workflowApi.js'
import { listWorkflowRuns } from '../api/runApi.js'

export default function DashboardPage() {
  const { t, i18n } = useTranslation()
  const { user } = useAuth()
  const [workflows, setWorkflows] = useState([])
  const [totalWorkflows, setTotalWorkflows] = useState(0)
  const [runMetrics, setRunMetrics] = useState({ total: 0, success: 0, failed: 0 })
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')

  const load = useCallback(async () => {
    setLoading(true)
    setError('')
    try {
      const [workflowResult, runResult] = await Promise.all([
        listWorkflows(5),
        listWorkflowRuns(5),
      ])
      setWorkflows(workflowResult.workflows)
      setTotalWorkflows(workflowResult.meta.total)
      setRunMetrics({
        total: runResult.meta.total,
        success: runResult.meta.status_counts?.success ?? 0,
        failed: runResult.meta.status_counts?.failed ?? 0,
      })
    } catch {
      setError(t('dashboard.workflowLoadError'))
    } finally {
      setLoading(false)
    }
  }, [t])

  useEffect(() => {
    load()
  }, [load])

  const metrics = [
    ['totalWorkflows', totalWorkflows],
    ['recentRuns', runMetrics.total],
    ['successfulRuns', runMetrics.success],
    ['failedRuns', runMetrics.failed],
  ]

  return (
    <div className="dashboard-page">
      <section className="workspace-heading">
        <div>
          <p className="eyebrow">{t('dashboard.eyebrow')}</p>
          <h1>{t('dashboard.welcome', { name: user.name })}</h1>
          <p>{t('dashboard.subtitle')}</p>
        </div>
        <Link className="button button-primary" to="/workflows?new=sample">
          <span aria-hidden="true">＋</span>
          {t('dashboard.createWorkflow')}
        </Link>
      </section>

      <section className="metric-grid" aria-label={t('dashboard.eyebrow')}>
        {metrics.map(([key, value]) => (
          <article className="metric-card" key={key}>
            <span>{t(`dashboard.${key}`)}</span>
            <strong>{value}</strong>
          </article>
        ))}
      </section>

      <div className="dashboard-grid">
        <section className="workspace-card recent-card" aria-labelledby="recent-title">
          <div className="card-heading">
            <h2 id="recent-title">{t('dashboard.recentTitle')}</h2>
          </div>
          {loading ? (
            <div className="empty-state" role="status"><p>{t('workflows.loading')}</p></div>
          ) : error ? (
            <div className="empty-state">
              <span className="empty-icon" aria-hidden="true">!</span>
              <h3>{t('dashboard.loadErrorTitle')}</h3>
              <p>{error}</p>
              <button className="button button-quiet button-small" type="button" onClick={load}>
                {t('common.retry')}
              </button>
            </div>
          ) : workflows.length === 0 ? (
            <div className="empty-state">
              <span className="empty-icon" aria-hidden="true">◇</span>
              <h3>{t('dashboard.emptyTitle')}</h3>
              <p>{t('dashboard.emptyCopy')}</p>
            </div>
          ) : (
            <div className="recent-workflow-list">
              {workflows.map((workflow) => (
                <Link className="recent-workflow-row" to={`/workflows/${workflow.id}`} key={workflow.id}>
                  <span className="recent-workflow-mark" aria-hidden="true">◇</span>
                  <span>
                    <strong>{workflow.name}</strong>
                    <small>{t('workflows.updated', { date: formatDate(workflow.updated_at, i18n.language) })}</small>
                  </span>
                  <b>{t('workflows.nodeCount', { count: workflow.node_count })}</b>
                  <i className="direction-icon" aria-hidden="true">→</i>
                </Link>
              ))}
            </div>
          )}
        </section>

        <section className="workspace-card sample-card" aria-labelledby="sample-title">
          <p className="eyebrow">{t('dashboard.sampleTitle')}</p>
          <h2 id="sample-title">{t('dashboard.sampleName')}</h2>
          <p>{t('dashboard.sampleCopy')}</p>
          <div className="mini-flow" aria-hidden="true">
            <span>{t('landing.research')}</span>
            <b className="direction-icon">→</b>
            <span>{t('landing.write')}</span>
            <b className="direction-icon">→</b>
            <span>{t('landing.export')}</span>
          </div>
          <div className="sample-footer">
            <span>{t('dashboard.sampleNodes')}</span>
            <Link to="/workflows?new=sample">{t('dashboard.preview')}</Link>
          </div>
        </section>
      </div>
    </div>
  )
}

function formatDate(value, locale) {
  return new Intl.DateTimeFormat(locale, {
    dateStyle: 'medium',
    timeStyle: 'short',
  }).format(new Date(value))
}
