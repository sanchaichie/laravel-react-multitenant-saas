"use client";

import { Button } from "@/components/ui/button";
import { useTheme } from "@/context/theme-context";
import { MoonIcon, SunIcon } from "@/icons";

export default function ThemeToggler() {
  const { toggleTheme } = useTheme();

  return (
    <Button onClick={toggleTheme} className="size-14 rounded-full" aria-label="Toggle dark mode">
      <MoonIcon className="block dark:hidden" />
      <SunIcon className="hidden dark:block" />
    </Button>
  );
}
