import { useCallback, useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { fetchAdminUsers, updateAdminUserRole, updateAdminUserStatus } from '../../api/adminApi.js'

export default function AdminUsersPage() {
  const { t, i18n } = useTranslation()
  const isRtl = i18n.dir() === 'rtl'

  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [successMsg, setSuccessMsg] = useState('')
  const [usersData, setUsersData] = useState(null)
  const [page, setPage] = useState(1)
  const [pendingAction, setPendingAction] = useState(null)
  const [confirmPassword, setConfirmPassword] = useState('')
  const [submitting, setSubmitting] = useState(false)

  const loadUsers = useCallback(async () => {
    setLoading(true)
    setError('')
    try {
      const data = await fetchAdminUsers(page)
      setUsersData(data)
    } catch (err) {
      setError(err.response?.data?.message || err.message || t('admin.fetchError'))
    } finally {
      setLoading(false)
    }
  }, [page, t])

  useEffect(() => {
    loadUsers()
  }, [loadUsers])

  function requestRoleChange(user) {
    setConfirmPassword('')
    setPendingAction({ type: 'role', user })
  }

  function requestStatusChange(user) {
    setConfirmPassword('')
    setPendingAction({ type: 'status', user })
  }

  async function confirmSensitiveAction(event) {
    event.preventDefault()
    if (!pendingAction) return
    const { user, type } = pendingAction
    const newRole = user.role === 'admin' ? 'user' : 'admin'
    const newStatus = user.account_status === 'suspended' ? 'active' : 'suspended'
    setError('')
    setSuccessMsg('')
    setSubmitting(true)
    try {
      if (type === 'role') {
        await updateAdminUserRole(user.id, newRole, confirmPassword)
        setSuccessMsg(t('admin.roleUpdated'))
      } else {
        await updateAdminUserStatus(user.id, newStatus, confirmPassword)
        setSuccessMsg(t('admin.statusUpdated'))
      }
      setPendingAction(null)
      setConfirmPassword('')
      loadUsers()
    } catch (err) {
      setError(err.response?.data?.message || t('admin.updateError'))
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <div className="workspace-page admin-users-page" dir={isRtl ? 'rtl' : 'ltr'}>
      <header className="workspace-page-header">
        <div>
          <span className="eyebrow">{t('admin.eyebrow')}</span>
          <h1>{t('admin.tabs.users')}</h1>
          <p>{t('admin.users.subtitle')}</p>
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

      {loading ? (
        <div className="card loading-card" style={{ padding: '2rem', textAlign: 'center' }}>
          <p>{t('common.loading')}</p>
        </div>
      ) : !usersData || usersData.users.length === 0 ? (
        <div className="card empty-card" style={{ padding: '2rem', textAlign: 'center' }}>
          <p>{t('admin.users.emptyTitle')}</p>
        </div>
      ) : (
        <div className="card">
          <div style={{ overflowX: 'auto' }}>
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
                        onClick={() => requestRoleChange(u)}
                      >
                        {u.role === 'admin' ? t('admin.actions.demote') : t('admin.actions.promote')}
                      </button>
                      <button
                        type="button"
                        className={`button button-small ${u.account_status === 'suspended' ? 'button-success' : 'button-danger'}`}
                        onClick={() => requestStatusChange(u)}
                      >
                        {u.account_status === 'suspended' ? t('admin.actions.activate') : t('admin.actions.suspend')}
                      </button>
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
            <span>{page} / {usersData.pagination?.last_page || 1}</span>
            <button
              type="button"
              className="button button-quiet button-small"
              disabled={page >= (usersData.pagination?.last_page || 1)}
              onClick={() => setPage((p) => p + 1)}
            >
              &gt;
            </button>
          </div>
        </div>
      )}

      {pendingAction && (
        <div className="modal-backdrop" style={{ position: 'fixed', inset: 0, background: 'rgba(0,0,0,0.5)', display: 'flex', alignItems: 'center', justifyContent: 'center', zIndex: 1000 }}>
          <div className="modal-card card" style={{ maxWidth: '480px', width: '90%' }}>
            <h2>{pendingAction.type === 'role' ? (pendingAction.user.role === 'admin' ? t('admin.actions.demote') : t('admin.actions.promote')) : (pendingAction.user.account_status === 'suspended' ? t('admin.actions.activate') : t('admin.actions.suspend'))}</h2>
            <p>Confirm this administrator action for <strong>{pendingAction.user.email}</strong>.</p>
            <form onSubmit={confirmSensitiveAction} style={{ display: 'flex', flexDirection: 'column', gap: '1rem' }}>
              <label>
                {t('admin.providers.confirmPasswordLabel')}
                <input type="password" className="input" required value={confirmPassword} onChange={(event) => setConfirmPassword(event.target.value)} />
              </label>
              <div style={{ display: 'flex', justifyContent: 'flex-end', gap: '0.5rem' }}>
                <button type="button" className="button button-quiet" disabled={submitting} onClick={() => setPendingAction(null)}>{t('common.cancel')}</button>
                <button type="submit" className="button button-danger" disabled={submitting}>{submitting ? t('common.loading') : t('common.confirm')}</button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  )
}
