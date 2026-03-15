import { useState } from 'react';
import { usePressConference, useAnswerPressConference } from '@/hooks/useApi';
import { PageLayout, PageHeader, Section, StatCard, ActionButton, EmptyBlock } from '@/components/ui/sports-ui';
import { Badge } from '@/components/ui/badge';
import { toast } from 'sonner';
import { Mic2 } from 'lucide-react';
import { motion } from 'framer-motion';

const toneColors: Record<string, string> = {
  confident: 'border-blue-500/30 bg-blue-500/10',
  humble: 'border-green-500/30 bg-green-500/10',
  diplomatic: 'border-gray-500/30 bg-gray-500/10',
  deflective: 'border-yellow-500/30 bg-yellow-500/10',
  combative: 'border-red-500/30 bg-red-500/10',
};

export default function PressConference() {
  const { data: pc, isLoading, error } = usePressConference();
  const answer = useAnswerPressConference();
  const [selectedAnswers, setSelectedAnswers] = useState<Record<number, number>>({});
  const [results, setResults] = useState<{ influence_change: number; morale_change: number; media_change: number } | null>(null);

  if (isLoading) return <p className="text-[var(--text-secondary)]">Loading...</p>;
  if (error || !pc) {
    return (
      <PageLayout>
        <PageHeader title="Press Conference" icon={Mic2} accentColor="var(--accent-red)" />
        <EmptyBlock
          icon={Mic2}
          title="No Press Conference Available"
          description="There's no press conference scheduled right now. Check back after your next game."
        />
      </PageLayout>
    );
  }

  const questions = pc.questions ?? [];
  const allAnswered = questions.length > 0 && questions.every((_: unknown, i: number) => selectedAnswers[i] !== undefined);

  const handleSubmit = async () => {
    try {
      const result = await answer.mutateAsync({ id: pc.id, answers: selectedAnswers });
      setResults(result);
      toast.success('Press conference complete!');
    } catch (e: unknown) {
      toast.error(e instanceof Error ? e.message : 'Failed to submit');
    }
  };

  if (results) {
    return (
      <PageLayout className="mx-auto max-w-2xl">
        <PageHeader
          title="Press Conference Results"
          icon={Mic2}
          accentColor="var(--accent-red)"
          subtitle="Here's how the media reacted"
        />
        <motion.div initial={{ opacity: 0, y: 20 }} animate={{ opacity: 1, y: 0 }}>
          <div className="grid grid-cols-3 gap-4">
            <ResultStatCard label="Influence" value={results.influence_change} />
            <ResultStatCard label="Morale" value={results.morale_change} />
            <ResultStatCard label="Media" value={results.media_change} />
          </div>
        </motion.div>
      </PageLayout>
    );
  }

  return (
    <PageLayout className="mx-auto max-w-2xl">
      <PageHeader
        title="Press Conference"
        icon={Mic2}
        accentColor="var(--accent-red)"
        subtitle={`Week ${pc.week} — ${pc.type === 'post_game' ? 'Post-Game' : 'Weekly'} Press Conference`}
      />

      <div className="space-y-6">
        {questions.map((q: any, qi: number) => (
          <Section key={qi} title={`Question ${qi + 1}`} delay={qi * 0.08}>
            <div className="rounded-lg border border-[var(--border)] bg-[var(--bg-surface)] overflow-hidden">
              <div className="px-5 py-4 border-b border-[var(--border)]">
                <p className="text-sm italic text-[var(--text-secondary)]">"{q.question}"</p>
                {q.topic && (
                  <Badge variant="outline" className="mt-2 w-fit text-[10px]">{q.topic}</Badge>
                )}
              </div>
              <div className="p-4 space-y-2">
                {q.answers.map((a: any, ai: number) => (
                  <button
                    key={ai}
                    onClick={() => setSelectedAnswers((prev) => ({ ...prev, [qi]: ai }))}
                    className={`w-full rounded-md border p-3 text-left transition-all ${
                      selectedAnswers[qi] === ai
                        ? toneColors[a.tone] ?? 'border-[var(--accent-blue)] bg-[var(--accent-blue)]/10'
                        : 'border-[var(--border)] hover:border-[var(--text-muted)]'
                    }`}
                  >
                    <p className="text-sm">"{a.text}"</p>
                    <Badge variant="outline" className="mt-1 text-[10px]">{a.tone}</Badge>
                  </button>
                ))}
              </div>
            </div>
          </Section>
        ))}
      </div>

      <div className="mt-6">
        <ActionButton
          onClick={handleSubmit}
          disabled={!allAnswered || answer.isPending}
          fullWidth
          accentColor="var(--accent-red)"
        >
          {answer.isPending ? 'Submitting...' : 'Submit Responses'}
        </ActionButton>
      </div>
    </PageLayout>
  );
}

function ResultStatCard({ label, value }: { label: string; value: number }) {
  const trend = value > 0 ? 'up' as const : value < 0 ? 'down' as const : 'neutral' as const;
  const display = value > 0 ? `+${value}` : `${value}`;

  return (
    <StatCard
      label={label}
      value={display}
      trend={trend}
      accentColor={value > 0 ? '#22c55e' : value < 0 ? '#ef4444' : 'var(--accent-blue)'}
    />
  );
}
