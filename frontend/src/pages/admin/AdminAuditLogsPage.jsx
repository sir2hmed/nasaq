import { useCallback, useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { fetchAdminLogs } from '../../api/adminApi.js'

export default function AdminAuditLogsPage() {
  const { t, i18n } = useTranslation()
  const isRtl = i18n.dir() === 'rtl'

  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [logsData, setLogsData] = useState(null)
  const [page, setPage] = useState(1)

  const loadLogs = useCallback(async () => {
    setLoading(true)
    setError('')
    try {
      const data = await fetchAdminLogs(page)
      setLogsData(data)
    } catch (err) {
      setError(err.response?.data?.message || err.message || t('admin.fetchError'))
    } finally {
      setLoading(false)
    }
  }, [page, t])

  useEffect(() => {
    loadLogs()
  }, [loadLogs])

  return (
    <div className="workspace-page admin-logs-page" dir={isRtl ? 'rtl' : 'ltr'}>
      <header className="workspace-page-header">
        <div>
          <span className="eyebrow">{t('admin.eyebrow')}</span>
          <h1>{t('admin.tabs.auditLogs')}</h1>
          <p>{t('admin.logs.subtitle')}</p>
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
      ) : !logsData || logsData.logs.length === 0 ? (
        <div className="card empty-card" style={{ padding: '2rem', textAlign: 'center' }}>
          <p>{t('admin.logs.emptyTitle')}</p>
        </div>
      ) : (
        <div className="card">
          <div style={{ overflowX: 'auto' }}>
            <table className="data-table" style={{ width: '100%', borderCollapse: 'collapse' }}>
              <thead>
                <tr style={{ borderBottom: '2px solid var(--border-color, #ccc)', textAlign: 'left' }}>
                  <th style={{ padding: '0.75rem' }}>{t('admin.logs.time')}</th>
                  <th style={{ padding: '0.75rem' }}>{t('admin.logs.event')}</th>
                  <th style={{ padding: '0.75rem' }}>{t('admin.logs.node')}</th>
                  <th style={{ padding: '0.75rem' }}>{t('admin.logs.message')}</th>
                </tr>
              </thead>
              <tbody>
                {logsData.logs.map((log) => (
                  <tr key={log.id} style={{ borderBottom: '1px solid var(--border-color, #eee)' }}>
                    <td style={{ padding: '0.75rem' }}>
                      {log.occurred_at ? new Date(log.occurred_at).toLocaleString() : '-'}
                    </td>
                    <td style={{ padding: '0.75rem' }}><code>{log.event_type}</code></td>
                    <td style={{ padding: '0.75rem' }}>{log.node_key || '-'}</td>
                    <td style={{ padding: '0.75rem' }}>{log.message}</td>
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
            <span>{page} / {logsData.pagination?.last_page || 1}</span>
            <button
              type="button"
              className="button button-quiet button-small"
              disabled={page >= (logsData.pagination?.last_page || 1)}
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
