import { LaptopIcon } from 'lucide-react'
import { toast } from 'sonner'
import { ConfirmDialog } from '@/components/ConfirmDialog'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Skeleton } from '@/components/ui/skeleton'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { errorMessage } from '@/lib/apiErrors'
import { formatDateTime } from '@/lib/format'
import type { Device } from '@/types/api'
import { useDevices, useRevokeDevice } from './api'

export function DevicesCard() {
  const devices = useDevices()
  const revoke = useRevokeDevice()

  function onRevoke(device: Device) {
    revoke.mutate(device, {
      onSuccess: () => {
        if (!device.is_current) toast.success(`${device.name} signed out`)
      },
      onError: (error) => toast.error('Could not sign it out', { description: errorMessage(error) }),
    })
  }

  return (
    <Card>
      <CardHeader>
        <CardTitle>
          <h2>Signed-in devices</h2>
        </CardTitle>
        <CardDescription>Where your account is logged in. Sign out any you don't recognize.</CardDescription>
      </CardHeader>
      <CardContent>
        {devices.isPending ? (
          <div className="grid gap-2" aria-label="Loading devices">
            <Skeleton className="h-9 w-full" />
            <Skeleton className="h-9 w-full" />
          </div>
        ) : devices.isError ? (
          <div className="flex flex-wrap items-center gap-3 text-sm">
            <p className="text-muted-foreground">Could not load your devices. {errorMessage(devices.error)}</p>
            <Button variant="outline" size="sm" onClick={() => void devices.refetch()}>
              Retry
            </Button>
          </div>
        ) : (
          <Table aria-label="Signed-in devices">
            <TableHeader>
              <TableRow>
                <TableHead>Device</TableHead>
                <TableHead className="hidden sm:table-cell">IP address</TableHead>
                <TableHead className="hidden sm:table-cell">Last used</TableHead>
                <TableHead className="text-right">
                  <span className="sr-only">Actions</span>
                </TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {devices.data.data.map((device) => (
                <TableRow key={device.id}>
                  <TableCell className="whitespace-normal">
                    <span className="flex flex-wrap items-center gap-2 font-medium">
                      <LaptopIcon aria-hidden className="size-4 text-muted-foreground" />
                      {device.name}
                      {device.is_current && <Badge variant="secondary">This device</Badge>}
                    </span>
                  </TableCell>
                  <TableCell className="hidden text-muted-foreground sm:table-cell">{device.ip_address ?? '—'}</TableCell>
                  <TableCell className="hidden text-muted-foreground sm:table-cell">
                    {device.last_used_at ? formatDateTime(device.last_used_at) : 'Never'}
                  </TableCell>
                  <TableCell className="text-right">
                    <ConfirmDialog
                      title={device.is_current ? 'Sign out of this device?' : `Sign out ${device.name}?`}
                      description={
                        device.is_current
                          ? "You'll be logged out here and taken to the login page."
                          : 'It will need to log in again.'
                      }
                      confirmLabel="Sign out"
                      onConfirm={() => onRevoke(device)}
                    >
                      <Button variant="outline" size="sm" disabled={revoke.isPending} aria-label={`Sign out ${device.name}`}>
                        Sign out
                      </Button>
                    </ConfirmDialog>
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        )}
      </CardContent>
    </Card>
  )
}
