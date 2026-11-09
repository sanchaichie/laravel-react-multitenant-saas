'use client'

import { type KeyboardEvent, useEffect, useLayoutEffect, useRef, useState } from 'react'
import { toast } from 'sonner'
import type { Task, TaskChatMessage } from '@shared/types/api'
import { Button } from '@/components/ui/button'
import { Dialog, DialogClose, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle, SheetTrigger } from '@/components/ui/sheet'
import { Skeleton } from '@/components/ui/skeleton'
import { Spinner } from '@/components/ui/spinner'
import { Textarea } from '@/components/ui/textarea'
import { type AiStreamStatus, useAiStream } from '@/hooks/use-ai-stream'
import { ChatIcon, SendIcon, SparkIcon, TrashBinIcon } from '@/icons'
import { ApiError, errorMessage } from '@/lib/errors'
import { cn } from '@/utils'
import { useClearTaskChat, useTaskChat } from './api'

// The API's limit (TaskChatPrompt::MAX_MESSAGE_LENGTH); the counter shows near it.
const MAX_MESSAGE = 2000

interface TaskChatSheetProps {
  task: Task
  /** The organization the page was rendered for (sent as X-Organization-Id). */
  organizationId: number
  /** Sending needs a verified email; reading and clearing your history don't. */
  verified: boolean
}

/**
 * "Ask AI" on the task page: your private chat about this task in TailAdmin's
 * side sheet. The sheet's content unmounts when it closes, so closing it stops an
 * answer being written (useAiStream aborts on unmount, the proxy aborts its call
 * to Laravel, and Laravel stops the AI call).
 */
export function TaskChatSheet({ task, organizationId, verified }: TaskChatSheetProps) {
  const [open, setOpen] = useState(false)

  return (
    <Sheet open={open} onOpenChange={setOpen}>
      {/* A real trigger: aria-haspopup/expanded, and focus comes back here on close. */}
      <SheetTrigger render={<Button variant="outline" />}>
        <SparkIcon aria-hidden />
        Ask AI
      </SheetTrigger>
      <SheetContent className="flex flex-col gap-0 p-0 data-[side=right]:w-full data-[side=right]:sm:max-w-md">
        <SheetHeader className="border-b border-gray-200 p-5 dark:border-gray-800">
          <SheetTitle>Ask AI about this task</SheetTitle>
          <SheetDescription>Only you see this conversation. The AI can read this task, never change it.</SheetDescription>
        </SheetHeader>
        {open && <TaskChat task={task} organizationId={organizationId} verified={verified} />}
      </SheetContent>
    </Sheet>
  )
}

/** A message on screen: saved ones from the API, or the exchange being written now. */
interface ShownMessage {
  key: string
  role: TaskChatMessage['role']
  content: string
  status: TaskChatMessage['status'] | 'streaming' | 'error'
}

function TaskChat({ task, organizationId, verified }: TaskChatSheetProps) {
  const chat = useTaskChat(organizationId, task.id)
  const clear = useClearTaskChat(organizationId, task.id)
  const stream = useAiStream(organizationId)
  const [draft, setDraft] = useState('')
  // The question being answered now, until the refetched history includes it.
  const [asked, setAsked] = useState<string | null>(null)
  // Stopped exchanges still shown as they were, until a later answer's refetch brings the saved rows.
  const [kept, setKept] = useState<ShownMessage[]>([])
  // One exchange at a time: a ref for the synchronous check (two Enters in a row), state for the button.
  const sendingRef = useRef(false)
  const [sending, setSending] = useState(false)
  const exchange = useRef(0)
  const mounted = useRef(true)
  useEffect(
    () => () => {
      mounted.current = false
    },
    [],
  )
  const listRef = useRef<HTMLDivElement>(null)
  // Follow new text to the bottom, unless the user scrolled up to read. A ref: no re-render.
  const stickToBottom = useRef(true)
  const [confirmClear, setConfirmClear] = useState(false)

  const streaming = stream.status === 'streaming'
  const saved = chat.data?.data ?? []
  // Server data and the live exchange, combined during render (never copied into state).
  const messages: ShownMessage[] = [
    ...saved.map((m) => ({ key: String(m.id), role: m.role, content: m.content, status: m.status })),
    ...kept,
    ...(asked === null ? [] : exchangeMessages('live', asked, stream.text, liveStatus(stream.status))),
  ]

  // After React updates the DOM, before the browser paints: keep the newest text in view.
  useLayoutEffect(() => {
    const list = listRef.current
    if (list && stickToBottom.current) list.scrollTop = list.scrollHeight
  }, [messages.length, stream.text])

  function onScroll() {
    const list = listRef.current
    if (list) stickToBottom.current = list.scrollHeight - list.scrollTop - list.clientHeight < 40
  }

  async function send() {
    const question = draft.trim()
    if (sendingRef.current || question === '' || question.length > MAX_MESSAGE) return
    sendingRef.current = true
    setSending(true)
    const id = ++exchange.current
    const isCurrent = () => mounted.current && exchange.current === id

    // A stopped exchange from before stays as it was shown.
    if (asked !== null) {
      setKept((previous) => [...previous, ...exchangeMessages(`kept-${id}`, asked, stream.text, 'stopped')])
    }
    setAsked(question)
    setDraft('')
    stickToBottom.current = true

    try {
      const end = await stream.start(`/api/tasks/${task.id}/chat`, { message: question })
      // Stopped: it stays on screen as it is.
      if (end.status === 'cancelled') return
      if (end.status === 'error') toast.error("Couldn't answer", { description: end.message })
      // Done or failed: the saved rows replace the live copies.
      await chat.refetch()
      if (!isCurrent()) return
      setAsked(null)
      setKept([])
      stream.reset()
    } catch (error) {
      if (!isCurrent()) return
      // Refused before anything was saved (budget, too many, invalid): give the question back.
      setAsked(null)
      setDraft(question)
      // A 409 (another tab switched organization) is handled by clientApi: it leaves the page.
      if (error instanceof ApiError && error.status === 409) return
      const message = error instanceof ApiError && error.status === 429 && error.data?.message ? error.data.message : errorMessage(error)
      toast.error('Could not send your question', { description: message })
    } finally {
      if (exchange.current === id) {
        sendingRef.current = false
        if (mounted.current) setSending(false)
      }
    }
  }

  function onKeyDown(event: KeyboardEvent<HTMLTextAreaElement>) {
    // Enter sends, Shift+Enter adds a line; not while an IME is composing a character.
    if (event.key === 'Enter' && !event.shiftKey && !event.nativeEvent.isComposing) {
      event.preventDefault()
      void send()
    }
  }

  function onClear() {
    setConfirmClear(false)
    clear.mutate(undefined, {
      onSuccess: () => {
        setAsked(null)
        setKept([])
        stream.reset()
        toast.success('Conversation cleared')
      },
      onError: (error) => toast.error('Could not clear the conversation', { description: errorMessage(error) }),
    })
  }

  const tooLong = draft.trim().length > MAX_MESSAGE

  return (
    <>
      {messages.length > 0 && (
        // Outside the composer: an unverified user can still clear their history.
        <div className="flex justify-end border-b border-gray-200 px-4 py-2 dark:border-gray-800">
          <Button type="button" variant="ghost" size="sm" disabled={sending || clear.isPending} onClick={() => setConfirmClear(true)}>
            <TrashBinIcon aria-hidden className="size-4" />
            Clear conversation
          </Button>
          <Dialog open={confirmClear} onOpenChange={setConfirmClear}>
            <DialogContent>
              <DialogHeader>
                <DialogTitle>Clear this conversation?</DialogTitle>
                <DialogDescription>Your messages about this task will be deleted. Other people&apos;s chats aren&apos;t affected.</DialogDescription>
              </DialogHeader>
              <DialogFooter>
                <DialogClose render={<Button variant="outline" />}>Cancel</DialogClose>
                <Button variant="destructive" onClick={onClear}>
                  Clear
                </Button>
              </DialogFooter>
            </DialogContent>
          </Dialog>
        </div>
      )}

      <div ref={listRef} onScroll={onScroll} className="min-h-0 flex-1 overflow-y-auto px-4 py-4">
        {chat.isPending ? (
          <div className="grid gap-3" aria-label="Loading the conversation">
            <Skeleton className="ms-auto h-10 w-2/3" />
            <Skeleton className="h-16 w-3/4" />
          </div>
        ) : chat.isError ? (
          <div role="alert" className="grid justify-items-center gap-3 py-8 text-center text-sm">
            <p className="text-gray-500 dark:text-gray-400">Could not load the conversation. {errorMessage(chat.error)}</p>
            <Button variant="outline" size="sm" onClick={() => void chat.refetch()}>
              Retry
            </Button>
          </div>
        ) : (
          <>
            {/* role="log" is always there (empty at first), so screen readers announce
                the first exchange too: a live region that appears with content isn't read. */}
            <ol role="log" aria-label="Conversation" className="grid gap-3">
              {messages.map((message) => (
                <ChatBubble key={message.key} message={message} />
              ))}
            </ol>
            {messages.length === 0 && (
              <div className="grid justify-items-center gap-2 py-12 text-center">
                <ChatIcon aria-hidden className="size-8 text-gray-400" />
                <p className="font-medium text-gray-800 dark:text-white/90">No messages yet</p>
                <p className="max-w-xs text-sm text-gray-500 dark:text-gray-400">
                  Ask anything about this task: what&apos;s left, the risks, a plan, a clearer wording.
                </p>
              </div>
            )}
          </>
        )}
      </div>

      <div className="grid gap-2 border-t border-gray-200 p-4 dark:border-gray-800">
        {!verified ? (
          <p className="text-sm text-gray-500 dark:text-gray-400">Verify your email to chat.</p>
        ) : (
          <form
            className="grid gap-2"
            onSubmit={(event) => {
              event.preventDefault()
              void send()
            }}
          >
            <label htmlFor="task-chat-message" className="sr-only">
              Your question
            </label>
            <Textarea
              id="task-chat-message"
              rows={2}
              placeholder="Ask about this task…"
              value={draft}
              onChange={(event) => setDraft(event.target.value)}
              onKeyDown={onKeyDown}
              aria-invalid={tooLong || undefined}
              aria-describedby="task-chat-hint"
            />
            <div className="flex flex-wrap items-center justify-between gap-2">
              <p id="task-chat-hint" className={cn('text-xs text-gray-500 dark:text-gray-400', tooLong && 'text-red-500')}>
                {draft.length > MAX_MESSAGE - 200
                  ? `${draft.trim().length} / ${MAX_MESSAGE} characters`
                  : 'Enter to send, Shift+Enter for a new line'}
              </p>
              {streaming ? (
                <Button type="button" size="sm" variant="outline" onClick={stream.stop}>
                  <span aria-hidden className="size-2.5 rounded-[2px] bg-current" />
                  Stop
                </Button>
              ) : (
                <Button type="submit" size="sm" disabled={sending || draft.trim() === '' || tooLong}>
                  <SendIcon aria-hidden className="size-4" />
                  Send
                </Button>
              )}
            </div>
          </form>
        )}
      </div>
    </>
  )
}

/** A question and its answer, as shown. */
function exchangeMessages(key: string, question: string, answer: string, status: ShownMessage['status']): ShownMessage[] {
  return [
    { key: `${key}-q`, role: 'user', content: question, status: 'complete' },
    { key: `${key}-a`, role: 'assistant', content: answer, status },
  ]
}

/** The live answer's status as a message shows it. */
function liveStatus(status: AiStreamStatus): ShownMessage['status'] {
  if (status === 'done') return 'complete'
  if (status === 'idle') return 'streaming'
  return status
}

function ChatBubble({ message }: { message: ShownMessage }) {
  const mine = message.role === 'user'
  return (
    <li className={cn('grid max-w-[85%] min-w-0 gap-1', mine ? 'justify-self-end' : 'justify-self-start')}>
      <span className="sr-only">{mine ? 'You:' : 'AI:'}</span>
      {/* Plain text, line breaks kept: never rendered as HTML. */}
      {message.content !== '' && (
        <p
          className={cn(
            'min-w-0 rounded-xl px-3 py-2 text-sm whitespace-pre-wrap [overflow-wrap:anywhere]',
            mine ? 'bg-brand-500 text-white' : 'bg-gray-100 text-gray-800 dark:bg-white/5 dark:text-white/90',
          )}
        >
          {message.content}
        </p>
      )}
      {message.status === 'streaming' && (
        <span className="flex items-center gap-2 text-xs text-gray-500 dark:text-gray-400">
          <Spinner className="size-3" />
          Writing…
        </span>
      )}
      {message.status === 'stopped' && <span className="text-xs text-gray-500 dark:text-gray-400">Stopped</span>}
      {(message.status === 'failed' || message.status === 'error') && <span className="text-xs text-red-500">Couldn&apos;t answer</span>}
    </li>
  )
}
