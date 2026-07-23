'use client';

import React, { useCallback, useEffect, useState } from 'react';
import Card from '@/components/ui/Card';
import { Workflow, Power, Trash2, Activity } from 'lucide-react';
import { cn } from '@/lib/utils';
import api from '@/lib/api';
import { useT } from '@/i18n';

type Rule = {
  id: number;
  name: string;
  trigger: string;
  priority: number;
  enabled: boolean;
  run_mode: 'live' | 'dry_run';
  matched_count: number;
  applied_count: number;
};

type Run = {
  id: number;
  rule_name: string;
  subject_label: string | null;
  outcome: string;
  created_at: string;
};

const outcomeColor: Record<string, string> = {
  matched: 'text-secondary bg-secondary/10',
  simulated: 'text-primary bg-primary/10',
  deduped: 'text-muted-foreground bg-accent',
  skipped: 'text-muted-foreground bg-accent',
  partial: 'text-orange-600 bg-orange-100',
  failed: 'text-destructive bg-destructive/10',
};

export default function AutomationPage() {
  const t = useT();
  const [rules, setRules] = useState<Rule[]>([]);
  const [runs, setRuns] = useState<Run[]>([]);
  const [isLoading, setIsLoading] = useState(true);

  const load = useCallback(async () => {
    setIsLoading(true);
    try {
      const [r, a] = await Promise.all([
        api.get('/automation/rules'),
        api.get('/automation/runs', { params: { limit: 30 } }),
      ]);
      setRules(r.data);
      setRuns(a.data);
    } catch (err) {
      console.error('Failed to load automation', err);
    } finally {
      setIsLoading(false);
    }
  }, []);

  useEffect(() => {
    void load();
  }, [load]);

  const toggle = async (rule: Rule) => {
    setRules((prev) => prev.map((r) => (r.id === rule.id ? { ...r, enabled: !r.enabled } : r)));
    try {
      await api.post(`/automation/rules/${rule.id}/toggle`);
    } catch {
      void load(); // revert on failure
    }
  };

  const remove = async (rule: Rule) => {
    if (!window.confirm(t('automation.confirmDelete'))) return;
    await api.delete(`/automation/rules/${rule.id}`);
    void load();
  };

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold flex items-center gap-3">
          <Workflow className="text-primary" />
          {t('automation.title')}
        </h1>
        <p className="text-muted-foreground text-sm">{t('automation.subtitle')}</p>
      </div>

      {isLoading ? (
        <div className="flex items-center justify-center py-24">
          <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-primary" />
        </div>
      ) : (
        <>
          <Card className="p-0 overflow-hidden">
            <div className="p-4 border-b border-border">
              <h3 className="font-bold">{t('automation.rules')}</h3>
            </div>
            {rules.length === 0 ? (
              <div className="p-10 text-center space-y-2">
                <p className="font-medium">{t('automation.empty')}</p>
                <p className="text-sm text-muted-foreground max-w-lg mx-auto">{t('automation.emptyHint')}</p>
              </div>
            ) : (
              <div className="overflow-x-auto">
                <table className="w-full text-left text-sm">
                  <thead>
                    <tr className="bg-accent/50 text-muted-foreground text-[10px] uppercase tracking-wider font-bold">
                      <th className="px-5 py-3">{t('automation.colName')}</th>
                      <th className="px-5 py-3">{t('automation.colTrigger')}</th>
                      <th className="px-5 py-3">{t('automation.colMode')}</th>
                      <th className="px-5 py-3 text-center">{t('automation.colMatched')}</th>
                      <th className="px-5 py-3 text-center">{t('automation.colApplied')}</th>
                      <th className="px-5 py-3 text-center">{t('automation.colStatus')}</th>
                      <th className="px-5 py-3" />
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-border">
                    {rules.map((rule) => (
                      <tr key={rule.id} className="hover:bg-accent/30 transition-colors">
                        <td className="px-5 py-3 font-medium">{rule.name}</td>
                        <td className="px-5 py-3 text-muted-foreground">
                          {t(`automation.triggers.${rule.trigger.replace(/\./g, '_')}`)}
                        </td>
                        <td className="px-5 py-3">
                          <span className={cn('px-2 py-0.5 rounded text-[10px] font-bold uppercase',
                            rule.run_mode === 'live' ? 'bg-secondary/10 text-secondary' : 'bg-accent text-muted-foreground')}>
                            {rule.run_mode === 'live' ? t('automation.live') : t('automation.dryRun')}
                          </span>
                        </td>
                        <td className="px-5 py-3 text-center tabular-nums">{rule.matched_count}</td>
                        <td className="px-5 py-3 text-center tabular-nums">{rule.applied_count}</td>
                        <td className="px-5 py-3 text-center">
                          <button
                            onClick={() => toggle(rule)}
                            title={rule.enabled ? t('automation.enabled') : t('automation.disabled')}
                            className={cn('inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg text-[11px] font-bold transition-colors',
                              rule.enabled ? 'bg-secondary/15 text-secondary' : 'bg-accent text-muted-foreground')}
                          >
                            <Power size={12} />
                            {rule.enabled ? t('automation.enabled') : t('automation.disabled')}
                          </button>
                        </td>
                        <td className="px-5 py-3 text-right">
                          <button
                            onClick={() => remove(rule)}
                            className="p-1.5 rounded-lg text-muted-foreground hover:text-destructive hover:bg-destructive/10 transition-colors"
                            title={t('automation.delete')}
                          >
                            <Trash2 size={16} />
                          </button>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </Card>

          <Card className="p-0 overflow-hidden">
            <div className="p-4 border-b border-border flex items-center gap-2">
              <Activity size={16} className="text-primary" />
              <h3 className="font-bold">{t('automation.runs')}</h3>
            </div>
            {runs.length === 0 ? (
              <p className="p-8 text-center text-sm text-muted-foreground">{t('automation.runsEmpty')}</p>
            ) : (
              <ul className="divide-y divide-border">
                {runs.map((run) => (
                  <li key={run.id} className="px-5 py-3 flex items-center gap-3 text-sm">
                    <span className={cn('px-2 py-0.5 rounded text-[10px] font-bold uppercase', outcomeColor[run.outcome] ?? 'bg-accent')}>
                      {t(`automation.outcome.${run.outcome}`)}
                    </span>
                    <span className="font-medium">{run.rule_name}</span>
                    {run.subject_label && <span className="text-muted-foreground">· {run.subject_label}</span>}
                    <span className="ms-auto text-xs text-muted-foreground">
                      {new Date(run.created_at).toLocaleString()}
                    </span>
                  </li>
                ))}
              </ul>
            )}
          </Card>
        </>
      )}
    </div>
  );
}
