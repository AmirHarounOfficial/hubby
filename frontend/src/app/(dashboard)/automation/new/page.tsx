'use client';

import React, { useEffect, useState } from 'react';
import { useRouter } from 'next/navigation';
import { ChevronLeft } from 'lucide-react';
import api from '@/lib/api';
import { useT } from '@/i18n';
import { RuleBuilder, EMPTY_RULE, type Schema } from '@/components/automation/RuleBuilder';

export default function NewRulePage() {
  const t = useT();
  const router = useRouter();
  const [schema, setSchema] = useState<Schema | null>(null);

  useEffect(() => {
    api.get('/automation/schema').then((r) => setSchema(r.data)).catch(() => setSchema(null));
  }, []);

  return (
    <div className="space-y-6">
      <button onClick={() => router.push('/automation')} className="flex items-center gap-2 text-sm text-muted-foreground hover:text-foreground">
        <ChevronLeft size={16} /> {t('automation.title')}
      </button>
      <h1 className="text-2xl font-bold">{t('automation.newRule')}</h1>
      {schema ? (
        <RuleBuilder schema={schema} initial={EMPTY_RULE} />
      ) : (
        <div className="flex items-center justify-center py-20">
          <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-primary" />
        </div>
      )}
    </div>
  );
}
