'use client';

import React, { useMemo, useState } from 'react';
import { useRouter } from 'next/navigation';
import { Plus, Trash2, CornerDownRight } from 'lucide-react';
import Card from '@/components/ui/Card';
import Button from '@/components/ui/Button';
import { cn } from '@/lib/utils';
import api from '@/lib/api';
import { useToast } from '@/components/ui/Toast';
import { useT } from '@/i18n';

type FieldDef = { field: string; type: string; operators: string[]; options?: string[]; unit?: string };
type ActionDef = { type: string; params: string[]; deferred: boolean };
export type Schema = {
  triggers: { value: string; label_key: string }[];
  fields: FieldDef[];
  operators: string[];
  operatorLabels: Record<string, string>;
  actions: ActionDef[];
};

type Leaf = { field: string; operator: string; value?: any };
type Group = { match: 'all' | 'any'; rules: Node[] };
type Node = Leaf | Group;
type Action = { id: string; type: string; [k: string]: any };

const isGroup = (n: Node): n is Group => (n as Group).rules !== undefined;
const NO_VALUE = ['is_empty', 'is_not_empty', 'is_true', 'is_false'];
const ARRAY_VALUE = ['in', 'not_in', 'any_of', 'all_of', 'none_of'];

let seq = 0;
const nextId = () => `a${Date.now().toString(36)}${seq++}`;

export type InitialRule = {
  id?: number;
  name: string;
  trigger: string;
  priority: number;
  run_mode: 'live' | 'dry_run';
  stop_processing: boolean;
  conditions: Group;
  actions: Action[];
};

export function RuleBuilder({ schema, initial }: { schema: Schema; initial: InitialRule }) {
  const t = useT();
  const router = useRouter();
  const { toast } = useToast();

  const [name, setName] = useState(initial.name);
  const [trigger, setTrigger] = useState(initial.trigger);
  const [priority, setPriority] = useState(initial.priority);
  const [runMode, setRunMode] = useState(initial.run_mode);
  const [stopProcessing, setStopProcessing] = useState(initial.stop_processing);
  const [conditions, setConditions] = useState<Group>(initial.conditions);
  const [actions, setActions] = useState<Action[]>(initial.actions);
  const [saving, setSaving] = useState(false);

  const fieldMap = useMemo(() => Object.fromEntries(schema.fields.map((f) => [f.field, f])), [schema.fields]);

  const save = async () => {
    setSaving(true);
    try {
      const payload = {
        name,
        trigger,
        priority: Number(priority) || 100,
        run_mode: runMode,
        stop_processing: stopProcessing,
        conditions,
        actions,
      };
      if (initial.id) {
        await api.put(`/automation/rules/${initial.id}`, payload);
      } else {
        await api.post('/automation/rules', payload);
      }
      toast(t('automation.builder.saved'), 'success');
      router.push('/automation');
    } catch {
      toast(t('automation.builder.saveError'), 'error');
    } finally {
      setSaving(false);
    }
  };

  return (
    <div className="space-y-6 max-w-3xl">
      <Card className="p-5 space-y-4">
        <label className="block">
          <span className="text-xs font-medium text-muted-foreground">{t('automation.builder.name')}</span>
          <input
            value={name}
            onChange={(e) => setName(e.target.value)}
            placeholder={t('automation.builder.namePlaceholder')}
            className="mt-1 w-full h-10 rounded-lg border border-border bg-background px-3 text-sm"
          />
        </label>
        <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
          <Field label={t('automation.builder.trigger')}>
            <Select value={trigger} onChange={setTrigger}>
              {schema.triggers.map((tr) => (
                <option key={tr.value} value={tr.value}>{t(`automation.triggers.${tr.value.replace(/\./g, '_')}`)}</option>
              ))}
            </Select>
          </Field>
          <Field label={t('automation.builder.priority')} hint={t('automation.builder.priorityHint')}>
            <input
              type="number"
              value={priority}
              onChange={(e) => setPriority(Number(e.target.value))}
              className="w-full h-10 rounded-lg border border-border bg-background px-3 text-sm"
            />
          </Field>
          <Field label={t('automation.builder.mode')}>
            <Select value={runMode} onChange={(v) => setRunMode(v as any)}>
              <option value="dry_run">{t('automation.dryRun')}</option>
              <option value="live">{t('automation.live')}</option>
            </Select>
          </Field>
        </div>
        <label className="flex items-center gap-2 text-sm">
          <input type="checkbox" checked={stopProcessing} onChange={(e) => setStopProcessing(e.target.checked)} />
          {t('automation.builder.stopProcessing')}
        </label>
      </Card>

      <RulePreview trigger={trigger} conditions={conditions} actions={actions} schema={schema} fieldMap={fieldMap} t={t} />

      <Card className="p-5 space-y-3">
        <h3 className="font-bold text-sm">{t('automation.builder.when')}</h3>
        <GroupEditor
          group={conditions}
          fields={schema.fields}
          fieldMap={fieldMap}
          operatorLabels={schema.operatorLabels}
          onChange={setConditions}
          depth={0}
          t={t}
        />
      </Card>

      <Card className="p-5 space-y-3">
        <h3 className="font-bold text-sm">{t('automation.builder.then')}</h3>
        {actions.length === 0 && <p className="text-xs text-muted-foreground">{t('automation.builder.noActions')}</p>}
        <div className="space-y-2">
          {actions.map((action, i) => (
            <ActionRow
              key={action.id}
              action={action}
              actionsSchema={schema.actions}
              onChange={(a) => setActions((prev) => prev.map((x, idx) => (idx === i ? a : x)))}
              onRemove={() => setActions((prev) => prev.filter((_, idx) => idx !== i))}
              t={t}
            />
          ))}
        </div>
        <button
          onClick={() => setActions((prev) => [...prev, { id: nextId(), type: 'add_tag', tags: '' }])}
          className="inline-flex items-center gap-1.5 text-xs font-semibold text-primary hover:underline"
        >
          <Plus size={14} /> {t('automation.builder.addAction')}
        </button>
      </Card>

      <div className="flex items-center gap-3">
        <Button onClick={save} disabled={saving || !name || actions.length === 0}>
          {saving ? t('automation.builder.saving') : t('automation.builder.save')}
        </Button>
        <Button variant="outline" onClick={() => router.push('/automation')}>{t('automation.builder.cancel')}</Button>
      </div>
    </div>
  );
}

function GroupEditor({
  group, fields, fieldMap, operatorLabels, onChange, depth, t,
}: {
  group: Group;
  fields: FieldDef[];
  fieldMap: Record<string, FieldDef>;
  operatorLabels: Record<string, string>;
  onChange: (g: Group) => void;
  depth: number;
  t: (k: string) => string;
}) {
  const setNode = (idx: number, node: Node) =>
    onChange({ ...group, rules: group.rules.map((r, i) => (i === idx ? node : r)) });
  const removeNode = (idx: number) => onChange({ ...group, rules: group.rules.filter((_, i) => i !== idx) });

  return (
    <div className={cn('rounded-lg border border-border p-3 space-y-2', depth > 0 && 'bg-accent/30')}>
      <div className="flex items-center gap-2">
        <span className="text-xs text-muted-foreground">{t('automation.builder.match')}</span>
        <div className="flex rounded-lg border border-border overflow-hidden">
          {(['all', 'any'] as const).map((m) => (
            <button
              key={m}
              onClick={() => onChange({ ...group, match: m })}
              className={cn('px-3 py-1 text-[11px] font-bold', group.match === m ? 'bg-primary text-white' : 'bg-background')}
            >
              {t(`automation.builder.${m}`)}
            </button>
          ))}
        </div>
      </div>

      {group.rules.length === 0 && (
        <p className="text-[11px] text-muted-foreground italic">{t('automation.builder.alwaysMatches')}</p>
      )}

      <div className="space-y-2">
        {group.rules.map((node, idx) =>
          isGroup(node) ? (
            <div key={idx} className="flex gap-2">
              <CornerDownRight size={16} className="mt-3 text-muted-foreground shrink-0" />
              <div className="flex-1">
                <GroupEditor group={node} fields={fields} fieldMap={fieldMap} operatorLabels={operatorLabels} onChange={(g) => setNode(idx, g)} depth={depth + 1} t={t} />
              </div>
              <button onClick={() => removeNode(idx)} className="mt-2 text-muted-foreground hover:text-destructive"><Trash2 size={15} /></button>
            </div>
          ) : (
            <LeafRow key={idx} leaf={node} fields={fields} fieldMap={fieldMap} operatorLabels={operatorLabels} onChange={(l) => setNode(idx, l)} onRemove={() => removeNode(idx)} t={t} />
          )
        )}
      </div>

      <div className="flex items-center gap-3 pt-1">
        <button
          onClick={() => onChange({ ...group, rules: [...group.rules, { field: fields[0].field, operator: fields[0].operators[0] }] })}
          className="inline-flex items-center gap-1 text-[11px] font-semibold text-primary hover:underline"
        >
          <Plus size={12} /> {t('automation.builder.addCondition')}
        </button>
        {depth < 2 && (
          <button
            onClick={() => onChange({ ...group, rules: [...group.rules, { match: 'any', rules: [] }] })}
            className="inline-flex items-center gap-1 text-[11px] font-semibold text-muted-foreground hover:underline"
          >
            <Plus size={12} /> {t('automation.builder.addGroup')}
          </button>
        )}
      </div>
    </div>
  );
}

function LeafRow({
  leaf, fields, fieldMap, operatorLabels, onChange, onRemove, t,
}: {
  leaf: Leaf;
  fields: FieldDef[];
  fieldMap: Record<string, FieldDef>;
  operatorLabels: Record<string, string>;
  onChange: (l: Leaf) => void;
  onRemove: () => void;
  t: (k: string) => string;
}) {
  const def = fieldMap[leaf.field];
  const operators = def?.operators ?? [];
  const showValue = !NO_VALUE.includes(leaf.operator);
  const singleEnum = def?.options && ['eq', 'neq'].includes(leaf.operator);

  return (
    <div className="flex flex-wrap items-center gap-2 rounded-lg bg-background border border-border p-2">
      <Select
        value={leaf.field}
        onChange={(field) => {
          const ops = fieldMap[field]?.operators ?? [];
          onChange({ field, operator: ops[0], value: undefined });
        }}
        className="min-w-[140px]"
      >
        {fields.map((f) => <option key={f.field} value={f.field}>{t(`automation.fields.${f.field}`)}</option>)}
      </Select>
      <Select value={leaf.operator} onChange={(operator) => onChange({ ...leaf, operator })} className="min-w-[130px]">
        {operators.map((op) => <option key={op} value={op}>{operatorLabels[op] ?? op}</option>)}
      </Select>
      {showValue && (
        singleEnum ? (
          <Select value={String(leaf.value ?? '')} onChange={(v) => onChange({ ...leaf, value: v })} className="min-w-[130px]">
            <option value="" disabled>{t('automation.builder.value')}</option>
            {def!.options!.map((o) => <option key={o} value={o}>{o}</option>)}
          </Select>
        ) : leaf.operator === 'between' ? (
          <div className="flex items-center gap-1">
            <input type="number" value={Array.isArray(leaf.value) ? leaf.value[0] ?? '' : ''} placeholder="min"
              onChange={(e) => onChange({ ...leaf, value: [Number(e.target.value), Array.isArray(leaf.value) ? leaf.value[1] ?? 0 : 0] })}
              className="w-20 h-9 rounded-lg border border-border bg-background px-2 text-sm" />
            <span className="text-muted-foreground">–</span>
            <input type="number" value={Array.isArray(leaf.value) ? leaf.value[1] ?? '' : ''} placeholder="max"
              onChange={(e) => onChange({ ...leaf, value: [Array.isArray(leaf.value) ? leaf.value[0] ?? 0 : 0, Number(e.target.value)] })}
              className="w-20 h-9 rounded-lg border border-border bg-background px-2 text-sm" />
          </div>
        ) : (
          <input
            value={Array.isArray(leaf.value) ? leaf.value.join(', ') : (leaf.value ?? '')}
            placeholder={ARRAY_VALUE.includes(leaf.operator) ? t('automation.builder.valuesHint') : t('automation.builder.value')}
            onChange={(e) => {
              const raw = e.target.value;
              const value = ARRAY_VALUE.includes(leaf.operator)
                ? raw.split(',').map((s) => s.trim()).filter(Boolean)
                : def?.type === 'int' || def?.type === 'decimal'
                  ? (raw === '' ? '' : Number(raw))
                  : raw;
              onChange({ ...leaf, value });
            }}
            className="flex-1 min-w-[120px] h-9 rounded-lg border border-border bg-background px-2 text-sm"
          />
        )
      )}
      <button onClick={onRemove} className="text-muted-foreground hover:text-destructive ms-auto"><Trash2 size={15} /></button>
    </div>
  );
}

function ActionRow({
  action, actionsSchema, onChange, onRemove, t,
}: {
  action: Action;
  actionsSchema: ActionDef[];
  onChange: (a: Action) => void;
  onRemove: () => void;
  t: (k: string) => string;
}) {
  const def = actionsSchema.find((a) => a.type === action.type);
  const params = def?.params ?? [];

  return (
    <div className="flex flex-wrap items-center gap-2 rounded-lg bg-background border border-border p-2">
      <Select
        value={action.type}
        onChange={(type) => onChange({ id: action.id, type })}
        className="min-w-[150px]"
      >
        {actionsSchema.map((a) => (
          <option key={a.type} value={a.type}>{t(`automation.actionTypes.${a.type}`)}{a.deferred ? ' •' : ''}</option>
        ))}
      </Select>
      {params.map((p) => (
        <input
          key={p}
          value={p === 'tags' ? (Array.isArray(action.tags) ? action.tags.join(', ') : action[p] ?? '') : (action[p] ?? '')}
          placeholder={t(`automation.params.${p}`)}
          onChange={(e) => {
            const v = p === 'tags' ? e.target.value.split(',').map((s) => s.trim()).filter(Boolean) : e.target.value;
            onChange({ ...action, [p]: v });
          }}
          className="flex-1 min-w-[110px] h-9 rounded-lg border border-border bg-background px-2 text-sm"
        />
      ))}
      {def?.deferred && <span className="text-[10px] text-orange-500">{t('automation.builder.deferredNote')}</span>}
      <button onClick={onRemove} className="text-muted-foreground hover:text-destructive ms-auto"><Trash2 size={15} /></button>
    </div>
  );
}

/** A plain-English rendering of the rule, so a non-technical user sees exactly what they're building. */
function RulePreview({
  trigger, conditions, actions, schema, t,
}: {
  trigger: string;
  conditions: Group;
  actions: Action[];
  schema: Schema;
  fieldMap: Record<string, FieldDef>;
  t: (k: string) => string;
}) {
  const opLabel = (op: string) => schema.operatorLabels[op] ?? op;
  const fieldLabel = (f: string) => t(`automation.fields.${f}`);
  const renderVal = (v: any) =>
    Array.isArray(v) ? v.join(', ') : v === undefined || v === null || v === '' ? '…' : String(v);

  const leafText = (leaf: Leaf) => {
    const noVal = NO_VALUE.includes(leaf.operator);
    return `${fieldLabel(leaf.field)} ${opLabel(leaf.operator)}${noVal ? '' : ' ' + renderVal(leaf.value)}`.trim();
  };
  const groupText = (group: Group): string => {
    const parts = (group.rules ?? []).map((n) => (isGroup(n) ? `(${groupText(n)})` : leafText(n)));
    if (parts.length === 0) return t('automation.builder.anyOrder');
    return parts.join(group.match === 'any' ? ` ${t('automation.builder.or')} ` : ` ${t('automation.builder.and')} `);
  };
  const actionText = (a: Action) => {
    const label = t(`automation.actionTypes.${a.type}`);
    const detail = a.tags
      ? `“${Array.isArray(a.tags) ? a.tags.join(', ') : a.tags}”`
      : a.status ?? a.folder ?? a.location ?? a.carrier ?? a.reason ?? a.title ?? '';
    return detail ? `${label} ${detail}` : label;
  };

  const triggerLabel = t(`automation.triggers.${trigger.replace(/\./g, '_')}`);
  const acts = actions.map(actionText).join(', ') || '…';

  return (
    <Card className="p-4 bg-primary/5 border-primary/20">
      <p className="text-[10px] uppercase font-bold text-primary/70 tracking-widest mb-1">{t('automation.builder.previewTitle')}</p>
      <p className="text-sm leading-relaxed">
        <span className="font-bold text-primary">{t('automation.builder.pWhen')}</span> {triggerLabel.toLowerCase()},{' '}
        <span className="font-bold text-primary">{t('automation.builder.pIf')}</span> {groupText(conditions)},{' '}
        <span className="font-bold text-primary">{t('automation.builder.pThen')}</span> {acts}.
      </p>
    </Card>
  );
}

function Field({ label, hint, children }: { label: string; hint?: string; children: React.ReactNode }) {
  return (
    <label className="block">
      <span className="text-xs font-medium text-muted-foreground">{label}</span>
      <div className="mt-1">{children}</div>
      {hint && <span className="text-[10px] text-muted-foreground">{hint}</span>}
    </label>
  );
}

function Select({ value, onChange, children, className }: {
  value: string;
  onChange: (v: string) => void;
  children: React.ReactNode;
  className?: string;
}) {
  return (
    <select
      value={value}
      onChange={(e) => onChange(e.target.value)}
      className={cn('h-9 rounded-lg border border-border bg-background px-2 text-sm', className)}
    >
      {children}
    </select>
  );
}

export const EMPTY_RULE: InitialRule = {
  name: '',
  trigger: 'order.created',
  priority: 100,
  run_mode: 'dry_run',
  stop_processing: false,
  conditions: { match: 'all', rules: [] },
  actions: [{ id: nextId(), type: 'add_tag', tags: '' }],
};
