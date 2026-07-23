'use client';

import React, { useEffect, useState } from 'react';
import { useParams, useRouter } from 'next/navigation';
import { ChevronLeft } from 'lucide-react';
import api from '@/lib/api';
import { useT } from '@/i18n';
import { RuleBuilder, type Schema, type InitialRule } from '@/components/automation/RuleBuilder';

export default function EditRulePage() {
  const t = useT();
  const router = useRouter();
  const { id } = useParams();
  const [schema, setSchema] = useState<Schema | null>(null);
  const [initial, setInitial] = useState<InitialRule | null>(null);

  useEffect(() => {
    (async () => {
      try {
        const [s, r] = await Promise.all([api.get('/automation/schema'), api.get(`/automation/rules/${id}`)]);
        setSchema(s.data);
        const rule = r.data;
        setInitial({
          id: rule.id,
          name: rule.name,
          trigger: rule.trigger,
          priority: rule.priority,
          run_mode: rule.run_mode,
          stop_processing: rule.stop_processing,
          conditions: rule.conditions ?? { match: 'all', rules: [] },
          actions: Array.isArray(rule.actions) ? rule.actions : [],
        });
      } catch {
        router.push('/automation');
      }
    })();
  }, [id, router]);

  return (
    <div className="space-y-6">
      <button onClick={() => router.push('/automation')} className="flex items-center gap-2 text-sm text-muted-foreground hover:text-foreground">
        <ChevronLeft size={16} /> {t('automation.title')}
      </button>
      <h1 className="text-2xl font-bold">{t('automation.editRule')}</h1>
      {schema && initial ? (
        <RuleBuilder schema={schema} initial={initial} />
      ) : (
        <div className="flex items-center justify-center py-20">
          <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-primary" />
        </div>
      )}
    </div>
  );
}
