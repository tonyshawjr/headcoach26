import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useFreeAgents, useBidFreeAgent, useMyBids, useSetTender, useMakeOfferSheet, useMatchOffer, useDeclineOffer, useRfaOffers } from '@/hooks/useApi';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import {
  PageLayout,
  PageHeader,
  SportsTabs,
  DataTable,
  ActionButton,
  EmptyBlock,
  RatingBadge,
} from '@/components/ui/sports-ui';
import { PlayerPhoto } from '@/components/PlayerPhoto';
import { UserPlus, DollarSign, Clock, Shield, FileCheck, X } from 'lucide-react';
import { toast } from 'sonner';
import type { FreeAgent } from '@/api/client';

const positions = ['QB', 'RB', 'WR', 'TE', 'OT', 'OG', 'C', 'DE', 'DT', 'LB', 'CB', 'S', 'K', 'P', 'LS'];

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

const potentialLabel = (p: string) =>
  ({ elite: 'Elite', high: 'High', average: 'Average', limited: 'Limited', superstar: 'Elite', star: 'High', normal: 'Average', slow: 'Limited' })[p] ?? p;

const tenderLevelLabel = (level: string) =>
  ({ first_round: '1st Round', second_round: '2nd Round', original_round: 'Original Round' })[level] ?? level;

export default function FreeAgency() {
  const navigate = useNavigate();
  const [posFilter, setPosFilter] = useState<string>('all');
  const [bidTarget, setBidTarget] = useState<FreeAgent | null>(null);
  const [salaryOffer, setSalaryOffer] = useState('');
  const [yearsOffer, setYearsOffer] = useState('');
  const [activeTab, setActiveTab] = useState('market');

  // Tender dialog state
  const [tenderTarget, setTenderTarget] = useState<FreeAgent | null>(null);
  const [tenderLevel, setTenderLevel] = useState<string>('');

  // Offer sheet dialog state
  const [offerSheetTarget, setOfferSheetTarget] = useState<FreeAgent | null>(null);
  const [offerSheetSalary, setOfferSheetSalary] = useState('');
  const [offerSheetYears, setOfferSheetYears] = useState('');

  const queryPos = posFilter === 'all' ? undefined : posFilter;
  const { data: agents, isLoading } = useFreeAgents(queryPos);
  const { data: myBids, isLoading: bidsLoading } = useMyBids();
  const { data: rfaData } = useRfaOffers();
  const bidMut = useBidFreeAgent();
  const tenderMut = useSetTender();
  const offerSheetMut = useMakeOfferSheet();
  const matchMut = useMatchOffer();
  const declineMut = useDeclineOffer();

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

  function handleSetTender() {
    if (!tenderTarget || !tenderLevel) return;
    tenderMut.mutate(
      { id: tenderTarget.id, level: tenderLevel },
      {
        onSuccess: (data) => {
          toast.success(`Tender set: ${tenderLevelLabel(tenderLevel)} (${formatSalary(data.tender_salary)})`);
          setTenderTarget(null);
          setTenderLevel('');
        },
        onError: (err) => toast.error(err.message),
      },
    );
  }

  function handleOfferSheet() {
    if (!offerSheetTarget) return;
    const salary = Number(offerSheetSalary);
    const years = Number(offerSheetYears);
    if (!salary || !years || years < 1) {
      toast.error('Enter a valid salary and years');
      return;
    }
    offerSheetMut.mutate(
      { id: offerSheetTarget.id, salary, years },
      {
        onSuccess: () => {
          toast.success(`Offer sheet submitted for ${offerSheetTarget.first_name} ${offerSheetTarget.last_name}`);
          setOfferSheetTarget(null);
          setOfferSheetSalary('');
          setOfferSheetYears('');
        },
        onError: (err) => toast.error(err.message),
      },
    );
  }

  function handleMatchOffer(faId: number, playerName: string) {
    matchMut.mutate(faId, {
      onSuccess: () => toast.success(`Matched offer sheet for ${playerName}`),
      onError: (err) => toast.error(err.message),
    });
  }

  function handleDeclineOffer(faId: number, playerName: string) {
    declineMut.mutate(faId, {
      onSuccess: (data) => toast.success(`Declined offer. Received ${data.compensation?.round_label ?? ''} pick compensation.`),
      onError: (err) => toast.error(err.message),
    });
  }

  if (isLoading) {
    return (
      <PageLayout>
        <div className="flex h-64 items-center justify-center">
          <div className="text-center">
            <UserPlus className="mx-auto h-8 w-8 animate-pulse text-[var(--accent-blue)]" />
            <p className="mt-2 text-sm text-[var(--text-secondary)]">Loading free agents...</p>
          </div>
        </div>
      </PageLayout>
    );
  }

  const sortedAgents = [...(agents ?? [])].sort((a, b) => b.overall_rating - a.overall_rating);
  const myRFAs = rfaData?.my_rfas ?? [];
  const pendingOffers = rfaData?.pending_offers ?? [];

  const marketColumns = [
    {
      key: 'name',
      label: 'Player',
      render: (fa: FreeAgent) => (
        <div className="flex items-center gap-3">
          <PlayerPhoto imageUrl={fa.image_url} firstName={fa.first_name} lastName={fa.last_name} size={40} />
          <div className="flex items-center gap-2">
            <span className="font-medium text-[var(--text-primary)]">
              {fa.first_name} {fa.last_name}
            </span>
            {fa.is_restricted ? (
              <span className="inline-flex items-center gap-1 rounded border border-orange-500/30 bg-orange-500/20 px-1.5 py-0.5 text-[10px] font-bold uppercase text-orange-400">
                <Shield className="h-3 w-3" />
                RFA
              </span>
            ) : null}
          </div>
        </div>
      ),
    },
    {
      key: 'position',
      label: 'Pos',
      width: 'w-16',
      render: (fa: FreeAgent) => (
        <span className="inline-flex items-center justify-center w-9 h-6 rounded text-[10px] font-bold uppercase bg-[var(--bg-elevated)] text-[var(--text-secondary)] border border-[var(--border)]">
          {fa.position}
        </span>
      ),
    },
    {
      key: 'age',
      label: 'Age',
      width: 'w-14',
      stat: true,
      align: 'center' as const,
    },
    {
      key: 'overall_rating',
      label: 'OVR',
      width: 'w-16',
      align: 'center' as const,
      render: (fa: FreeAgent) => <RatingBadge rating={fa.overall_rating} size="sm" />,
    },
    {
      key: 'potential',
      label: 'Potential',
      width: 'w-20',
      render: (fa: FreeAgent) => (
        <span className="inline-flex items-center rounded border border-[var(--border)] bg-[var(--bg-elevated)] px-1.5 py-0.5 text-[10px] font-bold uppercase text-[var(--text-secondary)]">
          {potentialLabel(fa.potential)}
        </span>
      ),
    },
    {
      key: 'market_value',
      label: 'Market Value',
      stat: true,
      render: (fa: FreeAgent) => (
        <span className="font-mono text-sm text-[var(--text-primary)]">
          {formatSalary(fa.market_value)}
        </span>
      ),
    },
    {
      key: 'rfa_status',
      label: 'Status',
      render: (fa: FreeAgent) => {
        if (!fa.is_restricted) {
          return (
            <span className={`inline-flex items-center rounded border px-1.5 py-0.5 text-[10px] font-bold uppercase ${interestBadge(fa.interest_level)}`}>
              {fa.interest_level || 'UFA'}
            </span>
          );
        }
        if (fa.tender_level) {
          return (
            <div className="flex flex-col gap-0.5">
              <span className="inline-flex items-center rounded border border-purple-500/30 bg-purple-500/20 px-1.5 py-0.5 text-[10px] font-bold uppercase text-purple-400">
                {tenderLevelLabel(fa.tender_level)} Tender
              </span>
              <span className="text-[10px] text-[var(--text-muted)] font-mono">{formatSalary(fa.tender_salary)}</span>
              {fa.offer_sheet && (
                <span className="inline-flex items-center rounded border border-yellow-500/30 bg-yellow-500/20 px-1.5 py-0.5 text-[10px] font-bold text-yellow-400">
                  Offer: {formatSalary(fa.offer_sheet.salary)}/{fa.offer_sheet.years}yr by {fa.offer_sheet.offering_team_abbr}
                </span>
              )}
            </div>
          );
        }
        return (
          <span className="inline-flex items-center rounded border border-orange-500/30 bg-orange-500/20 px-1.5 py-0.5 text-[10px] font-bold uppercase text-orange-400">
            Awaiting Tender
          </span>
        );
      },
    },
    {
      key: 'actions',
      label: '',
      width: 'w-28',
      render: (fa: FreeAgent) => {
        if (fa.is_restricted) {
          // If this is user's own RFA (original_team), show tender/match/decline buttons
          if (fa.original_team_id && !fa.tender_level) {
            return (
              <ActionButton
                variant="secondary"
                size="sm"
                icon={Shield}
                onClick={(e: React.MouseEvent) => {
                  e.stopPropagation();
                  setTenderTarget(fa);
                }}
              >
                Tender
              </ActionButton>
            );
          }
          // If tendered and has an offer sheet from another team, show match/decline (for original team)
          if (fa.offer_sheet && fa.original_team_id) {
            return (
              <div className="flex gap-1">
                <ActionButton
                  variant="primary"
                  size="sm"
                  icon={FileCheck}
                  onClick={(e: React.MouseEvent) => {
                    e.stopPropagation();
                    handleMatchOffer(fa.id, `${fa.first_name} ${fa.last_name}`);
                  }}
                  disabled={matchMut.isPending}
                >
                  Match
                </ActionButton>
                <ActionButton
                  variant="secondary"
                  size="sm"
                  icon={X}
                  onClick={(e: React.MouseEvent) => {
                    e.stopPropagation();
                    handleDeclineOffer(fa.id, `${fa.first_name} ${fa.last_name}`);
                  }}
                  disabled={declineMut.isPending}
                >
                  Decline
                </ActionButton>
              </div>
            );
          }
          // If tendered but not user's own RFA, show "Offer Sheet" button
          if (fa.tender_level && !fa.original_team_id) {
            return (
              <ActionButton
                variant="secondary"
                size="sm"
                icon={DollarSign}
                onClick={(e: React.MouseEvent) => {
                  e.stopPropagation();
                  setOfferSheetTarget(fa);
                  setOfferSheetSalary(String(Math.max(fa.tender_salary + 1000000, fa.market_value)));
                  setOfferSheetYears('3');
                }}
              >
                Offer Sheet
              </ActionButton>
            );
          }
          return null;
        }
        // Standard UFA bid button
        return (
          <ActionButton
            variant="secondary"
            size="sm"
            icon={DollarSign}
            onClick={(e: React.MouseEvent) => {
              e.stopPropagation();
              setBidTarget(fa);
              setSalaryOffer(String(fa.asking_salary));
              setYearsOffer(String(fa.asking_years));
            }}
          >
            Bid
          </ActionButton>
        );
      },
    },
  ];

  const bidsColumns = [
    {
      key: 'player_name',
      label: 'Player',
      render: (bid: any) => (
        <span className="font-medium text-[var(--text-primary)]">{bid.player_name}</span>
      ),
    },
    {
      key: 'position',
      label: 'Pos',
      width: 'w-16',
      render: (bid: any) => (
        <span className="inline-flex items-center justify-center w-9 h-6 rounded text-[10px] font-bold uppercase bg-[var(--bg-elevated)] text-[var(--text-secondary)] border border-[var(--border)]">
          {bid.position}
        </span>
      ),
    },
    {
      key: 'overall_rating',
      label: 'OVR',
      width: 'w-16',
      align: 'center' as const,
      render: (bid: any) => <RatingBadge rating={bid.overall_rating} size="sm" />,
    },
    {
      key: 'salary_offer',
      label: 'Salary Offer',
      stat: true,
      render: (bid: any) => (
        <span className="font-mono text-sm">{formatSalary(bid.salary_offer)}</span>
      ),
    },
    {
      key: 'years_offer',
      label: 'Years',
      stat: true,
      align: 'center' as const,
      render: (bid: any) => <span>{bid.years_offer} yr</span>,
    },
    {
      key: 'status',
      label: 'Status',
      render: (bid: any) => (
        <span className={`inline-flex items-center rounded border px-1.5 py-0.5 text-[10px] font-bold uppercase ${bidStatusBadge(bid.status)}`}>
          {bid.status}
        </span>
      ),
    },
  ];

  const rfaColumns = [
    {
      key: 'name',
      label: 'Player',
      render: (rfa: FreeAgent) => (
        <div className="flex items-center gap-3">
          <PlayerPhoto imageUrl={rfa.image_url} firstName={rfa.first_name} lastName={rfa.last_name} size={40} />
          <span className="font-medium text-[var(--text-primary)]">{rfa.first_name} {rfa.last_name}</span>
        </div>
      ),
    },
    {
      key: 'position',
      label: 'Pos',
      width: 'w-16',
      render: (rfa: FreeAgent) => (
        <span className="inline-flex items-center justify-center w-9 h-6 rounded text-[10px] font-bold uppercase bg-[var(--bg-elevated)] text-[var(--text-secondary)] border border-[var(--border)]">
          {rfa.position}
        </span>
      ),
    },
    {
      key: 'overall_rating',
      label: 'OVR',
      width: 'w-16',
      align: 'center' as const,
      render: (rfa: FreeAgent) => <RatingBadge rating={rfa.overall_rating} size="sm" />,
    },
    {
      key: 'tender',
      label: 'Tender',
      render: (rfa: FreeAgent) => rfa.tender_level ? (
        <div className="flex flex-col gap-0.5">
          <span className="inline-flex items-center rounded border border-purple-500/30 bg-purple-500/20 px-1.5 py-0.5 text-[10px] font-bold uppercase text-purple-400">
            {tenderLevelLabel(rfa.tender_level)}
          </span>
          <span className="text-[10px] font-mono text-[var(--text-muted)]">{formatSalary(rfa.tender_salary)}</span>
        </div>
      ) : (
        <span className="text-[10px] text-[var(--text-muted)]">Not set</span>
      ),
    },
    {
      key: 'offer_sheet',
      label: 'Offer Sheet',
      render: (rfa: FreeAgent) => rfa.offer_sheet ? (
        <div className="flex flex-col gap-0.5">
          <span className="text-sm font-mono text-yellow-400">{formatSalary(rfa.offer_sheet.salary)} / {rfa.offer_sheet.years}yr</span>
          <span className="text-[10px] text-[var(--text-muted)]">from {rfa.offer_sheet.offering_team_abbr}</span>
        </div>
      ) : (
        <span className="text-[10px] text-[var(--text-muted)]">None</span>
      ),
    },
    {
      key: 'actions',
      label: '',
      width: 'w-40',
      render: (rfa: FreeAgent) => {
        if (!rfa.tender_level) {
          return (
            <ActionButton
              variant="primary"
              size="sm"
              icon={Shield}
              onClick={() => setTenderTarget(rfa)}
            >
              Set Tender
            </ActionButton>
          );
        }
        if (rfa.offer_sheet) {
          return (
            <div className="flex gap-1">
              <ActionButton
                variant="primary"
                size="sm"
                icon={FileCheck}
                onClick={() => handleMatchOffer(rfa.id, `${rfa.first_name} ${rfa.last_name}`)}
                disabled={matchMut.isPending}
              >
                Match
              </ActionButton>
              <ActionButton
                variant="secondary"
                size="sm"
                icon={X}
                onClick={() => handleDeclineOffer(rfa.id, `${rfa.first_name} ${rfa.last_name}`)}
                disabled={declineMut.isPending}
              >
                Decline
              </ActionButton>
            </div>
          );
        }
        return <span className="text-[10px] text-[var(--text-muted)]">Tendered - awaiting offers</span>;
      },
    },
  ];

  return (
    <PageLayout>
      <PageHeader
        title="Free Agency"
        subtitle="Sign available players to your roster"
        icon={UserPlus}
        actions={
          <Select value={posFilter} onValueChange={(v) => v && setPosFilter(v)}>
            <SelectTrigger className="w-36">
              <SelectValue placeholder="Position" />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="all">All Positions</SelectItem>
              {positions.map((p) => (
                <SelectItem key={p} value={p}>{p}</SelectItem>
              ))}
            </SelectContent>
          </Select>
        }
      />

      <SportsTabs
        tabs={[
          { key: 'market', label: `Free Agent Market` },
          { key: 'bids', label: `My Bids${myBids && myBids.length > 0 ? ` (${myBids.length})` : ''}` },
          { key: 'rfas', label: `My RFAs${myRFAs.length > 0 ? ` (${myRFAs.length})` : ''}` },
        ]}
        activeTab={activeTab}
        onChange={setActiveTab}
      />

      <div className="mt-5">
        {activeTab === 'market' && (
          <>
            <div className="mb-3 flex items-center gap-2">
              <span className="text-xs text-[var(--text-muted)]">
                {sortedAgents.length} available
              </span>
              <span className="text-xs text-orange-400">
                ({sortedAgents.filter(a => a.is_restricted).length} restricted)
              </span>
            </div>

            {sortedAgents.length === 0 ? (
              <EmptyBlock
                icon={UserPlus}
                title="No free agents available"
                description="There are no free agents matching your current filters."
              />
            ) : (
              <DataTable
                columns={marketColumns}
                data={sortedAgents}
                rowKey={(fa) => fa.id}
                onRowClick={(fa) => navigate(`/player/${fa.player_id}`)}
                accentColor="var(--accent-blue)"
                emptyMessage="No free agents available"
              />
            )}
          </>
        )}

        {activeTab === 'bids' && (
          <>
            {bidsLoading ? (
              <p className="text-sm text-[var(--text-secondary)]">Loading bids...</p>
            ) : (myBids ?? []).length === 0 ? (
              <EmptyBlock
                icon={Clock}
                title="No active bids"
                description="Browse the free agent market and place bids on available players"
              />
            ) : (
              <DataTable
                columns={bidsColumns}
                data={myBids ?? []}
                rowKey={(bid: any) => bid.id}
                accentColor="var(--accent-blue)"
                emptyMessage="No active bids"
              />
            )}
          </>
        )}

        {activeTab === 'rfas' && (
          <>
            {myRFAs.length === 0 ? (
              <EmptyBlock
                icon={Shield}
                title="No restricted free agents"
                description="You have no restricted free agents. Players with 3 or fewer years in the league whose contracts expire become RFAs."
              />
            ) : (
              <>
                <div className="mb-4 rounded-lg border border-orange-500/20 bg-orange-500/5 p-3">
                  <p className="text-sm text-orange-300 font-medium">Restricted Free Agent Management</p>
                  <p className="text-xs text-[var(--text-muted)] mt-1">
                    Set tenders on your RFAs to retain their rights. Other teams can submit offer sheets, which you can match or decline for draft pick compensation.
                  </p>
                </div>
                <DataTable
                  columns={rfaColumns}
                  data={myRFAs}
                  rowKey={(rfa) => rfa.id}
                  onRowClick={(rfa) => navigate(`/player/${rfa.player_id}`)}
                  accentColor="var(--accent-orange)"
                  emptyMessage="No restricted free agents"
                />

                {pendingOffers.length > 0 && (
                  <div className="mt-6">
                    <h3 className="text-sm font-bold text-[var(--text-primary)] mb-3">Pending Offer Sheets</h3>
                    {pendingOffers.map((offer: any) => (
                      <div key={offer.offer_sheet_id} className="mb-2 flex items-center justify-between rounded-lg border border-yellow-500/30 bg-yellow-500/10 p-3">
                        <div>
                          <p className="font-medium text-[var(--text-primary)]">{offer.first_name} {offer.last_name} ({offer.position})</p>
                          <p className="text-xs text-[var(--text-muted)]">
                            Offer: {formatSalary(offer.salary)} / {offer.years}yr from {offer.offering_team_city} {offer.offering_team_name}
                          </p>
                        </div>
                        <div className="flex gap-2">
                          <ActionButton
                            variant="primary"
                            size="sm"
                            icon={FileCheck}
                            onClick={() => handleMatchOffer(offer.fa_id, `${offer.first_name} ${offer.last_name}`)}
                            disabled={matchMut.isPending}
                          >
                            Match
                          </ActionButton>
                          <ActionButton
                            variant="secondary"
                            size="sm"
                            icon={X}
                            onClick={() => handleDeclineOffer(offer.fa_id, `${offer.first_name} ${offer.last_name}`)}
                            disabled={declineMut.isPending}
                          >
                            Decline
                          </ActionButton>
                        </div>
                      </div>
                    ))}
                  </div>
                )}
              </>
            )}
          </>
        )}
      </div>

      {/* Standard Bid Dialog */}
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
                <PlayerPhoto imageUrl={bidTarget.image_url} firstName={bidTarget.first_name} lastName={bidTarget.last_name} size={48} />
                <div>
                  <p className="font-semibold">{bidTarget.first_name} {bidTarget.last_name}</p>
                  <p className="text-xs text-[var(--text-secondary)]">
                    {bidTarget.position} | {bidTarget.age} yrs | <RatingBadge rating={bidTarget.overall_rating} size="sm" /> | {potentialLabel(bidTarget.potential)}
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
            <ActionButton variant="secondary" onClick={() => setBidTarget(null)}>
              Cancel
            </ActionButton>
            <ActionButton
              variant="primary"
              icon={DollarSign}
              onClick={handleBid}
              disabled={bidMut.isPending}
            >
              {bidMut.isPending ? 'Placing bid...' : 'Place Bid'}
            </ActionButton>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* Tender Dialog */}
      <Dialog open={!!tenderTarget} onOpenChange={(open) => !open && setTenderTarget(null)}>
        <DialogContent className="bg-[var(--bg-surface)] border-[var(--border)]">
          <DialogHeader>
            <DialogTitle className="font-display text-xl">
              Set Qualifying Tender
            </DialogTitle>
          </DialogHeader>

          {tenderTarget && (
            <div className="space-y-4">
              <div className="flex items-center gap-4 rounded-lg bg-[var(--bg-primary)] border border-[var(--border)] p-4">
                <PlayerPhoto imageUrl={tenderTarget.image_url} firstName={tenderTarget.first_name} lastName={tenderTarget.last_name} size={48} />
                <div>
                  <p className="font-semibold">{tenderTarget.first_name} {tenderTarget.last_name}</p>
                  <p className="text-xs text-[var(--text-secondary)]">
                    {tenderTarget.position} | {tenderTarget.age} yrs | <RatingBadge rating={tenderTarget.overall_rating} size="sm" />
                  </p>
                </div>
              </div>

              <div className="space-y-3">
                <Label className="text-sm font-medium">Tender Level</Label>
                <div className="space-y-2">
                  {[
                    { value: 'first_round', label: '1st Round Tender', desc: 'Highest salary. If another team signs the player and you don\'t match, you receive their 1st round pick.' },
                    { value: 'second_round', label: '2nd Round Tender', desc: 'Medium salary. Compensation is a 2nd round pick.' },
                    { value: 'original_round', label: 'Original Round Tender', desc: `Lowest salary. Compensation is the round the player was drafted in${tenderTarget.original_draft_round ? ` (Round ${tenderTarget.original_draft_round})` : ''}.` },
                  ].map((opt) => (
                    <label
                      key={opt.value}
                      className={`flex cursor-pointer items-start gap-3 rounded-lg border p-3 transition-colors ${
                        tenderLevel === opt.value
                          ? 'border-purple-500/50 bg-purple-500/10'
                          : 'border-[var(--border)] bg-[var(--bg-primary)] hover:border-[var(--border-hover)]'
                      }`}
                    >
                      <input
                        type="radio"
                        name="tender_level"
                        value={opt.value}
                        checked={tenderLevel === opt.value}
                        onChange={(e) => setTenderLevel(e.target.value)}
                        className="mt-1"
                      />
                      <div>
                        <p className="text-sm font-medium text-[var(--text-primary)]">{opt.label}</p>
                        <p className="text-xs text-[var(--text-muted)] mt-0.5">{opt.desc}</p>
                      </div>
                    </label>
                  ))}
                </div>
              </div>
            </div>
          )}

          <DialogFooter>
            <ActionButton variant="secondary" onClick={() => setTenderTarget(null)}>
              Cancel
            </ActionButton>
            <ActionButton
              variant="primary"
              icon={Shield}
              onClick={handleSetTender}
              disabled={tenderMut.isPending || !tenderLevel}
            >
              {tenderMut.isPending ? 'Setting tender...' : 'Set Tender'}
            </ActionButton>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* Offer Sheet Dialog */}
      <Dialog open={!!offerSheetTarget} onOpenChange={(open) => !open && setOfferSheetTarget(null)}>
        <DialogContent className="bg-[var(--bg-surface)] border-[var(--border)]">
          <DialogHeader>
            <DialogTitle className="font-display text-xl">
              Submit Offer Sheet
            </DialogTitle>
          </DialogHeader>

          {offerSheetTarget && (
            <div className="space-y-4">
              <div className="flex items-center gap-4 rounded-lg bg-[var(--bg-primary)] border border-[var(--border)] p-4">
                <PlayerPhoto imageUrl={offerSheetTarget.image_url} firstName={offerSheetTarget.first_name} lastName={offerSheetTarget.last_name} size={48} />
                <div>
                  <p className="font-semibold">{offerSheetTarget.first_name} {offerSheetTarget.last_name}</p>
                  <p className="text-xs text-[var(--text-secondary)]">
                    {offerSheetTarget.position} | {offerSheetTarget.age} yrs | <RatingBadge rating={offerSheetTarget.overall_rating} size="sm" />
                  </p>
                </div>
                <div className="ml-auto text-right">
                  <p className="text-xs text-[var(--text-muted)]">Current Tender</p>
                  <p className="font-mono font-semibold text-purple-400">
                    {formatSalary(offerSheetTarget.tender_salary)}
                  </p>
                  <p className="text-[10px] text-[var(--text-muted)]">
                    {offerSheetTarget.original_team_abbr} | {tenderLevelLabel(offerSheetTarget.tender_level ?? '')}
                  </p>
                </div>
              </div>

              <div className="rounded-lg border border-yellow-500/20 bg-yellow-500/5 p-3">
                <p className="text-xs text-yellow-300">
                  The original team ({offerSheetTarget.original_team_abbr}) can match this offer. If they decline, they receive draft pick compensation ({tenderLevelLabel(offerSheetTarget.tender_level ?? '')}).
                </p>
              </div>

              <div className="grid grid-cols-2 gap-4">
                <div className="space-y-2">
                  <Label className="text-xs">Annual Salary ($)</Label>
                  <Input
                    type="number"
                    value={offerSheetSalary}
                    onChange={(e) => setOfferSheetSalary(e.target.value)}
                    placeholder="e.g. 8000000"
                  />
                  <p className="text-[10px] text-[var(--text-muted)]">
                    Min: {formatSalary(offerSheetTarget.tender_salary)} (tender salary)
                  </p>
                </div>
                <div className="space-y-2">
                  <Label className="text-xs">Contract Years</Label>
                  <Input
                    type="number"
                    min={1}
                    max={6}
                    value={offerSheetYears}
                    onChange={(e) => setOfferSheetYears(e.target.value)}
                    placeholder="e.g. 4"
                  />
                </div>
              </div>
            </div>
          )}

          <DialogFooter>
            <ActionButton variant="secondary" onClick={() => setOfferSheetTarget(null)}>
              Cancel
            </ActionButton>
            <ActionButton
              variant="primary"
              icon={DollarSign}
              onClick={handleOfferSheet}
              disabled={offerSheetMut.isPending}
            >
              {offerSheetMut.isPending ? 'Submitting...' : 'Submit Offer Sheet'}
            </ActionButton>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </PageLayout>
  );
}
