import { useTranslation } from 'react-i18next'

export default function FullPageLoader() {
  const { t } = useTranslation()

  return (
    <div className="full-page-loader" role="status" aria-live="polite">
      <span className="loader-mark" aria-hidden="true">
        ن
      </span>
      <span>{t('common.loading')}</span>
    </div>
  )
}
