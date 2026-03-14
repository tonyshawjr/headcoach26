import { useState } from 'react';
import { useLogin } from '@/hooks/useApi';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Button } from '@/components/ui/button';
import { Zap } from 'lucide-react';

export default function Login() {
  const [username, setUsername] = useState('');
  const [password, setPassword] = useState('');
  const login = useLogin();

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    login.mutate({ username, password });
  };

  return (
    <div className="flex min-h-screen items-center justify-center bg-[var(--bg-primary)]">
      {/* Background gradient accent */}
      <div className="pointer-events-none fixed inset-0 bg-[radial-gradient(ellipse_at_top,_var(--accent-blue)_0%,_transparent_50%)] opacity-[0.04]" />

      <Card className="relative w-full max-w-sm overflow-hidden border-[var(--border)] bg-[var(--bg-surface)]">
        {/* Top accent bar */}
        <div className="h-[2px] w-full bg-gradient-to-r from-[var(--accent-blue)] via-[var(--accent-red)] to-[var(--accent-gold)]" />

        <CardHeader className="pt-8 text-center">
          {/* Brand mark */}
          <div className="mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-lg bg-[var(--accent-blue)] shadow-lg shadow-[var(--accent-blue)]/20">
            <Zap className="h-7 w-7 text-white" />
          </div>
          <CardTitle className="font-display text-3xl tracking-tight">HEAD COACH</CardTitle>
          <p className="text-lg font-display tracking-widest text-[var(--accent-blue)]">26</p>
          <p className="mt-1 text-xs text-[var(--text-muted)]">Sign in to your account</p>
        </CardHeader>

        <CardContent className="pb-8">
          <form onSubmit={handleSubmit} className="space-y-4">
            <div className="space-y-1.5">
              <Label htmlFor="username" className="text-xs font-semibold uppercase tracking-wider text-[var(--text-muted)]">
                Username
              </Label>
              <Input
                id="username"
                value={username}
                onChange={(e) => setUsername(e.target.value)}
                placeholder="admin"
                className="border-[var(--border)] bg-[var(--bg-primary)] focus-visible:ring-[var(--accent-blue)]"
              />
            </div>
            <div className="space-y-1.5">
              <Label htmlFor="password" className="text-xs font-semibold uppercase tracking-wider text-[var(--text-muted)]">
                Password
              </Label>
              <Input
                id="password"
                type="password"
                value={password}
                onChange={(e) => setPassword(e.target.value)}
                className="border-[var(--border)] bg-[var(--bg-primary)] focus-visible:ring-[var(--accent-blue)]"
              />
            </div>
            {login.isError && (
              <p className="text-xs font-medium text-[var(--accent-red)]">{login.error.message}</p>
            )}
            <Button
              type="submit"
              className="w-full bg-[var(--accent-blue)] font-semibold uppercase tracking-wider text-white hover:bg-[var(--accent-blue)]/90"
              disabled={login.isPending}
            >
              {login.isPending ? 'Signing in...' : 'Sign In'}
            </Button>
          </form>
        </CardContent>
      </Card>
    </div>
  );
}
