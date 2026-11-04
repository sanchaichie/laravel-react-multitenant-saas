import type { ReactNode } from 'react'
import { zodResolver } from '@hookform/resolvers/zod'
import { Controller, useForm, useWatch } from 'react-hook-form'
import { toast } from 'sonner'
import { z } from 'zod'
import { Button } from '@/components/ui/button'
import { Checkbox } from '@/components/ui/checkbox'
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog'
import { Field, FieldDescription, FieldError, FieldGroup, FieldLabel } from '@/components/ui/field'
import { Input } from '@/components/ui/input'
import { Spinner } from '@/components/ui/spinner'
import { applyFieldErrors, errorMessage } from '@/lib/apiErrors'
import { describedBy } from '@/lib/aria'
import { formatUsd } from '@/lib/format'
import type { AiBudget } from '@/types/api'
import { useUpdateAiBudget } from './api'

const fields = z.object({
  use_default: z.boolean(),
  // The input's text: it may be empty while "use the plan's budget" is checked.
  monthly_budget_usd: z.string().trim(),
})

type Values = z.infer<typeof fields>

/**
 * The API's rules (UpdateAiBudgetRequest), checked here first for quick feedback. A
 * function of the budget: the most an organization can set is its plan's budget, which
 * the API sends (default_limit_usd). The API still decides, and its 422 lands on the field.
 */
function budgetSchema(budget: AiBudget) {
  // The amount only matters without the plan's budget: a check across two fields.
  return fields.superRefine((values, ctx) => {
    if (values.use_default) return
    const issue = (message: string) => ctx.addIssue({ code: 'custom', path: ['monthly_budget_usd'], message })
    const amount = values.monthly_budget_usd

    if (amount === '') return issue("Enter an amount, or use the plan's budget.")
    if (!/^\d+(\.\d+)?$/.test(amount)) return issue('Enter an amount in dollars, e.g. 25 or 12.50.')
    if (!/^\d+(\.\d{1,2})?$/.test(amount)) return issue('Use at most 2 decimals (cents).')
    if (Number(amount) > budget.default_limit_usd) {
      return issue(`The ${budget.plan} plan allows at most ${formatUsd(budget.default_limit_usd)} a month.`)
    }
  })
}

interface BudgetDialogProps {
  /** The button that opens it. As the dialog's trigger, it gets focus back on close. */
  children: ReactNode
  budget: AiBudget
  open: boolean
  onOpenChange: (open: boolean) => void
}

/** Change the organization's monthly AI budget (up to the plan's), or go back to the plan's. */
export function BudgetDialog({ children, budget, open, onOpenChange }: BudgetDialogProps) {
  return (
    // Controlled: the page owns `open` (v-model:open on a modal).
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogTrigger asChild>{children}</DialogTrigger>
      <DialogContent className="sm:max-w-md">
        <DialogHeader>
          <DialogTitle>Monthly AI budget</DialogTitle>
          <DialogDescription>
            AI calls stop for everyone in the organization once this month's spend reaches it. It resets on the 1st (UTC).
          </DialogDescription>
        </DialogHeader>
        {/* DialogContent unmounts when closed: each opening starts from the saved budget. */}
        <BudgetForm budget={budget} onDone={() => onOpenChange(false)} />
      </DialogContent>
    </Dialog>
  )
}

function BudgetForm({ budget, onDone }: { budget: AiBudget; onDone: () => void }) {
  const update = useUpdateAiBudget()
  const form = useForm<Values>({
    // Built once per opening (the form mounts with the dialog), from the budget it opened with.
    resolver: zodResolver(budgetSchema(budget)),
    defaultValues: {
      use_default: budget.is_default,
      monthly_budget_usd: budget.own_limit_usd === null ? '' : budget.own_limit_usd.toFixed(2),
    },
  })
  const { errors } = form.formState
  // Subscribes to the checkbox (a watch() in Vue): the input is off while the default applies.
  const useDefault = useWatch({ control: form.control, name: 'use_default' })

  function onSubmit(values: Values) {
    const amount = values.use_default ? null : Number(values.monthly_budget_usd)
    update.mutate(amount, {
      onSuccess: () => {
        toast.success(amount === 0 ? 'AI is off for this organization' : 'Budget saved')
        onDone()
      },
      onError: (error) => {
        if (!applyFieldErrors(error, form.setError, ['monthly_budget_usd'])) {
          toast.error('Could not save the budget', { description: errorMessage(error) })
        }
      },
    })
  }

  return (
    <form onSubmit={form.handleSubmit(onSubmit)} noValidate>
      <FieldGroup>
        <Controller
          control={form.control}
          name="use_default"
          render={({ field }) => (
            <Field orientation="horizontal">
              <Checkbox
                id="budget-use-default"
                checked={field.value}
                onCheckedChange={(next) => {
                  field.onChange(next === true)
                  form.clearErrors('monthly_budget_usd')
                }}
              />
              <FieldLabel htmlFor="budget-use-default" className="font-normal">
                Use the {budget.plan} plan's budget ({formatUsd(budget.default_limit_usd)})
              </FieldLabel>
            </Field>
          )}
        />

        <Field data-invalid={!!errors.monthly_budget_usd} data-disabled={useDefault || undefined}>
          <FieldLabel htmlFor="budget-amount">Budget per month (USD)</FieldLabel>
          <Input
            id="budget-amount"
            inputMode="decimal"
            autoComplete="off"
            placeholder="e.g. 25"
            disabled={useDefault}
            aria-invalid={!!errors.monthly_budget_usd}
            aria-describedby={describedBy('budget-amount-hint', errors.monthly_budget_usd && 'budget-amount-error')}
            {...form.register('monthly_budget_usd')}
          />
          <FieldDescription id="budget-amount-hint">
            0 turns AI off. At most {formatUsd(budget.default_limit_usd)} on the {budget.plan} plan; AI credits come on top.
          </FieldDescription>
          <FieldError id="budget-amount-error" errors={[errors.monthly_budget_usd]} />
        </Field>

        <div className="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <Button type="button" variant="outline" onClick={onDone}>
            Cancel
          </Button>
          <Button type="submit" disabled={update.isPending}>
            {update.isPending && <Spinner />}
            Save
          </Button>
        </div>
      </FieldGroup>
    </form>
  )
}
