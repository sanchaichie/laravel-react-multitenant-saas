import { zodResolver } from '@hookform/resolvers/zod'
import { Controller, useForm } from 'react-hook-form'
import { toast } from 'sonner'
import { z } from 'zod'
import { Button } from '@/components/ui/button'
import { Checkbox } from '@/components/ui/checkbox'
import { Field, FieldContent, FieldDescription, FieldError, FieldGroup, FieldLabel, FieldLegend, FieldSet } from '@/components/ui/field'
import { Input } from '@/components/ui/input'
import { Spinner } from '@/components/ui/spinner'
import { applyFieldErrors, errorMessage } from '@/lib/apiErrors'
import { describedBy } from '@/lib/aria'
import type { WebhookEndpoint, WebhookEvent } from '@/types/api'
import { useCreateWebhook, useUpdateWebhook } from './api'
import { WEBHOOK_EVENTS } from './events'

// The API checks the rest (https, a public address): its 422 lands on the field.
const schema = z.object({
  url: z.string().trim().min(1, 'Enter the URL to send to.').max(2048, 'At most 2048 characters.'),
  description: z.string().trim().max(200, 'At most 200 characters.'),
  events: z.array(z.enum(['task.created', 'task.updated', 'task.deleted'])).min(1, 'Choose at least one event.'),
})

type Values = z.infer<typeof schema>

const FIELDS = ['url', 'description', 'events'] as const

interface WebhookFormProps {
  /** The webhook to edit; none to create one. */
  webhook?: WebhookEndpoint
  /** After creating: the new webhook and its secret (shown once by the caller). */
  onCreated?: (webhook: WebhookEndpoint, secret: string) => void
  onCancel?: () => void
}

/** A webhook's URL, description and events (one array field, a checkbox each). */
export function WebhookForm({ webhook, onCreated, onCancel }: WebhookFormProps) {
  const create = useCreateWebhook()
  const update = useUpdateWebhook(webhook?.id ?? 0)
  const saving = create.isPending || update.isPending

  const form = useForm<Values>({
    resolver: zodResolver(schema),
    defaultValues: {
      url: webhook?.url ?? '',
      description: webhook?.description ?? '',
      events: webhook?.events ?? WEBHOOK_EVENTS.map((event) => event.value),
    },
  })
  const { errors, isDirty } = form.formState

  function onError(error: Error) {
    if (!applyFieldErrors(error, form.setError, FIELDS)) {
      toast.error(webhook ? 'Could not save the webhook' : 'Could not add the webhook', { description: errorMessage(error) })
    }
  }

  function onSubmit(values: Values) {
    const body = { url: values.url.trim(), description: values.description.trim() || null, events: values.events }

    if (webhook) {
      update.mutate(body, {
        onSuccess: (saved) => {
          toast.success('Webhook saved')
          // The saved values become the form's new starting point (isDirty goes back to false).
          form.reset({ url: saved.data.url ?? '', description: saved.data.description ?? '', events: saved.data.events })
        },
        onError,
      })
    } else {
      create.mutate(body, { onSuccess: (created) => onCreated?.(created.data, created.meta.secret), onError })
    }
  }

  return (
    <form onSubmit={form.handleSubmit(onSubmit)} noValidate>
      <FieldGroup>
        <Field data-invalid={!!errors.url}>
          <FieldLabel htmlFor="webhook-url">Endpoint URL</FieldLabel>
          <Input
            id="webhook-url"
            type="url"
            inputMode="url"
            autoComplete="off"
            autoFocus={!webhook}
            placeholder="https://example.com/webhooks"
            aria-invalid={!!errors.url}
            aria-describedby={describedBy('webhook-url-hint', errors.url && 'webhook-url-error')}
            {...form.register('url')}
          />
          <FieldDescription id="webhook-url-hint">An https address on the public internet.</FieldDescription>
          <FieldError id="webhook-url-error" errors={[errors.url]} />
        </Field>

        <Field data-invalid={!!errors.description}>
          <FieldLabel htmlFor="webhook-description">Description (optional)</FieldLabel>
          <Input
            id="webhook-description"
            autoComplete="off"
            placeholder="e.g. Sync to our CRM"
            aria-invalid={!!errors.description}
            aria-describedby={describedBy(errors.description && 'webhook-description-error')}
            {...form.register('description')}
          />
          <FieldError id="webhook-description-error" errors={[errors.description]} />
        </Field>

        {/* One field holds the list (like v-model on checkboxes bound to one array ref). */}
        <Controller
          control={form.control}
          name="events"
          render={({ field }) => (
            <FieldSet data-invalid={!!errors.events}>
              <FieldLegend variant="label">Events</FieldLegend>
              <FieldGroup className="gap-3">
                {WEBHOOK_EVENTS.map((event) => {
                  const id = `webhook-event-${event.value.replace('.', '-')}`
                  return (
                    <Field key={event.value} orientation="horizontal">
                      <Checkbox
                        id={id}
                        checked={field.value.includes(event.value)}
                        aria-invalid={!!errors.events || undefined}
                        aria-describedby={describedBy(`${id}-description`, errors.events && 'webhook-events-error')}
                        onCheckedChange={(next) =>
                          field.onChange(
                            next === true
                              ? // Kept in the list's order, whatever order they're ticked in.
                                WEBHOOK_EVENTS.map((e) => e.value).filter((v): v is WebhookEvent => v === event.value || field.value.includes(v))
                              : field.value.filter((value) => value !== event.value),
                          )
                        }
                      />
                      <FieldContent>
                        <FieldLabel htmlFor={id} className="font-normal">
                          {event.label}
                        </FieldLabel>
                        <FieldDescription id={`${id}-description`}>{event.description}</FieldDescription>
                      </FieldContent>
                    </Field>
                  )
                })}
              </FieldGroup>
              <FieldError id="webhook-events-error" errors={[errors.events]} />
            </FieldSet>
          )}
        />

        <div className="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          {onCancel && (
            <Button type="button" variant="outline" disabled={saving} onClick={onCancel}>
              Cancel
            </Button>
          )}
          <Button type="submit" disabled={saving || (webhook !== undefined && !isDirty)}>
            {saving && <Spinner />}
            {webhook ? 'Save changes' : 'Add webhook'}
          </Button>
        </div>
      </FieldGroup>
    </form>
  )
}
