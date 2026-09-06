import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { useAuth } from '../auth/AuthContext.jsx'

function ArrowMark() {
  return (
    <svg aria-hidden="true" viewBox="0 0 40 40" className="arrow-mark direction-icon">
      <path d="M8 20h23M23 12l8 8-8 8" fill="none" stroke="currentColor" strokeWidth="2.5" />
    </svg>
  )
}

export default function LandingPage() {
  const { t } = useTranslation()
  const { user } = useAuth()
  const flowSteps = ['research', 'write', 'export']
  const howSteps = ['One', 'Two', 'Three']
  const agentKeys = ['researcher', 'writer', 'export']

  return (
    <>
      <main className="public-main" id="main-content" tabIndex="-1">
        <section className="landing-hero" aria-labelledby="hero-title">
          <div className="hero-copy">
            <p className="eyebrow">{t('landing.eyebrow')}</p>
            <h1 id="hero-title">{t('landing.title')}</h1>
            <p className="hero-summary">{t('landing.summary')}</p>
            <div className="hero-actions">
              <Link className="button button-primary" to={user ? '/dashboard' : '/register'}>
                {t(user ? 'landing.dashboardAction' : 'landing.primaryAction')}
                <span className="direction-icon" aria-hidden="true">→</span>
              </Link>
              {!user && (
                <Link className="button button-secondary" to="/login">
                  {t('landing.secondaryAction')}
                </Link>
              )}
            </div>
            <div className="hero-meta" aria-label={t('landing.eyebrow')}>
              <span>{t('landing.proofBilingual')}</span>
              <span>{t('landing.proofProviders')}</span>
              <span>{t('landing.proofApproval')}</span>
            </div>
          </div>

          <div className="flow-preview" aria-label={t('landing.flowLabel')}>
            <span className="graph-label">{t('landing.liveGraph')}</span>
            {flowSteps.map((key, index) => (
              <div className="flow-step" key={key}>
                <div className="agent-card">
                  <span className="agent-index">0{index + 1}</span>
                  <strong>{t(`landing.${key}`)}</strong>
                  <small>
                    {t(index === flowSteps.length - 1 ? 'landing.readyForReview' : 'landing.structuredHandoff')}
                  </small>
                </div>
                {index < flowSteps.length - 1 && <ArrowMark />}
              </div>
            ))}
          </div>
        </section>

        <section className="landing-section how-section" aria-labelledby="how-title">
          <div className="section-intro">
            <p className="eyebrow">{t('landing.howEyebrow')}</p>
            <h2 id="how-title">{t('landing.howTitle')}</h2>
          </div>
          <div className="three-column-grid">
            {howSteps.map((key, index) => (
              <article className="numbered-card" key={key}>
                <span>0{index + 1}</span>
                <h3>{t(`landing.step${key}Title`)}</h3>
                <p>{t(`landing.step${key}Copy`)}</p>
              </article>
            ))}
          </div>
        </section>

        <section className="landing-section agents-section" aria-labelledby="agents-title">
          <div className="section-intro">
            <p className="eyebrow">{t('landing.agentsEyebrow')}</p>
            <h2 id="agents-title">{t('landing.agentsTitle')}</h2>
          </div>
          <div className="three-column-grid">
            {agentKeys.map((key) => (
              <article className="feature-card" key={key}>
                <span className="feature-dot" aria-hidden="true" />
                <h3>{t(`landing.${key}Title`)}</h3>
                <p>{t(`landing.${key}Copy`)}</p>
              </article>
            ))}
          </div>
        </section>

        <section className="landing-section benefits-section" aria-labelledby="benefits-title">
          <div className="section-intro">
            <p className="eyebrow">{t('landing.benefitsEyebrow')}</p>
            <h2 id="benefits-title">{t('landing.benefitsTitle')}</h2>
          </div>
          <ul className="benefit-list">
            {['One', 'Two', 'Three'].map((key) => (
              <li key={key}>
                <span aria-hidden="true">✓</span>
                {t(`landing.benefit${key}`)}
              </li>
            ))}
          </ul>
        </section>

        <section className="landing-cta" aria-labelledby="cta-title">
          <div>
            <h2 id="cta-title">{t('landing.ctaTitle')}</h2>
            <p>{t('landing.ctaCopy')}</p>
          </div>
          <Link className="button button-primary" to={user ? '/dashboard' : '/register'}>
            {t(user ? 'landing.dashboardAction' : 'landing.primaryAction')}
          </Link>
        </section>
      </main>

      <footer className="public-footer">
        <span>{t('brand.name')}</span>
        <span>{t('landing.footerProject')}</span>
      </footer>
    </>
  )
}
