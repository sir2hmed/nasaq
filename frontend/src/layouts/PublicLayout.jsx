import { Link, Outlet } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { useAuth } from '../auth/AuthContext.jsx'
import Brand from '../components/Brand.jsx'
import LanguageSwitcher from '../components/LanguageSwitcher.jsx'

export default function PublicLayout() {
  const { t } = useTranslation()
  const { user } = useAuth()

  return (
    <div className="public-shell">
      <header className="public-header">
        <Brand />
        <nav className="public-actions" aria-label={t('navigation.home')}>
          <LanguageSwitcher />
          {user ? (
            <Link className="button button-primary button-small" to="/dashboard">
              {t('navigation.dashboard')}
            </Link>
          ) : (
            <>
              <Link className="text-link" to="/login">
                {t('navigation.login')}
              </Link>
              <Link className="button button-primary button-small" to="/register">
                {t('navigation.register')}
              </Link>
            </>
          )}
        </nav>
      </header>
      <Outlet />
    </div>
  )
}
