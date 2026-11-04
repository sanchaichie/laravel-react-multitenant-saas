import { useMemo } from 'react'
import { encode } from 'uqr'

interface QrCodeProps {
  /** What the code holds, here the otpauth:// link for the authenticator app. */
  value: string
  /** What a screen reader says instead (the key is shown next to it as text). */
  label: string
  className?: string
}

/**
 * A QR code drawn as SVG in JSX. uqr's encode() gives the grid of dark and light
 * cells; each dark one becomes a 1×1 square in a single <path>. No HTML string, so
 * no dangerouslySetInnerHTML. Always black on white, in dark mode too: scanners
 * expect it.
 */
export function QrCode({ value, label, className }: QrCodeProps) {
  // Worked out during render, again only when `value` changes (like a computed()).
  const { size, path } = useMemo(() => {
    const qr = encode(value, { ecc: 'M', border: 2 })
    let d = ''
    qr.data.forEach((row, y) =>
      row.forEach((dark, x) => {
        if (dark) {
          d += `M${x} ${y}h1v1h-1z`
        }
      }),
    )
    return { size: qr.size, path: d }
  }, [value])

  return (
    <svg
      viewBox={`0 0 ${size} ${size}`}
      role="img"
      aria-label={label}
      shapeRendering="crispEdges"
      className={className}
    >
      <rect width={size} height={size} fill="#fff" />
      <path d={path} fill="#000" />
    </svg>
  )
}
