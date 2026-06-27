import React from 'react';
import { cn } from '@/lib/utils';

interface InputProps extends React.InputHTMLAttributes<HTMLInputElement> {
  label?: string;
  error?: string;
}

export default function Input({ label, error, className, id, ...props }: InputProps) {
  return (
    <div className="space-y-1.5 w-full">
      {label && (
        <label htmlFor={id} className="text-xs font-medium text-muted-foreground ml-1">
          {label}
        </label>
      )}
      <input
        id={id}
        className={cn(
          "w-full bg-background border border-border rounded-xl px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-primary/50 transition-all placeholder:text-muted-foreground/50",
          error && "border-destructive focus:ring-destructive/50",
          className
        )}
        {...props}
      />
      {error && (
        <p className="text-[10px] font-medium text-destructive ml-1">
          {error}
        </p>
      )}
    </div>
  );
}
