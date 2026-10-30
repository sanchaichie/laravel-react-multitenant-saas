import { createBrowserRouter, type RouteObject } from 'react-router'
import { AppLayout } from '@/components/AppLayout'
import { AuthLayout } from '@/components/AuthLayout'
import { RequireAuth } from '@/components/RequireAuth'
import { RequireGuest } from '@/components/RequireGuest'
import { AiUsagePage } from '@/routes/AiUsagePage'
import { AuditLogPage } from '@/routes/AuditLogPage'
import { HomePage } from '@/routes/HomePage'
import { JoinPage } from '@/routes/JoinPage'
import { LoginPage } from '@/routes/LoginPage'
import { MembersPage } from '@/routes/MembersPage'
import { RolesPage } from '@/routes/RolesPage'
import { NotFoundPage } from '@/routes/NotFoundPage'
import { RegisterPage } from '@/routes/RegisterPage'
import { RouteErrorPage } from '@/routes/RouteErrorPage'
import { SelectOrganizationPage } from '@/routes/SelectOrganizationPage'
import { TaskDetailPage } from '@/routes/TaskDetailPage'
import { TasksPage } from '@/routes/TasksPage'
import { VerifyEmailPage } from '@/routes/VerifyEmailPage'

// Routes without a `path` are layout routes: they render around their children
// (through <Outlet>) without adding to the URL. That's how guards and layouts nest.
export const routes: RouteObject[] = [
  {
    errorElement: <RouteErrorPage />,
    children: [
      {
        element: <RequireGuest />,
        children: [
          {
            element: <AuthLayout />,
            children: [
              { path: 'login', element: <LoginPage /> },
              { path: 'register', element: <RegisterPage /> },
            ],
          },
        ],
      },
      {
        element: <RequireAuth />,
        children: [
          {
            element: <AppLayout />,
            children: [
              { index: true, element: <HomePage /> },
              { path: 'tasks', element: <TasksPage /> },
              { path: 'tasks/:id', element: <TaskDetailPage /> },
              { path: 'members', element: <MembersPage /> },
              { path: 'roles', element: <RolesPage /> },
              { path: 'audit-log', element: <AuditLogPage /> },
              { path: 'ai-usage', element: <AiUsagePage /> },
            ],
          },
          // Logged in, but in the card layout of the auth pages.
          {
            element: <AuthLayout />,
            children: [
              { path: 'select-organization', element: <SelectOrganizationPage /> },
              { path: 'join/:token', element: <JoinPage /> },
              { path: 'verify-email', element: <VerifyEmailPage /> },
            ],
          },
        ],
      },
      { path: '*', element: <NotFoundPage /> },
    ],
  },
]

export const router = createBrowserRouter(routes)
