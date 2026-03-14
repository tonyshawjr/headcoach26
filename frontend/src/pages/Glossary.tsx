import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Badge } from '@/components/ui/badge';
import { ArrowLeft, Search, Zap, Brain, TrendingUp, Compass } from 'lucide-react';

// ─── Data ────────────────────────────────────────────────────────────────────

const DEVELOPMENT_TIERS = [
  {
    name: 'Elite',
    color: 'text-yellow-400',
    bg: 'bg-yellow-500/10 border-yellow-500/20',
    description: 'The highest growth trajectory. These players improve rapidly in the offseason and are worth significantly more in trades. Young Elite players can become franchise cornerstones.',
    tradeMultiplier: '1.30x',
    offseasonGrowth: '+2 to +5 OVR per year (age 26 and under)',
  },
  {
    name: 'High',
    color: 'text-blue-400',
    bg: 'bg-blue-500/10 border-blue-500/20',
    description: 'Above-average growth potential. These players steadily improve and carry a premium in trades. Solid building blocks for any roster.',
    tradeMultiplier: '1.12x',
    offseasonGrowth: '+1 to +3 OVR per year (age 26 and under)',
  },
  {
    name: 'Average',
    color: 'text-gray-400',
    bg: 'bg-gray-500/10 border-gray-500/20',
    description: 'Standard development curve. These players maintain their level but rarely make dramatic leaps. Reliable contributors who are what they are.',
    tradeMultiplier: '1.0x',
    offseasonGrowth: '0 to +2 OVR per year (age 26 and under)',
  },
  {
    name: 'Limited',
    color: 'text-red-400',
    bg: 'bg-red-500/10 border-red-500/20',
    description: 'Below-average growth potential. These players may stagnate or decline earlier. Their trade value takes a hit, but they can still fill a roster role.',
    tradeMultiplier: '0.80x',
    offseasonGrowth: '-1 to +1 OVR per year (age 26 and under)',
  },
];

interface AbilityInfo {
  name: string;
  description: string;
  positions: string[];
}

const EDGE_ABILITIES: AbilityInfo[] = [
  // QB
  { name: 'Cannon Arm', description: 'Unleashes throws with extreme velocity, allowing deep balls to arrive before defenders can react.', positions: ['QB'] },
  { name: 'Pocket Poise', description: 'Stays calm under heavy pressure, maintaining accuracy even when the pocket collapses.', positions: ['QB'] },
  { name: 'Moving Target', description: 'Delivers pinpoint passes while on the move, making the quarterback lethal outside the pocket.', positions: ['QB'] },
  { name: 'Quick Strike', description: 'Gets the ball out with lightning speed, exploiting defensive breakdowns before they recover.', positions: ['QB'] },
  { name: 'Signal Caller', description: 'Elite pre-snap reads allow this QB to identify and exploit defensive weaknesses consistently.', positions: ['QB'] },
  { name: 'Iron Will', description: 'Refuses to be rattled — bounces back from turnovers and bad plays with unwavering confidence.', positions: ['QB'] },
  { name: 'Precision Aim', description: 'Leads receivers perfectly into open space, maximizing yards after the catch on every throw.', positions: ['QB'] },
  // RB/FB
  { name: 'Clean Break', description: 'The first tackler rarely brings this runner down — exceptional ability to avoid initial contact.', positions: ['RB', 'FB'] },
  { name: 'Locomotive', description: 'Builds up unstoppable momentum — the longer the run, the harder he is to bring down.', positions: ['RB', 'FB'] },
  { name: 'Ankle Snap', description: 'Quick, devastating cuts that leave defenders grasping at air.', positions: ['RB', 'FB'] },
  { name: 'Battering Ram', description: 'Lowers the shoulder and punishes anyone in his path. Defenders think twice before stepping up.', positions: ['RB', 'FB'] },
  // WR
  { name: 'Uncoverable', description: 'Creates separation on every route. Double coverage is the only answer, and even that might not be enough.', positions: ['WR'] },
  { name: 'After the Catch', description: 'Turns short completions into big plays with elite vision and burst after securing the ball.', positions: ['WR'] },
  { name: 'Mismatch', description: 'Physically dominates any defensive back matched up in single coverage.', positions: ['WR', 'TE'] },
  { name: 'Yards After Contact', description: 'Fights through tackles and contact to consistently gain extra yardage after the catch.', positions: ['WR', 'TE'] },
  // TE
  { name: 'Slot Machine', description: 'Thrives when lined up in the slot, creating mismatches against linebackers and safeties.', positions: ['TE'] },
  // OL
  { name: 'Flattener', description: 'Delivers devastating blocks that put defenders on the ground — opens massive running lanes.', positions: ['OT', 'OG', 'C'] },
  { name: 'Brick Wall', description: 'Plants and holds ground with an immovable base. Pass rushers bounce off.', positions: ['OT', 'OG', 'C'] },
  { name: 'Immovable', description: 'An anchor so strong that even the most powerful bull rushes are absorbed and neutralized.', positions: ['OT', 'OG', 'C'] },
  { name: 'Marathon', description: 'Maintains peak blocking performance deep into the fourth quarter when others tire.', positions: ['OT', 'OG', 'C'] },
  // DL
  { name: 'Terror', description: 'Strikes fear into offensive linemen — they know the sack is coming, but can\'t stop it.', positions: ['DE', 'DT'] },
  { name: 'Juggernaut', description: 'An overwhelming force that no single blocker can contain. Demands double teams on every snap.', positions: ['DE', 'DT'] },
  { name: 'Gap Plugger', description: 'Fills rushing lanes with precision, shutting down the ground game at the point of attack.', positions: ['DE', 'DT', 'LB'] },
  { name: 'Relentless', description: 'Never takes a play off — pursues the ball carrier with elite effort from whistle to whistle.', positions: ['DE', 'DT'] },
  // LB
  { name: 'Cavalry', description: 'Always arrives at the right moment — provides critical support exactly where the defense needs it.', positions: ['LB', 'S'] },
  { name: 'Lockdown', description: 'Blankets any assignment in coverage, whether it\'s a tight end, running back, or receiver.', positions: ['LB', 'CB', 'S'] },
  { name: 'Heat Seeker', description: 'An elite blitzer who finds the fastest path to the quarterback on every rush.', positions: ['LB'] },
  // CB
  { name: 'Ball Hawk', description: 'Has a nose for the football — creates turnovers that change the course of games.', positions: ['CB', 'S'] },
  { name: 'High Wire', description: 'Makes acrobatic, highlight-reel plays on the ball that defy physics.', positions: ['CB'] },
  // S
  { name: 'Zone Ghost', description: 'Reads the quarterback\'s eyes and arrives at the catch point before the ball does.', positions: ['S'] },
  // K/P
  { name: 'Cold Blooded', description: 'Thrives in pressure situations — clutch kicks when the game is on the line.', positions: ['K'] },
  { name: 'Pin Drop', description: 'Consistently pins opponents deep with precise, directional punts inside the 10-yard line.', positions: ['P'] },
];

const INSTINCT_ABILITIES: AbilityInfo[] = [
  // QB
  { name: 'Run-Pass Read', description: 'Reads the defense while scrambling and delivers accurate throws on the move.', positions: ['QB'] },
  { name: 'Scramble Sense', description: 'Knows when to tuck and run, turning broken plays into positive gains.', positions: ['QB'] },
  { name: 'Pocket Precision', description: 'Delivers darts from a clean pocket with elite accuracy to all levels of the field.', positions: ['QB'] },
  { name: 'Plant & Fire', description: 'Sets his feet quickly and fires with maximum velocity, beating tight coverage windows.', positions: ['QB'] },
  { name: 'Boundary Accuracy', description: 'Places throws along the sideline with surgeon-like precision, keeping plays alive.', positions: ['QB'] },
  { name: 'Pocket Escape', description: 'Slips out of the pocket with elite awareness, avoiding sacks and extending plays.', positions: ['QB'] },
  { name: 'Audible Expert', description: 'Adjusts routes on the fly based on pre-snap reads, exploiting defensive alignments.', positions: ['QB'] },
  { name: 'Quick Release', description: 'Gets rid of the ball in a flash, neutralizing even the fastest pass rush.', positions: ['QB'] },
  { name: 'Rocket Arm', description: 'Throws with elite velocity, fitting balls into windows that other QBs can\'t.', positions: ['QB'] },
  { name: 'Hail Mary', description: 'Delivers deep bombs with accuracy when trailing late, giving receivers a fighting chance.', positions: ['QB'] },
  { name: 'No-Look Pass', description: 'Manipulates defenders with eye discipline, delivering passes to unexpected targets.', positions: ['QB'] },
  { name: 'Play Extender', description: 'Extends plays beyond the initial design, creating opportunities downfield.', positions: ['QB'] },
  // RB/FB
  { name: 'Stiff Arm Pro', description: 'A punishing stiff arm that sheds tacklers and creates extra yardage.', positions: ['RB', 'FB'] },
  { name: 'Receiving Threat', description: 'A dual-threat out of the backfield who creates mismatches in the passing game.', positions: ['RB', 'FB'] },
  { name: 'Pile Driver', description: 'Drives forward through contact, consistently falling forward for extra yards.', positions: ['RB', 'FB'] },
  { name: 'Ghost Runner', description: 'Elusive and difficult to square up — runs through traffic like a phantom.', positions: ['RB', 'FB'] },
  { name: 'Hip Shake', description: 'Devastating lateral quickness that freezes defenders in their tracks.', positions: ['RB', 'FB'] },
  { name: 'Tornado', description: 'A spin move so quick and violent that tacklers are left grabbing air.', positions: ['RB', 'FB'] },
  { name: 'Punisher', description: 'Punishes anyone who attempts a tackle with physicality and power.', positions: ['RB', 'FB', 'TE'] },
  { name: 'Extra Effort', description: 'Reaches for the first down marker or end zone with elite body control.', positions: ['RB', 'FB'] },
  // WR
  { name: 'High Wire', description: 'Makes acrobatic, circus catches that defy physics and leave defenders stunned.', positions: ['WR', 'TE', 'CB', 'S'] },
  { name: 'Deep Post', description: 'Runs the deep post route with elite precision, beating safeties over the top.', positions: ['WR'] },
  { name: 'Deep Corner', description: 'Masters the deep corner route, creating separation along the sideline.', positions: ['WR'] },
  { name: 'Snag & Go', description: 'Secures the catch and immediately accelerates upfield in one fluid motion.', positions: ['WR', 'TE'] },
  { name: 'Crossing Expert', description: 'Navigates traffic on crossing routes with fearless precision and timing.', positions: ['WR', 'TE'] },
  { name: 'Comeback King', description: 'Sharp comeback routes that create separation and give the QB a reliable target.', positions: ['WR'] },
  { name: 'After the Catch', description: 'Turns short completions into big gains with burst and vision after the catch.', positions: ['WR'] },
  { name: 'Route Surgeon', description: 'Runs routes with surgical precision — every break is crisp, every stem is threatening.', positions: ['WR', 'TE'] },
  { name: 'Slant Specialist', description: 'Quick off the line on slant routes, creating instant separation in the short game.', positions: ['WR'] },
  { name: 'Out Route Pro', description: 'Masters the out route with sharp cuts that create easy completions on the boundary.', positions: ['WR'] },
  { name: 'Slot Machine', description: 'Thrives in the slot, exploiting the middle of the field with quickness and savvy.', positions: ['WR', 'TE'] },
  { name: 'Burner', description: 'Pure deep speed that consistently gets behind the secondary for big plays.', positions: ['WR'] },
  // TE
  { name: 'Mismatch', description: 'Creates size/speed mismatches against any defender assigned in coverage.', positions: ['TE'] },
  { name: 'Mean Streak', description: 'Plays with an edge — finishes blocks aggressively and fights for every yard.', positions: ['TE', 'OT', 'OG', 'C'] },
  // OL
  { name: 'Brick Wall', description: 'Plants and holds ground — pass rushers bounce off this immovable blocker.', positions: ['OT', 'OG', 'C'] },
  { name: 'Sure Hands', description: 'Rarely loses grip on a block — provides consistent, reliable pass protection.', positions: ['OT', 'OG', 'C'] },
  { name: 'Edge Seal', description: 'Seals the edge on outside runs, preventing defensive ends from crashing inside.', positions: ['OT', 'OG', 'C'] },
  { name: 'Marathon', description: 'Stamina for days — maintains elite blocking effort deep into the fourth quarter.', positions: ['OT', 'OG', 'C'] },
  { name: 'Road Grader', description: 'Pulls from the guard position and flattens defenders at the second level.', positions: ['OT', 'OG', 'C'] },
  { name: 'Field General', description: 'Identifies blitzes and stunts pre-snap, organizing the offensive line.', positions: ['OT', 'OG', 'C'] },
  // DL
  { name: 'Speed Rush', description: 'Wins with pure speed off the edge, bending the corner and getting to the QB.', positions: ['DE', 'DT'] },
  { name: 'Interior Pressure', description: 'Collapses the pocket from the inside, disrupting timing and forcing errant throws.', positions: ['DE', 'DT'] },
  { name: 'Closer', description: 'Finishes sacks with authority — when he gets close, the quarterback goes down.', positions: ['DE', 'DT'] },
  { name: 'Fourth Quarter', description: 'Gets stronger as the game goes on, dominating tired offensive linemen late.', positions: ['DE', 'DT'] },
  { name: 'Swim Artist', description: 'A technically perfect swim move that leaves blockers reaching at air.', positions: ['DE', 'DT'] },
  { name: 'Power Drive', description: 'A violent bull rush that drives blockers backward into the quarterback\'s lap.', positions: ['DE', 'DT'] },
  { name: 'Gap Plugger', description: 'Fills rushing lanes with discipline, shutting down the run at the point of attack.', positions: ['DE', 'DT'] },
  // LB
  { name: 'Hard Hitter', description: 'Delivers bone-jarring hits that can jar the ball loose and punish ball carriers.', positions: ['LB', 'S'] },
  { name: 'Zone Reader', description: 'Reads the quarterback\'s eyes in zone coverage and jumps routes for interceptions.', positions: ['LB', 'S'] },
  { name: 'Blanket Coverage', description: 'Sticks to receivers like glue in coverage, limiting separation on any route.', positions: ['LB', 'CB', 'S'] },
  { name: 'Heat Seeker', description: 'Finds the fastest path to the quarterback when blitzing.', positions: ['LB'] },
  { name: 'Ball Stripper', description: 'Expert at punching the ball out — creates fumbles at a high rate.', positions: ['LB'] },
  { name: 'Overpowered', description: 'Physically overwhelms blockers at the point of attack with superior strength.', positions: ['LB'] },
  { name: 'Downhill', description: 'Attacks downhill against the run, filling gaps before the back can reach them.', positions: ['LB'] },
  // CB
  { name: 'Mirror Step', description: 'Mirrors the receiver\'s every move, staying in phase through every break and stem.', positions: ['CB'] },
  { name: 'Ball Hawk', description: 'Has a nose for the football — creates turnovers that swing momentum.', positions: ['CB', 'S'] },
  { name: 'Press Master', description: 'Disrupts receivers at the line of scrimmage with physical, aggressive press technique.', positions: ['CB'] },
  { name: 'Zone Buster', description: 'Reads routes and breaks on the ball with elite anticipation in zone coverage.', positions: ['CB', 'S'] },
  { name: 'Deep Patrol', description: 'Covers the deep third with elite range, taking away the big play.', positions: ['CB', 'S'] },
  // S
  { name: 'Sixth Sense', description: 'Anticipates plays before they develop, always in the right place at the right time.', positions: ['S'] },
  // K/P
  { name: 'Tunnel Vision', description: 'Blocks out all distractions — maintains focus and accuracy in hostile environments.', positions: ['K', 'P'] },
  { name: 'Ice Water', description: 'Performs best in the biggest moments — clutch when it matters most.', positions: ['K'] },
  { name: 'Pin Drop', description: 'Consistently places punts exactly where the coverage team needs them.', positions: ['P'] },
];

const BLUEPRINTS: Record<string, { positions: string[]; description: string; keyAttributes: string[] }> = {
  'Field General': { positions: ['QB', 'LB'], description: 'A cerebral leader who commands the offense/defense with elite awareness, pre-snap reads, and decision-making. Wins with his mind more than his arm or legs.', keyAttributes: ['Awareness', 'Play Action', 'Short Accuracy'] },
  'Improviser': { positions: ['QB'], description: 'A creative quarterback who thrives when plays break down, making magic happen outside the pocket with arm talent and instincts.', keyAttributes: ['Throw on the Run', 'Break Sack', 'Agility'] },
  'Scrambler': { positions: ['QB'], description: 'A dual-threat quarterback whose speed and elusiveness create constant headaches for the defense. A true run-pass threat.', keyAttributes: ['Speed', 'Acceleration', 'Agility'] },
  'Strong Arm': { positions: ['QB'], description: 'A quarterback who can make every throw on the field with elite arm strength, pushing the ball deep with authority.', keyAttributes: ['Throw Power', 'Deep Accuracy', 'Strength'] },
  'Elusive Back': { positions: ['RB'], description: 'A shifty runner who makes defenders miss in the open field with quick cuts, spins, and change of direction.', keyAttributes: ['Juke Move', 'Spin Move', 'Agility'] },
  'Power Back': { positions: ['RB'], description: 'A downhill runner who punishes tacklers and wears defenses down with physicality and power between the tackles.', keyAttributes: ['Trucking', 'Stiff Arm', 'Strength'] },
  'Receiving Back': { positions: ['RB'], description: 'A versatile back who is just as dangerous catching passes as he is running the ball, creating mismatches out of the backfield.', keyAttributes: ['Catching', 'Short Routes', 'Catch in Traffic'] },
  'Deep Threat': { positions: ['WR'], description: 'A speed demon who stretches the field vertically, forcing safeties to respect the deep ball on every snap.', keyAttributes: ['Deep Route Running', 'Spectacular Catch', 'Speed'] },
  'Slot': { positions: ['WR', 'CB'], description: 'A quick, savvy player who works the middle of the field from the slot, finding soft spots in zone coverage.', keyAttributes: ['Short Routes', 'Medium Routes', 'Change of Direction'] },
  'Physical': { positions: ['WR'], description: 'A big-bodied receiver who wins contested catches, breaks tackles after the catch, and bullies smaller defenders.', keyAttributes: ['Catch in Traffic', 'Strength', 'Break Tackle'] },
  'Possession': { positions: ['WR', 'TE'], description: 'The reliable chain-mover who catches everything thrown his way and converts on third downs with consistency.', keyAttributes: ['Catching', 'Catch in Traffic', 'Short Routes'] },
  'Vertical Threat': { positions: ['TE'], description: 'A tight end who can stretch the field like a wide receiver, creating matchup nightmares against linebackers and safeties.', keyAttributes: ['Speed', 'Deep Routes', 'Spectacular Catch'] },
  'Blocking': { positions: ['TE', 'FB'], description: 'A physical blocker first and foremost, this player excels at creating running lanes and protecting the quarterback.', keyAttributes: ['Run Block', 'Pass Block', 'Impact Blocking'] },
  'Utility': { positions: ['FB'], description: 'A versatile fullback who can block, catch, and carry — a Swiss Army knife in the offensive scheme.', keyAttributes: ['Catching', 'BC Vision', 'Speed'] },
  'Power': { positions: ['OT', 'OG', 'C', 'K', 'P'], description: 'Relies on raw strength and physicality to dominate. For linemen: mauling run blockers. For kickers: maximum distance.', keyAttributes: ['Strength', 'Run Block Power', 'Pass Block Power'] },
  'Agile': { positions: ['OT', 'OG', 'C'], description: 'A light-footed lineman who excels in pass protection with lateral movement and quick recovery.', keyAttributes: ['Agility', 'Acceleration', 'Pass Block Finesse'] },
  'Pass Protector': { positions: ['OT', 'OG', 'C'], description: 'A technician in pass protection who keeps the quarterback clean with elite footwork and hand placement.', keyAttributes: ['Pass Block', 'Pass Block Finesse', 'Pass Block Power'] },
  'Accurate': { positions: ['K', 'P'], description: 'Prioritizes precision over power. Consistently hits the target with accuracy that makes up for moderate distance.', keyAttributes: ['Kick Accuracy'] },
  'Speed Rusher': { positions: ['DE', 'DT'], description: 'Wins with quickness off the snap, bending the corner with elite burst and closing speed to get to the quarterback.', keyAttributes: ['Speed', 'Finesse Moves', 'Acceleration'] },
  'Power Rusher': { positions: ['DE', 'DT'], description: 'Overwhelms blockers with brute force, using a bull rush and power moves to collapse the pocket.', keyAttributes: ['Power Moves', 'Strength', 'Block Shedding'] },
  'Run Stopper': { positions: ['DE', 'DT', 'LB'], description: 'An anchor against the run who holds the point of attack, fills gaps, and makes tackles behind the line.', keyAttributes: ['Block Shedding', 'Tackle', 'Play Recognition'] },
  'Pass Coverage': { positions: ['LB'], description: 'A rangey linebacker who drops into coverage effectively, matching up with tight ends and running backs.', keyAttributes: ['Zone Coverage', 'Man Coverage', 'Speed'] },
  'Man to Man': { positions: ['CB'], description: 'A press corner who thrives in man-to-man coverage, using physicality and technique to shadow receivers.', keyAttributes: ['Man Coverage', 'Press', 'Play Recognition'] },
  'Zone': { positions: ['CB', 'S'], description: 'Reads the quarterback and routes from zone coverage, breaking on the ball with elite anticipation.', keyAttributes: ['Zone Coverage', 'Play Recognition', 'Pursuit'] },
  'Hybrid': { positions: ['S'], description: 'A versatile safety who can play in the box, cover slot receivers, or roam center field — the ultimate chess piece.', keyAttributes: ['Man Coverage', 'Zone Coverage', 'Agility'] },
  'Run Support': { positions: ['S'], description: 'A safety who plays downhill, supporting the run game with physicality and arriving with bad intentions.', keyAttributes: ['Tackle', 'Hit Power', 'Block Shedding'] },
};

// ─── Component ───────────────────────────────────────────────────────────────

export default function Glossary() {
  const navigate = useNavigate();
  const [search, setSearch] = useState('');

  const filterBySearch = (name: string, description: string) => {
    if (!search) return true;
    const q = search.toLowerCase();
    return name.toLowerCase().includes(q) || description.toLowerCase().includes(q);
  };

  return (
    <div className="mx-auto max-w-5xl">
      {/* Back */}
      <button
        onClick={() => navigate(-1)}
        className="mb-4 flex items-center gap-1.5 text-sm text-[var(--text-muted)] transition-colors hover:text-[var(--text-primary)]"
      >
        <ArrowLeft className="h-4 w-4" />
        Back
      </button>

      {/* Header */}
      <div className="mb-6">
        <h1 className="font-display text-3xl tracking-tight">Player Glossary</h1>
        <p className="mt-1 text-sm text-[var(--text-secondary)]">
          Everything you need to know about player Edges, Instincts, Blueprints, and Development tiers.
        </p>
      </div>

      {/* Search */}
      <div className="relative mb-6">
        <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-[var(--text-muted)]" />
        <input
          type="text"
          placeholder="Search abilities, blueprints..."
          value={search}
          onChange={(e) => setSearch(e.target.value)}
          className="w-full rounded-lg border border-[var(--border)] bg-[var(--bg-surface)] py-2.5 pl-10 pr-4 text-sm text-[var(--text-primary)] placeholder:text-[var(--text-muted)] focus:border-[var(--accent-blue)] focus:outline-none focus:ring-1 focus:ring-[var(--accent-blue)]"
        />
      </div>

      <Tabs defaultValue="edges">
        <div className="border-b border-white/5 bg-[var(--bg-surface)] rounded-t-xl">
          <TabsList variant="line" className="h-auto gap-0 rounded-none bg-transparent px-4">
            <TabsTrigger value="edges" className="rounded-none px-5 py-3 text-xs font-semibold uppercase tracking-wider">
              <Zap className="mr-1.5 h-3.5 w-3.5" /> Edges
            </TabsTrigger>
            <TabsTrigger value="instincts" className="rounded-none px-5 py-3 text-xs font-semibold uppercase tracking-wider">
              <Brain className="mr-1.5 h-3.5 w-3.5" /> Instincts
            </TabsTrigger>
            <TabsTrigger value="blueprints" className="rounded-none px-5 py-3 text-xs font-semibold uppercase tracking-wider">
              <Compass className="mr-1.5 h-3.5 w-3.5" /> Blueprints
            </TabsTrigger>
            <TabsTrigger value="development" className="rounded-none px-5 py-3 text-xs font-semibold uppercase tracking-wider">
              <TrendingUp className="mr-1.5 h-3.5 w-3.5" /> Development
            </TabsTrigger>
          </TabsList>
        </div>

        {/* ═══ EDGES ═══ */}
        <TabsContent value="edges" className="mt-0">
          <div className="rounded-b-xl bg-[var(--bg-surface)] p-6">
            <div className="mb-4">
              <h2 className="font-display text-lg uppercase tracking-wider">Edge Abilities</h2>
              <p className="mt-1 text-xs text-[var(--text-secondary)]">
                The defining trait of elite players (90+ OVR). Each player can have at most one Edge — it represents what makes them truly special.
              </p>
            </div>
            <div className="space-y-3">
              {EDGE_ABILITIES.filter(a => filterBySearch(a.name, a.description)).map((ability) => (
                <div key={ability.name} className="rounded-lg border border-yellow-500/10 bg-yellow-500/[0.03] p-4">
                  <div className="flex items-start justify-between gap-4">
                    <div>
                      <div className="flex items-center gap-2">
                        <Zap className="h-4 w-4 text-yellow-400" />
                        <h3 className="font-semibold text-yellow-400">{ability.name}</h3>
                      </div>
                      <p className="mt-1.5 text-sm leading-relaxed text-[var(--text-secondary)]">{ability.description}</p>
                    </div>
                    <div className="flex shrink-0 flex-wrap justify-end gap-1">
                      {ability.positions.map(pos => (
                        <Badge key={pos} variant="outline" className="text-[10px]">{pos}</Badge>
                      ))}
                    </div>
                  </div>
                </div>
              ))}
            </div>
          </div>
        </TabsContent>

        {/* ═══ INSTINCTS ═══ */}
        <TabsContent value="instincts" className="mt-0">
          <div className="rounded-b-xl bg-[var(--bg-surface)] p-6">
            <div className="mb-4">
              <h2 className="font-display text-lg uppercase tracking-wider">Instincts</h2>
              <p className="mt-1 text-xs text-[var(--text-secondary)]">
                Specific skills that go beyond raw ratings. Players rated 80+ OVR can earn 1-3 Instincts that give them an advantage in certain situations.
              </p>
            </div>
            <div className="space-y-3">
              {INSTINCT_ABILITIES.filter(a => filterBySearch(a.name, a.description)).map((ability) => (
                <div key={ability.name + ability.positions.join()} className="rounded-lg border border-purple-500/10 bg-purple-500/[0.03] p-4">
                  <div className="flex items-start justify-between gap-4">
                    <div>
                      <div className="flex items-center gap-2">
                        <Brain className="h-4 w-4 text-purple-400" />
                        <h3 className="font-semibold text-purple-400">{ability.name}</h3>
                      </div>
                      <p className="mt-1.5 text-sm leading-relaxed text-[var(--text-secondary)]">{ability.description}</p>
                    </div>
                    <div className="flex shrink-0 flex-wrap justify-end gap-1">
                      {ability.positions.map(pos => (
                        <Badge key={pos} variant="outline" className="text-[10px]">{pos}</Badge>
                      ))}
                    </div>
                  </div>
                </div>
              ))}
            </div>
          </div>
        </TabsContent>

        {/* ═══ BLUEPRINTS ═══ */}
        <TabsContent value="blueprints" className="mt-0">
          <div className="rounded-b-xl bg-[var(--bg-surface)] p-6">
            <div className="mb-4">
              <h2 className="font-display text-lg uppercase tracking-wider">Blueprints</h2>
              <p className="mt-1 text-xs text-[var(--text-secondary)]">
                A player's Blueprint defines their play style and determines which attributes are emphasized. It shapes how they perform on the field.
              </p>
            </div>
            <div className="space-y-3">
              {Object.entries(BLUEPRINTS).filter(([name, info]) => filterBySearch(name, info.description)).map(([name, info]) => (
                <div key={name} className="rounded-lg border border-[var(--accent-blue)]/10 bg-[var(--accent-blue)]/[0.03] p-4">
                  <div className="flex items-start justify-between gap-4">
                    <div>
                      <div className="flex items-center gap-2">
                        <Compass className="h-4 w-4 text-[var(--accent-blue)]" />
                        <h3 className="font-semibold text-[var(--accent-blue)]">{name}</h3>
                      </div>
                      <p className="mt-1.5 text-sm leading-relaxed text-[var(--text-secondary)]">{info.description}</p>
                      <div className="mt-2 flex flex-wrap gap-1.5">
                        <span className="text-[10px] font-semibold uppercase tracking-wider text-[var(--text-muted)]">Key Attributes:</span>
                        {info.keyAttributes.map(attr => (
                          <span key={attr} className="text-[10px] font-medium text-[var(--text-secondary)]">{attr}</span>
                        ))}
                      </div>
                    </div>
                    <div className="flex shrink-0 flex-wrap justify-end gap-1">
                      {info.positions.map(pos => (
                        <Badge key={pos} variant="outline" className="text-[10px]">{pos}</Badge>
                      ))}
                    </div>
                  </div>
                </div>
              ))}
            </div>
          </div>
        </TabsContent>

        {/* ═══ DEVELOPMENT ═══ */}
        <TabsContent value="development" className="mt-0">
          <div className="rounded-b-xl bg-[var(--bg-surface)] p-6">
            <div className="mb-4">
              <h2 className="font-display text-lg uppercase tracking-wider">Development Tiers</h2>
              <p className="mt-1 text-xs text-[var(--text-secondary)]">
                A player's Development tier determines how quickly they improve during the offseason and how they're valued in trades.
                Younger players are more likely to have higher tiers, while veterans tend to plateau or decline.
              </p>
            </div>
            <div className="space-y-4">
              {DEVELOPMENT_TIERS.map((tier) => (
                <div key={tier.name} className={`rounded-lg border p-5 ${tier.bg}`}>
                  <div className="flex items-start gap-4">
                    <TrendingUp className={`mt-0.5 h-5 w-5 shrink-0 ${tier.color}`} />
                    <div className="flex-1">
                      <h3 className={`font-display text-xl ${tier.color}`}>{tier.name}</h3>
                      <p className="mt-1 text-sm leading-relaxed text-[var(--text-secondary)]">{tier.description}</p>
                      <div className="mt-3 grid gap-2 sm:grid-cols-2">
                        <div className="rounded bg-black/20 p-2.5">
                          <p className="text-[10px] font-semibold uppercase tracking-wider text-[var(--text-muted)]">Trade Value Multiplier</p>
                          <p className="font-display text-lg">{tier.tradeMultiplier}</p>
                        </div>
                        <div className="rounded bg-black/20 p-2.5">
                          <p className="text-[10px] font-semibold uppercase tracking-wider text-[var(--text-muted)]">Offseason Growth</p>
                          <p className="text-sm font-medium">{tier.offseasonGrowth}</p>
                        </div>
                      </div>
                    </div>
                  </div>
                </div>
              ))}

              {/* Age & Development Chart */}
              <div className="mt-6 rounded-lg border border-white/5 bg-[var(--bg-elevated)] p-5">
                <h3 className="font-display text-sm uppercase tracking-wider text-[var(--text-muted)] mb-3">Age & Rating Progression</h3>
                <div className="space-y-2 text-sm">
                  <div className="flex justify-between">
                    <span className="text-[var(--text-secondary)]">Age 22-26</span>
                    <span className="font-semibold text-green-400">Growth Phase — ratings improve based on Development tier</span>
                  </div>
                  <div className="flex justify-between">
                    <span className="text-[var(--text-secondary)]">Age 27-29</span>
                    <span className="font-semibold text-yellow-400">Prime Years — ratings hold steady (-1 to +1)</span>
                  </div>
                  <div className="flex justify-between">
                    <span className="text-[var(--text-secondary)]">Age 30-32</span>
                    <span className="font-semibold text-orange-400">Early Decline — slight regression begins (-2 to 0)</span>
                  </div>
                  <div className="flex justify-between">
                    <span className="text-[var(--text-secondary)]">Age 33-34</span>
                    <span className="font-semibold text-red-400">Decline — noticeable drop-off (-4 to -1)</span>
                  </div>
                  <div className="flex justify-between">
                    <span className="text-[var(--text-secondary)]">Age 35+</span>
                    <span className="font-semibold text-red-500">Late Career — steep decline (-5 to -2) + retirement risk</span>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </TabsContent>
      </Tabs>
    </div>
  );
}
