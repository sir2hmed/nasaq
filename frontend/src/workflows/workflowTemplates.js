export const workflowTemplates = [
  { id: 'research_article', agents: ['researcher', 'writer', 'export'] },
  { id: 'research_video', agents: ['researcher', 'writer', 'video', 'export'] },
  { id: 'content_publishing', agents: ['researcher', 'writer', 'approval', 'publisher'] },
  { id: 'campaign_distribution', agents: ['researcher', 'writer', 'publisher', 'email'] },
  {
    id: 'full_demo',
    agents: ['researcher', 'writer', 'video', 'approval', 'publisher', 'email', 'export'],
  },
]

export function createTemplateGraph(templateId, name, description = null) {
  const builder = templateBuilders[templateId] ?? templateBuilders.research_article
  return {
    version: 1,
    name,
    description: description || null,
    ...builder(),
  }
}

const researcher = (topic = 'AI agents in education') => ({
  id: 'researcher_01',
  type: 'researcher',
  position: { x: 80, y: 180 },
  config: { topic, source_count: 5, language: 'en', search_depth: 'basic' },
})

const writer = (format = 'article') => ({
  id: 'writer_01',
  type: 'writer',
  position: { x: 360, y: 180 },
  config: { style: 'professional', length: 'medium', format, language: 'same_as_input' },
})

const edge = (id, source, target) => ({ id, source, target })

const templateBuilders = {
  research_article: () => ({
    nodes: [
      researcher(),
      writer(),
      {
        id: 'export_01',
        type: 'export',
        position: { x: 660, y: 180 },
        config: { formats: ['markdown', 'pdf', 'docx'] },
      },
    ],
    edges: [
      edge('edge_research_writer', 'researcher_01', 'writer_01'),
      edge('edge_writer_export', 'writer_01', 'export_01'),
    ],
  }),
  research_video: () => ({
    nodes: [
      researcher('Visual storytelling with AI agents'),
      writer('script'),
      {
        id: 'video_01',
        type: 'video',
        position: { x: 640, y: 180 },
        config: { scene_duration: 3, max_scenes: 6, narration: 'silent', voice: 'alloy' },
      },
      {
        id: 'export_01',
        type: 'export',
        position: { x: 920, y: 180 },
        config: { formats: ['markdown', 'pdf', 'docx'] },
      },
    ],
    edges: [
      edge('edge_research_writer', 'researcher_01', 'writer_01'),
      edge('edge_writer_video', 'writer_01', 'video_01'),
      edge('edge_video_export', 'video_01', 'export_01'),
    ],
  }),
  content_publishing: () => ({
    nodes: [
      researcher('Human-reviewed content automation'),
      writer(),
      { id: 'approval_01', type: 'approval', position: { x: 640, y: 180 }, config: {} },
      {
        id: 'publisher_01',
        type: 'publisher',
        position: { x: 920, y: 180 },
        config: { destination: 'google_drive', privacy_status: 'private' },
      },
    ],
    edges: [
      edge('edge_research_writer', 'researcher_01', 'writer_01'),
      edge('edge_writer_approval', 'writer_01', 'approval_01'),
      edge('edge_approval_publisher', 'approval_01', 'publisher_01'),
    ],
  }),
  campaign_distribution: () => ({
    nodes: [
      researcher('Bilingual campaign distribution'),
      writer(),
      {
        id: 'publisher_01',
        type: 'publisher',
        position: { x: 640, y: 180 },
        config: { destination: 'google_drive', privacy_status: 'private' },
      },
      {
        id: 'email_01',
        type: 'email',
        position: { x: 920, y: 180 },
        config: {
          recipients: ['reviewer@example.test'],
          subject: 'Your Nasaq campaign content',
          body_template: 'Your generated campaign is ready:\n\n{links}',
        },
      },
    ],
    edges: [
      edge('edge_research_writer', 'researcher_01', 'writer_01'),
      edge('edge_writer_publisher', 'writer_01', 'publisher_01'),
      edge('edge_publisher_email', 'publisher_01', 'email_01'),
    ],
  }),
  full_demo: () => ({
    nodes: [
      researcher('Secure bilingual visual AI workflows'),
      writer('script'),
      {
        id: 'video_01',
        type: 'video',
        position: { x: 640, y: 100 },
        config: { scene_duration: 2, max_scenes: 5, narration: 'silent', voice: 'alloy' },
      },
      { id: 'approval_01', type: 'approval', position: { x: 900, y: 100 }, config: {} },
      {
        id: 'publisher_01',
        type: 'publisher',
        position: { x: 1160, y: 100 },
        config: { destination: 'youtube', privacy_status: 'private' },
      },
      {
        id: 'email_01',
        type: 'email',
        position: { x: 1420, y: 100 },
        config: {
          recipients: ['reviewer@example.test'],
          subject: 'Nasaq full demo video',
          body_template: 'The approved demo video is ready:\n\n{links}',
        },
      },
      {
        id: 'export_01',
        type: 'export',
        position: { x: 900, y: 360 },
        config: { formats: ['markdown', 'pdf', 'docx'] },
      },
    ],
    edges: [
      edge('edge_research_writer', 'researcher_01', 'writer_01'),
      edge('edge_writer_video', 'writer_01', 'video_01'),
      edge('edge_video_approval', 'video_01', 'approval_01'),
      edge('edge_approval_publisher', 'approval_01', 'publisher_01'),
      edge('edge_publisher_email', 'publisher_01', 'email_01'),
      edge('edge_video_export', 'video_01', 'export_01'),
    ],
  }),
}
