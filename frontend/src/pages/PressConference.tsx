import { useState } from 'react';
import { usePressConference, useAnswerPressConference } from '@/hooks/useApi';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { toast } from 'sonner';
import { Mic2, TrendingUp, TrendingDown, Minus } from 'lucide-react';
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
  if (error || !pc) return <p className="text-[var(--text-secondary)]">No press conference available right now.</p>;

  const questions = pc.questions ?? [];
  const allAnswered = questions.length > 0 && questions.every((_, i) => selectedAnswers[i] !== undefined);

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
      <div className="mx-auto max-w-2xl space-y-6">
        <h1 className="font-display text-2xl">Press Conference Results</h1>
        <motion.div initial={{ opacity: 0, y: 20 }} animate={{ opacity: 1, y: 0 }}>
          <Card className="border-[var(--border)] bg-[var(--bg-surface)]">
            <CardContent className="flex items-center justify-around p-8">
              <ResultStat label="Influence" value={results.influence_change} />
              <ResultStat label="Morale" value={results.morale_change} />
              <ResultStat label="Media" value={results.media_change} />
            </CardContent>
          </Card>
        </motion.div>
      </div>
    );
  }

  return (
    <div className="mx-auto max-w-2xl space-y-6">
      <div className="flex items-center gap-3">
        <Mic2 className="h-6 w-6 text-[var(--accent-red)]" />
        <div>
          <h1 className="font-display text-2xl">Press Conference</h1>
          <p className="text-sm text-[var(--text-secondary)]">
            Week {pc.week} — {pc.type === 'post_game' ? 'Post-Game' : 'Weekly'} Press Conference
          </p>
        </div>
      </div>

      <div className="space-y-6">
        {questions.map((q, qi) => (
          <Card key={qi} className="border-[var(--border)] bg-[var(--bg-surface)]">
            <CardHeader>
              <CardTitle className="text-sm font-normal italic text-[var(--text-secondary)]">
                "{q.question}"
              </CardTitle>
              {q.topic && <Badge variant="outline" className="w-fit text-[10px]">{q.topic}</Badge>}
            </CardHeader>
            <CardContent className="space-y-2">
              {q.answers.map((a, ai) => (
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
            </CardContent>
          </Card>
        ))}
      </div>

      <Button onClick={handleSubmit} disabled={!allAnswered || answer.isPending} className="w-full">
        {answer.isPending ? 'Submitting...' : 'Submit Responses'}
      </Button>
    </div>
  );
}

function ResultStat({ label, value }: { label: string; value: number }) {
  const Icon = value > 0 ? TrendingUp : value < 0 ? TrendingDown : Minus;
  const color = value > 0 ? 'text-green-400' : value < 0 ? 'text-red-400' : 'text-[var(--text-muted)]';

  return (
    <div className="text-center">
      <Icon className={`mx-auto h-6 w-6 ${color}`} />
      <p className={`font-display text-2xl ${color}`}>{value > 0 ? '+' : ''}{value}</p>
      <p className="text-xs text-[var(--text-muted)]">{label}</p>
    </div>
  );
}
