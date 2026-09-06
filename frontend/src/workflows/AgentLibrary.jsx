import { useMemo, useState } from 'react'
import PropTypes from 'prop-types'
import { useTranslation } from 'react-i18next'

const initialAgents = ['researcher', 'writer', 'video', 'approval', 'publisher', 'email', 'export']
const agentIcons = {
  researcher: '⌕',
  writer: '✎',
  video: '▶',
  approval: '✓',
  publisher: '↗',
  email: '✉',
  export: '⇩',
}
const agentCategories = {
  researcher: 'creation',
  writer: 'creation',
  video: 'creation',
  approval: 'control',
  publisher: 'delivery',
  email: 'delivery',
  export: 'delivery',
}

export default function AgentLibrary({ onAdd }) {
  const { t } = useTranslation()
  const [query, setQuery] = useState('')
  const [category, setCategory] = useState('all')

  const visibleAgents = useMemo(() => {
    const normalized = query.trim().toLocaleLowerCase()
    return initialAgents.filter((agentType) => {
      if (category !== 'all' && agentCategories[agentType] !== category) return false
      if (!normalized) return true
      return `${t(`agents.${agentType}`)} ${t(`agents.purpose.${agentType}`)}`
        .toLocaleLowerCase()
        .includes(normalized)
    })
  }, [category, query, t])

  function handleDragStart(event, agentType) {
    event.dataTransfer.setData('application/nasaq-agent', agentType)
    event.dataTransfer.effectAllowed = 'move'
  }

  return (
    <aside className="canvas-panel agent-library-panel" aria-labelledby="agent-library-title">
      <div className="canvas-panel-heading">
        <span className="eyebrow">{t('canvas.build')}</span>
        <h2 id="agent-library-title">{t('canvas.agentLibrary')}</h2>
        <p>{t('canvas.libraryHelp')}</p>
      </div>
      <div className="agent-library-filters">
        <label>
          <span>{t('canvas.searchAgents')}</span>
          <input
            type="search"
            value={query}
            placeholder={t('canvas.searchAgentsPlaceholder')}
            onChange={(event) => setQuery(event.target.value)}
          />
        </label>
        <label>
          <span>{t('canvas.agentCategory')}</span>
          <select value={category} onChange={(event) => setCategory(event.target.value)}>
            <option value="all">{t('canvas.categories.all')}</option>
            <option value="creation">{t('canvas.categories.creation')}</option>
            <option value="control">{t('canvas.categories.control')}</option>
            <option value="delivery">{t('canvas.categories.delivery')}</option>
          </select>
        </label>
      </div>
      <div className="agent-library-list">
        {visibleAgents.map((agentType) => (
          <button
            className="library-agent-card"
            draggable
            key={agentType}
            type="button"
            onClick={() => onAdd(agentType)}
            onDragStart={(event) => handleDragStart(event, agentType)}
          >
            <span className="library-agent-icon" aria-hidden="true">
              {agentIcons[agentType]}
            </span>
            <span>
              <strong>{t(`agents.${agentType}`)}</strong>
              <small>{t(`agents.purpose.${agentType}`)}</small>
            </span>
            <b className="library-drag-handle" title={t('canvas.dragHandle')} aria-hidden="true">⋮⋮</b>
          </button>
        ))}
        {visibleAgents.length === 0 && (
          <p className="library-empty" role="status">{t('canvas.noAgents')}</p>
        )}
      </div>
    </aside>
  )
}

AgentLibrary.propTypes = {
  onAdd: PropTypes.func.isRequired,
}
