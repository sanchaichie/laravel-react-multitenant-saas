import Image from 'next/image'
import ThemeToggler from '@/components/common/theme-toggler'
import GridShape from '@/components/common/grid-shape'

/** TailAdmin's split auth layout: the form on the left, the brand panel on the right (lg and up). */
export default function AuthLayout({ children }: { children: React.ReactNode }) {
  return (
    <div className="relative z-1 bg-white p-6 sm:p-0 dark:bg-gray-900">
      <div className="relative flex min-h-screen w-full flex-col justify-center sm:p-0 lg:flex-row dark:bg-gray-900">
        {children}
        <div className="relative hidden min-h-screen w-full items-center justify-center bg-brand-950 lg:grid lg:w-1/2 dark:bg-white/5">
          <div className="z-1 flex h-full items-center justify-center">
            <GridShape />
            <div className="flex max-w-xs flex-col items-center gap-4">
              <Image width={48} height={48} src="/images/logo/logo-icon.svg" alt="" />
              <p className="text-center text-2xl font-semibold text-white">React SaaS</p>
              <p className="text-center text-gray-400 dark:text-white/60">
                Tasks for teams, on a Laravel API. This frontend is built with Next.js.
              </p>
            </div>
          </div>
        </div>
        <div className="fixed inset-e-6 bottom-6 z-50 hidden sm:block">
          <ThemeToggler />
        </div>
      </div>
    </div>
  )
}
