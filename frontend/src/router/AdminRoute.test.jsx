import { describe, expect, it, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { AdminRoute } from './AdminRoute.jsx'
import * as AuthContextModule from '../auth/AuthContext.jsx'

vi.mock('../auth/AuthContext.jsx', () => ({
  useAuth: vi.fn(),
}))

describe('AdminRoute', () => {
  it('redirects unauthenticated user to login', () => {
    vi.spyOn(AuthContextModule, 'useAuth').mockReturnValue({ user: null, loading: false })

    render(
      <MemoryRouter initialEntries={['/admin']}>
        <Routes>
          <Route element={<AdminRoute />}>
            <Route path="admin" element={<div>Admin Content</div>} />
          </Route>
          <Route path="login" element={<div>Login Page</div>} />
        </Routes>
      </MemoryRouter>,
    )

    expect(screen.getByText('Login Page')).toBeInTheDocument()
  })

  it('redirects non-admin user to dashboard', () => {
    vi.spyOn(AuthContextModule, 'useAuth').mockReturnValue({
      user: { name: 'Normal User', role: 'user' },
      loading: false,
    })

    render(
      <MemoryRouter initialEntries={['/admin']}>
        <Routes>
          <Route element={<AdminRoute />}>
            <Route path="admin" element={<div>Admin Content</div>} />
          </Route>
          <Route path="dashboard" element={<div>User Dashboard</div>} />
        </Routes>
      </MemoryRouter>,
    )

    expect(screen.getByText('User Dashboard')).toBeInTheDocument()
  })

  it('allows admin user to access admin page', () => {
    vi.spyOn(AuthContextModule, 'useAuth').mockReturnValue({
      user: { name: 'Admin User', role: 'admin' },
      loading: false,
    })

    render(
      <MemoryRouter initialEntries={['/admin']}>
        <Routes>
          <Route element={<AdminRoute />}>
            <Route path="admin" element={<div>Admin Content</div>} />
          </Route>
        </Routes>
      </MemoryRouter>,
    )

    expect(screen.getByText('Admin Content')).toBeInTheDocument()
  })
})
