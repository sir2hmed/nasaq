import { useState } from 'react'
import { NavLink, Outlet, useNavigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { useAuth } from '../auth/AuthContext.jsx'
import Brand from '../components/Brand.jsx'
import LanguageSwitcher from '../components/LanguageSwitcher.jsx'

const baseNavigationItems = [
  { to: '/dashboard', key: 'dashboard', icon: '⌂' },
  { to: '/workflows', key: 'workflows', icon: '◇' },
  { to: '/integrations', key: 'integrations', icon: '⌁' },
  { to: '/settings', key: 'settings', icon: '⚙' },
]

export default function AppShell() {
  const { t } = useTranslation()
  const { user, logout } = useAuth()
  const navigate = useNavigate()
  const [menuOpen, setMenuOpen] = useState(false)

  const navigationItems = [
    ...baseNavigationItems,
    ...(user?.role === 'admin' ? [{ to: '/admin', key: 'adminDashboard', icon: '⚡' }] : []),
  ]

  async function handleLogout() {
    await logout()
    navigate('/', { replace: true })
  }

  return (
    <div className="app-shell">
      <aside className={`app-sidebar ${menuOpen ? 'is-open' : ''}`} id="primary-navigation">
        <div className="sidebar-brand">
          <Brand />
          <button
            type="button"
            className="icon-button sidebar-close"
            onClick={() => setMenuOpen(false)}
            aria-label={t('navigation.closeMenu')}
          >
            ×
          </button>
        </div>
        <nav className="app-navigation" aria-label={t('navigation.primary')}>
          {navigationItems.map((item) => (
            <NavLink
              key={item.key}
              to={item.to}
              onClick={() => setMenuOpen(false)}
              className={({ isActive }) => (isActive ? 'active' : undefined)}
            >
              <span aria-hidden="true">{item.icon}</span>
              <span>{t(`navigation.${item.key}`)}</span>
            </NavLink>
          ))}
        </nav>
        <div className="sidebar-account">
          <span className="account-avatar" aria-hidden="true">
            {user.name.slice(0, 1).toLocaleUpperCase()}
          </span>
          <span className="account-copy">
            <strong>{user.name}</strong>
            <small>{user.email}</small>
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
        <header className="app-topbar">
          <button
            type="button"
            className="icon-button menu-button"
            onClick={() => setMenuOpen(true)}
            aria-label={t('navigation.openMenu')}
            aria-controls="primary-navigation"
            aria-expanded={menuOpen}
          >
            ☰
          </button>
          <div className="topbar-actions">
            <LanguageSwitcher />
            <button className="button button-quiet button-small" type="button" onClick={handleLogout}>
              {t('navigation.logout')}
            </button>
          </div>
        </header>
        <main className="workspace-main" id="main-content" tabIndex="-1">
          <Outlet />
        </main>
      </div>
    </div>
  )
}
