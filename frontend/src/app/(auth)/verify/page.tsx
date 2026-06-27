'use client';

import React, { useState, useEffect, Suspense } from 'react';
import { useSearchParams, useRouter } from 'next/navigation';
import api from '@/lib/api';
import Button from '@/components/ui/Button';
import Card from '@/components/ui/Card';
import { CheckCircle2, XCircle, Loader2, Mail } from 'lucide-react';

function VerifyContent() {
  const [status, setStatus] = useState<'loading' | 'success' | 'error'>('loading');
  const [message, setMessage] = useState('');
  const searchParams = useSearchParams();
  const router = useRouter();

  useEffect(() => {
    const id = searchParams.get('id');
    const hash = searchParams.get('hash');

    if (id && hash) {
      verifyEmail(id, hash);
    } else {
      setStatus('error');
      setMessage('Invalid verification link.');
    }
  }, [searchParams]);

  const verifyEmail = async (id: string, hash: string) => {
    try {
      const response = await api.get(`/email/verify/${id}/${hash}`);
      setStatus('success');
      setMessage(response.data.message);
      setTimeout(() => {
        router.push('/dashboard');
      }, 3000);
    } catch (err: any) {
      setStatus('error');
      setMessage(err.response?.data?.message || 'Verification failed.');
    }
  };

  return (
    <Card className="max-w-md w-full text-center py-10">
      {status === 'loading' && (
        <div className="flex flex-col items-center gap-4">
          <Loader2 className="animate-spin text-primary" size={48} />
          <h2 className="text-xl font-semibold">Verifying your email...</h2>
          <p className="text-muted-foreground text-sm">Please wait a moment while we process your request.</p>
        </div>
      )}

      {status === 'success' && (
        <div className="flex flex-col items-center gap-4">
          <CheckCircle2 className="text-secondary" size={48} />
          <h2 className="text-xl font-semibold text-secondary">Email Verified!</h2>
          <p className="text-muted-foreground text-sm">{message}</p>
          <p className="text-xs text-muted-foreground mt-4 italic">Redirecting to dashboard...</p>
          <Button onClick={() => router.push('/dashboard')} variant="secondary" className="mt-4">
            Go to Dashboard
          </Button>
        </div>
      )}

      {status === 'error' && (
        <div className="flex flex-col items-center gap-4">
          <XCircle className="text-destructive" size={48} />
          <h2 className="text-xl font-semibold text-destructive">Verification Failed</h2>
          <p className="text-muted-foreground text-sm">{message}</p>
          <Button onClick={() => router.push('/login')} variant="outline" className="mt-4">
            Back to Login
          </Button>
        </div>
      )}
    </Card>
  );
}

export default function VerifyPage() {
  return (
    <div className="min-h-screen flex items-center justify-center p-6 bg-background">
      <Suspense fallback={
        <Card className="max-w-md w-full text-center py-10 flex flex-col items-center gap-4">
          <Loader2 className="animate-spin text-primary" size={48} />
          <h2 className="text-xl font-semibold">Loading...</h2>
        </Card>
      }>
        <VerifyContent />
      </Suspense>
    </div>
  );
}
