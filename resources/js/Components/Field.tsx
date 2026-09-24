import type { ReactNode } from 'react';

export default function Field({ label, error, hint, htmlFor, children, className = '' }: { label: string; error?: string; hint?: string; htmlFor?: string; children: ReactNode; className?: string }) {
    return (
        <div className={className}>
            <label htmlFor={htmlFor} className="label">
                {label}
            </label>
            {children}
            {hint && !error && <p className="mt-1 text-xs text-muted">{hint}</p>}
            {error && <p className="mt-1 text-xs text-bad">{error}</p>}
        </div>
    );
}
