import { Navigate, Outlet, useLocation } from 'react-router-dom'
import { useAuth } from '../auth/AuthContext.jsx'
import FullPageLoader from '../components/FullPageLoader.jsx'

export function AdminRoute() {
  const { user, loading } = useAuth()
  const location = useLocation()

  if (loading) return <FullPageLoader />
  if (!user) return <Navigate to="/login" replace state={{ from: location }} />
  if (user.role !== 'admin') return <Navigate to="/dashboard" replace />
  return <Outlet />
}

export default AdminRoute
