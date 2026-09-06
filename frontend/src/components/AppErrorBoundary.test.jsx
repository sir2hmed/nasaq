import { render, screen } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import i18n from '../i18n/index.js'
import AppErrorBoundary from './AppErrorBoundary.jsx'

function BrokenView() {
  throw new Error('private implementation detail')
}

describe('application error recovery boundary', () => {
  beforeEach(async () => {
    vi.spyOn(console, 'error').mockImplementation(() => {})
    await i18n.changeLanguage('en')
  })

  afterEach(() => {
    vi.restoreAllMocks()
  })

  it('shows a localized safe recovery screen without exposing the exception', () => {
    render(
      <AppErrorBoundary>
        <BrokenView />
      </AppErrorBoundary>,
    )

    expect(screen.getByRole('heading', { name: 'Something unexpected happened' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Reload application' })).toBeInTheDocument()
    expect(screen.queryByText('private implementation detail')).not.toBeInTheDocument()
  })
})
