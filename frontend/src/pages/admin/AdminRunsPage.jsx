import { useCallback, useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { fetchAdminRuns } from '../../api/adminApi.js'

export default function AdminRunsPage() {
  const { t, i18n } = useTranslation()
  const isRtl = i18n.dir() === 'rtl'

  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [runsData, setRunsData] = useState(null)
  const [page, setPage] = useState(1)

  const loadRuns = useCallback(async () => {
    setLoading(true)
    setError('')
    try {
      const data = await fetchAdminRuns(page)
      setRunsData(data)
    } catch (err) {
      setError(err.response?.data?.message || err.message || t('admin.fetchError'))
    } finally {
      setLoading(false)
    }
  }, [page, t])

  useEffect(() => {
    loadRuns()
  }, [loadRuns])

  return (
    <div className="workspace-page admin-runs-page" dir={isRtl ? 'rtl' : 'ltr'}>
      <header className="workspace-page-header">
        <div>
          <span className="eyebrow">{t('admin.eyebrow')}</span>
          <h1>{t('admin.tabs.runs')}</h1>
          <p>{t('admin.runs.subtitle')}</p>
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
      ) : !runsData || runsData.runs.length === 0 ? (
        <div className="card empty-card" style={{ padding: '2rem', textAlign: 'center' }}>
          <p>{t('admin.runs.emptyTitle')}</p>
        </div>
      ) : (
        <div className="card">
          <div style={{ overflowX: 'auto' }}>
            <table className="data-table" style={{ width: '100%', borderCollapse: 'collapse' }}>
              <thead>
                <tr style={{ borderBottom: '2px solid var(--border-color, #ccc)', textAlign: 'left' }}>
                  <th style={{ padding: '0.75rem' }}>Run ID</th>
                  <th style={{ padding: '0.75rem' }}>{t('admin.runs.workflow')}</th>
                  <th style={{ padding: '0.75rem' }}>{t('admin.runs.user')}</th>
                  <th style={{ padding: '0.75rem' }}>{t('admin.runs.status')}</th>
                  <th style={{ padding: '0.75rem' }}>{t('admin.runs.mode')}</th>
                  <th style={{ padding: '0.75rem' }}>{t('admin.runs.created')}</th>
                </tr>
              </thead>
              <tbody>
                {runsData.runs.map((r) => (
                  <tr key={r.id} style={{ borderBottom: '1px solid var(--border-color, #eee)' }}>
                    <td style={{ padding: '0.75rem' }}><code>{r.id.slice(0, 8)}</code></td>
                    <td style={{ padding: '0.75rem' }}>{r.workflow_name}</td>
                    <td style={{ padding: '0.75rem' }}>{r.user_email}</td>
                    <td style={{ padding: '0.75rem' }}>
                      <span className="badge badge-info">{r.status}</span>
                    </td>
                    <td style={{ padding: '0.75rem' }}>{r.mode}</td>
                    <td style={{ padding: '0.75rem' }}>
                      {r.created_at ? new Date(r.created_at).toLocaleString() : '-'}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          <div style={{ display: 'flex', gap: '0.5rem', marginTop: '1rem', justifyContent: 'flex-end', alignItems: 'center' }}>
            <button
              type="button"
              className="button button-quiet button-small"
              disabled={page <= 1}
              onClick={() => setPage((p) => Math.max(1, p - 1))}
            >
              &lt;
            </button>
            <span>{page} / {runsData.pagination?.last_page || 1}</span>
            <button
              type="button"
              className="button button-quiet button-small"
              disabled={page >= (runsData.pagination?.last_page || 1)}
              onClick={() => setPage((p) => p + 1)}
            >
              &gt;
            </button>
          </div>
        </div>
      )}
    </div>
  )
}
