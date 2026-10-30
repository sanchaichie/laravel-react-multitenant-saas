# 12 — Streaming and AI writing help

**Status:** Done
**Commits:** `afe4fb4` (api), `6e15c38` (web), `c1c823a` (claude)

## Goal
Add streaming to the AI layer and use it for the first interactive feature: **writing help** in the task form. The user can improve, expand or shorten a task's description, and the answer streams into a preview they accept into the field or discard. Nothing is saved until they save the task.

This rebuilds saas-starter plan 15, including its "As built" fixes. Chat (plan 13) reuses the same streaming pieces.

**Out of scope:**
- writing help for the title or any other field;
- saving writing-help answers or keeping a history of them;
- streaming for summaries (they stay queued drafts).

## Decisions
- **Interactive AI streams; background work stays queued.** This becomes a CLAUDE.md rule.
- **`AiClient::stream(AiRequest, Closure $onText): AiResponse`** is added next to `generate()`.
  - The callback gets each piece of text. Returning `false` stops the call (the browser went away, or Stop was pressed).
  - Every wrapper handles it the same way:
    - `BudgetedAiClient` checks the budget and the current organization first.
    - `RecordingAiClient` records the call, including a stopped or cut-off one, with the tokens so far.
    - The provider streams.
- **Server-sent events over a plain `response()->stream()`**, as `App\Http\Responses\AiEventStream`. Laravel's `eventStream()` needs a generator, while the client reports text through a callback.
  - Events: `delta {text}`, `done {model}`, and `error {message}`. An error part-way means the text so far isn't a valid answer.
  - Headers: `Content-Type: text/event-stream`, `Cache-Control: no-cache`, `X-Accel-Buffering: no`.
  - Once streaming starts the status is 200. So permission, validation, the budget and the stream slots are all checked **before** the response starts, and they keep their own statuses (403, 422, 402, 429).
- **A stopped stream still costs money.** The provider reports output tokens only at the end, so a stopped or broken-off stream estimates them from the text (about 4 characters per token). Otherwise pressing Stop right away would count as almost free against the budget. It's recorded as `succeeded` with `error_code = aborted`.
- **At most 2 streams at once per organization** (`AiStreamSlots`, using cache locks; 429 when full). Each stream holds a PHP worker for its whole length, which the per-minute rate limit doesn't bound. The local and e2e servers already run 4 workers (`PHP_CLI_SERVER_WORKERS=4`).
- **`AI_STREAM_TIMEOUT=120`** is a total deadline for one answer. With streaming, Guzzle's timeout only limits each read, so `AnthropicClient` checks the deadline itself.
- **The stream runs as the organization that authorized it** (`CurrentOrganization::run()` inside the stream callback), even if the user switches organization meanwhile, and it releases its slot at the end.
- **Who may use it:** `ai.use`, plus the right to write that description, from a verified email, under `throttle:ai`.
  - **New task:** `tasks.create`.
  - **Existing task:** `TaskPolicy::update`.

  It's `TaskPolicy::writeWithAi(?Task)`.
- **Only the submitted form text goes into the prompt** (title and description, inside `<draft>` tags, with `<`/`>` escaped), never the saved task. The answer is plain text, held to the description's 5,000 characters; a longer answer ends with `error`, not `done`.
- **Modes:** `improve` and `shorten` need a description. `expand` works from the title alone.
- **Web: `fetch` with a `ReadableStream`, not `EventSource`.** `EventSource` can't send the Bearer token or POST a body.
  - The streaming call lives in `lib/apiStream.ts` next to `apiFetch`, with the same headers, the same 401 and 409 handling, and `ApiError` for a non-2xx answer.
  - The hook `useAiStream()` holds the stream's state.
- **The fake provider streams too.**
  - `FakeAiClient::pushStream([...chunks])` queues an answer. By default it streams a short rewrite of the draft in a few chunks.
  - New key `AI_FAKE_STREAM_DELAY_MS` (default 0) adds a pause between chunks. `servers.sh` sets it to 60, so you can watch the stream arrive (and press Stop) in e2e runs.
- **No new dependency.** The menu is the shadcn `dropdown-menu` that's already installed.

## Backend (`api/`)
1. **Config and env.** `config/ai.php` gets:
   - `stream_timeout` (`AI_STREAM_TIMEOUT`, 120);
   - `concurrent_streams` (2);
   - `fake_stream_delay_ms` (`AI_FAKE_STREAM_DELAY_MS`, 0).

   Both env keys go in `.env.example`, with comments.
2. **`AiClient::stream()`.** Implementations:
   - **`AnthropicClient::stream()`**:
     - Sends `stream: true` with `withOptions(['stream' => true])`, and refuses a request with a `schema`.
     - Reads the response body line by line and parses the SSE events:
       - `message_start`: model and input tokens;
       - `content_block_delta`/`text_delta`: calls `$onText`;
       - `message_delta`: stop reason and output tokens;
       - `error`: `overloaded_error`, `api_error` and `rate_limit_error` throw `AiUnavailable`; anything else throws `AiRequestFailed`. Either way the partial response is attached.
     - Enforces the total deadline.
     - A stream that ends without a stop reason throws `AiUnavailable` (`stream_incomplete`).
     - When `$onText` returns false, it closes the body and returns the partial answer with stop reason `aborted`.
     - The partial response estimates output tokens from the text (characters ÷ 4, rounded up) when the provider hasn't reported more.
     - Status and stop-reason handling are shared with `generate()`.
   - **`FakeAiClient`**: `stream()` and `pushStream()`. `assertSent` covers streams too.
   - **`RecordingAiClient::stream()`**: records like `generate()`. A stopped stream is recorded as `succeeded` with `error_code = aborted`.
   - **`BudgetedAiClient::stream()`**: the same checks as `generate()`.
3. **`App\Ai\AiStreamSlots::acquire(Organization): Lock`.**
   - Takes cache locks `ai-stream:{org}:{0..n-1}`, each held for the stream timeout plus 30 seconds.
   - When every slot is taken it throws `ThrottleRequestsException` (429): "Too many AI answers are being written for this organization. Try again when one finishes."
4. **`App\Http\Responses\AiEventStream`** (`Responsable`), ported:
   - runs inside `CurrentOrganization::run($organization)`;
   - sets `ignore_user_abort(true)`, and `connection_aborted()` stops the stream;
   - maps errors to short messages: budget, unavailable, refusal, `max_tokens`, and a generic one;
   - applies the maximum length;
   - always releases the slot.
5. **`App\Enums\WritingHelpMode`** (`improve`, `expand`, `shorten`) and the prompt class **`App\Ai\Prompts\TaskWritingPrompt`**:
   - feature `task_writing`, `MAX_LENGTH = 5000`, `effort: low`, `maxTokens: 2000`;
   - a system prompt that treats the draft as data, answers with the description only (plain text, the draft's language) and never invents facts.
6. **The action `App\Actions\Tasks\StreamTaskWritingHelp`** runs in this order: the budget's `ensureAvailable()`, then the slot's `acquire()`, then the `AiEventStream`.
7. **Form request `TaskWritingHelpRequest`:**
   - `mode` (enum), `title` (nullable, max 255, `required_without:description`), `description` (nullable, max 5000);
   - a description is required unless the mode is `expand`;
   - messages: "Enter a title or a description first." and "Write a description first."
8. **Policy and routes.** `TaskPolicy::writeWithAi(User, ?Task)` is `ai.use` plus either `create` (no task) or `update($task)`. Routes in the `organization` group, with `verified` and `throttle:ai`:
   - `POST /api/tasks/writing-help` (new task);
   - `POST /api/tasks/{task}/writing-help` (`{task}` binds through the scope, so another organization's task is a 404).

   Controller `TaskWritingHelpController` (`store`, `update`), thin.

   | Who | New task | Own or assigned task | Someone else's task |
   |---|---|---|---|
   | Owner, Admin | ✓ | ✓ | ✓ |
   | Member | ✓ | ✓ | ✗ |
   | Role without `ai.use` | ✗ | ✗ | ✗ |
   | Viewer (no `tasks.create`) | ✗ | ✗ | ✗ |
9. **`TaskResource`** gets `can.write_with_ai` (the policy plus a verified email), so the edit form knows. For a new task the web reads `ai.use` + `tasks.create` + `email_verified` from the user.

## Frontend (`web/`)
10. **`lib/apiStream.ts`:**
    - `apiStream(path, {body, signal, onEvent})` uses the same base URL, headers (Bearer token, `X-Organization-Id`), 401 handling and 409 catch-up as `apiFetch`; those helpers are shared.
    - A non-2xx answer throws `ApiError` before any event.
    - It reads `response.body` through a `TextDecoder`, splits events on blank lines, and calls `onEvent({event, data})`.
    - `lib/sse.ts` holds `parseSseChunk`, a parser that keeps a partial event across chunks. It's unit-tested.
11. **`hooks/useAiStream.ts`:** returns `{text, status, error, start(path, body), stop(), reset()}`, where `status` is `idle | streaming | done | error | stopped`.
    - State lives in `useReducer`.
    - The `AbortController` lives in a `useRef`, so changing it doesn't re-render.
    - A `useEffect` cleanup aborts the stream when the component unmounts (the dialog closes).
    - A new `start` aborts the previous stream, and a stopped or replaced stream never reports a late error.
12. **`features/tasks/TaskWritingHelp.tsx`**, under Description in `TaskForm`:
    - An "Improve with AI" `DropdownMenu` with Improve, Expand and Shorten. The modes that need text are disabled while the description is empty.
    - A preview card shows the streaming text in an `aria-live="polite"` region, with Stop while it streams. When it's done it shows Accept (puts the text into the field with `setValue(..., {shouldDirty: true, shouldValidate: true})`), Try again and Discard.
    - Accept appears only for a non-empty, finished answer. An `error` event shows its message.
    - Request errors show as toasts: 402 says the budget is used up, 429 says too many at once, and anything else shows `errorMessage`.
    - The button shows only when allowed: `task.can.write_with_ai` when editing, or the user's permissions and verified email when creating.
13. **AI usage:** `featureLabel('task_writing')` reads "Writing help".
14. **Types:** `Task['can']` gains `write_with_ai`, plus a new `WritingHelpMode` type.

React concepts:
- `useReducer` for state with several fields that change together (status, text, error) ↔ a small Pinia-style store local to one component.
- `useRef` for a mutable value that shouldn't re-render (the `AbortController`) ↔ a plain `let` in `setup()`, outside reactivity.
- `useEffect` cleanup to cancel work on unmount ↔ `onUnmounted`.
- Reading a stream with `ReadableStream.getReader()` and `TextDecoder` (plain web APIs, not React) ↔ the same in Vue; the hook only turns it into state.
- `setValue` from outside an input, with `shouldDirty` and `shouldValidate` ↔ assigning to a form model in Vue.

## Tests
- **`AnthropicStreamTest`** (`Http::fake` with an SSE body):
  - text arrives in order, and tokens and stop reason come from `message_delta`;
  - an `error` event part-way: overloaded maps to `AiUnavailable`, `invalid_request` to `AiRequestFailed`, each with the partial text;
  - a stream that ends early gives `stream_incomplete`;
  - returning false from `$onText` gives `aborted` with estimated output tokens;
  - a `schema` is refused;
  - a 429 or 529 status before streaming maps as before.
- **Recording and budget on streams:**
  - a stopped stream is recorded as `succeeded` with `aborted`;
  - a failed one is recorded with its partial tokens;
  - over budget, or with no organization: refused and not recorded.
- **`TaskWritingHelpTest`:**
  - the response is `text/event-stream`, with `delta` events in order and then `done` (`streamedContent()`);
  - the prompt contains only the submitted title and description, with `<`/`>` escaped (`assertSent`);
  - each mode's validation (improve without a description gives 422; expand with a title alone is OK);
  - a too-long answer ends with `error`;
  - an `AiRequestFailed` part-way gives an `error` event with a short message;
  - the usage row has feature `task_writing`.
- **Permissions:** the table above as a dataset (new task, own, someone else's), plus an unverified user (403 `email_unverified`) and a role without `ai.use`. `can.write_with_ai` is checked in `TaskResource`.
- **Limits:**
  - over budget → 402 before streaming, with nothing sent;
  - `throttle:ai` → 429;
  - both slots taken → 429, and the slot is freed after a stream ends (a third request then works).
- **Isolation:** org B's task gives 404 on `/tasks/{id}/writing-help`. The stream runs and records in the organization that authorized it, even if the user's current organization changes before the body is sent.
- **Vitest:**
  - `sse.test.ts`: events split across chunks, several events in one chunk, `\r\n` line endings;
  - `TaskWritingHelp` (through `renderApp` and the task dialog):
    - the preview streams in;
    - Accept replaces the description and the save sends it;
    - Discard leaves the field unchanged;
    - Stop ends the stream;
    - an `error` event shows its message;
    - 402 and 429 toasts;
    - the button is hidden without `ai.use`, with an unverified email, or when `can.write_with_ai` is false;
    - modes that need text are disabled with an empty description.

## README screenshots
- New `docs/screenshots/task-writing-help.png`: the New task dialog with a description and a finished "Improve" preview, with Accept, Try again and Discard. 1280×800, light mode, e2e demo data.

## Verification
- `/verify`.
- `/e2e-smoke` with `--mail` (fake provider, `AI_FAKE_STREAM_DELAY_MS=60`): watch the stream arrive in the browser.
- Reviewers in parallel: code, security (prompt injection, budget bypass, SSE auth and errors, stream slots), tenant isolation (the stream runs as the authorizing organization) and UI. Database only if a query changes, since there is no migration.
- UI review and flow-tester run one after the other.
- An optional check against the real API with your key, for the first real streaming call. You'd run it yourself: I can't edit `.env`.

Flows (for `flow-tester`, servers with `--mail`):
1. A verified owner opens New task, types a title and a short description, and picks Improve with AI → Improve → text streams into the preview piece by piece, then Accept, Try again and Discard appear.
2. Accept → the description field now holds the preview text. Save → the task page shows the new description.
3. Edit that task, then Shorten → the preview streams. Press Stop while it streams → it stops, and only Try again and Discard are offered. Discard → the field is unchanged.
4. With an empty description, Improve and Shorten are disabled, and Expand works from the title alone.
5. On the AI usage page, "Writing help" appears under features, with the calls from flows 1 to 4 (the stopped one included).
6. The owner sets the budget to $0 and tries Improve → toast "This organization's monthly AI budget is used up." with no preview. Then the owner sets the budget back to the default.
7. A Member whose role has lost `ai.use` (Roles page) opens New task → no "Improve with AI" button.
8. A user of another organization POSTs to `/api/tasks/{id}/writing-help` for a task of the first organization → 404.

## Changes during the build
- From the reviews:
  - **Stream slots:** they are per organization, per user (2, across organizations) and server-wide (`AI_MAX_CONCURRENT_STREAMS`, 3, below the 4 PHP workers). Per organization alone, one user with several organizations could hold every worker. `AiStreamSlots::acquire($org, $user)` returns an `AiStreamSlot` that releases all its locks together. A refused request gives back what it took. The slot is taken last, after the prompt is built.
  - **Lock TTL:** it is now twice the stream timeout plus 30s.
  - **Stalled provider:** `AnthropicClient` checks the total deadline in its read loop and limits each read to 30s (`read_timeout`), so a provider that stops sending can't hold a worker past the deadline.
  - **Long answers:** an answer past 5,000 characters is stopped as soon as it passes (recorded as `aborted`) and ends with the length error, instead of streaming to the end.
  - **Escaping:** `TaskWritingPrompt` escapes `&` too (`htmlspecialchars`).
  - **Web, dropped connection:** a connection that drops mid-answer now ends it as "The answer broke off", keeping the text, instead of resetting it and showing a generic toast. Only a refused request rejects `start()`.
  - **Web, end of stream:** the parser also reads a last event that has no blank line after it.
  - **Web, Try again:** it follows the same rule as the menu.
  - **Web, 429 toast:** it shows the API's own message, which says which limit applies.
- From the UI review:
  - **Mobile dialog:** the task dialog scrolls (`max-h-[90dvh]`). At 390px, a finished suggestion made it taller than the screen.
  - **Menu while streaming:** "Improve with AI" stays enabled while an answer streams (picking again replaces it), so the menu returns focus to it.
  - **Focus after Accept / Discard:** Accept focuses the Description field, and Discard focuses the menu button.
- **New env key:** `AI_MAX_CONCURRENT_STREAMS`.

## Commits after approval
1. `feat(api): stream AI answers and add writing help for tasks`.
2. `feat(web): add AI writing help to the task form`.
3. `chore(claude)`, if `servers.sh` or `SKILL.md` change (`AI_FAKE_STREAM_DELAY_MS`).
4. Docs (CLAUDE.md: the streaming rules, `useAiStream`/`apiStream`, `AiStreamSlots`; README: features, env keys, endpoints, screenshot), then this plan → Done.
