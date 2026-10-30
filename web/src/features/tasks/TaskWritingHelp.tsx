import { useRef, useState } from 'react'
import { CheckIcon, ChevronDownIcon, RefreshCwIcon, SparklesIcon, SquareIcon, XIcon } from 'lucide-react'
import { toast } from 'sonner'
import { Alert, AlertDescription } from '@/components/ui/alert'
import { Button } from '@/components/ui/button'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import { Spinner } from '@/components/ui/spinner'
import { useAiStream } from '@/hooks/useAiStream'
import { ApiError } from '@/lib/api'
import { errorMessage } from '@/lib/apiErrors'
import type { Task, WritingHelpMode } from '@/types/api'

const MODES: { mode: WritingHelpMode; label: string; needsDescription: boolean }[] = [
  { mode: 'improve', label: 'Improve', needsDescription: true },
  { mode: 'expand', label: 'Expand', needsDescription: false },
  { mode: 'shorten', label: 'Shorten', needsDescription: true },
]

interface TaskWritingHelpProps {
  /** The task being edited; none in the New task form. */
  task?: Task
  /** The form's current title and description: that's what gets rewritten, not the saved task. */
  title: string
  description: string
  /** Puts the accepted text into the description field (the user still saves the form) and focuses it. */
  onAccept: (text: string) => void
}

/**
 * "Improve with AI" under the description: the answer streams into a preview the
 * user accepts into the field, tries again or discards. Nothing is saved here.
 */
export function TaskWritingHelp({ task, title, description, onAccept }: TaskWritingHelpProps) {
  const stream = useAiStream()
  const [mode, setMode] = useState<WritingHelpMode | null>(null)
  // The menu button: Discard sends focus back to it (the preview it was in is gone).
  const menuButton = useRef<HTMLButtonElement>(null)
  const hasDescription = description.trim() !== ''
  const hasText = hasDescription || title.trim() !== ''
  // The same rule as the menu: improve and shorten need a description, expand any text.
  const canRun = (m: WritingHelpMode) => (MODES.find((item) => item.mode === m)?.needsDescription ? hasDescription : hasText)

  async function run(next: WritingHelpMode) {
    setMode(next)
    try {
      await stream.start(task ? `/api/tasks/${task.id}/writing-help` : '/api/tasks/writing-help', {
        mode: next,
        title: title.trim() || null,
        description: description.trim() || null,
      })
    } catch (error) {
      // Refused before any text: budget (402), too many requests (429), invalid (422)...
      // The API's 429s say which limit (per minute, or answers running at once).
      const message = error instanceof ApiError && error.status === 429 && error.data?.message ? error.data.message : errorMessage(error)
      toast.error('Could not get writing help', { description: message })
    }
  }

  function accept() {
    onAccept(stream.text.trim())
    stream.reset()
  }

  function discard() {
    stream.reset()
    menuButton.current?.focus()
  }

  const streaming = stream.status === 'streaming'
  const label = MODES.find((m) => m.mode === mode)?.label

  return (
    <div className="grid gap-3">
      <div>
        <DropdownMenu>
          <DropdownMenuTrigger asChild>
            {/* type="button": inside the task form, it must not submit it. Enabled while an
                answer streams (picking again replaces it), so the menu can return focus here. */}
            <Button ref={menuButton} type="button" variant="outline" size="sm" disabled={!hasText}>
              <SparklesIcon aria-hidden />
              Improve with AI
              <ChevronDownIcon aria-hidden />
            </Button>
          </DropdownMenuTrigger>
          <DropdownMenuContent align="start">
            {MODES.map((item) => (
              <DropdownMenuItem
                key={item.mode}
                disabled={!canRun(item.mode)}
                onSelect={() => void run(item.mode)}
              >
                {item.label}
              </DropdownMenuItem>
            ))}
          </DropdownMenuContent>
        </DropdownMenu>
      </div>

      {stream.status !== 'idle' && (
        <section aria-label="AI suggestion" className="grid gap-3 rounded-md border bg-muted/40 p-3">
          <p className="flex items-center gap-2 text-xs font-medium text-muted-foreground">
            <SparklesIcon aria-hidden className="size-3.5" />
            AI suggestion{label ? ` · ${label}` : ''}
          </p>
          {/* aria-live: screen readers read the text as it arrives. Plain text, never HTML. */}
          <p className="min-h-5 text-sm break-words whitespace-pre-wrap" aria-live="polite">
            {stream.text}
          </p>

          {stream.status === 'error' && (
            <Alert variant="destructive">
              <AlertDescription>{stream.error}</AlertDescription>
            </Alert>
          )}
          {stream.status === 'stopped' && <p className="text-xs text-muted-foreground">Stopped.</p>}

          <div className="flex flex-wrap gap-2">
            {streaming ? (
              <>
                <span className="flex items-center gap-2 text-xs text-muted-foreground">
                  <Spinner />
                  Writing…
                </span>
                <Button type="button" size="sm" variant="outline" onClick={stream.stop}>
                  <SquareIcon aria-hidden />
                  Stop
                </Button>
              </>
            ) : (
              <>
                {/* Only a finished, non-empty answer can replace the description. */}
                {stream.status === 'done' && stream.text.trim() !== '' && (
                  <Button type="button" size="sm" onClick={accept}>
                    <CheckIcon aria-hidden />
                    Accept
                  </Button>
                )}
                {mode && (
                  <Button type="button" size="sm" variant="outline" onClick={() => void run(mode)} disabled={!canRun(mode)}>
                    <RefreshCwIcon aria-hidden />
                    Try again
                  </Button>
                )}
                <Button type="button" size="sm" variant="outline" onClick={discard}>
                  <XIcon aria-hidden />
                  Discard
                </Button>
              </>
            )}
          </div>
        </section>
      )}
    </div>
  )
}
