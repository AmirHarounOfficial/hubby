'use client';

import React, { useEffect, useMemo, useState } from 'react';
import { useRouter } from 'next/navigation';
import { ChevronLeft, Sparkles, PenLine, ArrowRight } from 'lucide-react';
import Card from '@/components/ui/Card';
import api from '@/lib/api';
import { useT } from '@/i18n';
import { RuleBuilder, EMPTY_RULE, type Schema, type InitialRule } from '@/components/automation/RuleBuilder';

type Template = {
  id: string;
  category: string;
  icon: string;
  name: string;
  description: string;
  rule: Omit<InitialRule, 'id' | 'priority' | 'stop_processing'> & { priority?: number; stop_processing?: boolean };
};

const CATEGORY_ORDER = ['risk', 'fulfilment', 'organisation', 'alerts'];

export default function NewRulePage() {
  const t = useT();
  const router = useRouter();
  const [schema, setSchema] = useState<Schema | null>(null);
  const [templates, setTemplates] = useState<Template[]>([]);
  const [initial, setInitial] = useState<InitialRule | null>(null);

  useEffect(() => {
    Promise.all([api.get('/automation/schema'), api.get('/automation/templates')])
      .then(([s, tpl]) => {
        setSchema(s.data);
        setTemplates(tpl.data);
      })
      .catch(() => setSchema(null));
  }, []);

  const grouped = useMemo(() => {
    const byCat: Record<string, Template[]> = {};
    for (const tpl of templates) (byCat[tpl.category] ??= []).push(tpl);
    return CATEGORY_ORDER.filter((c) => byCat[c]?.length).map((c) => ({ category: c, items: byCat[c] }));
  }, [templates]);

  // Translated card text, falling back to the backend's English if a template has no translation yet.
  const tName = (tpl: Template) => {
    const k = `automation.templates.${tpl.id}.name`;
    const v = t(k);
    return v === k ? tpl.name : v;
  };
  const tDesc = (tpl: Template) => {
    const k = `automation.templates.${tpl.id}.description`;
    const v = t(k);
    return v === k ? tpl.description : v;
  };

  const pick = (tpl: Template) =>
    setInitial({
      name: tpl.rule.name,
      trigger: tpl.rule.trigger,
      priority: tpl.rule.priority ?? 100,
      run_mode: tpl.rule.run_mode ?? 'dry_run',
      stop_processing: tpl.rule.stop_processing ?? false,
      conditions: tpl.rule.conditions,
      actions: tpl.rule.actions,
    });

  return (
    <div className="space-y-6">
      <button
        onClick={() => (initial ? setInitial(null) : router.push('/automation'))}
        className="flex items-center gap-2 text-sm text-muted-foreground hover:text-foreground"
      >
        <ChevronLeft size={16} /> {initial ? t('automation.builder.startTemplate') : t('automation.title')}
      </button>
      <h1 className="text-2xl font-bold">{t('automation.newRule')}</h1>

      {!schema ? (
        <div className="flex items-center justify-center py-20">
          <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-primary" />
        </div>
      ) : initial ? (
        <RuleBuilder schema={schema} initial={initial} />
      ) : (
        <div className="space-y-8">
          {/* Start from scratch */}
          <button
            onClick={() => setInitial(EMPTY_RULE)}
            className="w-full text-left"
          >
            <Card className="p-5 flex items-center gap-4 hover:border-primary/40 transition-colors">
              <div className="h-11 w-11 rounded-xl bg-accent flex items-center justify-center text-muted-foreground">
                <PenLine size={20} />
              </div>
              <div className="flex-1">
                <p className="font-bold">{t('automation.builder.startScratch')}</p>
                <p className="text-xs text-muted-foreground">{t('automation.builder.startScratchHint')}</p>
              </div>
              <ArrowRight size={18} className="text-muted-foreground" />
            </Card>
          </button>

          {/* Templates */}
          <div className="space-y-6">
            <div className="flex items-center gap-2">
              <Sparkles size={16} className="text-primary" />
              <h2 className="font-bold text-sm">{t('automation.builder.startTemplate')}</h2>
            </div>
            {grouped.map(({ category, items }) => (
              <div key={category} className="space-y-3">
                <h3 className="text-[11px] uppercase font-bold text-muted-foreground tracking-widest">
                  {t(`automation.builder.categories.${category}`)}
                </h3>
                <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                  {items.map((tpl) => (
                    <button key={tpl.id} onClick={() => pick(tpl)} className="text-left">
                      <Card className="p-4 h-full flex flex-col gap-2 hover:border-primary/40 hover:shadow-md transition-all">
                        <p className="font-bold text-sm">{tName(tpl)}</p>
                        <p className="text-xs text-muted-foreground flex-1">{tDesc(tpl)}</p>
                        <span className="inline-flex items-center gap-1 text-xs font-semibold text-primary">
                          {t('automation.builder.use')} <ArrowRight size={13} />
                        </span>
                      </Card>
                    </button>
                  ))}
                </div>
              </div>
            ))}
          </div>
        </div>
      )}
    </div>
  );
}
