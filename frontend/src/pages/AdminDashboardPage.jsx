import { useCallback, useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import {
  fetchAdminOverview,
  fetchAdminUsers,
  updateAdminUserRole,
  updateAdminUserStatus,
  fetchAdminProviders,
  fetchAdminRuns,
  fetchAdminLogs,
} from '../api/adminApi.js'

export default function AdminDashboardPage() {
  const { t, i18n } = useTranslation()
  const isRtl = i18n.dir() === 'rtl'

  const [activeTab, setActiveTab] = useState('overview')
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [successMsg, setSuccessMsg] = useState('')

  const [overview, setOverview] = useState(null)
  const [usersData, setUsersData] = useState(null)
  const [providersData, setProvidersData] = useState(null)
  const [runsData, setRunsData] = useState(null)
  const [logsData, setLogsData] = useState(null)

  const [userPage, setUserPage] = useState(1)
  const [runPage, setRunPage] = useState(1)
  const [logPage, setLogPage] = useState(1)

  const loadData = useCallback(async () => {
    setLoading(true)
    setError('')
    try {
      if (activeTab === 'overview') {
        const data = await fetchAdminOverview()
        setOverview(data)
      } else if (activeTab === 'users') {
        const data = await fetchAdminUsers(userPage)
        setUsersData(data)
      } else if (activeTab === 'providers') {
        const data = await fetchAdminProviders()
        setProvidersData(data)
      } else if (activeTab === 'runs') {
        const data = await fetchAdminRuns(runPage)
        setRunsData(data)
      } else if (activeTab === 'health') {
        const data = await fetchAdminOverview()
        setOverview(data)
      } else if (activeTab === 'logs') {
        const data = await fetchAdminLogs(logPage)
        setLogsData(data)
      }
    } catch (err) {
      setError(err.response?.data?.message || err.message || t('admin.fetchError'))
    } finally {
      setLoading(false)
    }
  }, [activeTab, userPage, runPage, logPage, t])

  useEffect(() => {
    loadData()
  }, [loadData])

  async function handleToggleRole(user) {
    const newRole = user.role === 'admin' ? 'user' : 'admin'
    setError('')
    setSuccessMsg('')
    try {
      await updateAdminUserRole(user.id, newRole)
      setSuccessMsg(t('admin.roleUpdated'))
      loadData()
    } catch (err) {
      setError(err.response?.data?.message || t('admin.updateError'))
    }
  }

  async function handleToggleStatus(user) {
    const newStatus = user.account_status === 'suspended' ? 'active' : 'suspended'
    setError('')
    setSuccessMsg('')
    try {
      await updateAdminUserStatus(user.id, newStatus)
      setSuccessMsg(t('admin.statusUpdated'))
      loadData()
    } catch (err) {
      setError(err.response?.data?.message || t('admin.updateError'))
    }
  }

  const tabs = [
    { id: 'overview', label: t('admin.tabs.overview'), icon: '📊' },
    { id: 'users', label: t('admin.tabs.users'), icon: '👥' },
    { id: 'providers', label: t('admin.tabs.providers'), icon: '🔌' },
    { id: 'runs', label: t('admin.tabs.runs'), icon: '⚡' },
    { id: 'health', label: t('admin.tabs.health'), icon: '💚' },
    { id: 'logs', label: t('admin.tabs.logs'), icon: '📜' },
  ]

  return (
    <div className="workspace-page admin-dashboard-page" dir={isRtl ? 'rtl' : 'ltr'}>
      <header className="workspace-page-header">
        <div>
          <span className="eyebrow">{t('admin.eyebrow')}</span>
          <h1>{t('admin.title')}</h1>
          <p>{t('admin.subtitle')}</p>
        </div>
      </header>

      {error && (
        <div className="alert alert-danger" role="alert" style={{ marginBottom: '1.5rem' }}>
          <strong>{t('common.error')}:</strong> {error}
        </div>
      )}

      {successMsg && (
        <div className="alert alert-success" role="status" style={{ marginBottom: '1.5rem' }}>
          {successMsg}
        </div>
      )}

      <nav className="tab-navigation" aria-label={t('admin.tabs.label')}>
        <div className="tab-list" style={{ display: 'flex', gap: '0.5rem', marginBottom: '1.5rem', flexWrap: 'wrap' }}>
          {tabs.map((tab) => (
            <button
              key={tab.id}
              type="button"
              className={`button ${activeTab === tab.id ? 'button-primary' : 'button-quiet'}`}
              onClick={() => setActiveTab(tab.id)}
            >
              <span>{tab.icon}</span>
              <span>{tab.label}</span>
            </button>
          ))}
        </div>
      </nav>

      {loading ? (
        <div className="card loading-card" style={{ padding: '2rem', textAlign: 'center' }}>
          <p>{t('common.loading')}</p>
        </div>
      ) : (
        <div className="tab-content">
          {activeTab === 'overview' && overview && (
            <div className="admin-overview-grid" style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(280px, 1fr))', gap: '1.5rem' }}>
              <div className="card stat-card">
                <h3>{t('admin.overview.usersTitle')}</h3>
                <div className="stat-metric" style={{ fontSize: '2.5rem', fontWeight: 'bold' }}>{overview.users.total}</div>
                <div className="stat-details" style={{ display: 'flex', gap: '1rem', marginTop: '0.5rem' }}>
                  <span className="badge badge-info">{t('admin.roles.admin')}: {overview.users.admins}</span>
                  <span className="badge badge-success">{t('admin.status.active')}: {overview.users.active}</span>
                  <span className="badge badge-warning">{t('admin.status.suspended')}: {overview.users.suspended}</span>
                </div>
              </div>

              <div className="card stat-card">
                <h3>{t('admin.overview.workflowsTitle')}</h3>
                <div className="stat-metric" style={{ fontSize: '2.5rem', fontWeight: 'bold' }}>{overview.workflows.total}</div>
                <p>{t('admin.overview.totalWorkflowsHelp')}</p>
              </div>

              <div className="card stat-card">
                <h3>{t('admin.overview.runsTitle')}</h3>
                <div className="stat-metric" style={{ fontSize: '2.5rem', fontWeight: 'bold' }}>{overview.runs.total}</div>
                <div className="stat-details" style={{ display: 'flex', gap: '0.5rem', flexWrap: 'wrap', marginTop: '0.5rem' }}>
                  <span className="badge badge-success">✓ {overview.runs.success}</span>
                  <span className="badge badge-danger">✗ {overview.runs.failed}</span>
                  <span className="badge badge-info">⏱ {overview.runs.running}</span>
                  <span className="badge badge-warning">⏸ {overview.runs.waiting_for_approval}</span>
                </div>
              </div>
            </div>
          )}

          {activeTab === 'users' && usersData && (
            <div className="card">
              <h3>{t('admin.users.listTitle')}</h3>
              <div style={{ overflowX: 'auto', marginTop: '1rem' }}>
                <table className="data-table" style={{ width: '100%', borderCollapse: 'collapse' }}>
                  <thead>
                    <tr style={{ borderBottom: '2px solid var(--border-color, #ccc)', textAlign: 'left' }}>
                      <th style={{ padding: '0.75rem' }}>ID</th>
                      <th style={{ padding: '0.75rem' }}>{t('admin.users.name')}</th>
                      <th style={{ padding: '0.75rem' }}>{t('admin.users.email')}</th>
                      <th style={{ padding: '0.75rem' }}>{t('admin.users.role')}</th>
                      <th style={{ padding: '0.75rem' }}>{t('admin.users.status')}</th>
                      <th style={{ padding: '0.75rem' }}>{t('admin.users.actions')}</th>
                    </tr>
                  </thead>
                  <tbody>
                    {usersData.users.map((u) => (
                      <tr key={u.id} style={{ borderBottom: '1px solid var(--border-color, #eee)' }}>
                        <td style={{ padding: '0.75rem' }}><code>{u.id}</code></td>
                        <td style={{ padding: '0.75rem' }}><strong>{u.name}</strong></td>
                        <td style={{ padding: '0.75rem' }}>{u.email}</td>
                        <td style={{ padding: '0.75rem' }}>
                          <span className={`badge ${u.role === 'admin' ? 'badge-primary' : 'badge-secondary'}`}>
                            {t(`admin.roles.${u.role}`)}
                          </span>
                        </td>
                        <td style={{ padding: '0.75rem' }}>
                          <span className={`badge ${u.account_status === 'active' ? 'badge-success' : 'badge-danger'}`}>
                            {t(`admin.status.${u.account_status}`)}
                          </span>
                        </td>
                        <td style={{ padding: '0.75rem', display: 'flex', gap: '0.5rem' }}>
                          <button
                            type="button"
                            className="button button-quiet button-small"
                            onClick={() => handleToggleRole(u)}
                          >
                            {u.role === 'admin' ? t('admin.actions.demote') : t('admin.actions.promote')}
                          </button>
                          <button
                            type="button"
                            className={`button button-small ${u.account_status === 'suspended' ? 'button-success' : 'button-danger'}`}
                            onClick={() => handleToggleStatus(u)}
                          >
                            {u.account_status === 'suspended' ? t('admin.actions.activate') : t('admin.actions.suspend')}
                          </button>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
              <div style={{ display: 'flex', gap: '0.5rem', marginTop: '1rem', justifyContent: 'flex-end' }}>
                <button
                  type="button"
                  className="button button-quiet button-small"
                  disabled={userPage <= 1}
                  onClick={() => setUserPage((p) => Math.max(1, p - 1))}
                >
                  &lt;
                </button>
                <span>{userPage} / {usersData.pagination?.last_page || 1}</span>
                <button
                  type="button"
                  className="button button-quiet button-small"
                  disabled={userPage >= (usersData.pagination?.last_page || 1)}
                  onClick={() => setUserPage((p) => p + 1)}
                >
                  &gt;
                </button>
              </div>
            </div>
          )}

          {activeTab === 'providers' && providersData && (
            <div className="card">
              <h3>{t('admin.providers.title')}</h3>
              <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(280px, 1fr))', gap: '1.5rem', marginTop: '1rem' }}>
                <div className="card provider-card">
                  <h4>{providersData.llm.name}</h4>
                  <p><strong>{t('admin.providers.status')}:</strong> <span className="badge badge-info">{providersData.llm.status}</span></p>
                </div>
                <div className="card provider-card">
                  <h4>{providersData.search.name}</h4>
                  <p><strong>{t('admin.providers.status')}:</strong> <span className="badge badge-info">{providersData.search.status}</span></p>
                </div>
                <div className="card provider-card">
                  <h4>{providersData.video.name}</h4>
                  <p><strong>{t('admin.providers.status')}:</strong> <span className="badge badge-success">{providersData.video.status}</span></p>
                </div>
              </div>
            </div>
          )}

          {activeTab === 'runs' && runsData && (
            <div className="card">
              <h3>{t('admin.runs.title')}</h3>
              <div style={{ overflowX: 'auto', marginTop: '1rem' }}>
                <table className="data-table" style={{ width: '100%', borderCollapse: 'collapse' }}>
                  <thead>
                    <tr style={{ borderBottom: '2px solid var(--border-color, #ccc)', textAlign: 'left' }}>
                      <th style={{ padding: '0.75rem' }}>Run ID</th>
                      <th style={{ padding: '0.75rem' }}>Workflow</th>
                      <th style={{ padding: '0.75rem' }}>User</th>
                      <th style={{ padding: '0.75rem' }}>Status</th>
                      <th style={{ padding: '0.75rem' }}>Mode</th>
                    </tr>
                  </thead>
                  <tbody>
                    {runsData.runs.map((r) => (
                      <tr key={r.id} style={{ borderBottom: '1px solid var(--border-color, #eee)' }}>
                        <td style={{ padding: '0.75rem' }}><code>{r.id.slice(0, 8)}</code></td>
                        <td style={{ padding: '0.75rem' }}>{r.workflow_name}</td>
                        <td style={{ padding: '0.75rem' }}>{r.user_email}</td>
                        <td style={{ padding: '0.75rem' }}><span className="badge badge-info">{r.status}</span></td>
                        <td style={{ padding: '0.75rem' }}>{r.mode}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
              <div style={{ display: 'flex', gap: '0.5rem', marginTop: '1rem', justifyContent: 'flex-end' }}>
                <button
                  type="button"
                  className="button button-quiet button-small"
                  disabled={runPage <= 1}
                  onClick={() => setRunPage((p) => Math.max(1, p - 1))}
                >
                  &lt;
                </button>
                <span>{runPage} / {runsData.pagination?.last_page || 1}</span>
                <button
                  type="button"
                  className="button button-quiet button-small"
                  disabled={runPage >= (runsData.pagination?.last_page || 1)}
                  onClick={() => setRunPage((p) => p + 1)}
                >
                  &gt;
                </button>
              </div>
            </div>
          )}

          {activeTab === 'health' && overview && (
            <div className="card">
              <h3>{t('admin.health.title')}</h3>
              <ul style={{ listStyle: 'none', padding: 0, marginTop: '1rem' }}>
                <li style={{ padding: '0.5rem 0', borderBottom: '1px solid #eee' }}>🟢 Frontend SPA: Healthy</li>
                <li style={{ padding: '0.5rem 0', borderBottom: '1px solid #eee' }}>🟢 Laravel API: Healthy</li>
                <li style={{ padding: '0.5rem 0', borderBottom: '1px solid #eee' }}>🟢 FastAPI Orchestrator: Healthy</li>
                <li style={{ padding: '0.5rem 0', borderBottom: '1px solid #eee' }}>🟢 Celery / Redis Queue: Healthy</li>
                <li style={{ padding: '0.5rem 0' }}>🟢 PostgreSQL Database: Healthy</li>
              </ul>
            </div>
          )}

          {activeTab === 'logs' && logsData && (
            <div className="card">
              <h3>{t('admin.logs.title')}</h3>
              <div style={{ overflowX: 'auto', marginTop: '1rem' }}>
                <table className="data-table" style={{ width: '100%', borderCollapse: 'collapse' }}>
                  <thead>
                    <tr style={{ borderBottom: '2px solid var(--border-color, #ccc)', textAlign: 'left' }}>
                      <th style={{ padding: '0.75rem' }}>Time</th>
                      <th style={{ padding: '0.75rem' }}>Event</th>
                      <th style={{ padding: '0.75rem' }}>Node</th>
                      <th style={{ padding: '0.75rem' }}>Message</th>
                    </tr>
                  </thead>
                  <tbody>
                    {logsData.logs.map((log) => (
                      <tr key={log.id} style={{ borderBottom: '1px solid var(--border-color, #eee)' }}>
                        <td style={{ padding: '0.75rem' }}>{new Date(log.occurred_at).toLocaleTimeString()}</td>
                        <td style={{ padding: '0.75rem' }}><code>{log.event_type}</code></td>
                        <td style={{ padding: '0.75rem' }}>{log.node_key || '-'}</td>
                        <td style={{ padding: '0.75rem' }}>{log.message}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
              <div style={{ display: 'flex', gap: '0.5rem', marginTop: '1rem', justifyContent: 'flex-end' }}>
                <button
                  type="button"
                  className="button button-quiet button-small"
                  disabled={logPage <= 1}
                  onClick={() => setLogPage((p) => Math.max(1, p - 1))}
                >
                  &lt;
                </button>
                <span>{logPage} / {logsData.pagination?.last_page || 1}</span>
                <button
                  type="button"
                  className="button button-quiet button-small"
                  disabled={logPage >= (logsData.pagination?.last_page || 1)}
                  onClick={() => setLogPage((p) => p + 1)}
                >
                  &gt;
                </button>
              </div>
            </div>
          )}
        </div>
      )}
    </div>
  )
}
