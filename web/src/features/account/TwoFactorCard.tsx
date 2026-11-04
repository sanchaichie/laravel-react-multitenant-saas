import { useState } from 'react'
import { zodResolver } from '@hookform/resolvers/zod'
import { CheckIcon, CopyIcon, DownloadIcon, ShieldCheckIcon } from 'lucide-react'
import { Controller, useForm, useWatch } from 'react-hook-form'
import { toast } from 'sonner'
import { z } from 'zod'
import { CodeInput } from '@/components/CodeInput'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Field, FieldDescription, FieldError, FieldGroup, FieldLabel } from '@/components/ui/field'
import { Input } from '@/components/ui/input'
import { Spinner } from '@/components/ui/spinner'
import { applyFieldErrors, errorMessage } from '@/lib/apiErrors'
import { describedBy } from '@/lib/aria'
import type { TwoFactorSetup, User } from '@/types/api'
import {
  useConfirmTwoFactor,
  useDisableTwoFactor,
  useRegenerateRecoveryCodes,
  useStartTwoFactor,
} from './api'
import { QrCode } from './QrCode'

/**
 * Where the dialog is. One value with a `name` instead of several booleans, so it
 * can't be in two steps at once; each step carries what it needs (the setup, the codes).
 */
type Step =
  | { name: 'password' }
  | { name: 'setup'; setup: TwoFactorSetup }
  | { name: 'codes'; codes: string[] }
  | { name: 'verify'; purpose: 'disable' | 'regenerate' }

const TITLES: Record<Step['name'], string> = {
  password: 'Turn on two-factor authentication',
  setup: 'Scan the QR code',
  codes: 'Save your recovery codes',
  verify: '',
}

export function TwoFactorCard({ user }: { user: User }) {
  const [step, setStep] = useState<Step | null>(null)
  const close = () => setStep(null)

  const title =
    step?.name === 'verify'
      ? step.purpose === 'disable'
        ? 'Turn off two-factor authentication?'
        : 'Make new recovery codes'
      : step && TITLES[step.name]

  return (
    <Card>
      <CardHeader>
        <CardTitle>
          <h2>Two-factor authentication</h2>
        </CardTitle>
        <CardDescription>
          Logging in also asks for a code from an authenticator app on your phone (Google Authenticator, 1Password,
          Authy…), so a stolen password isn't enough.
        </CardDescription>
      </CardHeader>
      <CardContent>
        {user.two_factor_enabled ? (
          <div className="flex flex-wrap items-center gap-2">
            <Badge variant="secondary">
              <ShieldCheckIcon />
              On
            </Badge>
            <Button variant="outline" onClick={() => setStep({ name: 'verify', purpose: 'regenerate' })}>
              New recovery codes…
            </Button>
            <Button variant="ghost" className="text-destructive" onClick={() => setStep({ name: 'verify', purpose: 'disable' })}>
              Turn off…
            </Button>
          </div>
        ) : (
          <Button onClick={() => setStep({ name: 'password' })}>Turn on…</Button>
        )}

        {/* Controlled by `step`. While the codes are showing, Escape, a click outside
            and the close button are ignored: they're shown only once. */}
        <Dialog
          open={step !== null}
          onOpenChange={(open) => {
            if (!open && step?.name !== 'codes') {
              close()
            }
          }}
        >
          {step && (
            <DialogContent className="sm:max-w-md" showCloseButton={step.name !== 'codes'}>
              <DialogHeader>
                <DialogTitle>{title}</DialogTitle>
                <DialogDescription>{describe(step)}</DialogDescription>
              </DialogHeader>
              {/* A different component per step: moving on unmounts the last form, so nothing carries over. */}
              {step.name === 'password' && (
                <StartForm onCancel={close} onStarted={(setup) => setStep({ name: 'setup', setup })} />
              )}
              {step.name === 'setup' && (
                <SetupForm setup={step.setup} onCancel={close} onConfirmed={(codes) => setStep({ name: 'codes', codes })} />
              )}
              {step.name === 'codes' && <RecoveryCodes codes={step.codes} onDone={close} />}
              {step.name === 'verify' && (
                <VerifyForm
                  purpose={step.purpose}
                  onCancel={close}
                  onDone={(codes) => (codes ? setStep({ name: 'codes', codes }) : close())}
                />
              )}
            </DialogContent>
          )}
        </Dialog>
      </CardContent>
    </Card>
  )
}

function describe(step: Step): string {
  switch (step.name) {
    case 'password':
      return 'Enter your password to start.'
    case 'setup':
      return 'Scan it with your authenticator app, then enter the 6-digit code it shows.'
    case 'codes':
      return "Each one logs you in once if you lose your phone. Keep them somewhere safe: they won't be shown again."
    case 'verify':
      return step.purpose === 'disable'
        ? 'Logging in will only need your password. Enter it and a code from your app.'
        : 'The codes you have now will stop working. Enter your password and a code from your app.'
  }
}

const passwordSchema = z.object({ current_password: z.string().min(1, 'Enter your password.') })

function StartForm({ onCancel, onStarted }: { onCancel: () => void; onStarted: (setup: TwoFactorSetup) => void }) {
  const start = useStartTwoFactor()
  const form = useForm<z.infer<typeof passwordSchema>>({
    resolver: zodResolver(passwordSchema),
    defaultValues: { current_password: '' },
  })
  const { errors } = form.formState

  function onSubmit({ current_password }: z.infer<typeof passwordSchema>) {
    start.mutate(current_password, {
      onSuccess: ({ data }) => onStarted(data),
      onError: (error) => {
        if (!applyFieldErrors(error, form.setError, ['current_password'])) {
          toast.error('Could not start the setup', { description: errorMessage(error) })
        }
      },
    })
  }

  return (
    <form onSubmit={form.handleSubmit(onSubmit)} noValidate className="grid gap-4">
      <Field data-invalid={!!errors.current_password}>
        <FieldLabel htmlFor="two-factor-start-password">Password</FieldLabel>
        <Input
          id="two-factor-start-password"
          type="password"
          autoComplete="current-password"
          autoFocus
          aria-invalid={!!errors.current_password}
          aria-describedby={describedBy(errors.current_password && 'two-factor-start-password-error')}
          {...form.register('current_password')}
        />
        <FieldError id="two-factor-start-password-error" errors={[errors.current_password]} />
      </Field>
      <DialogFooter>
        <Button type="button" variant="outline" onClick={onCancel}>
          Cancel
        </Button>
        <Button type="submit" disabled={start.isPending}>
          {start.isPending && <Spinner />}
          Continue
        </Button>
      </DialogFooter>
    </form>
  )
}

const codeSchema = z.object({ code: z.string().regex(/^\d{6}$/, 'Enter the 6 digits from the app.') })

function SetupForm({
  setup,
  onCancel,
  onConfirmed,
}: {
  setup: TwoFactorSetup
  onCancel: () => void
  onConfirmed: (codes: string[]) => void
}) {
  const confirm = useConfirmTwoFactor({ onRecoveryCodes: onConfirmed })
  const form = useForm<z.infer<typeof codeSchema>>({ resolver: zodResolver(codeSchema), defaultValues: { code: '' } })
  const { errors } = form.formState
  // "JBSW Y3DP …": easier to type into the app by hand.
  const groupedKey = setup.secret.match(/.{1,4}/g)?.join(' ') ?? setup.secret

  function onSubmit({ code }: z.infer<typeof codeSchema>) {
    confirm.mutate(code, {
      onError: (error) => {
        form.setValue('code', '')
        if (!applyFieldErrors(error, form.setError, ['code'])) {
          toast.error('Could not turn on two-factor authentication', { description: errorMessage(error) })
        }
      },
    })
  }

  const submit = form.handleSubmit(onSubmit)

  return (
    <form onSubmit={submit} noValidate className="grid gap-4">
      <div className="flex flex-col items-center gap-4 sm:flex-row sm:items-start">
        <QrCode
          value={setup.otpauth_uri}
          label="QR code for your authenticator app"
          className="size-40 shrink-0 rounded-md border"
        />
        <div className="grid min-w-0 gap-2 text-sm">
          <p className="text-muted-foreground">Can't scan it? Enter this key in the app instead:</p>
          <code className="rounded-md bg-muted px-2 py-1 font-mono" aria-label={`Key: ${setup.secret}`}>
            {groupedKey}
          </code>
          <CopyButton text={setup.secret} label="Copy key" />
        </div>
      </div>
      <Field data-invalid={!!errors.code}>
        <FieldLabel htmlFor="two-factor-setup-code">Code from the app</FieldLabel>
        <Controller
          control={form.control}
          name="code"
          render={({ field }) => (
            <CodeInput
              id="two-factor-setup-code"
              ref={field.ref}
              autoFocus
              value={field.value}
              onChange={field.onChange}
              onBlur={field.onBlur}
              onComplete={() => {
                if (!confirm.isPending) {
                  void submit()
                }
              }}
              invalid={!!errors.code}
              aria-describedby={describedBy(errors.code && 'two-factor-setup-code-error')}
            />
          )}
        />
        <FieldError id="two-factor-setup-code-error" errors={[errors.code]} />
      </Field>
      <DialogFooter>
        <Button type="button" variant="outline" onClick={onCancel}>
          Cancel
        </Button>
        <Button type="submit" disabled={confirm.isPending}>
          {confirm.isPending && <Spinner />}
          Turn on
        </Button>
      </DialogFooter>
    </form>
  )
}

/** Shown once, after turning it on or making a new set. */
function RecoveryCodes({ codes, onDone }: { codes: string[]; onDone: () => void }) {
  // Browser APIs straight from the click: no effect needed, nothing to sync.
  function download() {
    const text = `React SaaS recovery codes\nEach one logs you in once.\n\n${codes.join('\n')}\n`
    const url = URL.createObjectURL(new Blob([text], { type: 'text/plain' }))
    const link = document.createElement('a')
    link.href = url
    link.download = 'react-saas-recovery-codes.txt'
    link.click()
    URL.revokeObjectURL(url)
  }

  return (
    <div className="grid gap-4">
      <ul className="grid grid-cols-2 gap-2 rounded-md bg-muted p-3 font-mono text-sm" aria-label="Recovery codes">
        {codes.map((code) => (
          <li key={code}>{code}</li>
        ))}
      </ul>
      <div className="flex flex-wrap gap-2">
        <CopyButton text={codes.join('\n')} label="Copy codes" />
        <Button type="button" variant="outline" size="sm" onClick={download}>
          <DownloadIcon />
          Download
        </Button>
      </div>
      <DialogFooter>
        <Button type="button" onClick={onDone}>
          I've saved them
        </Button>
      </DialogFooter>
    </div>
  )
}

function CopyButton({ text, label }: { text: string; label: string }) {
  const [copied, setCopied] = useState(false)

  async function copy() {
    try {
      await navigator.clipboard.writeText(text)
      setCopied(true)
      toast.success('Copied')
    } catch {
      toast.error("Couldn't copy. Select the text and copy it yourself.")
    }
  }

  return (
    <Button type="button" variant="outline" size="sm" className="justify-self-start" onClick={() => void copy()}>
      {copied ? <CheckIcon /> : <CopyIcon />}
      {label}
    </Button>
  )
}

const verifySchema = z
  .object({
    current_password: z.string().min(1, 'Enter your password.'),
    method: z.enum(['code', 'recovery_code']),
    code: z.string(),
    recovery_code: z.string(),
  })
  .superRefine((values, ctx) => {
    if (values.method === 'code' && !/^\d{6}$/.test(values.code)) {
      ctx.addIssue({ code: 'custom', path: ['code'], message: 'Enter the 6 digits from the app.' })
    }
    if (values.method === 'recovery_code' && values.recovery_code.trim() === '') {
      ctx.addIssue({ code: 'custom', path: ['recovery_code'], message: 'Enter one of your recovery codes.' })
    }
  })
type VerifyValues = z.infer<typeof verifySchema>

/** Turning it off, or a new set of codes: the password and a current code (or, to turn off, a recovery code). */
function VerifyForm({
  purpose,
  onCancel,
  onDone,
}: {
  purpose: 'disable' | 'regenerate'
  onCancel: () => void
  onDone: (codes?: string[]) => void
}) {
  const disable = useDisableTwoFactor()
  const regenerate = useRegenerateRecoveryCodes({ onRecoveryCodes: (codes) => onDone(codes) })
  const pending = disable.isPending || regenerate.isPending
  const form = useForm<VerifyValues>({
    resolver: zodResolver(verifySchema),
    defaultValues: { current_password: '', method: 'code', code: '', recovery_code: '' },
  })
  const { errors } = form.formState
  // Re-renders when `method` changes, like reading a ref in a Vue template.
  const method = useWatch({ control: form.control, name: 'method' })

  function onError(error: unknown) {
    form.setValue('code', '')
    if (!applyFieldErrors(error, form.setError, ['current_password', 'code', 'recovery_code'])) {
      toast.error(purpose === 'disable' ? 'Could not turn it off' : 'Could not make new codes', {
        description: errorMessage(error),
      })
    }
  }

  function onSubmit(values: VerifyValues) {
    const { current_password } = values
    if (purpose === 'regenerate') {
      regenerate.mutate({ current_password, code: values.code }, { onError })
      return
    }
    disable.mutate(
      values.method === 'code'
        ? { current_password, code: values.code }
        : { current_password, recovery_code: values.recovery_code.trim() },
      { onSuccess: () => onDone(), onError },
    )
  }

  return (
    <form onSubmit={form.handleSubmit(onSubmit)} noValidate className="grid gap-4">
      <FieldGroup>
        <Field data-invalid={!!errors.current_password}>
          <FieldLabel htmlFor="two-factor-verify-password">Password</FieldLabel>
          <Input
            id="two-factor-verify-password"
            type="password"
            autoComplete="current-password"
            autoFocus
            aria-invalid={!!errors.current_password}
            aria-describedby={describedBy(errors.current_password && 'two-factor-verify-password-error')}
            {...form.register('current_password')}
          />
          <FieldError id="two-factor-verify-password-error" errors={[errors.current_password]} />
        </Field>

        {method === 'code' ? (
          <Field data-invalid={!!errors.code}>
            <FieldLabel htmlFor="two-factor-verify-code">Code from your authenticator app</FieldLabel>
            <Controller
              control={form.control}
              name="code"
              render={({ field }) => (
                <CodeInput
                  id="two-factor-verify-code"
                  ref={field.ref}
              value={field.value}
                  onChange={field.onChange}
                  onBlur={field.onBlur}
                  invalid={!!errors.code}
                  aria-describedby={describedBy(errors.code && 'two-factor-verify-code-error')}
                />
              )}
            />
            <FieldError id="two-factor-verify-code-error" errors={[errors.code]} />
          </Field>
        ) : (
          <Field data-invalid={!!errors.recovery_code}>
            <FieldLabel htmlFor="two-factor-verify-recovery-code">Recovery code</FieldLabel>
            <FieldDescription id="two-factor-verify-recovery-code-hint">Each one works once.</FieldDescription>
            <Input
              id="two-factor-verify-recovery-code"
              autoComplete="off"
              autoCapitalize="none"
              spellCheck={false}
              aria-invalid={!!errors.recovery_code}
              aria-describedby={describedBy(
                'two-factor-verify-recovery-code-hint',
                errors.recovery_code && 'two-factor-verify-recovery-code-error',
              )}
              {...form.register('recovery_code')}
            />
            <FieldError id="two-factor-verify-recovery-code-error" errors={[errors.recovery_code]} />
          </Field>
        )}

        {/* A new set needs the app itself; turning off also takes a recovery code (lost phone). */}
        {purpose === 'disable' && (
          <Button
            type="button"
            variant="link"
            className="h-auto justify-self-start px-0"
            onClick={() => {
              form.clearErrors(['code', 'recovery_code'])
              form.setValue('method', method === 'code' ? 'recovery_code' : 'code')
            }}
          >
            {method === 'code' ? 'Lost your phone? Use a recovery code' : 'Use the authenticator app instead'}
          </Button>
        )}
      </FieldGroup>
      <DialogFooter>
        <Button type="button" variant="outline" onClick={onCancel}>
          Cancel
        </Button>
        <Button type="submit" variant={purpose === 'disable' ? 'destructive' : 'default'} disabled={pending}>
          {pending && <Spinner />}
          {purpose === 'disable' ? 'Turn off' : 'Make new codes'}
        </Button>
      </DialogFooter>
    </form>
  )
}
