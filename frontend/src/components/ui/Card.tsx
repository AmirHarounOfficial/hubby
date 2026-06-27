import React from 'react';
import { cn } from '@/lib/utils';

interface CardProps extends React.HTMLAttributes<HTMLDivElement> {
  children: React.ReactNode;
  className?: string;
}

export default function Card({ children, className, ...props }: CardProps) {
  return (
    <div 
      className={cn("bg-card border border-border rounded-2xl glass p-6 shadow-xl", className)}
      {...props}
    >
      {children}
    </div>
  );
}
