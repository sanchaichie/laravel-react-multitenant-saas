// Names people see for the API's feature keys (ai_usage.feature); an unknown one shows as is.
const featureLabels: Record<string, string> = {
  task_summary: 'Task summaries',
  task_writing: 'Writing help',
  task_chat: 'Task chat',
}

export const featureLabel = (feature: string) => featureLabels[feature] ?? feature

/** ?month= is YYYY-MM or nothing (this month): anything else is dropped. */
export function parseMonth(value: string | null): string {
  return value !== null && /^\d{4}-(0[1-9]|1[0-2])$/.test(value) ? value : ''
}
