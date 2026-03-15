import { useState } from 'react';
import { useNavigate, Link } from 'react-router-dom';
import { useQueryClient } from '@tanstack/react-query';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Button } from '@/components/ui/button';
import { Zap } from 'lucide-react';
import { motion } from 'framer-motion';
import { toast } from 'sonner';
import { api } from '@/api/client';

export default function Register() {
  const [username, setUsername] = useState('');
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [confirmPassword, setConfirmPassword] = useState('');
  const [inviteCode, setInviteCode] = useState('');
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [error, setError] = useState('');
  const navigate = useNavigate();
  const qc = useQueryClient();

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setError('');

    if (password !== confirmPassword) {
      setError('Passwords do not match');
      return;
    }

    if (password.length < 6) {
      setError('Password must be at least 6 characters');
      return;
    }

    setIsSubmitting(true);
    try {
      await api.post('/auth/register', {
        username,
        email,
        password,
        ...(inviteCode.trim() ? { invite_code: inviteCode.trim() } : {}),
      });

      // Auto-login after successful registration
      await api.post('/auth/login', { username, password });
      await qc.invalidateQueries({ queryKey: ['session'] });

      toast.success('Account created successfully!');
      navigate('/');
    } catch (err: unknown) {
      const message = err instanceof Error ? err.message : 'Registration failed';
      setError(message);
      toast.error(message);
    } finally {
      setIsSubmitting(false);
    }
  };

  return (
    <div className="flex min-h-screen items-center justify-center bg-[var(--bg-primary)]">
      {/* Background gradient accent */}
      <div className="pointer-events-none fixed inset-0 bg-[radial-gradient(ellipse_at_top,_var(--accent-blue)_0%,_transparent_50%)] opacity-[0.04]" />

      <motion.div
        initial={{ opacity: 0, y: 16 }}
        animate={{ opacity: 1, y: 0 }}
        transition={{ duration: 0.4 }}
        className="w-full max-w-sm"
      >
        <Card className="relative overflow-hidden border-[var(--border)] bg-[var(--bg-surface)]">
          {/* Top accent bar */}
          <div className="h-[2px] w-full bg-gradient-to-r from-[var(--accent-blue)] via-[var(--accent-red)] to-[var(--accent-gold)]" />

          <CardHeader className="pt-8 text-center">
            {/* Brand mark */}
            <div className="mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-lg bg-[var(--accent-blue)] shadow-lg shadow-[var(--accent-blue)]/20">
              <Zap className="h-7 w-7 text-white" />
            </div>
            <CardTitle className="font-display text-3xl tracking-tight">HEAD COACH</CardTitle>
            <p className="text-lg font-display tracking-widest text-[var(--accent-blue)]">26</p>
            <p className="mt-1 text-xs text-[var(--text-muted)]">Create your account</p>
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
                  placeholder="coach_name"
                  required
                  className="border-[var(--border)] bg-[var(--bg-primary)] focus-visible:ring-[var(--accent-blue)]"
                />
              </div>
              <div className="space-y-1.5">
                <Label htmlFor="email" className="text-xs font-semibold uppercase tracking-wider text-[var(--text-muted)]">
                  Email
                </Label>
                <Input
                  id="email"
                  type="email"
                  value={email}
                  onChange={(e) => setEmail(e.target.value)}
                  placeholder="coach@example.com"
                  required
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
                  placeholder="Min. 6 characters"
                  required
                  className="border-[var(--border)] bg-[var(--bg-primary)] focus-visible:ring-[var(--accent-blue)]"
                />
              </div>
              <div className="space-y-1.5">
                <Label htmlFor="confirmPassword" className="text-xs font-semibold uppercase tracking-wider text-[var(--text-muted)]">
                  Confirm Password
                </Label>
                <Input
                  id="confirmPassword"
                  type="password"
                  value={confirmPassword}
                  onChange={(e) => setConfirmPassword(e.target.value)}
                  required
                  className="border-[var(--border)] bg-[var(--bg-primary)] focus-visible:ring-[var(--accent-blue)]"
                />
              </div>
              <div className="space-y-1.5">
                <Label htmlFor="inviteCode" className="text-xs font-semibold uppercase tracking-wider text-[var(--text-muted)]">
                  Invite Code <span className="normal-case tracking-normal font-normal">(optional)</span>
                </Label>
                <Input
                  id="inviteCode"
                  value={inviteCode}
                  onChange={(e) => setInviteCode(e.target.value)}
                  placeholder="Enter code to join a league"
                  className="border-[var(--border)] bg-[var(--bg-primary)] focus-visible:ring-[var(--accent-blue)]"
                />
              </div>
              {error && (
                <motion.p
                  initial={{ opacity: 0, y: -4 }}
                  animate={{ opacity: 1, y: 0 }}
                  className="text-xs font-medium text-[var(--accent-red)]"
                >
                  {error}
                </motion.p>
              )}
              <Button
                type="submit"
                className="w-full bg-[var(--accent-blue)] font-semibold uppercase tracking-wider text-white hover:bg-[var(--accent-blue)]/90"
                disabled={isSubmitting}
              >
                {isSubmitting ? 'Creating account...' : 'Create Account'}
              </Button>
            </form>

            <p className="mt-4 text-center text-xs text-[var(--text-muted)]">
              Already have an account?{' '}
              <Link to="/login" className="text-[var(--accent-blue)] hover:underline">
                Sign in
              </Link>
            </p>
          </CardContent>
        </Card>
      </motion.div>
    </div>
  );
}
