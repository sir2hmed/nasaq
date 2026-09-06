const compatibleTargets = {
  researcher: ['writer'],
  writer: ['export', 'video', 'approval', 'publisher', 'email'],
  export: ['approval', 'publisher', 'email'],
  video: ['approval', 'publisher', 'email', 'export'],
  approval: ['publisher', 'email', 'export'],
  publisher: ['email'],
  email: [],
}

const requiredConfig = {
  researcher: ['topic', 'source_count', 'language'],
  writer: ['style', 'length', 'format', 'language'],
  video: ['scene_duration', 'max_scenes', 'narration'],
  export: ['formats'],
  publisher: ['destination'],
  email: ['recipients', 'subject'],
}

export function validateGraph(graph) {
  const nodes = graph.nodes ?? []
  const edges = graph.edges ?? []
  const nodeMap = new Map(nodes.map((node) => [node.id, node]))
  const incoming = new Map(nodes.map((node) => [node.id, 0]))
  const outgoing = new Map(nodes.map((node) => [node.id, []]))
  const errors = []
  const warnings = []

  if (nodes.length === 0) {
    errors.push(issue('no_start_node'))
    return { valid: false, errors, warnings }
  }

  edges.forEach((edge) => {
    if (edge.source === edge.target) {
      errors.push(issue('self_loop', { edgeId: edge.id }))
      return
    }
    if (!nodeMap.has(edge.source) || !nodeMap.has(edge.target)) {
      errors.push(issue('dangling_edge', { edgeId: edge.id }))
      return
    }

    outgoing.get(edge.source).push(edge.target)
    incoming.set(edge.target, incoming.get(edge.target) + 1)

    if (!isCompatible(nodeMap.get(edge.source).type, nodeMap.get(edge.target).type)) {
      errors.push(issue('incompatible_contract', { edgeId: edge.id }))
    }
  })

  if (![...incoming.values()].some((count) => count === 0)) {
    errors.push(issue('no_start_node'))
  }

  if (nodes.length > 1) {
    nodes.forEach((node) => {
      if (incoming.get(node.id) === 0 && outgoing.get(node.id).length === 0) {
        errors.push(issue('dangling_node', { nodeId: node.id }))
      }
    })
  }

  if (containsCycle(incoming, outgoing)) {
    errors.push(issue('cycle'))
  }

  nodes.forEach((node) => {
    missingConfig(node.type, node.config).forEach((field) => {
      warnings.push(issue('required_config', { nodeId: node.id, field }))
    })
    invalidConfig(node.type, node.config).forEach((field) => {
      warnings.push(issue('invalid_config', { nodeId: node.id, field }))
    })
  })

  return {
    valid: errors.length === 0 && warnings.length === 0,
    errors,
    warnings,
  }
}

export function isCompatible(sourceType, targetType) {
  return compatibleTargets[sourceType]?.includes(targetType) ?? false
}

export function wouldCreateCycle(nodes, edges, connection) {
  const adjacency = new Map(nodes.map((node) => [node.id, []]))
  edges.forEach((edge) => adjacency.get(edge.source)?.push(edge.target))
  adjacency.get(connection.source)?.push(connection.target)

  const visiting = new Set()
  const visited = new Set()

  function visit(nodeId) {
    if (visiting.has(nodeId)) return true
    if (visited.has(nodeId)) return false
    visiting.add(nodeId)
    for (const target of adjacency.get(nodeId) ?? []) {
      if (visit(target)) return true
    }
    visiting.delete(nodeId)
    visited.add(nodeId)
    return false
  }

  return nodes.some((node) => visit(node.id))
}

export function isNodeConfigured(agentType, config = {}) {
  return missingConfig(agentType, config).length === 0 && invalidConfig(agentType, config).length === 0
}

export function defaultConfig(agentType) {
  if (agentType === 'researcher') {
    return { topic: '', source_count: 5, language: 'en', search_depth: 'basic' }
  }
  if (agentType === 'writer') {
    return { style: 'professional', length: 'medium', format: 'article', language: 'same_as_input' }
  }
  if (agentType === 'export') {
    return { formats: [] }
  }
  if (agentType === 'video') {
    return { scene_duration: 3, max_scenes: 6, narration: 'silent', voice: 'alloy' }
  }
  if (agentType === 'publisher') {
    return { destination: 'google_drive', privacy_status: 'private' }
  }
  if (agentType === 'email') {
    return { recipients: [], subject: '', body_template: '{content}\n\n{links}' }
  }
  return {}
}

function missingConfig(agentType, config = {}) {
  return (requiredConfig[agentType] ?? []).filter((field) => {
    const value = config[field]
    return value === null || value === undefined || value === '' || (Array.isArray(value) && value.length === 0)
  })
}

function invalidConfig(agentType, config = {}) {
  const fields = []
  const present = (field) => config[field] !== null
    && config[field] !== undefined
    && config[field] !== ''
    && !(Array.isArray(config[field]) && config[field].length === 0)
  const invalidChoice = (field, allowed) => {
    if (present(field) && !allowed.includes(config[field])) fields.push(field)
  }

  if (agentType === 'researcher') {
    if (present('topic') && (typeof config.topic !== 'string' || !config.topic.trim())) fields.push('topic')
    if (present('source_count') && (!Number.isInteger(config.source_count) || config.source_count < 1 || config.source_count > 20)) fields.push('source_count')
    invalidChoice('language', ['en', 'ar'])
    invalidChoice('search_depth', ['basic', 'advanced'])
    if (present('simulate_transient_failures') && (!Number.isInteger(config.simulate_transient_failures) || config.simulate_transient_failures < 0 || config.simulate_transient_failures > 3)) fields.push('simulate_transient_failures')
  }
  if (agentType === 'writer') {
    invalidChoice('style', ['professional', 'educational', 'conversational'])
    invalidChoice('length', ['short', 'medium', 'long'])
    invalidChoice('format', ['article', 'script', 'summary'])
    invalidChoice('language', ['same_as_input', 'en', 'ar'])
  }
  if (agentType === 'video') {
    if (present('scene_duration') && (typeof config.scene_duration !== 'number' || !Number.isFinite(config.scene_duration) || config.scene_duration < 1 || config.scene_duration > 10)) fields.push('scene_duration')
    if (present('max_scenes') && (!Number.isInteger(config.max_scenes) || config.max_scenes < 1 || config.max_scenes > 8)) fields.push('max_scenes')
    invalidChoice('narration', ['silent', 'tts'])
    if (present('voice') && (typeof config.voice !== 'string' || !config.voice.trim() || config.voice.length > 80)) fields.push('voice')
  }
  if (agentType === 'export' && present('formats')) {
    const formats = config.formats
    if (!Array.isArray(formats) || formats.some((value) => !['markdown', 'pdf', 'docx'].includes(value)) || new Set(formats).size !== formats.length) fields.push('formats')
  }
  if (agentType === 'publisher') {
    invalidChoice('destination', ['google_drive', 'youtube'])
    invalidChoice('privacy_status', ['private', 'unlisted', 'public'])
    if (present('title') && (typeof config.title !== 'string' || config.title.length > 160)) fields.push('title')
  }
  if (agentType === 'email') {
    if (present('recipients')) {
      const recipients = config.recipients
      const valid = Array.isArray(recipients)
        && recipients.length >= 1
        && recipients.length <= 50
        && recipients.every((value) => typeof value === 'string' && value.length <= 254 && /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value))
      if (!valid) fields.push('recipients')
    }
    if (present('subject') && (typeof config.subject !== 'string' || !config.subject.trim() || config.subject.length > 998 || /[\r\n]/.test(config.subject))) fields.push('subject')
    if (present('body_template') && (typeof config.body_template !== 'string' || config.body_template.length > 20000)) fields.push('body_template')
  }
  return fields
}

function containsCycle(incomingMap, outgoing) {
  const incoming = new Map(incomingMap)
  const queue = [...incoming].filter(([, count]) => count === 0).map(([nodeId]) => nodeId)
  let visited = 0

  while (queue.length > 0) {
    const nodeId = queue.shift()
    visited += 1
    ;(outgoing.get(nodeId) ?? []).forEach((target) => {
      const next = incoming.get(target) - 1
      incoming.set(target, next)
      if (next === 0) queue.push(target)
    })
  }

  return visited !== incoming.size
}

function issue(code, details = {}) {
  return { code, ...details }
}
