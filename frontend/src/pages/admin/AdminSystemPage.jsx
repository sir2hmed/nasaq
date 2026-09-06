import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { fetchAdminSystem } from '../../api/adminApi.js'

export default function AdminSystemPage() {
  const { t, i18n } = useTranslation()
  const isRtl = i18n.dir() === 'rtl'
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [systemInfo, setSystemInfo] = useState(null)

  useEffect(() => {
    fetchAdminSystem().then(setSystemInfo).catch((err) => setError(err.response?.data?.message || err.message)).finally(() => setLoading(false))
  }, [])
  const entries = systemInfo ? Object.entries(systemInfo) : []
  return <div className="workspace-page admin-system-page" dir={isRtl ? 'rtl' : 'ltr'}>
    <header className="workspace-page-header"><div><span className="eyebrow">{t('admin.eyebrow')}</span><h1>{t('admin.tabs.system')}</h1><p>{t('admin.system.subtitle')}</p></div></header>
    {error && <div className="alert alert-danger" role="alert">{error}</div>}
    {loading ? <div className="card"><p>{t('common.loading')}</p></div> : <div className="card"><h3>{t('admin.health.title')}</h3><ul style={{ listStyle: 'none', padding: 0 }}>
      {entries.map(([component, status]) => <li key={component} style={{ padding: '0.75rem 0', borderBottom: '1px solid #eee', display: 'flex', justifyContent: 'space-between' }}><span>{component}</span><span className={`badge ${status === 'reachable' ? 'badge-success' : 'badge-warning'}`}>{status}</span></li>)}
    </ul></div>}
  </div>
}
