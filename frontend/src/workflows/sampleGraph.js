import { createTemplateGraph } from './workflowTemplates.js'

export function createSampleGraph(name, description = null) {
  return createTemplateGraph('research_article', name, description)
}
