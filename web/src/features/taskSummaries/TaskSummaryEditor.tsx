import { zodResolver } from '@hookform/resolvers/zod'
import { PlusIcon, XIcon } from 'lucide-react'
import { useFieldArray, useForm } from 'react-hook-form'
import { toast } from 'sonner'
import { z } from 'zod'
import { Button } from '@/components/ui/button'
import { Field, FieldError, FieldGroup, FieldLabel, FieldLegend, FieldSet } from '@/components/ui/field'
import { Input } from '@/components/ui/input'
import { Spinner } from '@/components/ui/spinner'
import { Textarea } from '@/components/ui/textarea'
import { ApiError } from '@/lib/api'
import { errorMessage } from '@/lib/apiErrors'
import { describedBy } from '@/lib/aria'
import type { TaskSummary } from '@/types/api'
import { useReviewTaskSummary } from './api'

// The API's limits (TaskSummaryPrompt::rules()), checked here first for quick feedback.
const MAX_SUMMARY = 600
const MAX_KEY_POINTS = 5
const MAX_KEY_POINT = 160

const schema = z.object({
  summary: z.string().trim().min(1, 'Enter a summary.').max(MAX_SUMMARY, `At most ${MAX_SUMMARY} characters.`),
  // useFieldArray needs objects (it adds an `id` to each for React's key), not bare strings.
  key_points: z
    .array(
      z.object({
        value: z
          .string()
          .trim()
          .min(1, 'Enter a point, or remove it.')
          .max(MAX_KEY_POINT, `At most ${MAX_KEY_POINT} characters.`),
      }),
    )
    .max(MAX_KEY_POINTS, `At most ${MAX_KEY_POINTS} key points.`),
})

type Values = z.infer<typeof schema>

interface TaskSummaryEditorProps {
  summary: TaskSummary
  /** Approved, or no longer a draft (reviewed elsewhere): either way, leave edit mode. */
  onDone: () => void
  onCancel: () => void
}

/** Edit a draft, then approve it with the changes. */
export function TaskSummaryEditor({ summary, onDone, onCancel }: TaskSummaryEditorProps) {
  const review = useReviewTaskSummary()
  const form = useForm<Values>({
    resolver: zodResolver(schema),
    defaultValues: {
      summary: summary.summary ?? '',
      key_points: summary.key_points.map((value) => ({ value })),
    },
  })
  const { errors } = form.formState
  // The list of inputs is owned by the form: append/remove keep values and errors in step.
  const keyPoints = useFieldArray({ control: form.control, name: 'key_points' })

  /** A 422's messages on the matching inputs: key_points.2 → the third point. */
  function applyServerErrors(error: unknown): boolean {
    if (!(error instanceof ApiError) || error.status !== 422 || !error.data?.errors) {
      return false
    }

    let applied = false
    for (const [field, messages] of Object.entries(error.data.errors)) {
      const message = messages[0]
      const point = /^key_points\.(\d+)$/.exec(field)
      if (!message) continue
      if (field === 'summary') {
        form.setError('summary', { type: 'server', message }, { shouldFocus: !applied })
      } else if (point) {
        form.setError(`key_points.${Number(point[1])}.value`, { type: 'server', message }, { shouldFocus: !applied })
      } else if (field === 'key_points') {
        // The list as a whole (e.g. too many points): shown under the group.
        form.setError('key_points', { type: 'server', message })
      } else {
        continue
      }
      applied = true
    }
    return applied
  }

  function onSubmit(values: Values) {
    review.mutate(
      {
        taskId: summary.task_id,
        summaryId: summary.id,
        approve: true,
        edits: { summary: values.summary.trim(), key_points: values.key_points.map((point) => point.value.trim()) },
      },
      {
        onSuccess: () => {
          toast.success('Summary approved')
          onDone()
        },
        onError: (error) => {
          if (applyServerErrors(error)) return
          toast.error('Could not approve the summary', { description: errorMessage(error) })
          // No longer a draft (or gone): the card shows what it is now. Anything else
          // (network, 500) keeps the editor open, so the edits aren't lost.
          if (error instanceof ApiError && (error.status === 409 || error.status === 404)) onDone()
        },
      },
    )
  }

  // A list-level error: too many points (zod puts it on the array, as does the API's 422).
  const listError = errors.key_points?.root ?? (errors.key_points?.message ? errors.key_points : undefined)

  return (
    <form onSubmit={form.handleSubmit(onSubmit)} noValidate aria-label="Edit summary">
      <FieldGroup>
        <Field data-invalid={!!errors.summary}>
          <FieldLabel htmlFor="summary-text">Summary</FieldLabel>
          <Textarea
            id="summary-text"
            rows={4}
            autoFocus
            aria-invalid={!!errors.summary}
            aria-describedby={describedBy(errors.summary && 'summary-text-error')}
            {...form.register('summary')}
          />
          <FieldError id="summary-text-error" errors={[errors.summary]} />
        </Field>

        <FieldSet data-invalid={!!listError}>
          <FieldLegend variant="label">Key points</FieldLegend>
          {keyPoints.fields.map((field, index) => {
            const error = errors.key_points?.[index]?.value
            const id = `key-point-${field.id}`
            return (
              // field.id, not the index: removing a point mustn't shift the other inputs' state.
              <Field key={field.id} data-invalid={!!error}>
                <div className="flex gap-2">
                  <Input
                    id={id}
                    aria-label={`Key point ${index + 1}`}
                    aria-invalid={!!error}
                    aria-describedby={describedBy(error && `${id}-error`)}
                    {...form.register(`key_points.${index}.value`)}
                  />
                  <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    aria-label={`Remove key point ${index + 1}`}
                    onClick={() => keyPoints.remove(index)}
                  >
                    <XIcon aria-hidden />
                  </Button>
                </div>
                <FieldError id={`${id}-error`} errors={[error]} />
              </Field>
            )
          })}
          {keyPoints.fields.length < MAX_KEY_POINTS && (
            <div>
              <Button type="button" variant="outline" size="sm" onClick={() => keyPoints.append({ value: '' })}>
                <PlusIcon aria-hidden />
                Add key point
              </Button>
            </div>
          )}
          <FieldError errors={[listError]} />
        </FieldSet>

        <div className="flex flex-wrap gap-2">
          <Button type="submit" disabled={review.isPending}>
            {review.isPending && <Spinner />}
            Approve with changes
          </Button>
          <Button type="button" variant="outline" onClick={onCancel} disabled={review.isPending}>
            Cancel
          </Button>
        </div>
      </FieldGroup>
    </form>
  )
}
