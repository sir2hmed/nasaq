import PropTypes from 'prop-types'
import { useTranslation } from 'react-i18next'
import { isNodeConfigured } from './graphValidation.js'

export default function NodeConfigurationPanel({ node, onChange, onDelete, onDuplicate }) {
  const { t } = useTranslation()

  if (!node) {
    return (
      <aside className="canvas-panel configuration-panel" aria-labelledby="configuration-title">
        <div className="canvas-panel-heading">
          <span className="eyebrow">{t('canvas.inspect')}</span>
          <h2 id="configuration-title">{t('canvas.configuration')}</h2>
          <p>{t('canvas.selectNodeHelp')}</p>
        </div>
        <div className="configuration-empty">
          <span aria-hidden="true">◇</span>
          <p>{t('canvas.noSelection')}</p>
        </div>
      </aside>
    )
  }

  const { agentType, config } = node.data
  const configured = isNodeConfigured(agentType, config)
  const update = (field, value) => onChange({ ...config, [field]: value })

  return (
    <aside className="canvas-panel configuration-panel" aria-labelledby="configuration-title">
      <div className="canvas-panel-heading configuration-heading">
        <span className="eyebrow">{t('canvas.configuration')}</span>
        <h2 id="configuration-title">{t(`agents.${agentType}`)}</h2>
        <code dir="ltr">{node.id}</code>
        <span className={`config-state ${configured ? 'configured' : ''}`}>
          {configured ? t('canvas.configured') : t('canvas.needsConfiguration')}
        </span>
      </div>

      <div className="configuration-fields">
        {agentType === 'researcher' && (
          <>
            <TextField
              label={t('canvas.fields.topic')}
              value={config.topic ?? ''}
              onChange={(value) => update('topic', value)}
            />
            <NumberField
              label={t('canvas.fields.sourceCount')}
              value={config.source_count ?? 5}
              min={1}
              max={20}
              onChange={(value) => update('source_count', value)}
            />
            <SelectField
              label={t('canvas.fields.outputLanguage')}
              value={config.language ?? 'en'}
              options={['en', 'ar']}
              optionLabel={(value) => t(`canvas.options.language.${value}`)}
              onChange={(value) => update('language', value)}
            />
            <SelectField
              label={t('canvas.fields.searchDepth')}
              value={config.search_depth ?? 'basic'}
              options={['basic', 'advanced']}
              optionLabel={(value) => t(`canvas.options.searchDepth.${value}`)}
              onChange={(value) => update('search_depth', value)}
            />
          </>
        )}

        {agentType === 'writer' && (
          <>
            <SelectField
              label={t('canvas.fields.writingStyle')}
              value={config.style ?? 'professional'}
              options={['professional', 'educational', 'conversational']}
              optionLabel={(value) => t(`canvas.options.style.${value}`)}
              onChange={(value) => update('style', value)}
            />
            <SelectField
              label={t('canvas.fields.outputLength')}
              value={config.length ?? 'medium'}
              options={['short', 'medium', 'long']}
              optionLabel={(value) => t(`canvas.options.length.${value}`)}
              onChange={(value) => update('length', value)}
            />
            <SelectField
              label={t('canvas.fields.outputFormat')}
              value={config.format ?? 'article'}
              options={['article', 'script', 'summary']}
              optionLabel={(value) => t(`canvas.options.format.${value}`)}
              onChange={(value) => update('format', value)}
            />
            <SelectField
              label={t('canvas.fields.outputLanguage')}
              value={config.language ?? 'same_as_input'}
              options={['same_as_input', 'en', 'ar']}
              optionLabel={(value) => t(`canvas.options.language.${value}`)}
              onChange={(value) => update('language', value)}
            />
          </>
        )}

        {agentType === 'video' && (
          <>
            <NumberField
              label={t('canvas.fields.sceneDuration')}
              value={config.scene_duration ?? 3}
              min={1}
              max={10}
              onChange={(value) => update('scene_duration', value)}
            />
            <NumberField
              label={t('canvas.fields.maxScenes')}
              value={config.max_scenes ?? 6}
              min={1}
              max={8}
              onChange={(value) => update('max_scenes', value)}
            />
            <SelectField
              label={t('canvas.fields.narration')}
              value={config.narration ?? 'silent'}
              options={['silent', 'tts']}
              optionLabel={(value) => t(`canvas.options.narration.${value}`)}
              onChange={(value) => update('narration', value)}
            />
            {(config.narration ?? 'silent') === 'tts' && (
              <TextField
                label={t('canvas.fields.voice')}
                value={config.voice ?? 'alloy'}
                onChange={(value) => update('voice', value)}
              />
            )}
          </>
        )}

        {agentType === 'approval' && (
          <div className="configuration-note">
            <strong>{t('canvas.approvalReady')}</strong>
            <span>{t('canvas.approvalReadyHelp')}</span>
          </div>
        )}

        {agentType === 'publisher' && (
          <>
            <SelectField
              label={t('canvas.fields.destination')}
              value={config.destination ?? 'google_drive'}
              options={['google_drive', 'youtube']}
              optionLabel={(value) => t(`canvas.options.destination.${value}`)}
              onChange={(value) => update('destination', value)}
            />
            {(config.destination ?? 'google_drive') === 'youtube' && (
              <SelectField
                label={t('canvas.fields.privacyStatus')}
                value={config.privacy_status ?? 'private'}
                options={['private', 'unlisted', 'public']}
                optionLabel={(value) => t(`canvas.options.privacy.${value}`)}
                onChange={(value) => update('privacy_status', value)}
              />
            )}
            <TextField
              label={t('canvas.fields.publicationTitle')}
              value={config.title ?? ''}
              onChange={(value) => update('title', value)}
            />
          </>
        )}

        {agentType === 'email' && (
          <>
            <TextField
              label={t('canvas.fields.recipients')}
              value={(config.recipients ?? []).join(', ')}
              onChange={(value) => update(
                'recipients',
                value.split(',').map((item) => item.trim()).filter(Boolean),
              )}
            />
            <TextField
              label={t('canvas.fields.subject')}
              value={config.subject ?? ''}
              onChange={(value) => update('subject', value)}
            />
            <TextAreaField
              label={t('canvas.fields.bodyTemplate')}
              value={config.body_template ?? '{content}\n\n{links}'}
              onChange={(value) => update('body_template', value)}
            />
            <small className="configuration-hint">{t('canvas.bodyTemplateHelp')}</small>
          </>
        )}

        {agentType === 'export' && (
          <fieldset className="format-fieldset">
            <legend>{t('canvas.fields.exportFormats')}</legend>
            {['markdown', 'pdf', 'docx'].map((format) => (
              <label className="checkbox-field" key={format}>
                <input
                  type="checkbox"
                  checked={(config.formats ?? []).includes(format)}
                  onChange={() => {
                    const formats = config.formats ?? []
                    update('formats', formats.includes(format)
                      ? formats.filter((item) => item !== format)
                      : [...formats, format])
                  }}
                />
                <span>{t(`canvas.options.exportFormat.${format}`)}</span>
              </label>
            ))}
          </fieldset>
        )}
      </div>

      <div className="configuration-actions">
        <button className="button button-quiet button-small" type="button" onClick={onDuplicate}>
          {t('canvas.duplicateNode')}
        </button>
        <button className="button button-danger button-small" type="button" onClick={onDelete}>
          {t('canvas.deleteNode')}
        </button>
      </div>
    </aside>
  )
}

NodeConfigurationPanel.propTypes = {
  node: PropTypes.shape({
    id: PropTypes.string.isRequired,
    data: PropTypes.shape({
      agentType: PropTypes.string.isRequired,
      config: PropTypes.object.isRequired,
    }).isRequired,
  }),
  onChange: PropTypes.func.isRequired,
  onDelete: PropTypes.func.isRequired,
  onDuplicate: PropTypes.func.isRequired,
}

NodeConfigurationPanel.defaultProps = {
  node: null,
}

function TextField({ label, value, onChange }) {
  return (
    <label className="form-field compact-field">
      <span>{label}</span>
      <input value={value} onChange={(event) => onChange(event.target.value)} />
    </label>
  )
}

TextField.propTypes = {
  label: PropTypes.string.isRequired,
  value: PropTypes.string.isRequired,
  onChange: PropTypes.func.isRequired,
}

function TextAreaField({ label, value, onChange }) {
  return (
    <label className="form-field compact-field">
      <span>{label}</span>
      <textarea rows={5} value={value} onChange={(event) => onChange(event.target.value)} />
    </label>
  )
}

TextAreaField.propTypes = {
  label: PropTypes.string.isRequired,
  value: PropTypes.string.isRequired,
  onChange: PropTypes.func.isRequired,
}

function NumberField({ label, value, min, max, onChange }) {
  return (
    <label className="form-field compact-field">
      <span>{label}</span>
      <input
        type="number"
        value={value}
        min={min}
        max={max}
        onChange={(event) => onChange(Number(event.target.value))}
      />
    </label>
  )
}

NumberField.propTypes = {
  label: PropTypes.string.isRequired,
  value: PropTypes.number.isRequired,
  min: PropTypes.number.isRequired,
  max: PropTypes.number.isRequired,
  onChange: PropTypes.func.isRequired,
}

function SelectField({ label, value, options, optionLabel, onChange }) {
  return (
    <label className="form-field compact-field">
      <span>{label}</span>
      <select value={value} onChange={(event) => onChange(event.target.value)}>
        {options.map((option) => <option key={option} value={option}>{optionLabel(option)}</option>)}
      </select>
    </label>
  )
}

SelectField.propTypes = {
  label: PropTypes.string.isRequired,
  value: PropTypes.string.isRequired,
  options: PropTypes.arrayOf(PropTypes.string).isRequired,
  optionLabel: PropTypes.func.isRequired,
  onChange: PropTypes.func.isRequired,
}
