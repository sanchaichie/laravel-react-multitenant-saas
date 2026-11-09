'use client'

import { Button } from '@/components/ui/button'
import { useTheme } from '@/context/theme-context'
import { MoonIcon, SunIcon } from '@/icons'

/** TailAdmin's header theme button (light ↔ dark). */
export default function ThemeToggleButton() {
  const { toggleTheme, theme } = useTheme()

  return (
    <Button
      variant="outline"
      size="icon-lg"
      className="hidden sm:inline-flex"
      aria-label={theme === 'dark' ? 'Switch to light mode' : 'Switch to dark mode'}
      onClick={toggleTheme}
    >
      {theme === 'dark' ? <SunIcon /> : <MoonIcon />}
    </Button>
  )
}
