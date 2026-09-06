import PropTypes from 'prop-types'
import { memo } from 'react'
import { Handle, Position } from '@xyflow/react'
import { useTranslation } from 'react-i18next'
import { isNodeConfigured } from './graphValidation.js'

function AgentNode({ id, data, selected }) {
  const { t } = useTranslation()
  const configured = isNodeConfigured(data.agentType, data.config)

  return (
    <article className={`canvas-agent-node ${selected ? 'is-selected' : ''} ${configured ? 'is-configured' : 'needs-config'}`}>
      <Handle type="target" position={Position.Left} />
      <div className="canvas-node-heading">
        <span className="canvas-node-icon" aria-hidden="true">{agentIcon(data.agentType)}</span>
        <span>
          <strong>{t(`agents.${data.agentType}`)}</strong>
          <small>{t(`agents.purpose.${data.agentType}`)}</small>
        </span>
      </div>
      <div className="canvas-node-footer">
        <span className={`configuration-dot ${configured ? 'configured' : ''}`} aria-hidden="true" />
        <span>
          {configured ? t('canvas.configured') : t('canvas.needsConfiguration')}
          <code dir="ltr">{id}</code>
        </span>
        <b className={`node-status status-${data.status ?? 'idle'}`}>
          {t(`status.${data.status ?? 'idle'}`)}
        </b>
      </div>
      <Handle type="source" position={Position.Right} />
    </article>
  )
}

AgentNode.propTypes = {
  id: PropTypes.string.isRequired,
  data: PropTypes.shape({
    agentType: PropTypes.string.isRequired,
    config: PropTypes.object.isRequired,
    status: PropTypes.string,
  }).isRequired,
  selected: PropTypes.bool,
}

AgentNode.defaultProps = {
  selected: false,
}

function agentIcon(agentType) {
  return {
    researcher: '⌕',
    writer: '✎',
    video: '▶',
    approval: '✓',
    publisher: '↗',
    email: '✉',
    export: '⇩',
  }[agentType] ?? '◇'
}

export default memo(AgentNode)
