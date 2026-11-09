'use client'

import { useRef, useState } from 'react'
import { toast } from 'sonner'
import type { WritingHelpMode } from '@shared/types/api'
import { FormMessage } from '@/components/FormMessage'
import { Button } from '@/components/ui/button'
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown'
import { Spinner } from '@/components/ui/spinner'
import { useAiStream } from '@/hooks/use-ai-stream'
import { CheckIcon, ChevronDownIcon, RegenerateIcon, SparkIcon, XIcon } from '@/icons'
import { ApiError, errorMessage } from '@/lib/errors'

const MODES: { mode: WritingHelpMode; label: string; needsDescription: boolean }[] = [
  { mode: 'improve', label: 'Improve', needsDescription: true },
  { mode: 'expand', label: 'Expand', needsDescription: false },
  { mode: 'shorten', label: 'Shorten', needsDescription: true },
]

export interface Draft {
  title: string
  description: string
}

interface TaskWritingHelpProps {
  organizationId: number
  /** The task being edited; none in the New task form. */
  taskId?: number
  /**
   * The form's title and description as typed now (read when needed: the fields
   * are uncontrolled, posted to a Server Action, so there's no state to watch).
   */
  readDraft: () => Draft
  /** Puts the accepted text into the description field (the user still saves the form). */
  onAccept: (text: string) => void
}

/** Whether a mode can run on this draft: improve and shorten need a description, expand any text. */
export function canRun(mode: WritingHelpMode, draft: Draft): boolean {
  const hasDescription = draft.description.trim() !== ''
  return MODES.find((item) => item.mode === mode)?.needsDescription ? hasDescription : hasDescription || draft.title.trim() !== ''
}

/**
 * "Improve with AI" under the description: the answer streams into a preview the
 * user accepts into the field, tries again or discards. Nothing is saved here.
 */
export function TaskWritingHelp({ organizationId, taskId, readDraft, onAccept }: TaskWritingHelpProps) {
  const stream = useAiStream(organizationId)
  const [mode, setMode] = useState<WritingHelpMode | null>(null)
  // The draft as it was when the menu opened: decides which modes are enabled.
  const [draftAtOpen, setDraftAtOpen] = useState<Draft>({ title: '', description: '' })
  const menuButton = useRef<HTMLButtonElement>(null)

  async function run(next: WritingHelpMode) {
    const draft = readDraft()
    if (!canRun(next, draft)) {
      toast.info(next === 'expand' ? 'Type a title or a description first.' : 'Type a description first.')
      return
    }
    setMode(next)
    try {
      await stream.start(taskId ? `/api/tasks/${taskId}/writing-help` : '/api/tasks/writing-help', {
        mode: next,
        title: draft.title.trim() || null,
        description: draft.description.trim() || null,
      })
    } catch (error) {
      // A 409 is handled by clientApi (it leaves the page).
      if (error instanceof ApiError && error.status === 409) return
      // Refused before any text: budget (402), too many requests (429, the API says which limit), invalid (422).
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
        <DropdownMenu onOpenChange={(open) => open && setDraftAtOpen(readDraft())}>
          {/* type="button": inside the task form, it must not submit it. */}
          <DropdownMenuTrigger
            ref={menuButton}
            render={<Button type="button" variant="outline" size="sm" />}
          >
            <SparkIcon aria-hidden className="size-4" />
            Improve with AI
            <ChevronDownIcon aria-hidden className="size-4" />
          </DropdownMenuTrigger>
          <DropdownMenuContent align="start" className="w-48 p-2">
            {MODES.map((item) => (
              <DropdownMenuItem key={item.mode} disabled={!canRun(item.mode, draftAtOpen)} onClick={() => void run(item.mode)}>
                {item.label}
              </DropdownMenuItem>
            ))}
          </DropdownMenuContent>
        </DropdownMenu>
      </div>

      {stream.status !== 'idle' && (
        <section
          aria-label="AI suggestion"
          className="grid gap-3 rounded-xl border border-gray-200 bg-gray-50 p-4 dark:border-gray-800 dark:bg-white/3"
        >
          <p className="flex items-center gap-2 text-xs font-medium text-gray-500 dark:text-gray-400">
            <SparkIcon aria-hidden className="size-3.5" />
            AI suggestion{label ? ` · ${label}` : ''}
          </p>
          {/* aria-live: screen readers read the text as it arrives. Plain text, never HTML. */}
          <p className="min-h-5 text-sm break-words whitespace-pre-wrap text-gray-800 dark:text-white/90" aria-live="polite">
            {stream.text}
          </p>

          {stream.status === 'error' && <FormMessage message={stream.error ?? undefined} />}
          {stream.status === 'stopped' && <p className="text-xs text-gray-500">Stopped.</p>}

          <div className="flex flex-wrap gap-2">
            {streaming ? (
              <>
                <span className="flex items-center gap-2 text-xs text-gray-500 dark:text-gray-400">
                  <Spinner className="size-3" />
                  Writing…
                </span>
                <Button type="button" size="sm" variant="outline" onClick={stream.stop}>
                  <span aria-hidden className="size-2.5 rounded-[2px] bg-current" />
                  Stop
                </Button>
              </>
            ) : (
              <>
                {/* Only a finished, non-empty answer can replace the description. */}
                {stream.status === 'done' && stream.text.trim() !== '' && (
                  <Button type="button" size="sm" onClick={accept}>
                    <CheckIcon aria-hidden className="size-4" />
                    Accept
                  </Button>
                )}
                {mode && (
                  <Button type="button" size="sm" variant="outline" onClick={() => void run(mode)}>
                    <RegenerateIcon aria-hidden className="size-4" />
                    Try again
                  </Button>
                )}
                <Button type="button" size="sm" variant="outline" onClick={discard}>
                  <XIcon aria-hidden className="size-4" />
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
