import PropTypes from 'prop-types'
import { useTranslation } from 'react-i18next'
import { useAuth } from '../auth/AuthContext.jsx'

export default function LanguageSwitcher({ compact = false }) {
  const { i18n, t } = useTranslation()
  const { changeLocale } = useAuth()
  const currentLocale = i18n.resolvedLanguage?.startsWith('ar') ? 'ar' : 'en'
  const targetLocale = currentLocale === 'ar' ? 'en' : 'ar'
  const targetLabel = t(`language.${targetLocale === 'ar' ? 'arabic' : 'english'}`)

  return (
    <button
      className="language-switcher"
      type="button"
      onClick={() => void changeLocale(targetLocale)}
      aria-label={t('language.switchTo', { language: targetLabel })}
    >
      <span aria-hidden="true">文</span>
      {!compact && <span>{targetLabel}</span>}
    </button>
  )
}

LanguageSwitcher.propTypes = {
  compact: PropTypes.bool,
}
