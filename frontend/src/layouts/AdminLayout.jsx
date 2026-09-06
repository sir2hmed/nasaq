import { useState } from 'react'
import { NavLink, Outlet, useLocation, useNavigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { useAuth } from '../auth/AuthContext.jsx'
import Brand from '../components/Brand.jsx'
import LanguageSwitcher from '../components/LanguageSwitcher.jsx'

const adminNavItems = [
  { to: '/admin/overview', key: 'overview', icon: '📊' },
  { to: '/admin/users', key: 'users', icon: '👥' },
  { to: '/admin/providers', key: 'providers', icon: '🔌' },
  { to: '/admin/runs', key: 'runs', icon: '⚡' },
  { to: '/admin/system', key: 'system', icon: '💚' },
  { to: '/admin/audit-logs', key: 'auditLogs', icon: '📜' },
]

export default function AdminLayout() {
  const { t, i18n } = useTranslation()
  const isRtl = i18n.dir() === 'rtl'
  const { user, logout } = useAuth()
  const navigate = useNavigate()
  const location = useLocation()
  const [menuOpen, setMenuOpen] = useState(false)

  async function handleLogout() {
    await logout()
    navigate('/', { replace: true })
  }

  // Calculate current breadcrumb title from path
  const currentPath = location.pathname.replace(/\/$/, '')
  let currentKey = 'overview'
  if (currentPath.includes('/users')) currentKey = 'users'
  else if (currentPath.includes('/providers')) currentKey = 'providers'
  else if (currentPath.includes('/runs')) currentKey = 'runs'
  else if (currentPath.includes('/system')) currentKey = 'system'
  else if (currentPath.includes('/audit-logs')) currentKey = 'auditLogs'

  const currentLabel = t(`admin.tabs.${currentKey}`)

  return (
    <div className="app-shell admin-shell" dir={isRtl ? 'rtl' : 'ltr'}>
      <aside className={`app-sidebar admin-sidebar ${menuOpen ? 'is-open' : ''}`} id="admin-navigation">
        <div className="sidebar-brand">
          <Brand />
          <span className="badge badge-primary" style={{ fontSize: '0.75rem', marginStart: '0.5rem' }}>
            {t('admin.roles.admin')}
          </span>
          <button
            type="button"
            className="icon-button sidebar-close"
            onClick={() => setMenuOpen(false)}
            aria-label={t('navigation.closeMenu')}
          >
            ×
          </button>
        </div>

        <nav className="app-navigation admin-navigation" aria-label={t('admin.tabs.label')}>
          {adminNavItems.map((item) => (
            <NavLink
              key={item.key}
              to={item.to}
              onClick={() => setMenuOpen(false)}
              className={({ isActive }) => (isActive ? 'active' : undefined)}
            >
              <span aria-hidden="true">{item.icon}</span>
              <span>{t(`admin.tabs.${item.key}`)}</span>
            </NavLink>
          ))}
        </nav>

        <div className="sidebar-account">
          <span className="account-avatar" aria-hidden="true">
            {user?.name ? user.name.slice(0, 1).toLocaleUpperCase() : 'A'}
          </span>
          <span className="account-copy">
            <strong>{user?.name}</strong>
            <small>{user?.email}</small>
          </span>
        </div>
      </aside>

      {menuOpen && (
        <button
          type="button"
          className="sidebar-scrim"
          onClick={() => setMenuOpen(false)}
          aria-label={t('navigation.closeMenu')}
        />
      )}

      <div className="app-main">
        <header className="app-topbar admin-topbar">
          <div style={{ display: 'flex', alignItems: 'center', gap: '1rem' }}>
            <button
              type="button"
              className="icon-button menu-button"
              onClick={() => setMenuOpen(true)}
              aria-label={t('navigation.openMenu')}
              aria-controls="admin-navigation"
              aria-expanded={menuOpen}
            >
              ☰
            </button>

            {/* Breadcrumb Navigation */}
            <nav className="breadcrumbs" aria-label={t('admin.breadcrumbs.label')}>
              <ol style={{ display: 'flex', listStyle: 'none', padding: 0, margin: 0, gap: '0.5rem', fontSize: '0.9rem', color: 'var(--text-muted, #666)' }}>
                <li>
                  <NavLink to="/admin/overview" style={{ color: 'inherit', textDecoration: 'none' }}>
                    {t('admin.eyebrow')}
                  </NavLink>
                </li>
                <li aria-hidden="true">/</li>
                <li aria-current="page" style={{ fontWeight: 'bold', color: 'var(--text-color, #111)' }}>
                  {currentLabel}
                </li>
              </ol>
            </nav>
          </div>

          <div className="topbar-actions" style={{ display: 'flex', alignItems: 'center', gap: '1rem' }}>
            <button
              type="button"
              className="button button-quiet button-small"
              onClick={() => navigate('/dashboard')}
            >
              ← {t('admin.exitToApp')}
            </button>
            <LanguageSwitcher />
            <button
              type="button"
              className="button button-quiet button-small"
              onClick={handleLogout}
            >
              {t('navigation.logout')}
            </button>
          </div>
        </header>

        <main className="workspace-main admin-main" id="main-content" tabIndex="-1">
          <Outlet />
        </main>
      </div>
    </div>
  )
}
