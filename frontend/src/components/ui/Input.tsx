import React from 'react';
import { cn } from '@/lib/utils';

interface InputProps extends React.InputHTMLAttributes<HTMLInputElement> {
  label?: string;
  error?: string;
}

export default function Input({ label, error, className, id, ...props }: InputProps) {
  // Ensure the label is always associated with the input, even when the caller
  // doesn't pass an explicit id (a11y: form-labels).
  const autoId = React.useId();
  const inputId = id ?? autoId;
  const errorId = error ? `${inputId}-error` : undefined;
  return (
    <div className="space-y-1.5 w-full">
      {label && (
        <label htmlFor={inputId} className="text-xs font-medium text-muted-foreground ml-1">
          {label}
        </label>
      )}
      <input
        id={inputId}
        aria-invalid={error ? true : undefined}
        aria-describedby={errorId}
        className={cn(
          "w-full bg-background border border-border rounded-xl px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-primary/50 transition-all placeholder:text-muted-foreground/50",
          error && "border-destructive focus:ring-destructive/50",
          className
        )}
        {...props}
      />
      {error && (
        <p id={errorId} className="text-[10px] font-medium text-destructive ml-1">
          {error}
        </p>
      )}
    </div>
  );
}
