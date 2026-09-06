import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { fetchAdminOverview } from '../../api/adminApi.js'

export default function AdminOverviewPage() {
  const { t, i18n } = useTranslation()
  const isRtl = i18n.dir() === 'rtl'

  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [overview, setOverview] = useState(null)

  useEffect(() => {
    async function loadOverview() {
      setLoading(true)
      setError('')
      try {
        const data = await fetchAdminOverview()
        setOverview(data)
      } catch (err) {
        setError(err.response?.data?.message || err.message || t('admin.fetchError'))
      } finally {
        setLoading(false)
      }
    }
    loadOverview()
  }, [t])

  return (
    <div className="workspace-page admin-overview-page" dir={isRtl ? 'rtl' : 'ltr'}>
      <header className="workspace-page-header">
        <div>
          <span className="eyebrow">{t('admin.eyebrow')}</span>
          <h1>{t('admin.tabs.overview')}</h1>
          <p>{t('admin.overview.subtitle')}</p>
        </div>
      </header>

      {error && (
        <div className="alert alert-danger" role="alert" style={{ marginBottom: '1.5rem' }}>
          <strong>{t('common.error')}:</strong> {error}
        </div>
      )}

      {loading ? (
        <div className="card loading-card" style={{ padding: '2rem', textAlign: 'center' }}>
          <p>{t('common.loading')}</p>
        </div>
      ) : !overview ? (
        <div className="card empty-card" style={{ padding: '2rem', textAlign: 'center' }}>
          <p>{t('admin.notAvailable')}</p>
        </div>
      ) : (
        <div className="admin-overview-grid" style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(260px, 1fr))', gap: '1.5rem' }}>
          <div className="card stat-card">
            <h3>{t('admin.overview.usersTitle')}</h3>
            <div className="stat-metric" style={{ fontSize: '2.5rem', fontWeight: 'bold' }}>{overview.users.total}</div>
            <div className="stat-details" style={{ display: 'flex', gap: '0.75rem', marginTop: '0.5rem', flexWrap: 'wrap' }}>
              <span className="badge badge-info">{t('admin.roles.admin')}: {overview.users.admins}</span>
              <span className="badge badge-success">{t('admin.status.active')}: {overview.users.active}</span>
              <span className="badge badge-warning">{t('admin.status.suspended')}: {overview.users.suspended}</span>
            </div>
          </div>

          <div className="card stat-card">
            <h3>{t('admin.overview.workflowsTitle')}</h3>
            <div className="stat-metric" style={{ fontSize: '2.5rem', fontWeight: 'bold' }}>{overview.workflows.total}</div>
            <p style={{ marginTop: '0.5rem', fontSize: '0.9rem', color: 'var(--text-muted, #666)' }}>{t('admin.overview.totalWorkflowsHelp')}</p>
          </div>

          <div className="card stat-card">
            <h3>{t('admin.overview.runsToday')}</h3>
            <div className="stat-metric" style={{ fontSize: '2.5rem', fontWeight: 'bold' }}>{overview.runs.runs_today ?? 0}</div>
            <p style={{ marginTop: '0.5rem', fontSize: '0.9rem', color: 'var(--text-muted, #666)' }}>{t('admin.overview.totalRunsHelp', { count: overview.runs.total })}</p>
          </div>

          <div className="card stat-card">
            <h3>{t('admin.overview.successRate')}</h3>
            <div className="stat-metric" style={{ fontSize: '2.5rem', fontWeight: 'bold' }}>
              {overview.runs.success_rate !== undefined ? `${overview.runs.success_rate}%` : t('admin.notAvailable')}
            </div>
            <div className="stat-details" style={{ display: 'flex', gap: '0.5rem', flexWrap: 'wrap', marginTop: '0.5rem' }}>
              <span className="badge badge-success">✓ {overview.runs.success}</span>
              <span className="badge badge-danger">✗ {overview.runs.failed}</span>
            </div>
          </div>
        </div>
      )}
    </div>
  )
}
