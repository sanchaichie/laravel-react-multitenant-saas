'use client'

import dynamic from 'next/dynamic'
import type { ApexOptions } from 'apexcharts'
import { Card } from '@/components/Card'
import { useTheme } from '@/context/theme-context'

// ApexCharts needs `window`: load it in the browser only (TailAdmin's rule), so the
// server sends the cards and the charts draw once the script arrives.
const Chart = dynamic(() => import('react-apexcharts'), {
  ssr: false,
  loading: () => <div className="h-64 animate-pulse rounded-xl bg-gray-100 dark:bg-gray-800" />,
})

// From TailAdmin's @theme (brand-500 and friends): chart options take hex values.
const COLORS = { todo: '#98a2b3', inProgress: '#f79009', done: '#12b76a', high: '#f04438', medium: '#465fff', low: '#98a2b3' }

interface TaskChartsProps {
  status: { todo: number; inProgress: number; done: number }
  priority: { high: number; medium: number; low: number }
}

/** Tasks by status (donut) and by priority (bars). The numbers come from the server as props. */
export function TaskCharts({ status, priority }: TaskChartsProps) {
  const { theme } = useTheme()
  const text = theme === 'dark' ? '#98a2b3' : '#667085'

  const donut: ApexOptions = {
    chart: { type: 'donut', fontFamily: 'inherit', background: 'transparent' },
    labels: ['To do', 'In progress', 'Done'],
    colors: [COLORS.todo, COLORS.inProgress, COLORS.done],
    legend: { position: 'bottom', labels: { colors: text } },
    dataLabels: { enabled: false },
    stroke: { width: 0 },
    plotOptions: {
      pie: {
        donut: {
          size: '70%',
          labels: { show: true, total: { show: true, label: 'Tasks', color: text }, value: { color: text } },
        },
      },
    },
    theme: { mode: theme },
  }

  const bars: ApexOptions = {
    chart: { type: 'bar', fontFamily: 'inherit', toolbar: { show: false }, background: 'transparent' },
    colors: [COLORS.high, COLORS.medium, COLORS.low],
    plotOptions: { bar: { distributed: true, borderRadius: 6, columnWidth: '45%' } },
    xaxis: { categories: ['High', 'Medium', 'Low'], labels: { style: { colors: text } }, axisBorder: { show: false }, axisTicks: { show: false } },
    yaxis: { labels: { style: { colors: text }, formatter: (value) => String(Math.round(value)) } },
    grid: { borderColor: theme === 'dark' ? '#1d2939' : '#e4e7ec' },
    legend: { show: false },
    dataLabels: { enabled: false },
    tooltip: { y: { formatter: (value) => `${value} tasks` } },
    theme: { mode: theme },
  }

  return (
    <div className="grid gap-4 md:gap-6 lg:grid-cols-2">
      <Card className="p-5 sm:p-6">
        <h2 className="mb-4 text-base font-medium text-gray-800 dark:text-white/90">By status</h2>
        <div role="img" aria-label={`By status: ${status.todo} to do, ${status.inProgress} in progress, ${status.done} done.`}>
          <Chart type="donut" height={280} options={donut} series={[status.todo, status.inProgress, status.done]} />
        </div>
      </Card>
      <Card className="p-5 sm:p-6">
        <h2 className="mb-4 text-base font-medium text-gray-800 dark:text-white/90">By priority</h2>
        <div role="img" aria-label={`By priority: ${priority.high} high, ${priority.medium} medium, ${priority.low} low.`}>
          <Chart type="bar" height={280} options={bars} series={[{ name: 'Tasks', data: [priority.high, priority.medium, priority.low] }]} />
        </div>
      </Card>
    </div>
  )
}
