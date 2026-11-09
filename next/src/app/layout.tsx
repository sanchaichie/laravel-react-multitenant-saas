import type { Metadata } from 'next'
import { Outfit } from 'next/font/google'
import { Toaster } from 'sonner'
import { TooltipProvider } from '@/components/ui/tooltip'
import { ThemeProvider } from '@/context/theme-context'
import './globals.css'

const outfit = Outfit({ subsets: ['latin'] })

// title.template ↔ web's per-page <title>: each page's `metadata.title` fills the %s.
export const metadata: Metadata = {
  title: { template: '%s · React SaaS (Next.js)', default: 'React SaaS (Next.js)' },
}

/** The root layout: <html> and the providers every page needs (theme, tooltips, toasts). */
export default function RootLayout({ children }: { children: React.ReactNode }) {
  return (
    // The theme script adds `dark` before React loads; suppressHydrationWarning accepts that difference.
    <html lang="en" suppressHydrationWarning>
      <head>
        <script dangerouslySetInnerHTML={{ __html: THEME_SCRIPT }} />
      </head>
      <body className={`relative isolate bg-gray-50 dark:bg-gray-900 ${outfit.className}`}>
        <ThemeProvider>
          <TooltipProvider>
            {children}
            <Toaster richColors closeButton position="top-right" />
          </TooltipProvider>
        </ThemeProvider>
      </body>
    </html>
  )
}

// Applies the saved theme before the first paint, so a dark-mode reload doesn't
// flash white (ThemeProvider takes over once React runs). A constant, never user content.
const THEME_SCRIPT = `try{var m=localStorage.getItem('theme-mode')||localStorage.getItem('theme');var d=m==='dark'||(m==='auto'&&matchMedia('(prefers-color-scheme: dark)').matches);if(d)document.documentElement.classList.add('dark')}catch(e){}`
