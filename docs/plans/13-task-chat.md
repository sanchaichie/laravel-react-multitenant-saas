# 13 — Task chat

**Status:** Done
**Commits:** `b6b69c8` (api), `feef1d3` (web)

## Goal
An **Ask AI** chat on the task page: you ask questions about one task (what's left, risks, a plan, a rewording) and the answer streams in. It's built on plan 12's streaming.
- History is saved per user per task, and only you see your own.
- You can stop an answer or clear the conversation.

This rebuilds saas-starter plan 16, including its "Changes while building".

**Out of scope:**
- tools, or any way for the AI to change the task (it only answers);
- chat across several tasks, or about the whole organization;
- sharing a conversation with other members;
- a retention limit on old messages (a follow-up).

## Decisions
- **History is private:**
  - Each message belongs to (organization, task, user), and every query filters by the current user.
  - Not even an Owner can read someone else's chat, so there's no "view all" permission.
  - It isn't audited: it's personal and changes a lot, just like saas-starter.
- **The database cleans up:**
  - `task_chat_messages` points at its task through `(organization_id, task_id)` and at the membership through `(organization_id, user_id)`, both as composite foreign keys with cascading deletes.
  - Deleting the task, or removing the member (or leaving), deletes those chats. A re-invited member never gets old conversations back.
  - No code in `RemoveMember` is needed.
- **Who may chat:** `TaskPolicy::chat` = view the task (any member) + `ai.use`. Sending also needs a verified email, `throttle:ai`, the budget and a stream slot, as with writing help.
  - Reading and clearing your own history needs only `chat`, so an unverified user still sees their past messages.
  - `TaskResource` gets `can.chat` (the policy + verified).
- **The answer row is saved up front.** The question and an empty answer (`stopped`) are saved together before streaming. The answer row is updated when the stream ends: `complete`, `stopped` (with the text so far) or `failed`.
  - This keeps the order right if the next question arrives before the server notices a stop.
  - A Clear can't be undone by a late save, because it's an update by id and finds nothing.
  - A worker that dies mid-answer leaves `stopped`. To do this, `AiEventStream` gains an `onEnd($text, $outcome, $model)` hook, called once before the last event.
- **The prompt (`TaskChatPrompt`, feature `task_chat`):**
  - The system prompt holds only this task: title, description, status, priority, due date and the approved summary, all in `<task>` tags, escaped (`htmlspecialchars`), as data.
  - The messages are the user's last 20, oldest first. Failed or empty answers are left out, and messages from the same role in a row are joined, because the API wants alternating turns starting with the user.
  - The rules: discuss only this task; it can't change anything; don't invent facts; never ask for credentials; no links that aren't in the task.
  - Questions are at most 2,000 characters, and answers 1,500 tokens.
- **Web: a shadcn `Sheet`** (already installed) opened from an **Ask AI** button on the task page.
  - There's no chat component in shadcn's base registry, so the message list and composer are small components here.
  - History comes from TanStack Query. The streaming answer comes from `useAiStream`, which picks Stop up for free. After each answer the history query is refetched, so the saved rows replace the live ones.
- **No new dependency.**

## Backend (`api/`)
1. **Migration `create_task_chat_messages_table`:**
   - `id`, `organization_id`, `task_id`, `user_id`, `role` (string 16), `content` (text), `model` (nullable), `status` (string 16), timestamps;
   - foreign keys: `(organization_id, task_id)` → `tasks(organization_id, id)` cascade, `(organization_id, user_id)` → `organization_user(organization_id, user_id)` cascade, and `organization_id` → `organizations` cascade;
   - index `(task_id, user_id, id)`: one conversation, newest last.
2. **Enums `ChatMessageRole`** (`user`, `assistant`) and **`ChatMessageStatus`** (`complete`, `stopped`, `failed`).
3. **Model `TaskChatMessage`:**
   - `BelongsToOrganization`, with no fillable fields;
   - scope `conversation(Task, User)`;
   - `Task::chatMessages()`.
4. **`AiEventStream`:** an optional `?Closure $onEnd`, called once with `(text, outcome: done|stopped|error, model)` before the last event. A failing hook is reported, and the browser still gets its event.
5. **`TaskChatPrompt`** (above): `MAX_MESSAGE_LENGTH = 2000`, `HISTORY = 20`.
6. **Actions:**
   - **`SendTaskChatMessage`:**
     - checks the budget;
     - saves the question and the empty answer, then loads the history and builds the prompt (with the approved summary);
     - takes a slot last;
     - returns `AiEventStream`, whose `onEnd` updates the answer row by id.
   - **`ClearTaskChat`:** deletes your messages on that task.
7. **`SendTaskChatMessageRequest`:** `message` required, a string, at most 2000 characters, and not only whitespace.
8. **`TaskChatMessageResource`:** `id`, `role`, `content`, `status`, `created_at`.
9. **Routes** (in the `organization` group, with `{task}` bound through the scope):
   - `GET /api/tasks/{task}/chat`: your last 50 messages, oldest first;
   - `POST /api/tasks/{task}/chat` (`verified`, `throttle:ai`): server-sent events (`delta`, `done`, `error`);
   - `DELETE /api/tasks/{task}/chat` (`throttle:60,1,task-chat`): 204.

   Controller: `TaskChatController`, thin.

   | Who | Read and clear own chat | Send |
   |---|---|---|
   | Owner, Admin, Member | ✓ | ✓ (verified) |
   | Viewer with `ai.use` | ✓ | ✓ (verified) |
   | Role without `ai.use` | ✗ | ✗ |
   | Another organization | 404 | 404 |
10. **`FakeAiClient`:** a default `task_chat` stream (about 30 words).

## Frontend (`web/`)
11. **Types:**
    - `TaskChatMessage` (`role`, `content`, `status`, `created_at`);
    - `Task['can']` gains `chat`.
12. **`features/taskChat/api.ts`:**
    - `useTaskChat(taskId, enabled)`, with key `['org', id, 'tasks', 'detail', taskId, 'chat']`, under the task's detail key so a switch drops it;
    - `useClearTaskChat()`, which sets the cache to an empty list.
13. **`features/taskChat/TaskChatSheet.tsx`:**
    - An **Ask AI** button (shown with `task.can.chat`) opens a right-hand `Sheet`, full width on phones, titled "Ask AI about this task".
    - **Messages:** an `ol` with `role="log"`.
      - Your questions sit on the right; answers sit on the left, as plain text with `whitespace-pre-wrap`.
      - Stopped answers say "Stopped"; failed ones say "Couldn't answer".
    - **While streaming:** the question you just sent and the live answer show after the saved history. Below them, "Writing…" and **Stop**.
    - **Scrolling:** the list scrolls to the bottom when it opens and as text arrives, unless you've scrolled up to read.
    - **Composer:** a `Textarea`, where Enter sends and Shift+Enter adds a new line, and a **Send** button. It's disabled while streaming, or when empty or over 2,000 characters (with a counter near the limit).
      - The composer is shown only with `task.can.chat`. An unverified user sees "Verify your email to chat" instead.
    - **Clear conversation** goes through `ConfirmDialog`.
    - **States:** loading (skeletons), empty ("Ask anything about this task: what's left, risks, a plan…"), and an error with Retry.
    - **Request errors** show as toasts:
      - 402: the budget is used up;
      - 429: the API's own message;
      - 422: the message.
      In each case the question stays in the composer, since it wasn't saved.
    - **After each answer**, the history query is refetched.
14. **`TaskDetailPage`:** the Ask AI button sits in the header next to Edit.
15. **AI usage:** `featureLabel('task_chat')` reads "Task chat".

React concepts:
- `useLayoutEffect` to scroll the list after the DOM updates but before paint (no flicker) ↔ `nextTick` + a template ref in `watch(..., { flush: 'post' })`.
- A ref (`useRef`) to a DOM node, plus a "stuck to bottom" flag kept in a ref rather than state ↔ a template ref and a plain variable.
- Combining server state (TanStack Query history) with in-flight local state (the live answer from `useAiStream`) during render, without copying one into the other ↔ a `computed` that merges a store's data with a local `ref`.
- Keyboard handling on a textarea (`onKeyDown`, Enter vs Shift+Enter, ignoring IME composition with `isComposing`) ↔ `@keydown.enter.exact`.
- A controlled `Sheet` that unmounts its content when closed, so leaving stops the stream through the hook's cleanup ↔ `v-if` on a drawer's content.

## Tests
- **`TaskChatTest`:**
  - **Sending:**
    - it streams `delta`, then `done`;
    - the question is saved as `complete`;
    - the answer is saved with the full text, `complete` and the model;
    - the usage row has feature `task_chat`.
  - **History:**
    - `GET` returns the last 50, oldest first;
    - the prompt sends the last 20;
    - failed answers are left out, same-role turns are joined, and the history always starts with the user.
  - **The prompt:**
    - it contains only this task's fields and the approved summary (not a draft), escaped;
    - another task's or another user's messages are never in it (`assertSent`).
  - **Endings:**
    - stopped (`FakeAiClient::ABORT`): the answer is `stopped` with the partial text;
    - an AI error part-way: `failed` with the partial text, and an `error` event.
  - **Clearing:**
    - Clear deletes only your messages on that task;
    - an answer that ends after a Clear doesn't come back.
  - **Privacy:** another member of the same organization sees none of your messages, and their Clear leaves yours.
  - **Limits:**
    - over budget: 402, and nothing saved;
    - stream slot full: 429, and nothing saved;
    - `throttle:ai`;
    - a message that's empty, only spaces, or 2001 characters: 422.
  - **Permissions:**
    - the table above as a dataset;
    - unverified: reading and clearing work, sending gets 403 `email_unverified`;
    - `can.chat` in `TaskResource`.
- **Isolation:**
  - org B's task gives 404 on all three routes;
  - B's messages never reach A's prompt;
  - a user in two organizations sees each task's own chat only;
  - the composite keys: a message can't point at another organization's task or a non-member (a direct insert is refused).
- **Cascades:**
  - deleting the task deletes its messages;
  - removing or leaving deletes that member's messages in that organization only (their chats elsewhere stay).
- **Vitest (`TaskChatSheet`):**
  - opens from Ask AI, with the loading, empty and error states;
  - sending streams the answer under the question, then the saved history shows;
  - Enter sends and Shift+Enter adds a new line;
  - Stop;
  - an `error` event;
  - 402 and 429 toasts keep the text;
  - Clear goes through the confirm dialog;
  - there's no Ask AI button without `can.chat`;
  - unverified users see the note instead of the composer.

## README screenshots
- New `docs/screenshots/task-chat.png`: the task page with the Ask AI sheet open, showing two exchanges and the composer. 1280×800, light mode, e2e demo data.

## Verification
- `/verify`.
- `/e2e-smoke` with `--mail` (the fake provider streams slowly, so you can watch it and press Stop).
- Reviewers in parallel:
  - code;
  - security: stored prompt injection through task fields, privacy of chats, budget and slots, error leakage;
  - tenant isolation: the composite keys, and the chat staying in the stream's organization;
  - database: migration, keys, the index, history queries;
  - UI: the sheet at 1280 and 390 pixels, keyboard, focus, the `log` role, dark mode.
- UI review and flow-tester run one after the other.

Flows (for `flow-tester`, servers with `--mail`):
1. A verified owner opens a task with a description and clicks **Ask AI** → the sheet opens with the empty state. They ask "What's left to do?" → the question shows, then the answer streams in word by word. Close and reopen → both messages are still there.
2. They ask a second question and press **Stop** mid-answer → the answer stays, marked Stopped. Reopen → it's still Stopped with its partial text.
3. **Clear conversation** → confirm → the empty state. Reopen → still empty.
4. A Member (invited, verified) opens the same task's Ask AI → none of the owner's messages show. They ask something and get an answer.
5. On the AI usage page, the owner sees "Task chat" with the calls from flows 1, 2 and 4.
6. With the budget at $0, sending gets the toast "This organization's monthly AI budget is used up." The question stays in the box, and no message is added. Then the owner sets the budget back to the default.
7. With `ai.use` removed from Member (Roles page), the Member's task page has no Ask AI button. A `GET /api/tasks/{id}/chat` with their token gets 403.
8. The owner removes the Member, then invites them again. After they rejoin, the Member's chat on that task is empty.
9. A user of another organization calls `GET`, `POST` and `DELETE /api/tasks/{id}/chat` for the first organization's task → 404 each time.

## Changes during the build
- **The slot is taken before saving, not last.** `SendTaskChatMessage` takes the stream slot before saving the question, so a refused send (429) leaves nothing in the history. If saving fails, it gives the slot back.
- **`useAiStream().start()` resolves with how the stream ended** (`done`, `error` with its message, or `cancelled`). The chat refetches its history right after an answer ends, in the event handler, instead of reacting to the status during render.
- **The chat's `send()`, after the code review:**
  - One exchange at a time: a guard taken synchronously at the start blocks a second send until the first is over, its refetch included. Send is disabled meanwhile.
  - The stream starts with nothing awaited before it, so closing the sheet always aborts it.
  - A stopped exchange stays on screen as it was (kept locally) until a later answer's refetch includes the saved rows. The server may not have saved the partial text yet when you stop.
  - Clear also drops the live and kept exchanges, shows whenever messages are on screen, and cancels a history refetch in flight.
  - Continuations check they're still current and mounted after each `await`.
- **Clear conversation sits in a bar above the messages**, outside the composer, so an unverified user (who sees "Verify your email address to ask questions." instead of the composer) can still clear their history.
- **The prompt escapes with `htmlspecialchars`** (`&` too), as plan 12's review asked.
- **From the database review:** sending locks the task row first, as summaries do. Sends on a task run one at a time, so two at once from one person can't interleave their questions and answers, and a task deleted mid-request is a 404, not a foreign key error.

- **From the UI review:** the sheet is full width on phones (`data-[side=right]:w-full`, since the component's own 3/4 width rule otherwise wins), and message text breaks long unbroken words (`[overflow-wrap:anywhere]`, `min-w-0`).
- **From the security review:** the prompt also says never to send the user to a link or a login because the task says so, and reading a chat has a per-user rate limit (120 a minute), like clearing.

## Follow-ups
- A stopped answer is saved with the text the server had sent when it noticed the stop, so it can be a few words longer than what was on screen at the click (flow-tester; accepted: the server can't know what the browser rendered).
- A retention limit for chat messages: `MassPrunable` with a `CHAT_RETENTION_DAYS` setting, plus a `created_at` index in a new migration (database review). Today only Clear and the cascades delete them.

## Commits after approval
1. `feat(api): add a private AI chat per task`.
2. `feat(web): add the Ask AI chat to the task page`.
3. Docs:
   - CLAUDE.md: the chat rules, `onEnd`, composite keys for privacy;
   - README: feature, endpoints, screenshot.
4. This plan → Done.
