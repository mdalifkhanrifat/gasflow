// Badge colors for each tone in status_definitions.tone. A new status or a
// changed tone is a database row; only a new tone needs an entry here.
export const statusTones = {
    neutral: 'bg-surface-muted text-ink',
    info: 'bg-primary-soft text-primary',
    success: 'bg-success-soft text-success',
    warning: 'bg-warning-soft text-warning',
    danger: 'bg-danger-soft text-danger',
    muted: 'bg-surface-muted text-ink-muted',
} as const;

export type StatusTone = keyof typeof statusTones;
