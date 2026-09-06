import PropTypes from 'prop-types'
import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'

export default function Brand({ compact = false }) {
  const { t } = useTranslation()

  return (
    <Link
      className="brand"
      to="/"
      aria-label={`${t('brand.name')} — ${t('navigation.home')}`}
    >
      <span className="brand-mark" aria-hidden="true">
        ن
      </span>
      {!compact && <span>{t('brand.name')}</span>}
    </Link>
  )
}

Brand.propTypes = {
  compact: PropTypes.bool,
}
