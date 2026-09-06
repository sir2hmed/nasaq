import { useState } from 'react'
import PropTypes from 'prop-types'
import { Link, useLocation, useNavigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { fieldErrors as responseFieldErrors } from '../api/client.js'
import { useAuth } from '../auth/AuthContext.jsx'
import Brand from '../components/Brand.jsx'
import LanguageSwitcher from '../components/LanguageSwitcher.jsx'

const emptyValues = {
  name: '',
  email: '',
  password: '',
  password_confirmation: '',
  remember: false,
}

AuthPage.propTypes = {
  mode: PropTypes.oneOf(['login', 'register']).isRequired,
}

export default function AuthPage({ mode }) {
  const isRegister = mode === 'register'
  const { t, i18n } = useTranslation()
  const { login, register } = useAuth()
  const navigate = useNavigate()
  const location = useLocation()
  const [values, setValues] = useState(emptyValues)
  const [errors, setErrors] = useState({})
  const [globalError, setGlobalError] = useState('')
  const [submitting, setSubmitting] = useState(false)

  function updateValue(event) {
    const { name, type, checked, value } = event.target
    setValues((current) => ({ ...current, [name]: type === 'checkbox' ? checked : value }))
    setErrors((current) => ({ ...current, [name]: undefined }))
  }

  function validate() {
    const nextErrors = {}
    const requiredFields = isRegister
      ? ['name', 'email', 'password', 'password_confirmation']
      : ['email', 'password']

    requiredFields.forEach((field) => {
      if (!values[field].trim()) nextErrors[field] = t('auth.requiredError')
    })

    if (isRegister && values.password !== values.password_confirmation) {
      nextErrors.password_confirmation = t('auth.passwordMismatch')
    }

    return nextErrors
  }

  function localizeServerErrors(error) {
    const serverErrors = responseFieldErrors(error)
    return Object.fromEntries(
      Object.keys(serverErrors).map((field) => {
        if (field === 'email') {
          const credentialFailure = serverErrors[field].some((message) => message.includes('credentials'))
          const duplicate = serverErrors[field].some((message) => message.includes('taken'))
          const key = credentialFailure
            ? 'auth.credentialsError'
            : duplicate
              ? 'auth.emailTaken'
              : 'auth.emailInvalid'
          return [field, t(key)]
        }
        if (field === 'password') return [field, t('auth.passwordHint')]
        return [field, t('auth.requiredError')]
      }),
    )
  }

  async function handleSubmit(event) {
    event.preventDefault()
    const clientErrors = validate()
    if (Object.keys(clientErrors).length) {
      setErrors(clientErrors)
      return
    }

    setSubmitting(true)
    setGlobalError('')

    try {
      if (isRegister) {
        await register({
          ...values,
          preferred_locale: i18n.resolvedLanguage?.startsWith('ar') ? 'ar' : 'en',
        })
      } else {
        await login({ email: values.email, password: values.password, remember: values.remember })
      }

      const requestedPath = location.state?.from?.pathname
      navigate(requestedPath?.startsWith('/') ? requestedPath : '/dashboard', { replace: true })
    } catch (error) {
      const localizedErrors = localizeServerErrors(error)
      setErrors(localizedErrors)
      if (!Object.keys(localizedErrors).length) setGlobalError(t('auth.genericError'))
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <main className="auth-page" id="main-content" tabIndex="-1">
      <section className="auth-brand-panel">
        <Brand />
        <div>
          <p className="eyebrow">{t('landing.eyebrow')}</p>
          <h1>{t('brand.tagline')}</h1>
        </div>
        <span>{t('landing.proofBilingual')}</span>
      </section>

      <section className="auth-form-panel" aria-labelledby="auth-title">
        <div className="auth-form-topbar">
          <Link className="text-link" to="/">
            <span className="direction-icon" aria-hidden="true">←</span>
            {t('navigation.home')}
          </Link>
          <LanguageSwitcher />
        </div>

        <div className="auth-form-wrap">
          <p className="eyebrow">{t(isRegister ? 'navigation.register' : 'navigation.login')}</p>
          <h2 id="auth-title">{t(isRegister ? 'auth.registerTitle' : 'auth.loginTitle')}</h2>
          <p className="auth-subtitle">
            {t(isRegister ? 'auth.registerSubtitle' : 'auth.loginSubtitle')}
          </p>

          {globalError && <div className="form-alert" role="alert">{globalError}</div>}

          <form className="auth-form" onSubmit={handleSubmit} noValidate>
            {isRegister && (
              <Field
                label={t('auth.name')}
                name="name"
                value={values.name}
                error={errors.name}
                onChange={updateValue}
                autoComplete="name"
              />
            )}
            <Field
              label={t('auth.email')}
              name="email"
              type="email"
              value={values.email}
              error={errors.email}
              onChange={updateValue}
              autoComplete="email"
            />
            <Field
              label={t('auth.password')}
              name="password"
              type="password"
              value={values.password}
              error={errors.password}
              hint={isRegister ? t('auth.passwordHint') : undefined}
              onChange={updateValue}
              autoComplete={isRegister ? 'new-password' : 'current-password'}
            />
            {isRegister && (
              <Field
                label={t('auth.passwordConfirmation')}
                name="password_confirmation"
                type="password"
                value={values.password_confirmation}
                error={errors.password_confirmation}
                onChange={updateValue}
                autoComplete="new-password"
              />
            )}
            {!isRegister && (
              <label className="checkbox-field">
                <input
                  type="checkbox"
                  name="remember"
                  checked={values.remember}
                  onChange={updateValue}
                />
                <span>{t('auth.remember')}</span>
              </label>
            )}
            <button className="button button-primary button-block" type="submit" disabled={submitting}>
              {t(submitting ? 'auth.submitting' : isRegister ? 'auth.registerSubmit' : 'auth.loginSubmit')}
            </button>
          </form>

          <p className="auth-alternate">
            {t(isRegister ? 'auth.hasAccount' : 'auth.noAccount')}{' '}
            <Link to={isRegister ? '/login' : '/register'}>
              {t(isRegister ? 'auth.loginLink' : 'auth.registerLink')}
            </Link>
          </p>
        </div>
      </section>
    </main>
  )
}

function Field({ label, name, type = 'text', value, error, hint, onChange, autoComplete }) {
  const helpId = `${name}-help`

  return (
    <label className="form-field">
      <span>{label}</span>
      <input
        name={name}
        type={type}
        value={value}
        onChange={onChange}
        autoComplete={autoComplete}
        aria-invalid={Boolean(error)}
        aria-describedby={error || hint ? helpId : undefined}
      />
      {(error || hint) && (
        <small id={helpId} className={error ? 'field-error' : undefined}>
          {error ?? hint}
        </small>
      )}
    </label>
  )
}

Field.propTypes = {
  label: PropTypes.string.isRequired,
  name: PropTypes.string.isRequired,
  type: PropTypes.string,
  value: PropTypes.oneOfType([PropTypes.string, PropTypes.bool]).isRequired,
  error: PropTypes.string,
  hint: PropTypes.string,
  onChange: PropTypes.func.isRequired,
  autoComplete: PropTypes.string.isRequired,
}
