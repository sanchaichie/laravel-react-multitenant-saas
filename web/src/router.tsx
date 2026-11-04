import { createBrowserRouter, type RouteObject } from 'react-router'
import { AppLayout } from '@/components/AppLayout'
import { AuthLayout } from '@/components/AuthLayout'
import { RequireAuth } from '@/components/RequireAuth'
import { RequireGuest } from '@/components/RequireGuest'
import { AccountPage } from '@/routes/AccountPage'
import { AiUsagePage } from '@/routes/AiUsagePage'
import { AuditLogPage } from '@/routes/AuditLogPage'
import { BillingPage } from '@/routes/BillingPage'
import { ConfirmEmailPage } from '@/routes/ConfirmEmailPage'
import { ForgotPasswordPage } from '@/routes/ForgotPasswordPage'
import { HomePage } from '@/routes/HomePage'
import { InvoicesPage } from '@/routes/InvoicesPage'
import { JoinPage } from '@/routes/JoinPage'
import { LoginPage } from '@/routes/LoginPage'
import { MembersPage } from '@/routes/MembersPage'
import { RolesPage } from '@/routes/RolesPage'
import { NotFoundPage } from '@/routes/NotFoundPage'
import { RegisterPage } from '@/routes/RegisterPage'
import { ResetPasswordPage } from '@/routes/ResetPasswordPage'
import { RevertEmailPage } from '@/routes/RevertEmailPage'
import { RevertTwoFactorPage } from '@/routes/RevertTwoFactorPage'
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
      // Logged in or not: the undo links from the "email was changed" and
      // "two-factor was turned on" emails.
      {
        element: <AuthLayout />,
        children: [
          { path: 'revert-email', element: <RevertEmailPage /> },
          { path: 'revert-two-factor', element: <RevertTwoFactorPage /> },
        ],
      },
      {
        element: <RequireGuest />,
        children: [
          {
            element: <AuthLayout />,
            children: [
              { path: 'login', element: <LoginPage /> },
              { path: 'register', element: <RegisterPage /> },
              { path: 'forgot-password', element: <ForgotPasswordPage /> },
              { path: 'reset-password', element: <ResetPasswordPage /> },
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
              { path: 'billing', element: <BillingPage /> },
              { path: 'billing/invoices', element: <InvoicesPage /> },
              { path: 'account', element: <AccountPage /> },
              // Platform admin pages, loaded only when one is opened (`lazy`: a separate
              // chunk, as Nuxt does for every page), so other people never download them.
              { path: 'admin/users', lazy: () => import('@/routes/admin/AdminUsersPage').then((m) => ({ Component: m.AdminUsersPage })) },
              { path: 'admin/users/:id', lazy: () => import('@/routes/admin/AdminUserPage').then((m) => ({ Component: m.AdminUserPage })) },
              { path: 'admin/organizations', lazy: () => import('@/routes/admin/AdminOrganizationsPage').then((m) => ({ Component: m.AdminOrganizationsPage })) },
              { path: 'admin/organizations/:id', lazy: () => import('@/routes/admin/AdminOrganizationPage').then((m) => ({ Component: m.AdminOrganizationPage })) },
              { path: 'admin/audit-log', lazy: () => import('@/routes/admin/AdminAuditLogPage').then((m) => ({ Component: m.AdminAuditLogPage })) },
            ],
          },
          // Logged in, but in the card layout of the auth pages.
          {
            element: <AuthLayout />,
            children: [
              { path: 'select-organization', element: <SelectOrganizationPage /> },
              { path: 'join/:token', element: <JoinPage /> },
              { path: 'verify-email', element: <VerifyEmailPage /> },
              { path: 'confirm-email', element: <ConfirmEmailPage /> },
            ],
          },
        ],
      },
      { path: '*', element: <NotFoundPage /> },
    ],
  },
]

export const router = createBrowserRouter(routes)
