import { useEffect, useState } from 'react'
import { CheckIcon, CopyIcon } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'

interface SecretDialogProps {
  /** The secret to show, or null when closed. It's only ever in the caller's state, never the query cache. */
  secret: string | null
  onClose: () => void
}

/** A webhook's signing secret, shown once (after creating it, or rolling it). */
export function SecretDialog({ secret, onClose }: SecretDialogProps) {
  return (
    <Dialog open={secret !== null} onOpenChange={(open) => !open && onClose()}>
      <DialogContent className="sm:max-w-lg">
        <DialogHeader>
          <DialogTitle>Save the signing secret</DialogTitle>
          <DialogDescription>
            Your receiver uses it to check that a delivery came from us. It won't be shown again: if you lose it, roll a new one.
          </DialogDescription>
        </DialogHeader>
        {secret !== null && <SecretValue secret={secret} />}
        <DialogFooter>
          <Button onClick={onClose}>I've saved it</Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}

function SecretValue({ secret }: { secret: string }) {
  const [copied, setCopied] = useState(false)

  // "Copied" for 2 seconds. The cleanup clears the timer if the dialog closes first
  // (onBeforeUnmount(clearTimeout) in Vue), so nothing updates an unmounted component.
  useEffect(() => {
    if (!copied) return
    const timer = setTimeout(() => setCopied(false), 2000)
    return () => clearTimeout(timer)
  }, [copied])

  async function copy() {
    try {
      await navigator.clipboard.writeText(secret)
      setCopied(true)
    } catch {
      // No clipboard (an http page, a denied permission): the text is selectable.
    }
  }

  return (
    <div className="flex items-center gap-2">
      <code className="min-w-0 flex-1 rounded-md border bg-muted px-3 py-2 font-mono text-xs break-all select-all">{secret}</code>
      <Button type="button" variant="outline" size="sm" onClick={() => void copy()}>
        {copied ? <CheckIcon aria-hidden /> : <CopyIcon aria-hidden />}
        {copied ? 'Copied' : 'Copy'}
      </Button>
    </div>
  )
}
