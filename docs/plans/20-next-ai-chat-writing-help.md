# 20 — AI task chat and writing help in the Next.js app

**Status:** Done
**Commits:** `66cf0dc` (next)

## Goal
Bring the two streaming AI features to the Next.js frontend (`next/`), on the API built in plans 12 and 13:
- **Ask AI:** a private chat about one task in a side sheet on the task page. Answers stream in word by word, with Stop and Clear.
- **Writing help:** Improve, Expand and Shorten under the description in the New task and Edit task forms. The answer streams into a preview you accept, retry or discard.

It's the clearest side-by-side for a client: the same streamed answer, read in the browser by a React SPA (`web/`) and by a Next.js app whose token never leaves the server.

Out of scope:
- AI summaries (the summary card), and the AI usage and budget page: later plans;
- any API change (the endpoints, prompts, limits and fake provider stay as they are);
- a Server Actions version of streaming (see Decisions).

## Decisions
- **Streams go through the `/api` proxy, not a Server Action.** A Server Action answers once, while chat needs text as it arrives and a Stop that cancels the AI call. The browser `fetch`es `/api/tasks/{id}/chat` on this origin. The proxy adds the cookie's token and pipes Laravel's server-sent events through, already allowed by `ALLOWED` (`tasks/...`). The token still never reaches page scripts.
- **The proxy must not buffer an event stream.**
  - `next start` gzips responses by default, and gzip holds small chunks back.
  - For `text/event-stream` answers the proxy sets `Cache-Control: private, no-store, no-transform` (the compression step skips those) and passes `X-Accel-Buffering: no` on.
  - When the browser goes away, `request.signal` aborts the upstream fetch, so Laravel sees the disconnect and stops the AI call. That's the same path as web's Stop.
  - Checked end to end, since unit tests can't see compression.
- **Client calls get one helper, `lib/clientApi.ts`** (planned in 19, needed now). It calls `fetch('/api/...')` with `X-Organization-Id` (the organization the page was rendered for) and throws the same `ApiError`/`NetworkError` as the server side. On a 409 `organization_changed` it toasts and goes to `/tasks`, then `router.refresh()`. On a 401 it goes to `/session-expired`. `clientStream()` is the POST that reads server-sent events, with `lib/sse.ts` ported from `web/` (and its tests).
- **`useAiStream()` is ported from `web/hooks/useAiStream.ts`:** the same states (`idle | streaming | done | error | stopped`), the same `start` (resolves with how the stream ended, rejects when the request is refused), `stop` and `reset`, and aborting on unmount. Its tests come too.
- **History is read with TanStack Query, client side.** The chat opens on demand in a sheet and refetches after each answer, which is client state. That's the case for a query cache, not a Server Component. This adds `@tanstack/react-query` to `next/`; it's already in the approved stack and listed for `next/` in plan 19.
  - A `QueryProvider` (client) goes in `app/(app)/layout.tsx`.
  - Keys follow web's: `['org', organizationId, 'tasks', 'detail', taskId, 'chat']`.
  - Clear is a `useMutation` that sets the list to empty, as in web.
- **The UI is TailAdmin's.** It uses `ui/sheet` (right side, full width on phones), `Textarea`, `Button`, `Dialog` to confirm Clear, and `Spinner`. The message list and composer are small components here; TailAdmin has no chat widget in the free set.
- **Writing help in a posted form.** The task form's fields are uncontrolled, posted to a Server Action. Writing help reads the current title and description from the form (`new FormData(formRef.current)`) when you pick a mode. Accept writes the text into the description textarea through a ref and focuses it. Nothing is saved until you press Save.
- **Who sees what:** the API decides, the app only hides.
  - Ask AI shows with `task.can.chat` and not `task.content_hidden`.
  - Writing help when editing shows with `task.can.write_with_ai`.
  - Writing help on a new task shows with `ai.use` and `tasks.create` in the organization's permissions, plus a verified email, the same rule as web.
  - An unverified user sees their chat history, with "Verify your email to chat" in place of the composer.
- **No new dependency** beyond `@tanstack/react-query`.

## Backend (`api/`)
No change. The endpoints are used as plans 12 and 13 built them:
- `GET|POST|DELETE /api/tasks/{task}/chat`
- `POST /api/tasks/writing-help` and `POST /api/tasks/{task}/writing-help`

They answer with SSE `delta {text}`, `done {model}` or `error {message}`, or a 402, 422 or 429 before the stream starts.

## Frontend (`next/`)
1. **`app/api/[...path]/route.ts`:** for an upstream `text/event-stream`, set `Cache-Control: private, no-store, no-transform` and keep `X-Accel-Buffering: no`. Everything else stays the same.
2. **`lib/sse.ts`:** `parseSseChunk`, copied from `web/` with its test.
3. **`lib/clientApi.ts`:** `clientFetch<T>(path, {method, body, organizationId, signal})` and `clientStream(path, {body, organizationId, signal, onEvent})`, plus the 401 and 409 handling.
4. **`hooks/use-ai-stream.ts`:** the port of `useAiStream`. It takes the organization id for the header.
5. **`components/QueryProvider.tsx`:** one `QueryClient` per browser session, in `useState`. The `(app)` layout wraps its children with it.
6. **`features/taskChat/`:**
   - **`api.ts`:** `useTaskChat(organizationId, taskId)` (fetched each time the sheet opens) and `useClearTaskChat`.
   - **`TaskChatSheet.tsx`:** the Ask AI button and sheet. It holds:
     - a message list (`role="log"`; your questions on the right, answers on the left as plain text with `whitespace-pre-wrap`, "Stopped" and "Couldn't answer" labels);
     - the live exchange after the saved history, combined during render;
     - "Writing…" with a Stop button;
     - sticky scrolling with `useLayoutEffect` and a `stickToBottom` ref;
     - the composer: Enter sends, Shift+Enter adds a line, ignored while an IME is composing, 2,000-character limit with a counter;
     - Clear with a confirm dialog;
     - loading, empty and error states (with Retry);
     - toasts for 402, 422 and 429, giving the question back to the composer.

     Closing the sheet unmounts the chat, which stops a running answer.
7. **`features/tasks/TaskWritingHelp.tsx`:** "Improve with AI" (Improve, Expand, Shorten) under the description.
   - The preview streams in, with Stop.
   - Then Accept, Try again or Discard.
   - The modes follow web's rules: Improve and Shorten need a description, Expand needs any text.
8. **`features/tasks/TaskForm.tsx`:** gains a form ref, a description ref and the writing help. The page passes whether the user may use it (see Decisions).
9. **`app/(app)/tasks/[id]/page.tsx`:** the Ask AI button in the header, next to Edit.
10. **Shared types:** `TaskChatMessage` and `WritingHelpMode` are already in `shared/types/api.ts`.

React concepts (compared with what `web/` and Nuxt do):
- Reading a streamed `fetch` body in a client component (`ReadableStream` + `TextDecoder`) ↔ the same code in `web/`; in Nuxt, `$fetch` with `responseType: 'stream'`.
- Why streaming doesn't use a Server Action: an action is one request and one answer, not a stream; the route-handler proxy is.
- TanStack Query next to Server Components: server data for the page, the query cache for client-only state that refetches, `QueryClientProvider` in a client component ↔ `useAsyncData` for page data plus a Pinia store for the chat.
- `useReducer` for the stream's state machine ↔ a small store with mutations.
- Uncontrolled inputs changed through refs (writing help writes into a posted form's textarea) ↔ `v-model` on a ref'd element.
- `useLayoutEffect` to scroll before paint ↔ `nextTick` + a template ref.

## Tests
Vitest in `next/`:
- **`lib/sse.test.ts`:** events split across chunks, several events in one chunk, `\r\n`, data that isn't JSON (ported).
- **`hooks/use-ai-stream.test.tsx`:**
  - text arrives in order and ends `done`;
  - an `error` event; a stream that breaks off;
  - Stop aborts the fetch and never changes the state again;
  - unmounting aborts;
  - a 402 before the stream rejects with `ApiError` and goes back to `idle`;
  - `X-Organization-Id` is sent.
- **`lib/clientApi.test.ts`:** a 409 `organization_changed` goes to `/tasks` and refreshes; a 401 goes to `/session-expired`; a network failure is a `NetworkError`.
- **`app/api/[...path]/route.test.ts`:**
  - an event-stream answer comes back unbuffered: the body is the upstream stream, read chunk by chunk, with `no-transform`;
  - the browser aborting aborts the upstream fetch.
- **`features/taskChat/TaskChatSheet.test.tsx`:**
  - history renders oldest first;
  - send streams in, then the history refetch replaces the live copy;
  - Enter vs Shift+Enter;
  - Stop keeps the partial answer as "Stopped";
  - Clear, after confirming, empties the list;
  - a 402 toasts and gives the question back;
  - an unverified user sees the history without the composer;
  - without `can.chat` there's no button.
- **`features/tasks/TaskWritingHelp.test.tsx`:**
  - the modes are enabled by what's typed;
  - the preview streams;
  - Accept writes into the description field and focuses it, without saving;
  - Discard; a 429 toast with the API's message.
- **`TaskForm`:** writing help is hidden without `ai.use` and for an unverified user on a new task, and shown with `can.write_with_ai` when editing.

No Pest changes: the API is unchanged, and its chat isolation tests (plan 13) still cover org A vs org B.

## README screenshots
`docs/screenshots/next-task-chat.png`: a task page in the Next app with the Ask AI sheet open, a question and a streamed answer (fake provider), at 1280×800 in light mode. Captured from e2e demo data as in plan 19. It's shown in the "React or Next.js" section, and the coverage table there gains "AI chat and writing help".

## Verification
`verify` (all sides: no API change, but CI runs them all), `e2e-smoke` with `FRONTEND=next ... start --mail` (fake AI, 60 ms between words), then the code, security and tenant-isolation reviewers, and `ui-reviewer` on the sheet at 390px and on the form's writing help.

Flows (for `flow-tester`, Next frontend on :5112):
1. A verified Owner opens a task and clicks **Ask AI**. The sheet says only they see it. They ask "What's left?" and the answer appears word by word, not all at once (the proxy isn't buffering). Reload and reopen: both messages are there.
2. Ask again and press **Stop** halfway. The partial answer shows "Stopped". The API log shows the stream stopped, and `ai_usage` has a row with `error_code = aborted`.
3. Close the sheet while an answer is being written. Reopen: the answer is saved as stopped, and nothing keeps streaming.
4. **Clear conversation**, then confirm: the list is empty and stays empty after a reload.
5. Log in as another member of the same organization and open the same task's chat: they see an empty conversation, never the first user's.
6. An unverified user opens the chat: the history shows, and "Verify your email to chat" replaces the composer. A role without `ai.use`: no Ask AI button.
7. **Edit task**, then **Improve with AI** → **Improve**: the preview streams in. **Accept**: the description field holds the text, nothing is saved yet. **Save**: the task page shows the new description.
8. **New task**: type a title, choose **Expand**, accept, create. The new task has the expanded description.
9. Set the organization's AI budget to 0 (API), then ask in the chat: a toast says the budget is used up, and the question stays in the composer.
10. Two tabs: switch organization in one, then send a chat message in the other. The other tab says the organization changed and goes to `/tasks` (409).

## Commits after approval
`feat(next): streamed AI task chat and writing help`, then this plan → Done (plus `chore(claude): ...` if the e2e tooling changes).

## Follow-ups
- AI summaries (the summary card) and the AI usage and budget page in `next/`.
- After Stop, the live answer can show a few words fewer than the row the server saves (it notices the abort a moment later); the next refetch shows the saved text. Harmless, same as `web/`.
- `useReportFailure` in `features/tasks/TaskActions.tsx` no longer uses a hook: rename it to a plain function next time it's touched.
