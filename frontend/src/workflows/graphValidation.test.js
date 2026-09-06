import { describe, expect, it } from 'vitest'
import { createSampleGraph } from './sampleGraph.js'
import { createTemplateGraph, workflowTemplates } from './workflowTemplates.js'
import {
  defaultConfig,
  isCompatible,
  isNodeConfigured,
  validateGraph,
  wouldCreateCycle,
} from './graphValidation.js'

describe('workflow graph validation', () => {
  it('accepts the configured Researcher to Writer to Export DAG', () => {
    const result = validateGraph(createSampleGraph('Research to Article'))
    expect(result).toEqual({ valid: true, errors: [], warnings: [] })
  })

  it('rejects cycles and incompatible data contracts', () => {
    const graph = createSampleGraph('Invalid graph')
    graph.edges.push({ id: 'edge_export_researcher', source: 'export_01', target: 'researcher_01' })
    const result = validateGraph(graph)

    expect(result.valid).toBe(false)
    expect(result.errors.map((error) => error.code)).toEqual(
      expect.arrayContaining(['cycle', 'incompatible_contract']),
    )
    expect(wouldCreateCycle(graph.nodes, graph.edges.slice(0, 2), graph.edges[2])).toBe(true)
  })

  it('reports dangling nodes and required configuration warnings', () => {
    const graph = createSampleGraph('Warnings')
    graph.nodes[0].config.topic = ''
    graph.nodes.push({
      id: 'export_02',
      type: 'export',
      position: { x: 800, y: 400 },
      config: defaultConfig('export'),
    })
    const result = validateGraph(graph)

    expect(result.errors).toContainEqual(expect.objectContaining({ code: 'dangling_node', nodeId: 'export_02' }))
    expect(result.warnings).toEqual(expect.arrayContaining([
      expect.objectContaining({ code: 'required_config', nodeId: 'researcher_01', field: 'topic' }),
      expect.objectContaining({ code: 'required_config', nodeId: 'export_02', field: 'formats' }),
    ]))
    expect(isNodeConfigured('export', defaultConfig('export'))).toBe(false)
  })

  it('rejects present configuration values that violate agent contracts', () => {
    const graph = createSampleGraph('Invalid configuration')
    graph.nodes[0].config.source_count = 0
    graph.nodes[1].config.style = 'casual'
    graph.nodes[2].config.formats = ['pdf', 'pdf']

    const result = validateGraph(graph)

    expect(result.valid).toBe(false)
    expect(result.warnings).toEqual(expect.arrayContaining([
      expect.objectContaining({ code: 'invalid_config', nodeId: 'researcher_01', field: 'source_count' }),
      expect.objectContaining({ code: 'invalid_config', nodeId: 'writer_01', field: 'style' }),
      expect.objectContaining({ code: 'invalid_config', nodeId: 'export_01', field: 'formats' }),
    ]))
    expect(isNodeConfigured('email', {
      recipients: ['not-an-email'],
      subject: 'Unsafe\nsubject',
    })).toBe(false)
  })

  it('enforces the initial compatible handoffs', () => {
    expect(isCompatible('researcher', 'writer')).toBe(true)
    expect(isCompatible('writer', 'export')).toBe(true)
    expect(isCompatible('export', 'researcher')).toBe(false)
    expect(isCompatible('writer', 'approval')).toBe(true)
    expect(isCompatible('approval', 'export')).toBe(true)
    expect(isCompatible('video', 'export')).toBe(true)
    expect(isCompatible('publisher', 'email')).toBe(true)
  })

  it('ships every required copyable template plus the complete demo path', () => {
    expect(workflowTemplates.map((template) => template.id)).toEqual([
      'research_article',
      'research_video',
      'content_publishing',
      'campaign_distribution',
      'full_demo',
    ])
    workflowTemplates.forEach((template) => {
      const graph = createTemplateGraph(template.id, template.id)
      expect(validateGraph(graph)).toEqual({ valid: true, errors: [], warnings: [] })
      expect(graph).not.toBe(createTemplateGraph(template.id, template.id))
    })
    const fullTypes = createTemplateGraph('full_demo', 'Full demo').nodes.map((node) => node.type)
    expect(new Set(fullTypes)).toEqual(new Set([
      'researcher', 'writer', 'video', 'approval', 'publisher', 'email', 'export',
    ]))
    expect(isNodeConfigured('publisher', defaultConfig('publisher'))).toBe(true)
    expect(isNodeConfigured('email', defaultConfig('email'))).toBe(false)
  })
})
