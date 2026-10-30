import { type KeyboardEvent, useEffect, useLayoutEffect, useRef, useState } from 'react'
import { MessageSquareIcon, SendIcon, SparklesIcon, SquareIcon, Trash2Icon } from 'lucide-react'
import { toast } from 'sonner'
import { ConfirmDialog } from '@/components/ConfirmDialog'
import { Button } from '@/components/ui/button'
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle, SheetTrigger } from '@/components/ui/sheet'
import { Skeleton } from '@/components/ui/skeleton'
import { Spinner } from '@/components/ui/spinner'
import { Textarea } from '@/components/ui/textarea'
import { useAiStream } from '@/hooks/useAiStream'
import { ApiError } from '@/lib/api'
import { errorMessage } from '@/lib/apiErrors'
import { cn } from '@/lib/utils'
import { useAuth } from '@/stores/auth'
import type { Task, TaskChatMessage } from '@/types/api'
import { useClearTaskChat, useTaskChat } from './api'

// The API's limit (TaskChatPrompt::MAX_MESSAGE_LENGTH); the counter shows near it.
const MAX_MESSAGE = 2000

/**
 * "Ask AI" on the task page: your private chat about this task in a side sheet.
 * The sheet's content unmounts when it closes, so closing it stops an answer being
 * written (useAiStream aborts on unmount).
 */
export function TaskChatSheet({ task }: { task: Task }) {
  return (
    <Sheet>
      <SheetTrigger asChild>
        <Button variant="outline">
          <SparklesIcon aria-hidden />
          Ask AI
        </Button>
      </SheetTrigger>
      {/* data-[side=right]: the same variant as the component's own 3/4 width, so this one wins. */}
      <SheetContent className="flex flex-col gap-0 p-0 data-[side=right]:w-full data-[side=right]:sm:max-w-md">
        <SheetHeader className="border-b">
          <SheetTitle>Ask AI about this task</SheetTitle>
          <SheetDescription>Only you see this conversation. The AI can read this task, never change it.</SheetDescription>
        </SheetHeader>
        <TaskChat task={task} />
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

function TaskChat({ task }: { task: Task }) {
  const verified = useAuth((s) => s.user?.email_verified ?? false)
  const chat = useTaskChat(task.id)
  const clear = useClearTaskChat(task.id)
  const stream = useAiStream()
  const [draft, setDraft] = useState('')
  // The question being answered now, until the saved history (refetched) includes it.
  const [asked, setAsked] = useState<string | null>(null)
  // Stopped exchanges still shown as they were: the server may still be saving them,
  // so they stay until a later answer's refetch brings the saved rows.
  const [kept, setKept] = useState<ShownMessage[]>([])
  // From Send until the exchange is over (its refetch included): one at a time. A ref
  // for the synchronous check (two Enters in a row), state for the button.
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
  // Whether to follow new text to the bottom: not after the user scrolled up to read.
  // A ref, not state: changing it shouldn't re-render.
  const stickToBottom = useRef(true)

  const streaming = stream.status === 'streaming'
  const saved = chat.data?.data ?? []
  // Server data and the live exchange, combined during render (never copied into state).
  const messages: ShownMessage[] = [
    ...saved.map((m) => ({ key: String(m.id), role: m.role, content: m.content, status: m.status })),
    ...kept,
    ...(asked === null ? [] : exchangeMessages('live', asked, stream.text, liveStatus(stream.status))),
  ]

  // After React updates the DOM but before the browser paints (no jump): keep the
  // newest text in view, like nextTick + scrollTop in Vue.
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
    // Still current and on screen after an await? (A closed sheet or a newer exchange: stop here.)
    const isCurrent = () => mounted.current && exchange.current === id

    // A stopped exchange from before stays as it was shown.
    if (asked !== null) {
      setKept((previous) => [...previous, ...exchangeMessages(`kept-${id}`, asked, stream.text, 'stopped')])
    }
    setAsked(question)
    setDraft('')
    stickToBottom.current = true

    try {
      // Started right away (nothing awaited first), so closing the sheet aborts it.
      const end = await stream.start(`/api/tasks/${task.id}/chat`, { message: question })
      // Stopped: it stays on screen as it is.
      if (end.status === 'cancelled') return
      if (end.status === 'error') toast.error("Couldn't answer", { description: end.message })
      // Done or failed: the saved rows (this exchange and any kept ones) replace the live copies.
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
    clear.mutate(undefined, {
      onSuccess: () => {
        // The live and kept exchanges were deleted too.
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
        <div className="flex justify-end border-b px-4 py-2">
          <ConfirmDialog
            title="Clear this conversation?"
            description="Your messages about this task will be deleted. Other people's chats aren't affected."
            confirmLabel="Clear"
            onConfirm={onClear}
          >
            <Button type="button" variant="ghost" size="sm" disabled={sending || clear.isPending}>
              <Trash2Icon aria-hidden />
              Clear conversation
            </Button>
          </ConfirmDialog>
        </div>
      )}
      <div ref={listRef} onScroll={onScroll} className="min-h-0 flex-1 overflow-y-auto px-4 py-4">
        {chat.isPending ? (
          <div className="grid gap-3" aria-label="Loading the conversation">
            <Skeleton className="ml-auto h-10 w-2/3" />
            <Skeleton className="h-16 w-3/4" />
          </div>
        ) : chat.isError ? (
          <div className="grid justify-items-center gap-3 py-8 text-center text-sm">
            <p className="text-muted-foreground">Could not load the conversation. {errorMessage(chat.error)}</p>
            <Button variant="outline" size="sm" onClick={() => void chat.refetch()}>
              Retry
            </Button>
          </div>
        ) : messages.length === 0 ? (
          <div className="grid justify-items-center gap-2 py-12 text-center">
            <MessageSquareIcon aria-hidden className="size-8 text-muted-foreground" />
            <p className="font-medium">No messages yet</p>
            <p className="max-w-xs text-sm text-muted-foreground">
              Ask anything about this task: what's left, the risks, a plan, a clearer wording.
            </p>
          </div>
        ) : (
          // role="log": screen readers announce new messages as they're added.
          <ol role="log" aria-label="Conversation" className="grid gap-3">
            {messages.map((message) => (
              <ChatBubble key={message.key} message={message} />
            ))}
          </ol>
        )}
      </div>

      <div className="grid gap-2 border-t p-4">
        {!verified ? (
          <p className="text-sm text-muted-foreground">Verify your email address to ask questions.</p>
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
              aria-invalid={tooLong}
              aria-describedby="task-chat-hint"
            />
            <div className="flex flex-wrap items-center justify-between gap-2">
              <p id="task-chat-hint" className={cn('text-xs text-muted-foreground', tooLong && 'text-destructive')}>
                {draft.length > MAX_MESSAGE - 200
                  ? `${draft.trim().length} / ${MAX_MESSAGE} characters`
                  : 'Enter to send, Shift+Enter for a new line'}
              </p>
              <div className="flex gap-2">
                {streaming ? (
                  <Button type="button" size="sm" variant="outline" onClick={stream.stop}>
                    <SquareIcon aria-hidden />
                    Stop
                  </Button>
                ) : (
                  <Button type="submit" size="sm" disabled={sending || draft.trim() === '' || tooLong}>
                    <SendIcon aria-hidden />
                    Send
                  </Button>
                )}
              </div>
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
function liveStatus(status: ReturnType<typeof useAiStream>['status']): ShownMessage['status'] {
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
            // overflow-wrap: anywhere also breaks a long unbroken word (a URL, "xxxx...").
            'min-w-0 rounded-lg px-3 py-2 text-sm whitespace-pre-wrap [overflow-wrap:anywhere]',
            mine ? 'bg-primary text-primary-foreground' : 'bg-muted',
          )}
        >
          {message.content}
        </p>
      )}
      {message.status === 'streaming' && (
        <span className="flex items-center gap-2 text-xs text-muted-foreground">
          <Spinner />
          Writing…
        </span>
      )}
      {message.status === 'stopped' && <span className="text-xs text-muted-foreground">Stopped</span>}
      {(message.status === 'failed' || message.status === 'error') && (
        <span className="text-xs text-destructive">Couldn't answer</span>
      )}
    </li>
  )
}
