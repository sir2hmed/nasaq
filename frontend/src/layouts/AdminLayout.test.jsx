import { describe, expect, it, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import '../i18n/index.js'
import AdminLayout from './AdminLayout.jsx'
import * as AuthContextModule from '../auth/AuthContext.jsx'

vi.mock('../auth/AuthContext.jsx', () => ({
  useAuth: vi.fn(),
}))

describe('AdminLayout', () => {
  it('renders admin sidebar, topbar breadcrumbs, and profile info for admin user', () => {
    vi.spyOn(AuthContextModule, 'useAuth').mockReturnValue({
      user: { name: 'Admin Master', email: 'admin@example.test', role: 'admin' },
      logout: vi.fn(),
    })

    render(
      <MemoryRouter initialEntries={['/admin/overview']}>
        <Routes>
          <Route element={<AdminLayout />}>
            <Route path="/admin/overview" element={<div>Overview Content</div>} />
          </Route>
        </Routes>
      </MemoryRouter>,
    )

    expect(screen.getByText('Admin Master')).toBeInTheDocument()
    expect(screen.getByText('admin@example.test')).toBeInTheDocument()
    expect(screen.getByText('Overview Content')).toBeInTheDocument()
  })
})
