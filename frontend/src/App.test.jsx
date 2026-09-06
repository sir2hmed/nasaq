import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import App from './App.jsx'
import * as authApi from './api/authApi.js'
import * as workflowApi from './api/workflowApi.js'
import * as runApi from './api/runApi.js'
import * as integrationApi from './api/integrationApi.js'
import i18n, { LOCALE_STORAGE_KEY } from './i18n/index.js'

vi.mock('./api/authApi.js', () => ({
  getCurrentUser: vi.fn(),
  loginAccount: vi.fn(),
  logoutAccount: vi.fn(),
  persistLocale: vi.fn(),
  registerAccount: vi.fn(),
}))

vi.mock('./api/workflowApi.js', () => ({
  createWorkflow: vi.fn(),
  deleteWorkflow: vi.fn(),
  duplicateWorkflow: vi.fn(),
  getWorkflow: vi.fn(),
  listWorkflows: vi.fn(),
  updateWorkflow: vi.fn(),
  validateWorkflow: vi.fn(),
}))

vi.mock('./api/runApi.js', () => ({
  absoluteDownloadUrl: vi.fn((path) => `http://localhost:8000${path}`),
  approveWorkflowRun: vi.fn(),
  cancelWorkflowRun: vi.fn(),
  getRun: vi.fn(),
  getRunLogs: vi.fn(),
  getRunOutputs: vi.fn(),
  listWorkflowRuns: vi.fn(),
  rejectWorkflowRun: vi.fn(),
  startWorkflowRun: vi.fn(),
}))

vi.mock('./api/integrationApi.js', () => ({
  connectIntegration: vi.fn(),
  disconnectIntegration: vi.fn(),
  listIntegrations: vi.fn(),
}))

const unauthorized = { response: { status: 401 } }
const user = {
  id: 7,
  name: 'Nasaq User',
  email: 'user@example.com',
  preferred_locale: 'en',
}

const workflow = {
  id: 11,
  name: 'Research to Article',
  description: 'Core workflow graph',
  status: 'draft',
  version: 1,
  node_count: 3,
  edge_count: 2,
  last_run_at: null,
  last_run_status: null,
  created_at: '2026-07-15T06:00:00.000Z',
  updated_at: '2026-07-15T07:00:00.000Z',
  graph_json: {
    version: 1,
    name: 'Research to Article',
    description: 'Core workflow graph',
    nodes: [
      {
        id: 'researcher_01',
        type: 'researcher',
        position: { x: 120, y: 180 },
        config: { topic: 'AI', source_count: 5, language: 'en', search_depth: 'basic' },
      },
      {
        id: 'writer_01',
        type: 'writer',
        position: { x: 460, y: 180 },
        config: { style: 'professional', length: 'medium', format: 'article', language: 'same_as_input' },
      },
      { id: 'export_01', type: 'export', position: { x: 800, y: 180 }, config: { formats: ['pdf'] } },
    ],
    edges: [
      { id: 'edge_researcher_writer', source: 'researcher_01', target: 'writer_01' },
      { id: 'edge_writer_export', source: 'writer_01', target: 'export_01' },
    ],
  },
}

describe('Nasaq application shell and persisted workflow editor', () => {
  beforeEach(async () => {
    vi.clearAllMocks()
    window.localStorage.clear()
    window.history.pushState({}, '', '/')
    authApi.getCurrentUser.mockRejectedValue(unauthorized)
    authApi.persistLocale.mockImplementation(async (locale) => ({
      ...user,
      preferred_locale: locale,
    }))
    workflowApi.listWorkflows.mockResolvedValue({ workflows: [], meta: { total: 0 } })
    workflowApi.getWorkflow.mockResolvedValue(workflow)
    workflowApi.createWorkflow.mockResolvedValue(workflow)
    workflowApi.updateWorkflow.mockImplementation(async (_id, payload) => ({
      ...workflow,
      ...payload,
      updated_at: '2026-07-15T08:00:00.000Z',
    }))
    workflowApi.validateWorkflow.mockResolvedValue({ valid: true, errors: [], warnings: [] })
    workflowApi.duplicateWorkflow.mockResolvedValue({
      ...workflow,
      id: 12,
      name: 'Research to Article (Copy)',
    })
    workflowApi.deleteWorkflow.mockResolvedValue()
    runApi.listWorkflowRuns.mockResolvedValue({
      runs: [],
      meta: { total: 0, status_counts: {} },
    })
    integrationApi.listIntegrations.mockResolvedValue([
      { provider: 'google_drive', connected: false, status: 'disconnected', configured_fields: [] },
      { provider: 'youtube', connected: false, status: 'disconnected', configured_fields: [] },
      { provider: 'smtp', connected: false, status: 'disconnected', configured_fields: [] },
    ])
    integrationApi.connectIntegration.mockImplementation(async (provider, credentials) => ({
      provider,
      connected: true,
      status: 'connected',
      configured_fields: Object.keys(credentials),
    }))
    integrationApi.disconnectIntegration.mockResolvedValue()
    runApi.startWorkflowRun.mockResolvedValue({
      id: 'run-phase-six',
      status: 'queued',
      current_node_key: null,
      node_statuses: {
        researcher_01: 'pending',
        writer_01: 'pending',
        export_01: 'pending',
      },
      demo_mode: true,
      cancellation_requested: false,
    })
    runApi.getRun.mockResolvedValue({
      id: 'run-phase-six',
      status: 'success',
      current_node_key: null,
      node_statuses: {
        researcher_01: 'success',
        writer_01: 'success',
        export_01: 'success',
      },
      demo_mode: true,
      cancellation_requested: false,
    })
    runApi.getRunLogs.mockResolvedValue([{
      id: 1,
      node_key: 'writer_01',
      level: 'info',
      event_type: 'NODE_SUCCEEDED',
      message: 'Writer completed.',
      occurred_at: '2026-07-15T12:00:00.000Z',
    }])
    runApi.getRunOutputs.mockResolvedValue([{
      id: 1,
      node_key: 'writer_01',
      agent_type: 'writer',
      output_type: 'text',
      text_content: 'A deterministic demo article.',
      content: { title: 'Demo article' },
      download_url: null,
    }, {
      id: 2,
      node_key: 'export_01',
      agent_type: 'export',
      output_type: 'artifact',
      text_content: null,
      content: null,
      file_name: 'demo-output.md',
      download_url: '/api/outputs/2/download',
    }])
    runApi.cancelWorkflowRun.mockImplementation(async (runId) => ({
      id: runId,
      status: 'running',
      current_node_key: 'researcher_01',
      node_statuses: {
        researcher_01: 'retrying',
        writer_01: 'pending',
        export_01: 'pending',
      },
      demo_mode: true,
      cancellation_requested: true,
    }))
    runApi.approveWorkflowRun.mockImplementation(async (runId) => ({
      id: runId,
      status: 'queued',
      current_node_key: null,
      node_statuses: {},
      demo_mode: true,
      approvals: [{ node_key: 'approval_01', status: 'approved' }],
      cancellation_requested: false,
    }))
    runApi.rejectWorkflowRun.mockImplementation(async (runId) => ({
      id: runId,
      status: 'failed',
      current_node_key: 'approval_01',
      node_statuses: { approval_01: 'waiting_for_approval' },
      demo_mode: true,
      approvals: [{ node_key: 'approval_01', status: 'rejected' }],
      cancellation_requested: false,
    }))
    window.confirm = vi.fn(() => true)
    await i18n.changeLanguage('en')
  })

  afterEach(() => cleanup())

  it('renders the product landing page from translation catalogs', () => {
    render(<App />)

    expect(
      screen.getByRole('heading', { name: /one clear path from an idea/i }),
    ).toBeInTheDocument()
    expect(screen.getAllByRole('link', { name: /create your account/i })[0]).toHaveAttribute(
      'href',
      '/register',
    )
    expect(screen.getByText('Researcher Agent')).toBeInTheDocument()
  })

  it('redirects an unauthenticated protected route to login', async () => {
    window.history.pushState({}, '', '/dashboard')
    render(<App />)

    expect(
      await screen.findByRole('heading', { name: /welcome back/i }),
    ).toBeInTheDocument()
    expect(window.location.pathname).toBe('/login')
  })

  it('restores an authenticated session on a protected route', async () => {
    authApi.getCurrentUser.mockResolvedValue(user)
    window.history.pushState({}, '', '/dashboard')
    render(<App />)

    expect(
      await screen.findByRole('heading', { name: /welcome, nasaq user/i }),
    ).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Workflows' })).toHaveAttribute('href', '/workflows')
  })

  it('shows persisted run totals instead of placeholder dashboard metrics', async () => {
    authApi.getCurrentUser.mockResolvedValue(user)
    runApi.listWorkflowRuns.mockResolvedValue({
      runs: [],
      meta: { total: 7, status_counts: { success: 5, failed: 2 } },
    })
    window.history.pushState({}, '', '/dashboard')
    render(<App />)

    const recentRuns = (await screen.findByText('Recent runs')).closest('article')
    const successful = screen.getByText('Successful').closest('article')
    const failed = screen.getByText('Failed').closest('article')
    await waitFor(() => expect(within(recentRuns).getByText('7')).toBeInTheDocument())
    expect(within(successful).getByText('5')).toBeInTheDocument()
    expect(within(failed).getByText('2')).toBeInTheDocument()
  })

  it('logs in and opens the requested protected route', async () => {
    authApi.loginAccount.mockResolvedValue(user)
    window.history.pushState({}, '', '/login')
    render(<App />)

    fireEvent.change(await screen.findByLabelText('Email'), {
      target: { value: 'user@example.com' },
    })
    fireEvent.change(screen.getByLabelText('Password'), {
      target: { value: 'NasaqPass2026' },
    })
    fireEvent.click(screen.getByRole('button', { name: 'Sign in' }))

    expect(
      await screen.findByRole('heading', { name: /welcome, nasaq user/i }),
    ).toBeInTheDocument()
    expect(authApi.loginAccount).toHaveBeenCalledWith({
      email: 'user@example.com',
      password: 'NasaqPass2026',
      remember: false,
    })
    expect(window.location.pathname).toBe('/dashboard')
  })

  it('registers with the current locale and opens the dashboard', async () => {
    authApi.registerAccount.mockResolvedValue(user)
    window.history.pushState({}, '', '/register')
    render(<App />)

    fireEvent.change(await screen.findByLabelText('Name'), { target: { value: 'Nasaq User' } })
    fireEvent.change(screen.getByLabelText('Email'), { target: { value: 'user@example.com' } })
    fireEvent.change(screen.getByLabelText(/^Password/), { target: { value: 'NasaqPass2026' } })
    fireEvent.change(screen.getByLabelText('Confirm password'), {
      target: { value: 'NasaqPass2026' },
    })
    fireEvent.click(screen.getByRole('button', { name: 'Create account' }))

    await screen.findByRole('heading', { name: /welcome, nasaq user/i })
    expect(authApi.registerAccount).toHaveBeenCalledWith(
      expect.objectContaining({
        name: 'Nasaq User',
        preferred_locale: 'en',
      }),
    )
  })

  it('switches the full document to Arabic RTL and persists the choice', async () => {
    render(<App />)

    fireEvent.click(screen.getByRole('button', { name: /switch to العربية/i }))

    expect(
      await screen.findByRole('heading', { name: /مسار واضح واحد/i }),
    ).toBeInTheDocument()
    await waitFor(() => {
      expect(document.documentElement).toHaveAttribute('lang', 'ar')
      expect(document.documentElement).toHaveAttribute('dir', 'rtl')
      expect(window.localStorage.getItem(LOCALE_STORAGE_KEY)).toBe('ar')
    })
  })

  it('loads persisted workflows and supports duplicate and delete actions', async () => {
    authApi.getCurrentUser.mockResolvedValue(user)
    workflowApi.listWorkflows.mockResolvedValue({ workflows: [workflow], meta: { total: 1 } })
    window.history.pushState({}, '', '/workflows')
    render(<App />)

    const heading = await screen.findByRole('heading', { name: 'Research to Article' })
    const card = heading.closest('article')
    expect(within(card).getByText('3')).toBeInTheDocument()

    fireEvent.click(within(card).getByRole('button', { name: 'Duplicate' }))
    expect(await screen.findByRole('heading', { name: 'Research to Article (Copy)' })).toBeInTheDocument()
    expect(workflowApi.duplicateWorkflow).toHaveBeenCalledWith(11)

    fireEvent.click(within(card).getByRole('button', { name: 'Delete' }))
    await waitFor(() => expect(workflowApi.deleteWorkflow).toHaveBeenCalledWith(11))
    expect(window.confirm).toHaveBeenCalled()
  })

  it('creates the starter graph and opens its reloadable detail route', async () => {
    authApi.getCurrentUser.mockResolvedValue(user)
    window.history.pushState({}, '', '/workflows?new=sample')
    render(<App />)

    fireEvent.change(await screen.findByLabelText('Workflow name'), {
      target: { value: 'My bilingual workflow' },
    })
    fireEvent.click(screen.getByRole('button', { name: 'Create workflow' }))

    await waitFor(() => expect(workflowApi.createWorkflow).toHaveBeenCalledWith(
      expect.objectContaining({
        name: 'My bilingual workflow',
        graph_json: expect.objectContaining({
          nodes: expect.arrayContaining([
            expect.objectContaining({ type: 'researcher' }),
            expect.objectContaining({ type: 'writer' }),
            expect.objectContaining({ type: 'export' }),
          ]),
        }),
      }),
    ))
    expect(window.location.pathname).toBe('/workflows/11')
    expect((await screen.findAllByText('researcher_01', {}, { timeout: 5000 }))[0])
      .toBeInTheDocument()
  })

  it('creates an independent full-demo workflow from the copyable template library', async () => {
    authApi.getCurrentUser.mockResolvedValue(user)
    window.history.pushState({}, '', '/workflows?new=sample')
    render(<App />)

    expect(await screen.findByRole('button', { name: /Research to Article/i })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: /Research to Video/i })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: /Content Publishing/i })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: /Campaign Distribution/i })).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: /Full Product Demo/i }))
    expect(screen.getByLabelText('Workflow name')).toHaveValue('Full Product Demo')
    fireEvent.click(screen.getByRole('button', { name: 'Create workflow' }))

    await waitFor(() => expect(workflowApi.createWorkflow).toHaveBeenCalledWith(
      expect.objectContaining({
        graph_json: expect.objectContaining({
          nodes: expect.arrayContaining([
            expect.objectContaining({ type: 'researcher' }),
            expect.objectContaining({ type: 'writer' }),
            expect.objectContaining({ type: 'video' }),
            expect.objectContaining({ type: 'approval' }),
            expect.objectContaining({ type: 'publisher' }),
            expect.objectContaining({ type: 'email' }),
            expect.objectContaining({ type: 'export' }),
          ]),
        }),
      }),
    ))
  })

  it('restores the exact workflow graph on reload and saves metadata edits', async () => {
    authApi.getCurrentUser.mockResolvedValue(user)
    window.history.pushState({}, '', '/workflows/11')
    render(<App />)

    expect((await screen.findAllByText('researcher_01'))[0]).toBeInTheDocument()
    expect(screen.getAllByText('writer_01')[0]).toBeInTheDocument()
    expect(screen.getAllByText('export_01')[0]).toBeInTheDocument()
    expect(workflowApi.getWorkflow).toHaveBeenCalledWith('11')

    fireEvent.change(screen.getByLabelText('Workflow name'), {
      target: { value: 'Updated workflow' },
    })
    fireEvent.click(screen.getByRole('button', { name: 'Save Workflow' }))

    expect(await screen.findByText('Workflow saved to the database.')).toBeInTheDocument()
    expect(workflowApi.updateWorkflow).toHaveBeenCalledWith(
      11,
      expect.objectContaining({
        name: 'Updated workflow',
        graph_json: expect.objectContaining({ name: 'Updated workflow' }),
      }),
    )
  })

  it('selects and configures a visual agent node before saving the graph', async () => {
    authApi.getCurrentUser.mockResolvedValue(user)
    window.history.pushState({}, '', '/workflows/11')
    render(<App />)

    const identifiers = await screen.findAllByText('researcher_01')
    const nodeIdentifier = identifiers.find((element) => element.closest('.react-flow__node'))
    fireEvent.click(nodeIdentifier.closest('.react-flow__node'))

    const topic = await screen.findByLabelText('Topic')
    fireEvent.change(topic, { target: { value: 'Arabic content automation' } })
    fireEvent.click(screen.getByRole('button', { name: 'Save Workflow' }))

    await waitFor(() => expect(workflowApi.updateWorkflow).toHaveBeenCalledWith(
      11,
      expect.objectContaining({
        graph_json: expect.objectContaining({
          nodes: expect.arrayContaining([
            expect.objectContaining({
              id: 'researcher_01',
              config: expect.objectContaining({ topic: 'Arabic content automation' }),
            }),
          ]),
        }),
      }),
    ))
  })

  it('adds agents from the library and displays blocking and configuration issues', async () => {
    authApi.getCurrentUser.mockResolvedValue(user)
    window.history.pushState({}, '', '/workflows/11')
    render(<App />)

    const exportLabels = await screen.findAllByText('Export Agent')
    const libraryButton = exportLabels.find((element) => element.closest('button')).closest('button')
    fireEvent.click(libraryButton)
    expect(screen.getAllByText('Needs configuration')[0]).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Validate' }))

    expect(await screen.findByText('Connect this node to the execution path.')).toBeInTheDocument()
    expect(screen.getByText('Configure the required export formats field.')).toBeInTheDocument()
  })

  it('filters the agent library by localized search and category', async () => {
    authApi.getCurrentUser.mockResolvedValue(user)
    window.history.pushState({}, '', '/workflows/11')
    render(<App />)

    const library = await screen.findByRole('complementary', { name: 'Agent Library' })
    fireEvent.change(within(library).getByLabelText('Search agents'), {
      target: { value: 'email' },
    })
    expect(within(library).getByRole('button', { name: /Email Agent/i })).toBeInTheDocument()
    expect(within(library).queryByRole('button', { name: /Researcher Agent/i })).not.toBeInTheDocument()

    fireEvent.change(within(library).getByLabelText('Search agents'), { target: { value: '' } })
    fireEvent.change(within(library).getByLabelText('Agent category'), {
      target: { value: 'control' },
    })
    expect(within(library).getByRole('button', { name: /Approval/i })).toBeInTheDocument()
    expect(within(library).queryByRole('button', { name: /Writer Agent/i })).not.toBeInTheDocument()
  })

  it('adds and configures the Video Agent from the visual library', async () => {
    authApi.getCurrentUser.mockResolvedValue(user)
    window.history.pushState({}, '', '/workflows/11')
    render(<App />)

    const videoLabels = await screen.findAllByText('Video Agent')
    const libraryButton = videoLabels.find((element) => element.closest('button')).closest('button')
    fireEvent.click(libraryButton)

    expect(await screen.findByLabelText('Scene duration (seconds)')).toHaveValue(3)
    expect(screen.getByLabelText('Maximum scenes')).toHaveValue(6)
    fireEvent.change(screen.getByLabelText('Narration'), { target: { value: 'tts' } })
    expect(await screen.findByLabelText('TTS voice')).toHaveValue('alloy')
  })

  it('adds and configures Publisher and Email agents from the complete library', async () => {
    authApi.getCurrentUser.mockResolvedValue(user)
    window.history.pushState({}, '', '/workflows/11')
    render(<App />)

    const publisherLabels = await screen.findAllByText('Publisher Agent')
    fireEvent.click(publisherLabels.find((element) => element.closest('button')).closest('button'))
    expect(await screen.findByLabelText('Publishing destination')).toHaveValue('google_drive')
    fireEvent.change(screen.getByLabelText('Publishing destination'), {
      target: { value: 'youtube' },
    })
    expect(await screen.findByLabelText('YouTube privacy')).toHaveValue('private')

    const emailLabels = screen.getAllByText('Email Agent')
    fireEvent.click(emailLabels.find((element) => element.closest('button')).closest('button'))
    fireEvent.change(await screen.findByLabelText('Recipients'), {
      target: { value: 'one@example.test, two@example.test' },
    })
    fireEvent.change(screen.getByLabelText('Email subject'), {
      target: { value: 'Generated campaign' },
    })
    expect(screen.getByLabelText('Email body template')).toHaveValue('{content}\n\n{links}')
  })

  it('shows published resource details and simulated email delivery outputs', async () => {
    authApi.getCurrentUser.mockResolvedValue(user)
    runApi.getRunOutputs.mockResolvedValue([
      {
        id: 20,
        node_key: 'publisher_01',
        agent_type: 'publisher',
        output_type: 'publication',
        public_url: 'https://drive.example.invalid/nasaq/demo-123',
        content: {
          provider: 'demo-google_drive',
          resource_id: 'demo-123',
          url: 'https://drive.example.invalid/nasaq/demo-123',
          simulated: true,
        },
      },
      {
        id: 21,
        node_key: 'email_01',
        agent_type: 'email',
        output_type: 'email_delivery',
        content: {
          recipients: ['reviewer@example.test'],
          subject: 'Demo delivery',
          delivery_status: 'simulated',
          simulated: true,
        },
      },
    ])
    window.history.pushState({}, '', '/workflows/11')
    render(<App />)

    fireEvent.click(await screen.findByRole('button', { name: 'Run demo' }))
    expect(await screen.findByText('demo-google_drive')).toBeInTheDocument()
    expect(screen.getByText('demo-123')).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Open published output' }))
      .toHaveAttribute('href', 'https://drive.example.invalid/nasaq/demo-123')
    expect(screen.getByText('reviewer@example.test')).toBeInTheDocument()
    expect(screen.getByText('Simulated — not sent')).toBeInTheDocument()
  })

  it('runs the saved workflow and presents polled logs and downloadable outputs', async () => {
    authApi.getCurrentUser.mockResolvedValue(user)
    window.history.pushState({}, '', '/workflows/11')
    render(<App />)

    fireEvent.click(await screen.findByRole('button', { name: 'Run demo' }))

    await waitFor(() => expect(runApi.startWorkflowRun).toHaveBeenCalledWith(11, 'demo'))
    expect(await screen.findByText('Execution timeline and outputs')).toBeInTheDocument()
    expect(await screen.findByText('Writer completed.')).toBeInTheDocument()
    expect(screen.getByText('A deterministic demo article.')).toBeInTheDocument()
    expect(screen.getAllByText('Success').length).toBeGreaterThanOrEqual(1)
    expect(screen.getByRole('link', { name: 'Download demo-output.md' }))
      .toHaveAttribute('href', 'http://localhost:8000/api/outputs/2/download')
  })

  it('restores the latest persisted run, logs, and outputs after reloading a workflow', async () => {
    authApi.getCurrentUser.mockResolvedValue(user)
    workflowApi.getWorkflow.mockResolvedValue({ ...workflow, latest_run_id: 'run-persisted' })
    window.history.pushState({}, '', '/workflows/11')
    render(<App />)

    expect(await screen.findByText('Execution timeline and outputs')).toBeInTheDocument()
    expect(runApi.getRun).toHaveBeenCalledWith('run-persisted')
    expect(runApi.getRunLogs).toHaveBeenCalledWith('run-persisted')
    expect(runApi.getRunOutputs).toHaveBeenCalledWith('run-persisted')
    expect(screen.getByText('A deterministic demo article.')).toBeInTheDocument()
  })

  it('plays and downloads an owner-authorized MP4 run output', async () => {
    authApi.getCurrentUser.mockResolvedValue(user)
    runApi.getRunOutputs.mockResolvedValue([{
      id: 10,
      node_key: 'video_01',
      agent_type: 'video',
      output_type: 'artifact',
      text_content: null,
      content: null,
      mime_type: 'video/mp4',
      file_name: 'nasaq-video.mp4',
      stream_url: '/api/outputs/10/stream',
      download_url: '/api/outputs/10/download',
    }])
    window.history.pushState({}, '', '/workflows/11')
    render(<App />)

    fireEvent.click(await screen.findByRole('button', { name: 'Run demo' }))

    expect(await screen.findByLabelText('Generated video preview'))
      .toHaveAttribute('src', 'http://localhost:8000/api/outputs/10/stream')
    expect(screen.getByRole('link', { name: 'Download nasaq-video.mp4' }))
      .toHaveAttribute('href', 'http://localhost:8000/api/outputs/10/download')
  })

  it('starts real provider mode explicitly and labels the execution', async () => {
    authApi.getCurrentUser.mockResolvedValue(user)
    runApi.startWorkflowRun.mockResolvedValue({
      id: 'run-phase-eight-real',
      status: 'queued',
      current_node_key: null,
      node_statuses: {},
      demo_mode: false,
      cancellation_requested: false,
    })
    runApi.getRun.mockResolvedValue({
      id: 'run-phase-eight-real',
      status: 'failed',
      current_node_key: null,
      node_statuses: { researcher_01: 'failed' },
      demo_mode: false,
      cancellation_requested: false,
    })
    window.history.pushState({}, '', '/workflows/11')
    render(<App />)

    fireEvent.click(await screen.findByRole('button', { name: 'Run real' }))

    await waitFor(() => expect(runApi.startWorkflowRun).toHaveBeenCalledWith(11, 'real'))
    expect(await screen.findByText('REAL PROVIDERS')).toBeInTheDocument()
  })

  it('connects and disconnects an integration without echoing saved secrets', async () => {
    authApi.getCurrentUser.mockResolvedValue(user)
    window.history.pushState({}, '', '/integrations')
    render(<App />)

    const driveHeading = await screen.findByRole('heading', { name: 'Google Drive' })
    const driveCard = driveHeading.closest('article')
    const accessToken = within(driveCard).getByLabelText('OAuth access token')
    fireEvent.change(accessToken, { target: { value: 'drive-secret-access-token' } })
    fireEvent.change(within(driveCard).getByLabelText('Drive folder ID (optional)'), {
      target: { value: 'folder_123' },
    })
    fireEvent.click(within(driveCard).getByRole('button', { name: 'Connect securely' }))

    await waitFor(() => expect(integrationApi.connectIntegration).toHaveBeenCalledWith(
      'google_drive',
      { access_token: 'drive-secret-access-token', folder_id: 'folder_123' },
    ))
    expect(await within(driveCard).findByText('Connected')).toBeInTheDocument()
    expect(accessToken).toHaveValue('')
    expect(screen.queryByText('drive-secret-access-token')).not.toBeInTheDocument()
    expect(screen.getByText('Integration connected. Saved secrets are now hidden.')).toBeInTheDocument()

    fireEvent.click(within(driveCard).getByRole('button', { name: 'Disconnect' }))
    await waitFor(() => expect(integrationApi.disconnectIntegration).toHaveBeenCalledWith('google_drive'))
    expect(await within(driveCard).findByText('Not connected')).toBeInTheDocument()
  })

  it('shows integration loading and offers a working retry after a safe failure', async () => {
    authApi.getCurrentUser.mockResolvedValue(user)
    integrationApi.listIntegrations
      .mockRejectedValueOnce(new Error('service detail'))
      .mockResolvedValueOnce([
        { provider: 'google_drive', connected: false, status: 'disconnected', configured_fields: [] },
        { provider: 'youtube', connected: false, status: 'disconnected', configured_fields: [] },
        { provider: 'smtp', connected: false, status: 'disconnected', configured_fields: [] },
      ])
    window.history.pushState({}, '', '/integrations')
    render(<App />)

    expect(await screen.findByText('Integration status could not be loaded. Try again.')).toBeInTheDocument()
    expect(screen.queryByText('service detail')).not.toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Try again' }))

    expect(await screen.findByRole('heading', { name: 'Google Drive' })).toBeInTheDocument()
    expect(integrationApi.listIntegrations).toHaveBeenCalledTimes(2)
  })

  it('renders the secure integrations workspace in Arabic and RTL', async () => {
    authApi.getCurrentUser.mockResolvedValue({ ...user, preferred_locale: 'ar' })
    window.localStorage.removeItem(LOCALE_STORAGE_KEY)
    window.history.pushState({}, '', '/integrations')
    render(<App />)

    expect(await screen.findByRole('heading', { name: 'التكاملات' })).toBeInTheDocument()
    expect(screen.getByText('بيانات الاعتماد تبقى خاصة')).toBeInTheDocument()
    expect(document.documentElement).toHaveAttribute('lang', 'ar')
    expect(document.documentElement).toHaveAttribute('dir', 'rtl')
  })

  it('shows the human-review preview and submits an approval decision', async () => {
    authApi.getCurrentUser.mockResolvedValue(user)
    const waitingRun = {
      id: 'run-phase-nine',
      status: 'waiting_for_approval',
      current_node_key: 'approval_01',
      node_statuses: { approval_01: 'waiting_for_approval' },
      demo_mode: true,
      cancellation_requested: false,
      approvals: [{
        node_key: 'approval_01',
        status: 'pending',
        preview_output: { text_content: 'Review this generated article before continuing.' },
      }],
    }
    runApi.startWorkflowRun.mockResolvedValue(waitingRun)
    runApi.getRun.mockResolvedValue(waitingRun)
    window.history.pushState({}, '', '/workflows/11')
    render(<App />)

    fireEvent.click(await screen.findByRole('button', { name: 'Run demo' }))
    expect(await screen.findByText('Review before continuing')).toBeInTheDocument()
    expect(screen.getByText('Review this generated article before continuing.')).toBeInTheDocument()
    fireEvent.change(screen.getByLabelText('Review comment (optional)'), {
      target: { value: 'Reviewed in the editor.' },
    })
    fireEvent.click(screen.getByRole('button', { name: 'Approve and continue' }))

    await waitFor(() => expect(runApi.approveWorkflowRun).toHaveBeenCalledWith(
      'run-phase-nine',
      'approval_01',
      'Reviewed in the editor.',
    ))
  })

  it('requests cancellation without blocking the active execution panel', async () => {
    authApi.getCurrentUser.mockResolvedValue(user)
    let cancellationRequested = false
    const runningRun = {
      id: 'run-phase-six',
      status: 'running',
      current_node_key: 'researcher_01',
      node_statuses: {
        researcher_01: 'retrying',
        writer_01: 'pending',
        export_01: 'pending',
      },
      demo_mode: true,
    }
    runApi.getRun.mockImplementation(async () => ({
      ...runningRun,
      cancellation_requested: cancellationRequested,
    }))
    runApi.cancelWorkflowRun.mockImplementation(async () => {
      cancellationRequested = true
      return { ...runningRun, cancellation_requested: true }
    })
    window.history.pushState({}, '', '/workflows/11')
    render(<App />)

    fireEvent.click(await screen.findByRole('button', { name: 'Run demo' }))
    fireEvent.click(await screen.findByRole('button', { name: 'Cancel run' }))

    await waitFor(() => expect(runApi.cancelWorkflowRun).toHaveBeenCalledWith('run-phase-six'))
    expect(await screen.findByRole('button', { name: 'Cancellation requested' })).toBeDisabled()
    expect(screen.getByText('Execution timeline and outputs')).toBeInTheDocument()
  })
})
