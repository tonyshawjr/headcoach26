import { useState } from 'react';
import { useDraftClass, useDraftBoard, useMyDraftPicks, useScoutProspect, useDraftPick } from '@/hooks/useApi';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/components/ui/dialog';
import { Separator } from '@/components/ui/separator';
import { motion } from 'framer-motion';
import { GraduationCap, Search, Check, Eye, Trophy, Target, Hash } from 'lucide-react';
import { toast } from 'sonner';
import type { DraftProspect, DraftPick, ScoutResult } from '@/api/client';

const positions = ['QB', 'RB', 'WR', 'TE', 'OT', 'OG', 'C', 'DE', 'DT', 'LB', 'CB', 'S', 'K', 'P', 'LS'];

function combineGradeColor(grade?: string) {
  if (!grade) return 'text-[var(--text-muted)]';
  const map: Record<string, string> = {
    A: 'text-green-400', 'A+': 'text-green-400', 'A-': 'text-green-400',
    B: 'text-blue-400', 'B+': 'text-blue-400', 'B-': 'text-blue-400',
    C: 'text-yellow-400', 'C+': 'text-yellow-400', 'C-': 'text-yellow-400',
    D: 'text-orange-400', F: 'text-red-400',
  };
  return map[grade] ?? 'text-[var(--text-secondary)]';
}

function ScoutReportDialog({ result, prospect, open, onClose }: {
  result: ScoutResult | null;
  prospect: DraftProspect | null;
  open: boolean;
  onClose: () => void;
}) {
  if (!result || !prospect) return null;

  return (
    <Dialog open={open} onOpenChange={(o) => !o && onClose()}>
      <DialogContent className="bg-[var(--bg-surface)] border-[var(--border)]">
        <DialogHeader>
          <DialogTitle className="font-display text-xl">
            Scout Report: {prospect.first_name} {prospect.last_name}
          </DialogTitle>
        </DialogHeader>
        <div className="space-y-4">
          <div className="flex items-center gap-4 rounded-lg bg-[var(--bg-primary)] border border-[var(--border)] p-4">
            <div>
              <p className="font-semibold">{prospect.first_name} {prospect.last_name}</p>
              <p className="text-xs text-[var(--text-secondary)]">
                {prospect.position} | {prospect.college} | Age {prospect.age}
              </p>
            </div>
            <div className="ml-auto text-right">
              <p className="text-xs text-[var(--text-muted)]">Projected</p>
              <p className="font-display text-lg text-[var(--accent-blue)]">
                Rd {prospect.projected_round}, #{prospect.projected_pick}
              </p>
            </div>
          </div>

          <div className="grid grid-cols-3 gap-4">
            <Card className="border-[var(--border)] bg-[var(--bg-primary)]">
              <CardContent className="p-3 text-center">
                <p className="text-xs text-[var(--text-muted)]">OVR Range</p>
                <p className="font-display text-lg">
                  {result.overall_range_low}-{result.overall_range_high}
                </p>
              </CardContent>
            </Card>
            <Card className="border-[var(--border)] bg-[var(--bg-primary)]">
              <CardContent className="p-3 text-center">
                <p className="text-xs text-[var(--text-muted)]">Combine Grade</p>
                <p className={`font-display text-lg ${combineGradeColor(result.combine_grade)}`}>
                  {result.combine_grade}
                </p>
              </CardContent>
            </Card>
            <Card className="border-[var(--border)] bg-[var(--bg-primary)]">
              <CardContent className="p-3 text-center">
                <p className="text-xs text-[var(--text-muted)]">Potential Span</p>
                <p className="font-display text-lg">
                  {result.overall_range_high - result.overall_range_low}
                </p>
              </CardContent>
            </Card>
          </div>

          <div className="grid gap-4 md:grid-cols-2">
            <div>
              <p className="text-xs font-medium text-green-400 mb-1">Strengths</p>
              <ul className="space-y-1">
                {result.strengths.map((s, i) => (
                  <li key={i} className="flex items-center gap-2 text-sm text-[var(--text-secondary)]">
                    <Check className="h-3 w-3 text-green-400 shrink-0" /> {s}
                  </li>
                ))}
              </ul>
            </div>
            <div>
              <p className="text-xs font-medium text-red-400 mb-1">Weaknesses</p>
              <ul className="space-y-1">
                {result.weaknesses.map((w, i) => (
                  <li key={i} className="flex items-center gap-2 text-sm text-[var(--text-secondary)]">
                    <Target className="h-3 w-3 text-red-400 shrink-0" /> {w}
                  </li>
                ))}
              </ul>
            </div>
          </div>
        </div>
        <DialogFooter>
          <Button variant="outline" onClick={onClose}>Close</Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

export default function DraftRoom() {
  const [posFilter, setPosFilter] = useState('all');
  const [selectedProspect, setSelectedProspect] = useState<DraftProspect | null>(null);
  const [selectedPick, setSelectedPick] = useState<DraftPick | null>(null);
  const [scoutReport, setScoutReport] = useState<{ result: ScoutResult; prospect: DraftProspect } | null>(null);
  const [confirmOpen, setConfirmOpen] = useState(false);

  const queryPos = posFilter === 'all' ? undefined : posFilter;
  const { data: draftClass } = useDraftClass();
  const { data: board, isLoading } = useDraftBoard(queryPos);
  const { data: myPicks } = useMyDraftPicks();
  const scoutMut = useScoutProspect();
  const draftMut = useDraftPick();

  const availablePicks = (myPicks ?? []).filter((p) => !p.is_used);

  function handleScout(prospect: DraftProspect) {
    scoutMut.mutate(prospect.id, {
      onSuccess: (result) => {
        setScoutReport({ result, prospect });
        toast.success(`Scouted ${prospect.first_name} ${prospect.last_name}`);
      },
      onError: (err) => toast.error(err.message),
    });
  }

  function handleDraft() {
    if (!selectedPick || !selectedProspect) return;
    draftMut.mutate(
      { pickId: selectedPick.id, prospectId: selectedProspect.id },
      {
        onSuccess: (result) => {
          toast.success(result.message || `Drafted ${selectedProspect.first_name} ${selectedProspect.last_name}!`);
          setConfirmOpen(false);
          setSelectedProspect(null);
          setSelectedPick(null);
        },
        onError: (err) => toast.error(err.message),
      },
    );
  }

  if (isLoading) {
    return (
      <div className="flex h-64 items-center justify-center">
        <div className="text-center">
          <GraduationCap className="mx-auto h-8 w-8 animate-pulse text-[var(--accent-blue)]" />
          <p className="mt-2 text-sm text-[var(--text-secondary)]">Loading draft board...</p>
        </div>
      </div>
    );
  }

  return (
    <div className="space-y-6">
      <motion.div
        initial={{ opacity: 0, y: -10 }}
        animate={{ opacity: 1, y: 0 }}
        transition={{ duration: 0.3 }}
      >
        <div className="flex items-center gap-3">
          <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-[var(--accent-blue)]/10">
            <GraduationCap className="h-5 w-5 text-[var(--accent-blue)]" />
          </div>
          <div>
            <h1 className="font-display text-2xl">Draft Room</h1>
            <p className="text-sm text-[var(--text-secondary)]">
              {draftClass ? `${draftClass.year} Draft Class -- ${draftClass.total_prospects} prospects (${draftClass.strength})` : 'Scout and draft the next generation'}
            </p>
          </div>
        </div>
      </motion.div>

      <div className="grid gap-6 lg:grid-cols-4">
        {/* Main Draft Board */}
        <div className="lg:col-span-3 space-y-4">
          <div className="flex items-center gap-2">
            <Select value={posFilter} onValueChange={(v) => v && setPosFilter(v)}>
              <SelectTrigger className="w-32">
                <SelectValue placeholder="Position" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="all">All Positions</SelectItem>
                {positions.map((p) => (
                  <SelectItem key={p} value={p}>{p}</SelectItem>
                ))}
              </SelectContent>
            </Select>
            <span className="text-xs text-[var(--text-muted)]">
              {(board ?? []).filter((p) => !p.is_drafted).length} available
            </span>
          </div>

          <Card className="border-[var(--border)] bg-[var(--bg-surface)]">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead className="w-16">Proj</TableHead>
                  <TableHead>Name</TableHead>
                  <TableHead>Pos</TableHead>
                  <TableHead>College</TableHead>
                  <TableHead>Age</TableHead>
                  <TableHead>Scouted</TableHead>
                  <TableHead>Combine</TableHead>
                  <TableHead className="w-24"></TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {(board ?? []).length === 0 ? (
                  <TableRow>
                    <TableCell colSpan={8} className="text-center py-8 text-[var(--text-muted)]">
                      No prospects available
                    </TableCell>
                  </TableRow>
                ) : (
                  (board ?? []).map((prospect, i) => {
                    const isDrafted = prospect.is_drafted;
                    const isSelected = selectedProspect?.id === prospect.id;
                    return (
                      <motion.tr
                        key={prospect.id}
                        initial={{ opacity: 0 }}
                        animate={{ opacity: isDrafted ? 0.4 : 1 }}
                        transition={{ delay: i * 0.015 }}
                        className={`border-b border-[var(--border)] transition-colors ${
                          isDrafted
                            ? 'opacity-40 cursor-not-allowed'
                            : isSelected
                            ? 'bg-[var(--accent-blue)]/10'
                            : 'hover:bg-[var(--bg-elevated)] cursor-pointer'
                        }`}
                        onClick={() => {
                          if (!isDrafted) setSelectedProspect(prospect);
                        }}
                      >
                        <TableCell className="font-mono text-xs text-[var(--text-muted)]">
                          Rd{prospect.projected_round} #{prospect.projected_pick}
                        </TableCell>
                        <TableCell className="font-medium">
                          {prospect.first_name} {prospect.last_name}
                          {isDrafted && (
                            <Badge variant="outline" className="ml-2 text-[8px] bg-red-500/10 text-red-400 border-red-500/30">
                              DRAFTED
                            </Badge>
                          )}
                        </TableCell>
                        <TableCell>
                          <Badge variant="outline" className="text-[10px]">{prospect.position}</Badge>
                        </TableCell>
                        <TableCell className="text-sm text-[var(--text-secondary)]">
                          {prospect.college}
                        </TableCell>
                        <TableCell>{prospect.age}</TableCell>
                        <TableCell>
                          {prospect.scouted ? (
                            <span className="font-mono text-sm text-[var(--accent-blue)]">
                              {prospect.scouted_range_low}-{prospect.scouted_range_high}
                            </span>
                          ) : (
                            <span className="text-xs text-[var(--text-muted)]">--</span>
                          )}
                        </TableCell>
                        <TableCell>
                          {prospect.combine_grade ? (
                            <span className={`font-semibold ${combineGradeColor(prospect.combine_grade)}`}>
                              {prospect.combine_grade}
                            </span>
                          ) : (
                            <span className="text-xs text-[var(--text-muted)]">--</span>
                          )}
                        </TableCell>
                        <TableCell>
                          {!isDrafted && (
                            <Button
                              size="sm"
                              variant="outline"
                              className="h-7 text-xs"
                              onClick={(e) => {
                                e.stopPropagation();
                                handleScout(prospect);
                              }}
                              disabled={scoutMut.isPending}
                            >
                              <Search className="mr-1 h-3 w-3" /> Scout
                            </Button>
                          )}
                        </TableCell>
                      </motion.tr>
                    );
                  })
                )}
              </TableBody>
            </Table>
          </Card>
        </div>

        {/* Sidebar: My Picks */}
        <div className="space-y-4">
          <Card className="border-[var(--border)] bg-[var(--bg-surface)]">
            <CardHeader className="pb-2">
              <CardTitle className="font-display text-base flex items-center gap-2">
                <Hash className="h-4 w-4 text-[var(--accent-blue)]" /> My Picks
              </CardTitle>
            </CardHeader>
            <CardContent className="space-y-2">
              {availablePicks.length === 0 ? (
                <p className="text-sm text-[var(--text-muted)] py-4 text-center">
                  No picks available
                </p>
              ) : (
                availablePicks.map((pick) => {
                  const isSelected = selectedPick?.id === pick.id;
                  return (
                    <button
                      key={pick.id}
                      onClick={() => setSelectedPick(pick)}
                      className={`flex w-full items-center justify-between rounded-lg border px-3 py-2 text-left transition-colors ${
                        isSelected
                          ? 'border-[var(--accent-blue)] bg-[var(--accent-blue)]/10'
                          : 'border-[var(--border)] bg-[var(--bg-primary)] hover:bg-[var(--bg-elevated)]'
                      }`}
                    >
                      <div>
                        <p className="font-display text-sm">Round {pick.round}</p>
                        <p className="text-xs text-[var(--text-muted)]">Pick #{pick.pick_number}</p>
                      </div>
                      {isSelected && <Check className="h-4 w-4 text-[var(--accent-blue)]" />}
                    </button>
                  );
                })
              )}
            </CardContent>
          </Card>

          {/* Draft Action */}
          {selectedProspect && selectedPick && (
            <motion.div
              initial={{ opacity: 0, y: 10 }}
              animate={{ opacity: 1, y: 0 }}
              transition={{ duration: 0.3 }}
            >
              <Card className="border-[var(--accent-blue)] bg-[var(--accent-blue)]/5">
                <CardContent className="p-4 space-y-3">
                  <div className="flex items-center gap-2">
                    <Trophy className="h-4 w-4 text-[var(--accent-gold)]" />
                    <p className="font-display text-sm">Ready to Draft</p>
                  </div>
                  <div className="rounded-lg bg-[var(--bg-primary)] border border-[var(--border)] p-3">
                    <p className="font-semibold text-sm">
                      {selectedProspect.first_name} {selectedProspect.last_name}
                    </p>
                    <p className="text-xs text-[var(--text-secondary)]">
                      {selectedProspect.position} | {selectedProspect.college}
                    </p>
                  </div>
                  <p className="text-xs text-[var(--text-muted)] text-center">
                    with Round {selectedPick.round}, Pick #{selectedPick.pick_number}
                  </p>
                  <Button
                    className="w-full"
                    onClick={() => setConfirmOpen(true)}
                  >
                    <GraduationCap className="mr-1 h-4 w-4" /> Draft Player
                  </Button>
                </CardContent>
              </Card>
            </motion.div>
          )}

          {/* Draft Class Summary */}
          {draftClass && (
            <Card className="border-[var(--border)] bg-[var(--bg-surface)]">
              <CardHeader className="pb-2">
                <CardTitle className="font-display text-base flex items-center gap-2">
                  <Eye className="h-4 w-4 text-[var(--accent-blue)]" /> Class Overview
                </CardTitle>
              </CardHeader>
              <CardContent className="space-y-2">
                <div className="flex justify-between text-sm">
                  <span className="text-[var(--text-muted)]">Year</span>
                  <span className="font-semibold">{draftClass.year}</span>
                </div>
                <div className="flex justify-between text-sm">
                  <span className="text-[var(--text-muted)]">Total Prospects</span>
                  <span className="font-semibold">{draftClass.total_prospects}</span>
                </div>
                <div className="flex justify-between text-sm">
                  <span className="text-[var(--text-muted)]">Class Strength</span>
                  <Badge variant="outline" className="text-[10px]">{draftClass.strength}</Badge>
                </div>
                <Separator />
                <p className="text-xs font-medium text-[var(--text-muted)]">Top Positions</p>
                <div className="flex flex-wrap gap-1">
                  {Object.entries(draftClass.top_positions ?? {}).map(([pos, count]) => (
                    <Badge key={pos} variant="outline" className="text-[10px]">
                      {pos}: {count}
                    </Badge>
                  ))}
                </div>
              </CardContent>
            </Card>
          )}
        </div>
      </div>

      {/* Scout Report Dialog */}
      <ScoutReportDialog
        result={scoutReport?.result ?? null}
        prospect={scoutReport?.prospect ?? null}
        open={!!scoutReport}
        onClose={() => setScoutReport(null)}
      />

      {/* Draft Confirmation Dialog */}
      <Dialog open={confirmOpen} onOpenChange={setConfirmOpen}>
        <DialogContent className="bg-[var(--bg-surface)] border-[var(--border)]">
          <DialogHeader>
            <DialogTitle className="font-display text-xl">Confirm Draft Pick</DialogTitle>
          </DialogHeader>
          <div className="space-y-3">
            <p className="text-sm text-[var(--text-secondary)]">
              Are you sure you want to select{' '}
              <span className="font-semibold text-[var(--text-primary)]">
                {selectedProspect?.first_name} {selectedProspect?.last_name}
              </span>{' '}
              ({selectedProspect?.position}) with Round {selectedPick?.round}, Pick #{selectedPick?.pick_number}?
            </p>
            <p className="text-xs text-[var(--text-muted)]">
              This action cannot be undone.
            </p>
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setConfirmOpen(false)}>
              Cancel
            </Button>
            <Button onClick={handleDraft} disabled={draftMut.isPending}>
              <GraduationCap className="mr-1 h-4 w-4" />
              {draftMut.isPending ? 'Drafting...' : 'Confirm Pick'}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  );
}
