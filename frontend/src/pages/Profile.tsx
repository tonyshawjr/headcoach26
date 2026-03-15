import { useState, useRef } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { profileApi, type ProfileData, type ProfileUpdate } from '@/api/client';
import { useAuthStore } from '@/stores/authStore';
import { ImageCropper } from '@/components/ImageCropper';
import {
  User, Pencil, Save, X, Camera, Upload,
  Briefcase, Lock,
} from 'lucide-react';
import { toast } from 'sonner';

const AVATAR_COLORS = [
  '#EF4444', '#F97316', '#EAB308', '#22C55E', '#14B8A6',
  '#3B82F6', '#6366F1', '#A855F7', '#EC4899', '#F43F5E',
  '#0EA5E9', '#10B981', '#8B5CF6', '#D946EF', '#F59E0B',
  '#64748B', '#1E293B', '#0F172A',
];

export default function Profile() {
  const queryClient = useQueryClient();
  const [editing, setEditing] = useState(false);

  const { data: profile, isLoading } = useQuery({
    queryKey: ['profile'],
    queryFn: () => profileApi.get(),
  });

  const updateMutation = useMutation({
    mutationFn: (data: ProfileUpdate) => profileApi.update(data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['profile'] });
      queryClient.invalidateQueries({ queryKey: ['session'] });
      toast.success('Profile updated');
      setEditing(false);
    },
    onError: (err: Error) => toast.error(err.message),
  });

  if (isLoading) {
    return <div className="flex h-96 items-center justify-center"><p className="text-[var(--text-secondary)]">Loading profile...</p></div>;
  }

  if (!profile) {
    return <p className="text-[var(--text-secondary)]">Unable to load profile.</p>;
  }

  if (editing) {
    return (
      <EditProfile
        profile={profile}
        onSave={(data) => updateMutation.mutate(data)}
        onCancel={() => {
          queryClient.invalidateQueries({ queryKey: ['profile'] });
          queryClient.invalidateQueries({ queryKey: ['session'] });
          setEditing(false);
        }}
        isSaving={updateMutation.isPending}
      />
    );
  }

  return <ViewProfile profile={profile} onEdit={() => setEditing(true)} />;
}

// ── View Mode ──────────────────────────────────────────────────────────

function ViewProfile({ profile, onEdit }: { profile: ProfileData; onEdit: () => void }) {
  const [changingPassword, setChangingPassword] = useState(false);
  const team = useAuthStore((s) => s.team);
  const coach = useAuthStore((s) => s.coach);

  const passwordMutation = useMutation({
    mutationFn: (data: ProfileUpdate) => profileApi.update(data),
    onSuccess: () => {
      toast.success('Password updated');
      setChangingPassword(false);
    },
    onError: (err: Error) => toast.error(err.message),
  });

  const displayName = profile.display_name || profile.coach_name || profile.username;
  const teamColor = profile.team_primary_color || '#3B82F6';
  const teamColor2 = (profile as Record<string, unknown>).team_secondary_color as string || teamColor;
  const hasAvatar = profile.avatar_url && (
    profile.avatar_url.endsWith('.webp') || profile.avatar_url.endsWith('.jpg') ||
    profile.avatar_url.endsWith('.jpeg') || profile.avatar_url.endsWith('.png')
  );

  const firstName = displayName.split(' ')[0] || displayName;
  const lastName = displayName.split(' ').slice(1).join(' ') || '';

  return (
    <div className="space-y-6 -mt-6">
      {/* ESPN-style Profile Header */}
      <div className="-mx-4 sm:-mx-6" style={{ width: '100vw', marginLeft: 'calc(-50vw + 50%)' }}>
        <div className="h-1" style={{ backgroundColor: teamColor }} />

        <div className="bg-[var(--bg-surface)]">
          <div className="flex items-stretch" style={{ minHeight: '200px' }}>
            {/* Avatar area with team color + angled strips */}
            <div className="relative shrink-0 w-56 sm:w-72 hidden sm:block overflow-hidden">
              <div className="absolute inset-0" style={{ background: `linear-gradient(135deg, ${teamColor} 0%, ${teamColor2} 100%)` }} />

              <div className="absolute inset-0 flex items-center justify-center" style={{ zIndex: 5 }}>
                {hasAvatar ? (
                  <img src={profile.avatar_url!} alt={displayName} className="h-full w-full object-cover object-center" />
                ) : (
                  <svg viewBox="0 0 80 80" className="h-24 w-24 text-white opacity-25">
                    <circle cx="40" cy="28" r="14" fill="currentColor" />
                    <path d="M12 72c0-15.5 12.5-28 28-28s28 12.5 28 28" fill="currentColor" />
                  </svg>
                )}
              </div>

              <div className="absolute -top-2 -bottom-2 left-[15%] w-[6px]" style={{ background: 'rgba(255,255,255,0.15)', transform: 'skewX(-8deg)', zIndex: 8 }} />
              <div className="absolute -top-2 -bottom-2 right-[20px] w-[10px]" style={{ background: `${teamColor}66`, transform: 'skewX(-8deg)', boxShadow: '-2px 0 4px rgba(0,0,0,0.1)', zIndex: 10 }} />
              <div className="absolute -top-2 -bottom-2 right-[-8px] w-[35px]" style={{ background: 'var(--bg-surface)', transform: 'skewX(-8deg)', boxShadow: '-4px 0 10px rgba(0,0,0,0.15)', zIndex: 12 }} />
            </div>

            {/* Name + meta */}
            <div className="flex-1 min-w-0 px-8 py-8 flex flex-col justify-center">
              <div className="flex items-start gap-6">
                <div className="flex-1 min-w-0">
                  <h1 className="leading-none">
                    <span className="block text-xl sm:text-2xl font-normal text-[var(--text-secondary)]">{firstName}</span>
                    {lastName && <span className="block text-3xl sm:text-5xl font-black text-[var(--text-primary)] uppercase tracking-tight">{lastName}</span>}
                    {!lastName && <span className="block text-3xl sm:text-5xl font-black text-[var(--text-primary)] uppercase tracking-tight">{firstName}</span>}
                  </h1>

                  <div className="flex flex-wrap items-center gap-x-2 mt-3 text-sm text-[var(--text-secondary)]">
                    {profile.team_name && <span className="font-semibold" style={{ color: teamColor }}>{profile.team_name}</span>}
                    {profile.team_name && <span className="text-[var(--text-muted)]">&middot;</span>}
                    <span>Head Coach</span>
                    {profile.archetype && (
                      <>
                        <span className="text-[var(--text-muted)]">&middot;</span>
                        <span>{profile.archetype.replace(/_/g, ' ').replace(/\b\w/g, (c: string) => c.toUpperCase())}</span>
                      </>
                    )}
                  </div>

                  <button
                    onClick={onEdit}
                    className="mt-4 inline-flex items-center gap-2 rounded border border-[var(--border)] px-4 py-1.5 text-sm font-semibold text-[var(--text-primary)] hover:bg-[var(--bg-elevated)] transition-colors"
                  >
                    <Pencil className="h-3.5 w-3.5" /> Edit Profile
                  </button>
                </div>
              </div>
            </div>

            {/* Details column */}
            <div className="hidden lg:flex flex-col justify-center gap-3 px-8 py-6 border-l border-[var(--border)] min-w-[220px]">
              <DetailLine label="USERNAME" value={`@${profile.username}`} />
              <DetailLine label="EMAIL" value={profile.email} />
              <DetailLine label="JOINED" value={new Date(profile.created_at).toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' })} />
              {profile.is_admin && <DetailLine label="ROLE" value="Commissioner" />}
            </div>

            {/* Coach stats */}
            <div className="hidden xl:flex flex-col shrink-0 min-w-[260px]">
              <div className="px-4 py-1.5 text-center text-xs font-bold uppercase tracking-wider text-white" style={{ backgroundColor: teamColor }}>
                Coach Stats
              </div>
              <div className="flex-1 flex items-center justify-center gap-6 px-6 py-4">
                <div className="text-center">
                  <p className="text-2xl font-bold text-[var(--text-primary)]">{coach?.influence ?? '--'}</p>
                  <p className="text-[11px] text-[var(--text-muted)]">Influence</p>
                </div>
                <div className="text-center">
                  <p className="text-2xl font-bold text-[var(--text-primary)]">{coach?.job_security ?? '--'}</p>
                  <p className="text-[11px] text-[var(--text-muted)]">Job Security</p>
                </div>
                <div className="text-center">
                  <p className="text-2xl font-bold text-[var(--text-primary)]">{team?.overall_rating ?? '--'}</p>
                  <p className="text-[11px] text-[var(--text-muted)]">Team OVR</p>
                </div>
              </div>
            </div>
          </div>
        </div>

        {/* Dark info strip */}
        <div className="bg-[#222222] text-white">
          <div className="flex items-center justify-between px-8 py-3.5">
            <div className="flex items-center gap-6 text-sm text-white/70">
              {team && <span>Record: <span className="font-semibold text-white">{team.wins}-{team.losses}{team.ties ? `-${team.ties}` : ''}</span></span>}
              {team && <span>Morale: <span className="font-semibold text-white">{team.morale ?? '--'}</span></span>}
            </div>
            <div className="text-sm text-white/70">
              {team?.streak && <span>Streak: <span className="font-semibold text-white">{team.streak}</span></span>}
            </div>
          </div>
        </div>
      </div>

      {/* Content below */}
      <div className="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
        {profile.bio && (
          <div className="sm:col-span-2 lg:col-span-3 rounded-xl border border-[var(--border)] bg-[var(--bg-surface)] p-6">
            <h3 className="font-bold text-[var(--text-primary)] mb-2">About</h3>
            <p className="text-[var(--text-secondary)] leading-relaxed">{profile.bio}</p>
          </div>
        )}
        {profile.coaching_philosophy && (
          <div className="sm:col-span-2 lg:col-span-3 rounded-xl border border-[var(--border)] bg-[var(--bg-surface)] p-6">
            <h3 className="font-bold text-[var(--text-primary)] mb-2">Coaching Philosophy</h3>
            <p className="text-[var(--text-secondary)] leading-relaxed italic">"{profile.coaching_philosophy}"</p>
          </div>
        )}
        <div className="rounded-xl border border-[var(--border)] bg-[var(--bg-surface)] p-6">
          <h3 className="font-bold text-[var(--text-primary)] mb-3">Security</h3>
          <button
            onClick={() => setChangingPassword(true)}
            className="flex items-center gap-2 rounded-lg border border-[var(--border)] px-4 py-2 text-sm font-medium text-[var(--text-primary)] hover:bg-[var(--bg-elevated)] transition-colors w-full justify-center"
          >
            <Lock className="h-3.5 w-3.5" /> Change Password
          </button>
        </div>
      </div>

      {changingPassword && (
        <ChangePasswordModal
          onSave={(current, newPass) => passwordMutation.mutate({ current_password: current, new_password: newPass })}
          onCancel={() => setChangingPassword(false)}
          isSaving={passwordMutation.isPending}
        />
      )}
    </div>
  );
}

function DetailLine({ label, value }: { label: string; value: string }) {
  return (
    <div className="flex items-baseline gap-4">
      <span className="text-xs font-semibold uppercase tracking-wider text-[var(--text-muted)] w-24 shrink-0">{label}</span>
      <span className="text-sm font-medium text-[var(--text-primary)]">{value}</span>
    </div>
  );
}

// ── Edit Mode ──────────────────────────────────────────────────────────

function EditProfile({ profile, onSave, onCancel, isSaving }: { profile: ProfileData; onSave: (data: ProfileUpdate) => void; onCancel: () => void; isSaving: boolean }) {
  const queryClient = useQueryClient();
  const fileInputRef = useRef<HTMLInputElement>(null);
  const [form, setForm] = useState({
    display_name: profile.display_name || '',
    email: profile.email || '',
    bio: profile.bio || '',
    coach_name: profile.coach_name || '',
    coaching_philosophy: profile.coaching_philosophy || '',
  });
  const [avatarColor, setAvatarColor] = useState(profile.avatar_color || profile.team_primary_color || '#3B82F6');
  const [avatarPreview, setAvatarPreview] = useState<string | null>(profile.avatar_url || null);
  const [uploading, setUploading] = useState(false);
  const [cropFile, setCropFile] = useState<File | null>(null);

  const set = (key: string, value: string) => setForm((f) => ({ ...f, [key]: value }));

  const handleFileSelect = (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0];
    if (!file) return;
    // Show cropper instead of uploading immediately
    setCropFile(file);
    // Reset input so same file can be re-selected
    e.target.value = '';
  };

  const handleCroppedUpload = async (blob: Blob) => {
    setCropFile(null);
    setUploading(true);
    try {
      const file = new File([blob], 'avatar.jpg', { type: 'image/jpeg' });
      const result = await profileApi.uploadAvatar(file);
      setAvatarPreview(result.avatar_url);
      queryClient.invalidateQueries({ queryKey: ['profile'] });
      toast.success('Avatar uploaded');
    } catch (err: unknown) {
      toast.error(err instanceof Error ? err.message : 'Upload failed');
    } finally { setUploading(false); }
  };

  const handleSave = () => {
    const updates: ProfileUpdate = {};
    if (form.display_name !== (profile.display_name || '')) updates.display_name = form.display_name;
    if (form.email !== (profile.email || '')) updates.email = form.email;
    if (form.bio !== (profile.bio || '')) updates.bio = form.bio;
    if (form.coach_name !== (profile.coach_name || '')) updates.coach_name = form.coach_name;
    if (form.coaching_philosophy !== (profile.coaching_philosophy || '')) updates.coaching_philosophy = form.coaching_philosophy;
    if (avatarColor !== (profile.avatar_color || profile.team_primary_color || '#3B82F6')) updates.avatar_color = avatarColor;
    if (Object.keys(updates).length === 0) { onCancel(); return; }
    onSave(updates);
  };

  const displayName = form.display_name || form.coach_name || profile.username;
  const initials = displayName.split(' ').map((w: string) => w[0]).join('').toUpperCase().slice(0, 2);

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <h1 className="text-2xl font-bold">Edit Profile</h1>
        <div className="flex gap-3">
          <button onClick={onCancel} className="flex items-center gap-2 rounded-lg border border-[var(--border)] px-5 py-2.5 text-sm font-medium text-[var(--text-secondary)] hover:bg-[var(--bg-elevated)] transition-colors"><X className="h-3.5 w-3.5" /> Cancel</button>
          <button onClick={handleSave} disabled={isSaving} className="flex items-center gap-2 rounded-lg bg-[var(--accent-blue)] px-5 py-2.5 text-sm font-medium text-white hover:bg-[var(--accent-blue)]/90 disabled:opacity-50 transition-colors"><Save className="h-3.5 w-3.5" /> {isSaving ? 'Saving...' : 'Save Changes'}</button>
        </div>
      </div>

      <div className="grid gap-6 lg:grid-cols-3">
        <div className="lg:col-span-1">
          <div className="rounded-xl border border-[var(--border)] bg-[var(--bg-surface)] p-6 flex flex-col items-center gap-4">
            <h3 className="font-bold text-[var(--text-primary)] self-start"><Camera className="h-4 w-4 inline mr-2" />Avatar</h3>
            <div className="relative">
              {avatarPreview ? (
                <img src={avatarPreview} alt="Avatar" className="h-28 w-28 rounded-full object-cover border-4 border-[var(--bg-elevated)]" />
              ) : (
                <div className="flex h-28 w-28 items-center justify-center rounded-full text-3xl font-bold text-white border-4 border-[var(--bg-elevated)]" style={{ backgroundColor: avatarColor }}>{initials}</div>
              )}
              <button onClick={() => fileInputRef.current?.click()} disabled={uploading} className="absolute bottom-0 right-0 flex h-9 w-9 items-center justify-center rounded-full bg-[var(--accent-blue)] text-white shadow-lg hover:bg-[var(--accent-blue)]/90 transition-colors">
                {uploading ? <div className="h-4 w-4 animate-spin rounded-full border-2 border-white/30 border-t-white" /> : <Upload className="h-4 w-4" />}
              </button>
              <input ref={fileInputRef} type="file" accept="image/jpeg,image/png,image/gif,image/webp" onChange={handleFileSelect} className="hidden" />
            </div>
            <p className="text-xs text-[var(--text-secondary)] text-center">JPG, PNG, GIF, or WebP</p>
            <div className="w-full">
              <p className="mb-2 text-xs font-semibold uppercase tracking-wider text-[var(--text-secondary)] text-center">{avatarPreview ? 'Fallback Color' : 'Avatar Color'}</p>
              <div className="flex flex-wrap justify-center gap-2">
                {AVATAR_COLORS.map((color) => (
                  <button key={color} onClick={() => setAvatarColor(color)} className={`h-7 w-7 rounded-full transition-all hover:scale-110 ${avatarColor === color ? 'ring-2 ring-white ring-offset-2 ring-offset-[var(--bg-surface)] scale-110' : ''}`} style={{ backgroundColor: color }} />
                ))}
              </div>
            </div>
            {avatarPreview && <button onClick={() => { setAvatarPreview(null); profileApi.update({ avatar_url: '' }); }} className="text-xs text-red-400 hover:text-red-300 transition-colors">Remove photo</button>}
          </div>
        </div>

        {/* Crop modal */}
        {cropFile && (
          <ImageCropper
            file={cropFile}
            onCrop={handleCroppedUpload}
            onCancel={() => setCropFile(null)}
            outputSize={400}
          />
        )}

        <div className="lg:col-span-2 space-y-6">
          <div className="rounded-xl border border-[var(--border)] bg-[var(--bg-surface)] p-6 space-y-4">
            <h3 className="font-bold text-[var(--text-primary)]"><User className="h-4 w-4 inline mr-2" />Personal Info</h3>
            <div className="grid gap-4 sm:grid-cols-2">
              <Field label="Display Name"><input className="input-field" placeholder={profile.username} value={form.display_name} onChange={(e) => set('display_name', e.target.value)} /></Field>
              <Field label="Email"><input className="input-field" type="email" value={form.email} onChange={(e) => set('email', e.target.value)} /></Field>
            </div>
            <Field label="Bio"><textarea className="input-field min-h-[100px] resize-y" placeholder="Tell us about yourself..." value={form.bio} onChange={(e) => set('bio', e.target.value)} /></Field>
          </div>

          <div className="rounded-xl border border-[var(--border)] bg-[var(--bg-surface)] p-6 space-y-4">
            <h3 className="font-bold text-[var(--text-primary)]"><Briefcase className="h-4 w-4 inline mr-2" />Coach Details</h3>
            <Field label="Coach Name"><input className="input-field" placeholder="Coach name" value={form.coach_name} onChange={(e) => set('coach_name', e.target.value)} /></Field>
            <Field label="Coaching Philosophy"><textarea className="input-field min-h-[100px] resize-y" placeholder="What drives your approach to the game?" value={form.coaching_philosophy} onChange={(e) => set('coaching_philosophy', e.target.value)} /></Field>
          </div>
        </div>
      </div>
    </div>
  );
}

function Field({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <label className="block">
      <span className="mb-1.5 block text-xs font-semibold uppercase tracking-wider text-[var(--text-secondary)]">{label}</span>
      {children}
    </label>
  );
}

function ChangePasswordModal({ onSave, onCancel, isSaving }: { onSave: (current: string, newPass: string) => void; onCancel: () => void; isSaving: boolean }) {
  const [currentPassword, setCurrentPassword] = useState('');
  const [newPassword, setNewPassword] = useState('');
  const [confirmPassword, setConfirmPassword] = useState('');

  const handleSave = () => {
    if (newPassword.length < 6) { toast.error('Password must be at least 6 characters'); return; }
    if (newPassword !== confirmPassword) { toast.error('Passwords do not match'); return; }
    onSave(currentPassword, newPassword);
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-sm" onClick={onCancel}>
      <div className="w-full max-w-md rounded-2xl border border-[var(--border)] bg-[var(--bg-surface)] p-6 shadow-2xl" onClick={(e) => e.stopPropagation()}>
        <h2 className="flex items-center gap-2 text-lg font-bold mb-4"><Lock className="h-5 w-5 text-[var(--text-secondary)]" /> Change Password</h2>
        <div className="space-y-4">
          <Field label="Current Password"><input type="password" className="input-field" value={currentPassword} onChange={(e) => setCurrentPassword(e.target.value)} /></Field>
          <Field label="New Password"><input type="password" className="input-field" placeholder="Min 6 characters" value={newPassword} onChange={(e) => setNewPassword(e.target.value)} /></Field>
          <Field label="Confirm New Password"><input type="password" className="input-field" value={confirmPassword} onChange={(e) => setConfirmPassword(e.target.value)} /></Field>
        </div>
        <div className="flex justify-end gap-3 mt-6">
          <button onClick={onCancel} className="rounded-lg border border-[var(--border)] px-4 py-2.5 text-sm font-medium text-[var(--text-secondary)] hover:bg-[var(--bg-elevated)]">Cancel</button>
          <button onClick={handleSave} disabled={isSaving || !currentPassword || !newPassword} className="rounded-lg bg-[var(--accent-blue)] px-5 py-2.5 text-sm font-medium text-white hover:bg-[var(--accent-blue)]/90 disabled:opacity-50">{isSaving ? 'Updating...' : 'Update Password'}</button>
        </div>
      </div>
    </div>
  );
}
