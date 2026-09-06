import { lazy, Suspense } from 'react'
import { BrowserRouter, Navigate, Route, Routes } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { AuthProvider } from './auth/AuthContext.jsx'
import AppShell from './layouts/AppShell.jsx'
import AdminLayout from './layouts/AdminLayout.jsx'
import PublicLayout from './layouts/PublicLayout.jsx'
import AuthPage from './pages/AuthPage.jsx'
import DashboardPage from './pages/DashboardPage.jsx'
import LandingPage from './pages/LandingPage.jsx'
import NotFoundPage from './pages/NotFoundPage.jsx'
import WorkflowsPage from './pages/WorkflowsPage.jsx'
import FullPageLoader from './components/FullPageLoader.jsx'
import { IntegrationsPage, SettingsPage } from './pages/WorkspacePages.jsx'
import { ProtectedRoute, PublicOnlyRoute } from './router/ProtectedRoute.jsx'
import AdminRoute from './router/AdminRoute.jsx'
import AdminOverviewPage from './pages/admin/AdminOverviewPage.jsx'
import AdminUsersPage from './pages/admin/AdminUsersPage.jsx'
import AdminProvidersPage from './pages/admin/AdminProvidersPage.jsx'
import AdminRunsPage from './pages/admin/AdminRunsPage.jsx'
import AdminSystemPage from './pages/admin/AdminSystemPage.jsx'
import AdminAuditLogsPage from './pages/admin/AdminAuditLogsPage.jsx'

const WorkflowDetailPage = lazy(() => import('./pages/WorkflowDetailPage.jsx'))

export default function App() {
  const { t } = useTranslation()

  return (
    <BrowserRouter>
      <a className="skip-link" href="#main-content">{t('common.skipToContent')}</a>
      <AuthProvider>
        <Routes>
          <Route element={<PublicLayout />}>
            <Route index element={<LandingPage />} />
          </Route>

          <Route element={<PublicOnlyRoute />}>
            <Route path="login" element={<AuthPage mode="login" />} />
            <Route path="register" element={<AuthPage mode="register" />} />
          </Route>

          <Route element={<ProtectedRoute />}>
            <Route element={<AppShell />}>
              <Route path="dashboard" element={<DashboardPage />} />
              <Route path="workflows" element={<WorkflowsPage />} />
              <Route
                path="workflows/:workflowId"
                element={(
                  <Suspense fallback={<FullPageLoader />}>
                    <WorkflowDetailPage />
                  </Suspense>
                )}
              />
              <Route path="integrations" element={<IntegrationsPage />} />
              <Route path="settings" element={<SettingsPage />} />
            </Route>
          </Route>

          <Route element={<AdminRoute />}>
            <Route path="admin" element={<AdminLayout />}>
              <Route index element={<Navigate to="/admin/overview" replace />} />
              <Route path="overview" element={<AdminOverviewPage />} />
              <Route path="users" element={<AdminUsersPage />} />
              <Route path="providers" element={<AdminProvidersPage />} />
              <Route path="runs" element={<AdminRunsPage />} />
              <Route path="system" element={<AdminSystemPage />} />
              <Route path="audit-logs" element={<AdminAuditLogsPage />} />
            </Route>
          </Route>

          <Route path="*" element={<NotFoundPage />} />
        </Routes>
      </AuthProvider>
    </BrowserRouter>
  )
}
