import { useState } from 'react';
import { Card, CardContent } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Badge } from '@/components/ui/badge';
import { Tabs, TabsList, TabsTrigger, TabsContent } from '@/components/ui/tabs';
import { motion } from 'framer-motion';
import {
  Sparkles, Wand2, Zap, BarChart3, Key, Check, AlertCircle,
} from 'lucide-react';
import {
  BarChart, Bar, XAxis, YAxis, Tooltip, ResponsiveContainer, CartesianGrid,
} from 'recharts';
import {
  useAiStatus, useConfigureAi,
  useGenerateRecap, useGenerateFeature, useGenerateSocial,
} from '@/hooks/useApi';
import { PageLayout, PageHeader } from '@/components/ui/sports-ui';

// --- AI Config Section ---

function AiConfigCard({
  configured,
  onSave,
  isSaving,
}: {
  configured: boolean;
  onSave: (key: string) => void;
  isSaving: boolean;
}) {
  const [apiKey, setApiKey] = useState('');

  return (
    <Card className="border-[var(--border)] bg-[var(--bg-surface)]">
      <CardContent className="p-5">
        <div className="flex items-center justify-between mb-4">
          <div className="flex items-center gap-2">
            <Key className="h-4 w-4 text-[var(--accent-gold)]" />
            <h3 className="font-display text-sm uppercase tracking-wider text-[var(--text-muted)]">
              AI Configuration
            </h3>
          </div>
          <Badge
            className={
              configured
                ? 'border-green-500/30 bg-green-500/10 text-green-400'
                : 'border-red-500/30 bg-red-500/10 text-red-400'
            }
          >
            {configured ? (
              <><Check className="mr-1 h-3 w-3" /> Configured</>
            ) : (
              <><AlertCircle className="mr-1 h-3 w-3" /> Not Configured</>
            )}
          </Badge>
        </div>

        {!configured && (
          <p className="mb-4 text-sm text-[var(--text-secondary)]">
            Enter your OpenAI API key to enable AI-powered content generation.
          </p>
        )}

        <div className="flex gap-2">
          <Input
            type="password"
            placeholder={configured ? 'Enter new API key to update...' : 'sk-...'}
            value={apiKey}
            onChange={(e) => setApiKey(e.target.value)}
            className="flex-1"
          />
          <Button
            onClick={() => {
              if (apiKey.trim()) {
                onSave(apiKey.trim());
                setApiKey('');
              }
            }}
            disabled={!apiKey.trim() || isSaving}
          >
            {isSaving ? (
              <span className="flex items-center gap-1.5">
                <Sparkles className="h-3.5 w-3.5 animate-spin" /> Saving...
              </span>
            ) : (
              'Save Key'
            )}
          </Button>
        </div>
      </CardContent>
    </Card>
  );
}

// --- Usage Stats ---

function UsageChart({
  usage,
}: {
  usage: Array<{ type: string; count: number; total_prompt: number; total_completion: number }>;
}) {
  if (!usage || usage.length === 0) {
    return (
      <div className="flex flex-col items-center justify-center py-12 text-center">
        <BarChart3 className="h-8 w-8 text-[var(--text-muted)] mb-2" />
        <p className="text-sm text-[var(--text-secondary)]">No usage data yet.</p>
        <p className="text-xs text-[var(--text-muted)] mt-1">
          Generate some content to see usage statistics here.
        </p>
      </div>
    );
  }

  const chartData = usage.map((u) => ({
    name: u.type.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase()),
    Requests: u.count,
    'Prompt Tokens': u.total_prompt,
    'Completion Tokens': u.total_completion,
  }));

  return (
    <div className="h-64">
      <ResponsiveContainer width="100%" height="100%">
        <BarChart data={chartData} barGap={4}>
          <CartesianGrid strokeDasharray="3 3" stroke="rgba(48,54,61,0.5)" />
          <XAxis dataKey="name" tick={{ fill: '#8B949E', fontSize: 12 }} />
          <YAxis tick={{ fill: '#8B949E', fontSize: 12 }} />
          <Tooltip
            contentStyle={{
              backgroundColor: 'var(--bg-surface)',
              border: '1px solid var(--border)',
              borderRadius: 8,
              color: 'var(--text-primary)',
            }}
          />
          <Bar dataKey="Requests" fill="#2188FF" radius={[4, 4, 0, 0]} />
          <Bar dataKey="Prompt Tokens" fill="#22C55E" radius={[4, 4, 0, 0]} />
          <Bar dataKey="Completion Tokens" fill="#D4A017" radius={[4, 4, 0, 0]} />
        </BarChart>
      </ResponsiveContainer>
    </div>
  );
}

// --- Content Preview ---

function ContentPreview({
  content,
  isLoading,
  label,
}: {
  content: unknown;
  isLoading: boolean;
  label: string;
}) {
  if (isLoading) {
    return (
      <motion.div
        initial={{ opacity: 0 }}
        animate={{ opacity: 1 }}
        className="flex items-center gap-3 rounded-lg border border-[var(--border)] bg-[var(--bg-primary)] p-6"
      >
        <Sparkles className="h-5 w-5 animate-spin text-[var(--accent-blue)]" />
        <div>
          <p className="text-sm font-medium">Generating {label}...</p>
          <p className="text-xs text-[var(--text-muted)]">This may take a moment.</p>
        </div>
      </motion.div>
    );
  }

  if (!content) return null;

  const text =
    typeof content === 'string'
      ? content
      : typeof content === 'object' && content !== null
        ? JSON.stringify(content, null, 2)
        : String(content);

  return (
    <motion.div
      initial={{ opacity: 0, y: 10 }}
      animate={{ opacity: 1, y: 0 }}
      transition={{ duration: 0.3 }}
      className="rounded-lg border border-[var(--border)] bg-[var(--bg-primary)] p-4"
    >
      <div className="flex items-center gap-2 mb-3">
        <Check className="h-4 w-4 text-green-400" />
        <span className="text-xs font-medium uppercase tracking-wider text-green-400">
          Generated
        </span>
      </div>
      <pre className="whitespace-pre-wrap text-sm leading-relaxed text-[var(--text-secondary)] font-sans">
        {text}
      </pre>
    </motion.div>
  );
}

// --- Tab: Game Recaps ---

function RecapsTab() {
  const [gameId, setGameId] = useState('');
  const recap = useGenerateRecap();

  return (
    <div className="space-y-4">
      <p className="text-sm text-[var(--text-secondary)]">
        Generate an AI-written game recap article from a completed game.
      </p>
      <div className="flex gap-2">
        <Input
          type="number"
          placeholder="Game ID"
          value={gameId}
          onChange={(e) => setGameId(e.target.value)}
          className="w-40"
        />
        <Button
          onClick={() => {
            const id = parseInt(gameId, 10);
            if (!isNaN(id)) recap.mutate(id);
          }}
          disabled={!gameId || recap.isPending}
        >
          {recap.isPending ? (
            <span className="flex items-center gap-1.5">
              <Sparkles className="h-3.5 w-3.5 animate-spin" /> Generating...
            </span>
          ) : (
            <span className="flex items-center gap-1.5">
              <Wand2 className="h-3.5 w-3.5" /> Generate Recap
            </span>
          )}
        </Button>
      </div>

      {recap.isError && (
        <div className="flex items-center gap-2 rounded-lg border border-red-500/30 bg-red-500/10 p-3 text-sm text-red-400">
          <AlertCircle className="h-4 w-4 shrink-0" />
          {recap.error?.message || 'Failed to generate recap.'}
        </div>
      )}

      <ContentPreview
        content={recap.data}
        isLoading={recap.isPending}
        label="game recap"
      />
    </div>
  );
}

// --- Tab: Features ---

function FeaturesTab() {
  const [topic, setTopic] = useState('');
  const feature = useGenerateFeature();

  return (
    <div className="space-y-4">
      <p className="text-sm text-[var(--text-secondary)]">
        Generate an AI-written feature article on any football topic.
      </p>
      <div className="flex gap-2">
        <Input
          placeholder="Enter topic (e.g., rookie quarterback analysis)"
          value={topic}
          onChange={(e) => setTopic(e.target.value)}
          className="flex-1"
        />
        <Button
          onClick={() => {
            if (topic.trim()) feature.mutate({ topic: topic.trim() });
          }}
          disabled={!topic.trim() || feature.isPending}
        >
          {feature.isPending ? (
            <span className="flex items-center gap-1.5">
              <Sparkles className="h-3.5 w-3.5 animate-spin" /> Generating...
            </span>
          ) : (
            <span className="flex items-center gap-1.5">
              <Wand2 className="h-3.5 w-3.5" /> Generate Feature
            </span>
          )}
        </Button>
      </div>

      {feature.isError && (
        <div className="flex items-center gap-2 rounded-lg border border-red-500/30 bg-red-500/10 p-3 text-sm text-red-400">
          <AlertCircle className="h-4 w-4 shrink-0" />
          {feature.error?.message || 'Failed to generate feature.'}
        </div>
      )}

      <ContentPreview
        content={feature.data}
        isLoading={feature.isPending}
        label="feature article"
      />
    </div>
  );
}

// --- Tab: Social Posts ---

function SocialTab() {
  const [week, setWeek] = useState('');
  const social = useGenerateSocial();

  return (
    <div className="space-y-4">
      <p className="text-sm text-[var(--text-secondary)]">
        Generate AI-written social media posts for a given week.
      </p>
      <div className="flex gap-2">
        <Input
          type="number"
          placeholder="Week number"
          value={week}
          onChange={(e) => setWeek(e.target.value)}
          className="w-40"
        />
        <Button
          onClick={() => {
            const w = parseInt(week, 10);
            if (!isNaN(w)) social.mutate({ week: w });
          }}
          disabled={!week || social.isPending}
        >
          {social.isPending ? (
            <span className="flex items-center gap-1.5">
              <Sparkles className="h-3.5 w-3.5 animate-spin" /> Generating...
            </span>
          ) : (
            <span className="flex items-center gap-1.5">
              <Zap className="h-3.5 w-3.5" /> Generate Posts
            </span>
          )}
        </Button>
      </div>

      {social.isError && (
        <div className="flex items-center gap-2 rounded-lg border border-red-500/30 bg-red-500/10 p-3 text-sm text-red-400">
          <AlertCircle className="h-4 w-4 shrink-0" />
          {social.error?.message || 'Failed to generate social posts.'}
        </div>
      )}

      <ContentPreview
        content={social.data}
        isLoading={social.isPending}
        label="social posts"
      />
    </div>
  );
}

// --- Main Page ---

export default function AiStudio() {
  const { data: status, isLoading } = useAiStatus();
  const configureMut = useConfigureAi();

  const configured = status?.configured ?? false;
  const usage = status?.usage ?? [];

  if (isLoading) {
    return (
      <div className="flex h-64 items-center justify-center">
        <div className="text-center">
          <Sparkles className="mx-auto h-8 w-8 animate-pulse text-[var(--accent-blue)]" />
          <p className="mt-2 text-sm text-[var(--text-secondary)]">Loading AI Studio...</p>
        </div>
      </div>
    );
  }

  return (
    <PageLayout>
      <PageHeader
        title="AI Studio"
        subtitle="Generate AI-powered content for your league"
        icon={Sparkles}
        accentColor="var(--accent-gold)"
      />

      {/* Config Card */}
      <motion.div
        initial={{ opacity: 0, scale: 0.95 }}
        animate={{ opacity: 1, scale: 1 }}
        transition={{ duration: 0.4, delay: 0.1 }}
      >
        <AiConfigCard
          configured={configured}
          onSave={(key) => configureMut.mutate(key)}
          isSaving={configureMut.isPending}
        />
      </motion.div>

      {/* Usage Stats */}
      <motion.div
        initial={{ opacity: 0, y: 10 }}
        animate={{ opacity: 1, y: 0 }}
        transition={{ duration: 0.4, delay: 0.2 }}
      >
        <Card className="border-[var(--border)] bg-[var(--bg-surface)]">
          <CardContent className="p-5">
            <div className="flex items-center gap-2 mb-4">
              <BarChart3 className="h-4 w-4 text-[var(--accent-blue)]" />
              <h3 className="font-display text-sm uppercase tracking-wider text-[var(--text-muted)]">
                Usage Statistics
              </h3>
            </div>
            <UsageChart usage={usage} />
          </CardContent>
        </Card>
      </motion.div>

      {/* Content Generation */}
      <motion.div
        initial={{ opacity: 0, y: 10 }}
        animate={{ opacity: 1, y: 0 }}
        transition={{ duration: 0.4, delay: 0.3 }}
      >
        <Card className="border-[var(--border)] bg-[var(--bg-surface)]">
          <CardContent className="p-5">
            <div className="flex items-center gap-2 mb-4">
              <Wand2 className="h-4 w-4 text-[var(--accent-gold)]" />
              <h3 className="font-display text-sm uppercase tracking-wider text-[var(--text-muted)]">
                Content Generation
              </h3>
            </div>

            {!configured ? (
              <div className="flex flex-col items-center justify-center py-8 text-center">
                <Key className="h-8 w-8 text-[var(--text-muted)] mb-2" />
                <p className="text-sm text-[var(--text-secondary)]">
                  Configure your API key above to start generating content.
                </p>
              </div>
            ) : (
              <Tabs defaultValue="recaps">
                <TabsList>
                  <TabsTrigger value="recaps">
                    <Wand2 className="mr-1.5 h-3.5 w-3.5" />
                    Game Recaps
                  </TabsTrigger>
                  <TabsTrigger value="features">
                    <Sparkles className="mr-1.5 h-3.5 w-3.5" />
                    Features
                  </TabsTrigger>
                  <TabsTrigger value="social">
                    <Zap className="mr-1.5 h-3.5 w-3.5" />
                    Social Posts
                  </TabsTrigger>
                </TabsList>

                <TabsContent value="recaps" className="mt-4">
                  <RecapsTab />
                </TabsContent>
                <TabsContent value="features" className="mt-4">
                  <FeaturesTab />
                </TabsContent>
                <TabsContent value="social" className="mt-4">
                  <SocialTab />
                </TabsContent>
              </Tabs>
            )}
          </CardContent>
        </Card>
      </motion.div>
    </PageLayout>
  );
}
