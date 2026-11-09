import type { NextConfig } from 'next'

const nextConfig: NextConfig = {
  // The e2e build gets its own folder (servers.sh sets NEXT_DIST_DIR), like web's dist-e2e.
  distDir: process.env.NEXT_DIST_DIR || '.next',
  // This folder is the app's root (a lockfile higher up would otherwise be taken for it).
  // ../shared is outside it: import only types from @shared (erased at build), never values.
  outputFileTracingRoot: import.meta.dirname,
  turbopack: { root: import.meta.dirname },
  // Don't advertise the framework in every response.
  poweredByHeader: false,
  async headers() {
    return [
      {
        source: '/:path*',
        headers: [
          // No framing (clickjacking the task buttons), no MIME sniffing of the proxied JSON.
          { key: 'X-Frame-Options', value: 'DENY' },
          { key: 'Content-Security-Policy', value: "frame-ancestors 'none'" },
          { key: 'X-Content-Type-Options', value: 'nosniff' },
          // Reset and verification links carry tokens in the query: never send them on.
          { key: 'Referrer-Policy', value: 'no-referrer' },
        ],
      },
    ]
  },
}

export default nextConfig
