import { createBrowserRouter, type RouteObject } from 'react-router'
import { AppLayout } from '@/components/AppLayout'
import { AuthLayout } from '@/components/AuthLayout'
import { RequireAuth } from '@/components/RequireAuth'
import { RequireGuest } from '@/components/RequireGuest'
import { HomePage } from '@/routes/HomePage'
import { LoginPage } from '@/routes/LoginPage'
import { NotFoundPage } from '@/routes/NotFoundPage'
import { RegisterPage } from '@/routes/RegisterPage'
import { RouteErrorPage } from '@/routes/RouteErrorPage'
import { SelectOrganizationPage } from '@/routes/SelectOrganizationPage'

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
            children: [{ index: true, element: <HomePage /> }],
          },
          // Logged in, but in the card layout of the auth pages.
          {
            element: <AuthLayout />,
            children: [{ path: 'select-organization', element: <SelectOrganizationPage /> }],
          },
        ],
      },
      { path: '*', element: <NotFoundPage /> },
    ],
  },
]

export const router = createBrowserRouter(routes)
