import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'

export default function NotFoundPage() {
  const { t } = useTranslation()
  return (
    <main className="not-found-page" id="main-content" tabIndex="-1">
      <span aria-hidden="true">404</span>
      <h1>{t('notFound.title')}</h1>
      <p>{t('notFound.copy')}</p>
      <Link className="button button-primary" to="/">
        {t('notFound.action')}
      </Link>
    </main>
  )
}
