import PropTypes from 'prop-types'
import { useCallback, useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import {
  connectIntegration,
  disconnectIntegration,
  listIntegrations,
} from '../api/integrationApi.js'
import { useAuth } from '../auth/AuthContext.jsx'
import LanguageSwitcher from '../components/LanguageSwitcher.jsx'

export function IntegrationsPage() {
  const { t } = useTranslation()
  const [integrations, setIntegrations] = useState([])
  const [forms, setForms] = useState(initialIntegrationForms)
  const [busyProvider, setBusyProvider] = useState(null)
  const [notice, setNotice] = useState(null)
  const [loading, setLoading] = useState(true)
  const [loadFailed, setLoadFailed] = useState(false)

  const refreshIntegrations = useCallback(async () => {
    setLoading(true)
    setLoadFailed(false)
    setNotice(null)
    try {
      setIntegrations(await listIntegrations())
    } catch {
      setLoadFailed(true)
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => {
    refreshIntegrations()
  }, [refreshIntegrations])

  const updateField = (provider, field, value) => {
    setForms((current) => ({
      ...current,
      [provider]: { ...current[provider], [field]: value },
    }))
  }

  const connect = async (event, provider) => {
    event.preventDefault()
    setBusyProvider(provider)
    setNotice(null)
    try {
      const integration = await connectIntegration(
        provider,
        normalizedCredentials(provider, forms[provider]),
      )
      setIntegrations((current) => upsertIntegration(current, integration))
      setForms((current) => ({
        ...current,
        [provider]: clearedSecretFields(provider, current[provider]),
      }))
      setNotice({ type: 'success', text: t('integrations.saved') })
    } catch {
      setNotice({ type: 'error', text: t('integrations.saveError') })
    } finally {
      setBusyProvider(null)
    }
  }

  const disconnect = async (provider) => {
    setBusyProvider(provider)
    setNotice(null)
    try {
      await disconnectIntegration(provider)
      setIntegrations((current) => current.map((item) => (
        item.provider === provider
          ? { ...item, connected: false, status: 'disconnected', configured_fields: [] }
          : item
      )))
      setNotice({ type: 'success', text: t('integrations.disconnected') })
    } catch {
      setNotice({ type: 'error', text: t('integrations.disconnectError') })
    } finally {
      setBusyProvider(null)
    }
  }

  return (
    <PageFrame title={t('integrations.title')} subtitle={t('integrations.subtitle')}>
      <div className="integration-security-note" role="note">
        <strong>{t('integrations.securityTitle')}</strong>
        <span>{t('integrations.securityCopy')}</span>
      </div>
      {notice && (
        <div
          aria-live="polite"
          className={`form-alert ${notice.type === 'error' ? 'form-alert-error' : 'form-alert-success'}`}
          role={notice.type === 'error' ? 'alert' : 'status'}
        >
          {notice.text}
        </div>
      )}
      {loading && (
        <div className="workspace-card loading-panel" role="status" aria-live="polite">
          {t('integrations.loading')}
        </div>
      )}
      {!loading && loadFailed && (
        <div className="workspace-card empty-state integration-recovery" role="alert">
          <span className="empty-icon" aria-hidden="true">!</span>
          <p>{t('integrations.loadError')}</p>
          <button className="button button-secondary button-small" onClick={refreshIntegrations} type="button">
            {t('common.retry')}
          </button>
        </div>
      )}
      {!loading && !loadFailed && (
        <div className="provider-grid">
          {['google_drive', 'youtube', 'smtp'].map((provider) => (
            <IntegrationCard
              busy={busyProvider === provider}
              form={forms[provider]}
              integration={findIntegration(integrations, provider)}
              key={provider}
              onChange={updateField}
              onConnect={connect}
              onDisconnect={disconnect}
              provider={provider}
            />
          ))}
        </div>
      )}
    </PageFrame>
  )
}

function IntegrationCard({ provider, integration, form, busy, onChange, onConnect, onDisconnect }) {
  const { t } = useTranslation()
  const connected = integration?.connected === true
  return (
    <article className="workspace-card provider-card integration-card" aria-busy={busy}>
      <header className="integration-card-heading">
        <span className="provider-mark" aria-hidden="true">⌁</span>
        <div>
          <h2>{t(`integrations.providers.${provider}.name`)}</h2>
          <p>{t(`integrations.providers.${provider}.description`)}</p>
        </div>
        <span className={`status-pill ${connected ? 'status-success' : ''}`} role="status">
          {t(connected ? 'integrations.connected' : 'integrations.notConnected')}
        </span>
      </header>
      {connected && integration.configured_fields?.length > 0 && (
        <p className="configured-summary">
          {t('integrations.configuredSummary', { count: integration.configured_fields.length })}
        </p>
      )}
      <form className="integration-form" onSubmit={(event) => onConnect(event, provider)}>
        <ProviderFields form={form} onChange={onChange} provider={provider} />
        <small className="secret-help">{t('integrations.secretHelp')}</small>
        <div className="integration-actions">
          <button className="button button-primary button-small" disabled={busy} type="submit">
            {busy
              ? t('integrations.saving')
              : t(connected ? 'integrations.replace' : 'integrations.connect')}
          </button>
          {connected && (
            <button
              className="button button-quiet button-small"
              disabled={busy}
              onClick={() => onDisconnect(provider)}
              type="button"
            >
              {t('integrations.disconnect')}
            </button>
          )}
        </div>
      </form>
    </article>
  )
}

function ProviderFields({ provider, form, onChange }) {
  const { t } = useTranslation()
  const field = (name, type = 'text', required = false) => (
    <label className="compact-field" key={name}>
      <span>{t(`integrations.fields.${name}`)}</span>
      <input
        autoComplete={type === 'password' ? 'new-password' : 'off'}
        onChange={(event) => onChange(provider, name, event.target.value)}
        required={required}
        type={type}
        value={form[name]}
      />
    </label>
  )

  if (provider === 'google_drive') {
    return <>{field('access_token', 'password', true)}{field('refresh_token', 'password')}{field('folder_id')}</>
  }
  if (provider === 'youtube') {
    return (
      <>
        {field('access_token', 'password', true)}
        {field('refresh_token', 'password')}
        <label className="compact-field">
          <span>{t('integrations.fields.privacy_status')}</span>
          <select
            onChange={(event) => onChange(provider, 'privacy_status', event.target.value)}
            value={form.privacy_status}
          >
            <option value="private">{t('integrations.privacy.private')}</option>
            <option value="unlisted">{t('integrations.privacy.unlisted')}</option>
            <option value="public">{t('integrations.privacy.public')}</option>
          </select>
        </label>
      </>
    )
  }
  return (
    <>
      <div className="integration-field-row">{field('host', 'text', true)}{field('port', 'number', true)}</div>
      <div className="integration-field-row">{field('username')}{field('password', 'password')}</div>
      <div className="integration-field-row">{field('from_email', 'email', true)}{field('from_name')}</div>
      <label className="compact-field">
        <span>{t('integrations.fields.encryption')}</span>
        <select
          onChange={(event) => onChange(provider, 'encryption', event.target.value)}
          value={form.encryption}
        >
          <option value="tls">TLS</option>
          <option value="ssl">SSL</option>
          <option value="none">{t('integrations.encryptionNone')}</option>
        </select>
      </label>
    </>
  )
}

export function SettingsPage() {
  const { t } = useTranslation()
  const { user } = useAuth()
  return (
    <PageFrame title={t('settings.title')} subtitle={t('settings.subtitle')}>
      <div className="workspace-card settings-card">
        <div>
          <p className="eyebrow">{t('settings.profile')}</p>
          <h2>{user.name}</h2>
          <p>{t('settings.signedInAs', { email: user.email })}</p>
        </div>
        <div className="locale-setting">
          <div>
            <strong>{t('settings.preferredLanguage')}</strong>
            <small>{t('settings.localeSaved')}</small>
          </div>
          <LanguageSwitcher />
        </div>
      </div>
    </PageFrame>
  )
}

function PageFrame({ title, subtitle, children }) {
  return (
    <div className="standard-page">
      <header className="workspace-heading compact-heading">
        <div>
          <h1>{title}</h1>
          <p>{subtitle}</p>
        </div>
      </header>
      {children}
    </div>
  )
}

const initialIntegrationForms = {
  google_drive: { access_token: '', refresh_token: '', folder_id: '' },
  youtube: { access_token: '', refresh_token: '', privacy_status: 'private' },
  smtp: {
    host: '', port: '587', username: '', password: '', from_email: '', from_name: '', encryption: 'tls',
  },
}

function normalizedCredentials(provider, form) {
  const credentials = Object.fromEntries(Object.entries(form).filter(([, value]) => value !== ''))
  if (provider === 'smtp') credentials.port = Number(credentials.port)
  return credentials
}

function clearedSecretFields(provider, form) {
  if (provider === 'smtp') return { ...form, password: '' }
  return { ...form, access_token: '', refresh_token: '' }
}

function findIntegration(integrations, provider) {
  return integrations.find((item) => item.provider === provider)
}

function upsertIntegration(integrations, integration) {
  return integrations.some((item) => item.provider === integration.provider)
    ? integrations.map((item) => item.provider === integration.provider ? integration : item)
    : [...integrations, integration]
}

IntegrationCard.propTypes = {
  provider: PropTypes.oneOf(['google_drive', 'youtube', 'smtp']).isRequired,
  integration: PropTypes.shape({
    connected: PropTypes.bool,
    configured_fields: PropTypes.arrayOf(PropTypes.string),
  }),
  form: PropTypes.objectOf(PropTypes.oneOfType([PropTypes.string, PropTypes.number])).isRequired,
  busy: PropTypes.bool.isRequired,
  onChange: PropTypes.func.isRequired,
  onConnect: PropTypes.func.isRequired,
  onDisconnect: PropTypes.func.isRequired,
}

ProviderFields.propTypes = {
  provider: PropTypes.oneOf(['google_drive', 'youtube', 'smtp']).isRequired,
  form: PropTypes.objectOf(PropTypes.oneOfType([PropTypes.string, PropTypes.number])).isRequired,
  onChange: PropTypes.func.isRequired,
}

PageFrame.propTypes = {
  title: PropTypes.string.isRequired,
  subtitle: PropTypes.string.isRequired,
  children: PropTypes.node.isRequired,
}
