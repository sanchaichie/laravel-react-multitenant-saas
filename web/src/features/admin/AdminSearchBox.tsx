import { useState } from 'react'
import { SearchIcon } from 'lucide-react'
import { Input } from '@/components/ui/input'
import { useDebouncedCallback } from '@/hooks/useDebouncedCallback'

interface AdminSearchBoxProps {
  /** The search in the URL. */
  value: string
  onSearch: (value: string) => void
  label: string
  placeholder: string
}

/** A search box ahead of the URL by a 300 ms debounce, following it when it changes some other way (Back). */
export function AdminSearchBox({ value, onSearch, label, placeholder }: AdminSearchBoxProps) {
  const [text, setText] = useState(value)
  // State adjusted during render when the prop changes, instead of an effect.
  const [seen, setSeen] = useState(value)
  if (value !== seen) {
    setSeen(value)
    if (value !== text.trim()) {
      setText(value)
    }
  }

  // Dropped if the URL changed meanwhile (Back): it would write an old search back.
  const push = useDebouncedCallback((next: string, typedOn: string) => {
    if (value === typedOn) {
      onSearch(next)
    }
  }, 300)

  return (
    <div className="relative max-w-md">
      <SearchIcon className="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground" aria-hidden />
      <Input
        type="search"
        aria-label={label}
        placeholder={placeholder}
        className="pl-8"
        maxLength={100}
        value={text}
        onChange={(event) => {
          setText(event.target.value)
          push(event.target.value.trim(), value)
        }}
      />
    </div>
  )
}
