import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useFreeAgents, useBidFreeAgent, useMyBids } from '@/hooks/useApi';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { motion } from 'framer-motion';
import { UserPlus, DollarSign, Clock, Star } from 'lucide-react';
import { toast } from 'sonner';
import type { FreeAgent } from '@/api/client';

const positions = ['QB', 'RB', 'WR', 'TE', 'OT', 'OG', 'C', 'DE', 'DT', 'LB', 'CB', 'S', 'K', 'P', 'LS'];

function ratingColor(r: number) {
  if (r >= 85) return 'text-green-400';
  if (r >= 75) return 'text-blue-400';
  if (r >= 65) return 'text-yellow-400';
  return 'text-red-400';
}

function interestBadge(level: string) {
  const map: Record<string, string> = {
    high: 'bg-green-500/20 text-green-400 border-green-500/30',
    medium: 'bg-yellow-500/20 text-yellow-400 border-yellow-500/30',
    low: 'bg-red-500/20 text-red-400 border-red-500/30',
  };
  return map[level] ?? '';
}

function bidStatusBadge(status: string) {
  const map: Record<string, string> = {
    pending: 'bg-yellow-500/20 text-yellow-400 border-yellow-500/30',
    accepted: 'bg-green-500/20 text-green-400 border-green-500/30',
    rejected: 'bg-red-500/20 text-red-400 border-red-500/30',
    outbid: 'bg-orange-500/20 text-orange-400 border-orange-500/30',
  };
  return map[status] ?? '';
}

function formatSalary(amount: number) {
  if (amount >= 1_000_000) return `$${(amount / 1_000_000).toFixed(1)}M`;
  if (amount >= 1_000) return `$${(amount / 1_000).toFixed(0)}K`;
  return `$${amount}`;
}

export default function FreeAgency() {
  const navigate = useNavigate();
  const [posFilter, setPosFilter] = useState<string>('all');
  const [bidTarget, setBidTarget] = useState<FreeAgent | null>(null);
  const [salaryOffer, setSalaryOffer] = useState('');
  const [yearsOffer, setYearsOffer] = useState('');

  const queryPos = posFilter === 'all' ? undefined : posFilter;
  const { data: agents, isLoading } = useFreeAgents(queryPos);
  const { data: myBids, isLoading: bidsLoading } = useMyBids();
  const bidMut = useBidFreeAgent();

  function handleBid() {
    if (!bidTarget) return;
    const salary = Number(salaryOffer);
    const years = Number(yearsOffer);
    if (!salary || !years || years < 1) {
      toast.error('Enter a valid salary and years');
      return;
    }
    bidMut.mutate(
      { id: bidTarget.id, salary_offer: salary, years_offer: years },
      {
        onSuccess: () => {
          toast.success(`Bid placed on ${bidTarget.first_name} ${bidTarget.last_name}`);
          setBidTarget(null);
          setSalaryOffer('');
          setYearsOffer('');
        },
        onError: (err) => toast.error(err.message),
      },
    );
  }

  if (isLoading) {
    return (
      <div className="flex h-64 items-center justify-center">
        <div className="text-center">
          <UserPlus className="mx-auto h-8 w-8 animate-pulse text-[var(--accent-blue)]" />
          <p className="mt-2 text-sm text-[var(--text-secondary)]">Loading free agents...</p>
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
            <UserPlus className="h-5 w-5 text-[var(--accent-blue)]" />
          </div>
          <div>
            <h1 className="font-display text-2xl">Free Agency</h1>
            <p className="text-sm text-[var(--text-secondary)]">
              Sign available players to your roster
            </p>
          </div>
        </div>
      </motion.div>

      <Tabs defaultValue="market">
        <TabsList>
          <TabsTrigger value="market">Free Agent Market</TabsTrigger>
          <TabsTrigger value="bids">
            My Bids {myBids && myBids.length > 0 && `(${myBids.length})`}
          </TabsTrigger>
        </TabsList>

        <TabsContent value="market" className="mt-4">
          <div className="mb-3 flex items-center gap-2">
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
              {(agents ?? []).length} available
            </span>
          </div>

          <Card className="border-[var(--border)] bg-[var(--bg-surface)]">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Name</TableHead>
                  <TableHead>Pos</TableHead>
                  <TableHead>Age</TableHead>
                  <TableHead>OVR</TableHead>
                  <TableHead>Potential</TableHead>
                  <TableHead>Market Value</TableHead>
                  <TableHead>Interest</TableHead>
                  <TableHead className="w-20"></TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {(agents ?? []).length === 0 ? (
                  <TableRow>
                    <TableCell colSpan={8} className="text-center py-8 text-[var(--text-muted)]">
                      No free agents available
                    </TableCell>
                  </TableRow>
                ) : (
                  (agents ?? [])
                    .sort((a, b) => b.overall_rating - a.overall_rating)
                    .map((fa, i) => (
                      <motion.tr
                        key={fa.id}
                        initial={{ opacity: 0 }}
                        animate={{ opacity: 1 }}
                        transition={{ delay: i * 0.02 }}
                        className="border-b border-[var(--border)] hover:bg-[var(--bg-elevated)] cursor-pointer"
                        onClick={() => navigate(`/player/${fa.player_id}`)}
                      >
                        <TableCell className="font-medium">
                          {fa.first_name} {fa.last_name}
                        </TableCell>
                        <TableCell>
                          <Badge variant="outline" className="text-[10px]">{fa.position}</Badge>
                        </TableCell>
                        <TableCell>{fa.age}</TableCell>
                        <TableCell className={`font-mono font-semibold ${ratingColor(fa.overall_rating)}`}>
                          {fa.overall_rating}
                        </TableCell>
                        <TableCell>
                          <Badge variant="outline" className="text-[10px]">{({elite:'Elite',high:'High',average:'Average',limited:'Limited',superstar:'Elite',star:'High',normal:'Average',slow:'Limited'})[fa.potential] ?? fa.potential}</Badge>
                        </TableCell>
                        <TableCell className="font-mono text-sm">
                          {formatSalary(fa.market_value)}
                        </TableCell>
                        <TableCell>
                          <Badge variant="outline" className={`text-[10px] ${interestBadge(fa.interest_level)}`}>
                            {fa.interest_level}
                          </Badge>
                        </TableCell>
                        <TableCell>
                          <Button
                            size="sm"
                            variant="outline"
                            className="h-7 text-xs"
                            onClick={(e) => {
                              e.stopPropagation();
                              setBidTarget(fa);
                              setSalaryOffer(String(fa.asking_salary));
                              setYearsOffer(String(fa.asking_years));
                            }}
                          >
                            <DollarSign className="mr-1 h-3 w-3" /> Bid
                          </Button>
                        </TableCell>
                      </motion.tr>
                    ))
                )}
              </TableBody>
            </Table>
          </Card>
        </TabsContent>

        <TabsContent value="bids" className="mt-4">
          {bidsLoading ? (
            <p className="text-sm text-[var(--text-secondary)]">Loading bids...</p>
          ) : (myBids ?? []).length === 0 ? (
            <Card className="border-[var(--border)] bg-[var(--bg-surface)]">
              <CardContent className="flex flex-col items-center justify-center py-12">
                <Clock className="h-8 w-8 text-[var(--text-muted)] mb-2" />
                <p className="text-sm text-[var(--text-secondary)]">No active bids</p>
                <p className="text-xs text-[var(--text-muted)] mt-1">
                  Browse the free agent market and place bids on available players
                </p>
              </CardContent>
            </Card>
          ) : (
            <Card className="border-[var(--border)] bg-[var(--bg-surface)]">
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>Player</TableHead>
                    <TableHead>Pos</TableHead>
                    <TableHead>OVR</TableHead>
                    <TableHead>Salary Offer</TableHead>
                    <TableHead>Years</TableHead>
                    <TableHead>Status</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {(myBids ?? []).map((bid) => (
                    <TableRow key={bid.id}>
                      <TableCell className="font-medium">{bid.player_name}</TableCell>
                      <TableCell>
                        <Badge variant="outline" className="text-[10px]">{bid.position}</Badge>
                      </TableCell>
                      <TableCell className={`font-mono font-semibold ${ratingColor(bid.overall_rating)}`}>
                        {bid.overall_rating}
                      </TableCell>
                      <TableCell className="font-mono">{formatSalary(bid.salary_offer)}</TableCell>
                      <TableCell>{bid.years_offer} yr</TableCell>
                      <TableCell>
                        <Badge variant="outline" className={`text-[10px] ${bidStatusBadge(bid.status)}`}>
                          {bid.status}
                        </Badge>
                      </TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            </Card>
          )}
        </TabsContent>
      </Tabs>

      {/* Bid Dialog */}
      <Dialog open={!!bidTarget} onOpenChange={(open) => !open && setBidTarget(null)}>
        <DialogContent className="bg-[var(--bg-surface)] border-[var(--border)]">
          <DialogHeader>
            <DialogTitle className="font-display text-xl">
              Place Bid on {bidTarget?.first_name} {bidTarget?.last_name}
            </DialogTitle>
          </DialogHeader>

          {bidTarget && (
            <div className="space-y-4">
              <div className="flex items-center gap-4 rounded-lg bg-[var(--bg-primary)] border border-[var(--border)] p-4">
                <div className="flex h-12 w-12 items-center justify-center rounded-full bg-[var(--accent-blue)]/10">
                  <Star className="h-6 w-6 text-[var(--accent-blue)]" />
                </div>
                <div>
                  <p className="font-semibold">{bidTarget.first_name} {bidTarget.last_name}</p>
                  <p className="text-xs text-[var(--text-secondary)]">
                    {bidTarget.position} | {bidTarget.age} yrs | {bidTarget.overall_rating} OVR | {({elite:'Elite',high:'High',average:'Average',limited:'Limited',superstar:'Elite',star:'High',normal:'Average',slow:'Limited'})[bidTarget.potential] ?? bidTarget.potential}
                  </p>
                </div>
                <div className="ml-auto text-right">
                  <p className="text-xs text-[var(--text-muted)]">Market Value</p>
                  <p className="font-mono font-semibold text-[var(--accent-gold)]">
                    {formatSalary(bidTarget.market_value)}
                  </p>
                </div>
              </div>

              <div className="grid grid-cols-2 gap-4">
                <div className="space-y-2">
                  <Label className="text-xs">Annual Salary Offer ($)</Label>
                  <Input
                    type="number"
                    value={salaryOffer}
                    onChange={(e) => setSalaryOffer(e.target.value)}
                    placeholder="e.g. 5000000"
                  />
                  <p className="text-[10px] text-[var(--text-muted)]">
                    Asking: {formatSalary(bidTarget.asking_salary)}/yr
                  </p>
                </div>
                <div className="space-y-2">
                  <Label className="text-xs">Contract Years</Label>
                  <Input
                    type="number"
                    min={1}
                    max={6}
                    value={yearsOffer}
                    onChange={(e) => setYearsOffer(e.target.value)}
                    placeholder="e.g. 3"
                  />
                  <p className="text-[10px] text-[var(--text-muted)]">
                    Asking: {bidTarget.asking_years} years
                  </p>
                </div>
              </div>
            </div>
          )}

          <DialogFooter>
            <Button variant="outline" onClick={() => setBidTarget(null)}>
              Cancel
            </Button>
            <Button onClick={handleBid} disabled={bidMut.isPending}>
              <DollarSign className="mr-1 h-4 w-4" />
              {bidMut.isPending ? 'Placing bid...' : 'Place Bid'}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  );
}
