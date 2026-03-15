import { useState, useEffect, useRef } from 'react';
import { useAuthStore } from '@/stores/authStore';
import { useMessages, usePostMessage } from '@/hooks/useApi';
import { Card, CardContent } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { motion, AnimatePresence } from 'framer-motion';
import { MessageSquare, Send, Pin } from 'lucide-react';
import { PageHeader } from '@/components/ui/sports-ui';

interface Message {
  id: number;
  body: string;
  channel: string;
  user_id: number;
  username: string;
  team_emoji?: string;
  team_name?: string;
  is_pinned: boolean;
  created_at: string;
}

const CHANNELS = [
  { value: 'general', label: 'General' },
  { value: 'trades', label: 'Trades' },
  { value: 'trash_talk', label: 'Trash Talk' },
  { value: 'announcements', label: 'Announcements' },
];

function formatTime(dateStr: string) {
  const d = new Date(dateStr);
  const now = new Date();
  const diffMs = now.getTime() - d.getTime();
  const diffMins = Math.floor(diffMs / 60000);

  if (diffMins < 1) return 'just now';
  if (diffMins < 60) return `${diffMins}m ago`;

  const diffHours = Math.floor(diffMins / 60);
  if (diffHours < 24) return `${diffHours}h ago`;

  return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' });
}

export default function MessageBoard() {
  const user = useAuthStore((s) => s.user);
  const [channel, setChannel] = useState('general');
  const [input, setInput] = useState('');
  const { data: messages, isLoading } = useMessages(channel);
  const postMut = usePostMessage();
  const messagesEndRef = useRef<HTMLDivElement>(null);
  const containerRef = useRef<HTMLDivElement>(null);

  const messageList = (messages as Message[] | undefined) ?? [];

  // Auto-scroll to bottom when new messages arrive
  useEffect(() => {
    if (messagesEndRef.current) {
      messagesEndRef.current.scrollIntoView({ behavior: 'smooth' });
    }
  }, [messageList.length]);

  function handleSend() {
    const trimmed = input.trim();
    if (!trimmed) return;
    postMut.mutate({ body: trimmed, channel }, {
      onSuccess: () => setInput(''),
    });
  }

  function handleKeyDown(e: React.KeyboardEvent) {
    if (e.key === 'Enter' && !e.shiftKey) {
      e.preventDefault();
      handleSend();
    }
  }

  return (
    <div className="flex h-[calc(100vh-7rem)] flex-col space-y-4">
      <PageHeader
        title="Message Board"
        subtitle="Chat with your league"
        icon={MessageSquare}
        accentColor="var(--accent-blue)"
      />

      {/* Channel Tabs */}
      <Tabs value={channel} onValueChange={setChannel}>
        <TabsList>
          {CHANNELS.map((ch) => (
            <TabsTrigger key={ch.value} value={ch.value}>
              {ch.label}
            </TabsTrigger>
          ))}
        </TabsList>
      </Tabs>

      {/* Messages */}
      <Card className="flex-1 overflow-hidden border-[var(--border)] bg-[var(--bg-surface)]">
        <CardContent className="flex h-full flex-col p-0">
          <div ref={containerRef} className="flex-1 overflow-y-auto p-4 space-y-3">
            {isLoading ? (
              <div className="flex h-full items-center justify-center">
                <MessageSquare className="h-8 w-8 animate-pulse text-[var(--accent-blue)]" />
              </div>
            ) : messageList.length === 0 ? (
              <div className="flex h-full flex-col items-center justify-center text-center">
                <MessageSquare className="h-10 w-10 text-[var(--text-muted)] mb-2" />
                <p className="text-sm text-[var(--text-secondary)]">No messages yet</p>
                <p className="text-xs text-[var(--text-muted)] mt-1">Be the first to say something!</p>
              </div>
            ) : (
              <AnimatePresence initial={false}>
                {messageList.map((msg) => {
                  const isMe = msg.user_id === user?.id;
                  return (
                    <motion.div
                      key={msg.id}
                      initial={{ opacity: 0, y: 8 }}
                      animate={{ opacity: 1, y: 0 }}
                      exit={{ opacity: 0 }}
                      transition={{ duration: 0.2 }}
                      className={`flex gap-3 ${isMe ? 'flex-row-reverse' : ''}`}
                    >
                      {/* Avatar */}
                      <div className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-[var(--bg-elevated)] text-sm">
                        {msg.team_emoji || msg.username.charAt(0).toUpperCase()}
                      </div>

                      {/* Bubble */}
                      <div className={`max-w-[70%] ${isMe ? 'text-right' : ''}`}>
                        <div className="flex items-center gap-2 mb-0.5">
                          {!isMe && (
                            <span className="text-xs font-semibold text-[var(--text-primary)]">
                              {msg.username}
                            </span>
                          )}
                          {msg.team_name && !isMe && (
                            <Badge variant="outline" className="text-[9px] py-0">
                              {msg.team_name}
                            </Badge>
                          )}
                          {msg.is_pinned && (
                            <Pin className="h-3 w-3 text-yellow-400" />
                          )}
                          <span className="text-[10px] text-[var(--text-muted)]">
                            {formatTime(msg.created_at)}
                          </span>
                        </div>
                        <div
                          className={`inline-block rounded-lg px-3 py-2 text-sm ${
                            isMe
                              ? 'bg-[var(--accent-blue)] text-white'
                              : 'bg-[var(--bg-elevated)] text-[var(--text-primary)]'
                          }`}
                        >
                          {msg.body}
                        </div>
                      </div>
                    </motion.div>
                  );
                })}
              </AnimatePresence>
            )}
            <div ref={messagesEndRef} />
          </div>

          {/* Input */}
          <div className="border-t border-[var(--border)] p-3">
            <div className="flex items-center gap-2">
              <input
                value={input}
                onChange={(e) => setInput(e.target.value)}
                onKeyDown={handleKeyDown}
                placeholder={`Message #${channel}...`}
                className="flex-1 rounded-lg border border-[var(--border)] bg-[var(--bg-primary)] px-3 py-2 text-sm text-[var(--text-primary)] placeholder:text-[var(--text-muted)] focus:border-[var(--accent-blue)] focus:outline-none"
              />
              <Button
                size="sm"
                onClick={handleSend}
                disabled={!input.trim() || postMut.isPending}
              >
                <Send className="h-4 w-4" />
              </Button>
            </div>
          </div>
        </CardContent>
      </Card>
    </div>
  );
}
