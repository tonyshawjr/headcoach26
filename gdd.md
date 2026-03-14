# Head Coach 26
## Game Design Document v1.1
**Port City Pixel · March 2026 · Confidential**

---

## Table of Contents
1. [Game Overview](#1-game-overview)
2. [The Player's Role — Head Coach](#2-the-players-role--head-coach)
3. [Season Structure](#3-season-structure)
4. [Game Simulation Engine](#4-game-simulation-engine)
5. [Roster Management](#5-roster-management)
6. [Trades & Free Agency](#6-trades--free-agency)
7. [The Draft](#7-the-draft)
8. [Coaching Staff](#8-coaching-staff)
9. [Narrative Engine & Media Universe](#9-narrative-engine--media-universe)
10. [AI Coaches](#10-ai-coaches)
11. [Multiplayer League](#11-multiplayer-league)
12. [Technical Architecture](#12-technical-architecture)
13. [UI & Design Specification](#13-ui--design-specification)
14. [Monetization](#14-monetization)
15. [Build Phases](#15-build-phases)

---

## 1. Game Overview

### 1.1 Concept Statement

Head Coach 26 is a self-hosted, narrative-driven football head coach simulation. Players take the role of a head coach in a fictional 32-team professional football league. The game blends strategic management (rosters, trades, game planning) with deep narrative immersion — dynamic news coverage styled after ESPN and NFL Network, press conferences, player relationships, and locker room drama — to create a world that feels alive whether you are actively playing or simply checking the league feed.

Unlike traditional sports sims that hand the player total control, Head Coach 26 puts you in the specific role of head coach — giving you real authority over what coaches control, and realistic friction with the front office over everything else.

> 📌 The name "Head Coach 26" is a deliberate spiritual successor to EA's NFL Head Coach series (2006, 2009). "Head Coach" is a generic descriptive term with no active trademark. The NFL brand is not used anywhere in the game.

### 1.2 Core Design Pillars

- **Narrative First** — every game produces journalism, social media, player arcs, and radio segments. The world tells stories.
- **Earned Authority** — influence with the owner and front office is a resource you build and spend, not a given.
- **Self-Hosted & Multiplayer** — commissioner installs on their server; up to 32 friends each coach a team asynchronously.
- **ESPN/NFL Network Aesthetic** — the entire UI looks and feels like a premium sports broadcast network and its website.
- **AI-Powered World (optional)** — Claude API drives dynamic journalism, AI coach personalities, and social media.
- **Long-Term Dynasty** — multiple seasons, player aging, legacy tracking, hall of fame.

### 1.3 Platform & Distribution

- Platform: PHP web application — runs in any modern browser, no download required for players
- Server: Standard cPanel shared hosting with MySQL or SQLite
- Install: One-click installer — download zip, upload to server, visit `/install`, done
- Distribution: One-time purchase, self-hosted by buyer. No ongoing hosting fees for Port City Pixel.
- Multiple leagues per install — solo vs. AI, friends league, or mixed

---

## 2. The Player's Role — Head Coach

### 2.1 What You Are

You are the Head Coach. Not the GM. Not the owner. You call plays via game plan selection, manage the locker room, run press conferences, develop your coaching staff, and influence (but do not control) roster decisions. This creates the game's central tension.

### 2.2 The Influence System

Influence (0–100) is your most important resource. It determines how much sway you have over the front office on roster moves, contract negotiations, and draft strategy.

| Range | Level | What You Can Do |
|-------|-------|-----------------|
| 0–39 | Low | Owner overrides depth chart. Trade requests ignored. Staff budget fixed. |
| 40–69 | Medium | Owner hears trade requests, may decline. Coordinator hire/fire within budget. |
| 70–89 | High | Most trades go through. Draft strategy input taken seriously. |
| 90–100 | Elite | Near-GM authority. Owner defers on almost everything. |

**Influence Gains:**
- Wins, especially vs. strong opponents or on the road
- Meeting or exceeding owner's season expectations
- Positive press conference performances
- Locker room stability and team morale
- Developing young players who break out

**Influence Losses:**
- Losing streaks, especially at home
- Locker room drama going public
- Negative or evasive press conference moments
- Missing owner expectations at mid-season check-in

### 2.3 Job Security & Career

- **Job Security Meter** always visible — red/yellow/green bar tied to owner confidence
- Hitting zero = fired. Career continues with another team; legacy score carries forward.
- Being fired is not game over — it's a new chapter
- **Contract system** — years, salary, performance clauses. Other teams can poach you.
- **Legacy Score** — career-spanning stat: wins, championships, player development, press reputation
- **Hall of Fame** eligibility after career ends based on legacy score

---

## 3. Season Structure

### 3.1 Season Calendar

| Phase | Timing |
|-------|--------|
| Preseason | Weeks 1–3: Roster battles, depth chart competition, rookie evaluation |
| Regular Season | Weeks 1–18: Full schedule — divisional and conference games |
| Trade Deadline | Week 9 — no trades after this point |
| Playoffs | Wild Card → Divisional → Conference Championship → Championship |
| Offseason | Free agency, draft, training camp, contract extensions |

### 3.2 Weekly Loop

1. **Monday** — Post-game news cycle: recaps, grades, social media, radio segment
2. **Tue–Wed** — Injury report, depth chart adjustments, trade activity window
3. **Thursday** — Opponent scouting report released, game plan selection opens
4. **Friday** — Pre-game press conference
5. **Sat–Sun** — Game simulation runs (commissioner controls timing in multiplayer)
6. **Sunday Night** — Post-game press conference, box score, news cycle restarts

### 3.3 Dynasty Play

- Player aging, regression, and retirement events across seasons
- Coaching carousel — AI coaches get fired and hired between seasons
- Historical records tracked permanently — stats, champions, records
- Hall of Fame unlocks after sufficient seasons have passed

---

## 4. Game Simulation Engine

### 4.1 Philosophy

You never play games in real time. You prepare for them. The simulation engine resolves games based on player ratings, game plan, injuries, home field, weather, and coaching ratings. Outcomes feel earned, not random.

### 4.2 Game Plan System

**Offensive Schemes:** Run-heavy · Balanced · Pass-heavy · No-huddle · Ball-control

**Defensive Schemes:** Base 4-3 · 3-4 · Aggressive blitz · Prevent · Zone-heavy

**Special Emphasis:** Exploit identified opponent weakness from scouting report

Each scheme has strengths and counters — there is no dominant strategy. OC and DC ratings influence how well the selected scheme executes.

### 4.3 Simulation Factors

- Player ratings (overall + positional attributes)
- Injury status of key players
- Team morale and chemistry rating
- Home field advantage modifier
- Opponent scouting accuracy bonus
- Weather conditions (rain/wind affects passing game)
- Coordinator ratings applied per unit

### 4.4 Post-Game Output

- Full box score — passing, rushing, receiving, defense, special teams
- Player grades A–F for key contributors
- Turning point moment — the key play, narrated in the news feed
- Injury updates — severity and timeline
- Full simulation data fed to narrative engine for all media content generation

---

## 5. Roster Management

### 5.1 Structure

- 53-man active roster + 10-man practice squad
- Injured Reserve (IR) — minimum 4-week stay, frees a roster spot
- Depth chart fully editable via drag-and-drop UI

### 5.2 Player Attributes

| Attribute | Details |
|-----------|---------|
| Overall Rating | 0–99 scale |
| Positional Ratings | Speed, Strength, Awareness, Route Running, Tackling, etc. (position-specific) |
| Age | Drives development arc and regression timeline |
| Contract | Years remaining, salary, cap hit |
| Morale | Happy / Content / Unhappy / Demanding Trade |
| Personality | Vocal Leader · Quiet Professional · Troublemaker · Competitor · Team Player |
| Development | Superstar · Star · Normal · Slow — affects rating progression speed |

### 5.3 Roster Upload

- **Default:** Procedurally generated fictional players with realistic rating distributions
- **Roster Upload:** CSV/JSON import converts entire league to real NFL players and ratings
- Recommended source: community-maintained Madden ratings exports
- Upload is fully reversible — fictional player data preserved and restorable at any time

### 5.4 Injuries & Morale

**Injury Tiers:**
- Day-to-Day (1 week)
- Short-Term (2–4 weeks)
- Long-Term (4–8 weeks)
- Season-Ending

Injury history is tracked — repeated injuries to the same body part increase re-injury risk. Morale spreads — one unhappy star can affect teammates if not addressed. All morale events trigger narrative content and social media reactions.

---

## 6. Trades & Free Agency

- Propose trades to any team (human or AI) — AI evaluates based on positional need, cap space, personality archetype
- **Trade Block** — list players to signal availability to the league
- **Counter-offers** — AI coaches can counter rather than flat accept/reject
- Trade deadline at Week 9 — no trades after this
- Owner approval required for blockbuster trades when influence is below 60
- Future draft picks tradeable up to 3 years out
- **Waiver Wire** — 72-hour window before released players become free agents
- **Free agency bidding window** in offseason — AI teams compete for top players
- Cap management: cutting players incurs dead cap consequences

---

## 7. The Draft

- Annual 7-round draft in reverse standings order
- Draft class generated each offseason with initially hidden ratings
- **Scouting system** — assign scouts to positions to progressively reveal ratings
- Combine results partially reveal athletic potential
- **Draft board** — build and reorder your personal prospect rankings
- Trade up/down during the live draft
- On-the-clock timer in multiplayer (commissioner sets pick time limit)
- AI-generated scout reports and mock draft articles published in news feed leading up to draft day
- Immediate media grades after each round from the narrative engine
- Rookies have accelerated development potential in years 1–3

---

## 8. Coaching Staff

| Role | Impact |
|------|--------|
| Offensive Coordinator (OC) | Scheme execution, QB development |
| Defensive Coordinator (DC) | Scheme execution, pass rush, coverage |
| Special Teams Coordinator (STC) | Kicking game, return game |
| Player Development Coach | Overall rating growth per season |

- Each coordinator has an Overall rating + two specialty ratings
- Hot coordinators get poached by AI teams — compete to retain your staff
- Firing a coordinator mid-season costs influence and player morale
- Coordinators improve with strong unit performance each season
- Coordinator-to-HC pipeline generates media storylines (raises their profile)

---

## 9. Narrative Engine & Media Universe

### 9.1 Overview

The media universe is a parallel layer that runs alongside all game systems. It transforms raw game data — scores, stats, injuries, trades, press conference answers — into immersive journalism, social media, and radio content. This is the feature that makes Head Coach 26 feel like a living world rather than a spreadsheet.

> 📌 All UI in the media universe is styled after ESPN.com and NFL Network — dark theme, bold typography, broadcast-quality data presentation. This is the visual heart of the game.

### 9.2 The League Hub — In-Game Website

A dedicated section of the UI that looks and feels like NFL.com meets ESPN. All generated media content lives here.

- **GridironInsider.com** — the fictional national network covering all 32 teams
- Local beat pages — one per team, team-branded, team-specific writers
- Breaking news ticker scrolling across the top of every screen
- Full article archive — every story ever generated, browsable by team/player/week
- Stats leaderboards updated after every game sim
- Power rankings published weekly with written narrative justification

### 9.3 Content Types

#### Game Recaps
- Full ESPN-style article after every game in the league
- Headline, lede, key moments, player quotes, turning point, coach reaction
- Box score embedded in article
- Both teams covered — local beat angle + national angle

#### Feature Stories
- Triggered by in-game events — comeback, injury return, rookie explosion, veteran farewell season
- You get to know your own players through these stories
- Rival players get profiled too — you learn who you're facing before you play them
- Example headlines: *"The Redemption of Marcus Webb"* / *"Can Anyone Stop the Vipers?"*

#### Power Rankings
- Published every Tuesday with a narrative paragraph for each team
- Your ranking and the written justification directly affect owner confidence

#### Columnist Personas
| Writer | Personality |
|--------|-------------|
| Terry Hollis | National hot-take columnist. Always has an angle. Often buries your team. |
| Dana Reeves | Analytical, process-oriented. Respects the build. |
| Marcus Bell | Former player. Emotional. Champions underdog stories. |
| Local Beat Writer (per team) | Deeply informed. Slightly homer. Knows your team's history. |

Columnists reference your press conference answers — what you say matters. Columnists disagree with each other publicly, creating drama in the feed.

#### GridironX — In-Game Social Media
- Fictional Twitter/X-style feed, styled as **GridironX**
- Players post after games — hype, frustration, subtle shade
- Fan accounts react with humor and outrage
- Rival coach statements generated from their AI personality
- Your press conference clips get quote-posted with hot takes
- Trending topics sidebar shows what the league is discussing this week

#### The Morning Blitz — Radio Segment
- Text-based sports radio transcript published every Monday morning
- Two fictional hosts with opposing personalities debating last week's games
- Your team gets discussed — sometimes favorably, sometimes not
- Formatted as a readable dialogue with host names and timestamps

#### Injury Report Wire
- Official report styled after the real NFL injury report format
- AI-written speculation: *"Sources close to the team say..."*

#### Press Conferences
- Pre-game and post-game, every week
- Multiple choice dialogue — 3 options per question
- Tone options: confident · humble · deflective · combative · diplomatic
- Questions generated based on current news — reporters ask about injuries, streaks, trade rumors
- Answers logged in `press-conference-log.md` and referenced by columnists afterward
- **Media Rating (0–100)** tracks your public reputation across the season

### 9.4 Narrative Memory System

The engine maintains memory across the full season and across seasons via markdown files stored per league:

```
/storage/leagues/league_001/seasons/2026/
  narrative-arcs.md         ← running story threads
  player-stories.md         ← individual player moments
  team-storylines.md        ← each franchise's arc
  weekly-recap-week-XX.md   ← permanent record per game
  press-conference-log.md   ← your answers and consequences
```

> 📌 A player who demanded a trade in Week 3 and caught the game-winner in Week 9 gets a redemption arc article — because both events are in memory. This is not scripted. It just happens.

### 9.5 Scripted vs. AI Tier

#### Scripted Tier (Base Game — No API Key Required)
- Template-based stories with variable injection (names, stats, scores)
- All content types present, all screens fully populated
- Stories are readable and functional — less variety over time

#### AI Media Pack (Claude API — Optional)
- Every content piece prompts Claude with full game context + memory files
- Unique articles every time — different angles, different tones, genuine surprise
- Columnist personas feel distinct and consistent across the full season
- User provides their own Anthropic API key in league settings
- Estimated API cost: ~$2–5 per full season playthrough

---

## 10. AI Coaches

### 10.1 Personality Archetypes

| Archetype | Behavior |
|-----------|----------|
| The Rebuilder | Trades veterans for picks, starts young players, patient with losses |
| Win-Now | Aggressive trades for stars, mortgages future, low patience for losing |
| The Conservative | Rarely makes bold moves, values stability, hard to trade with |
| The Gambler | Unpredictable, high variance, beats anyone on a given day |
| The Developer | Prioritizes young talent, produces breakout stars, trades from depth |

### 10.2 Scripted AI Behavior

- Trade logic based on positional need score, win probability, player age, cap space
- Free agency targets scheme fit — each archetype overpays for different things
- Game plan reflects personality archetype

### 10.3 AI Coach Pack (Claude API)

- Each AI coach has a name, backstory, and personality prompt fed to Claude
- Trade offers include AI-generated reasoning in natural language
- Rival coach quotes appear in the news feed and GridironX
- Post-game press conference quotes from other coaches generated by AI

---

## 11. Multiplayer League

- Commissioner installs the game, creates a league, fills empty teams with AI coaches
- **Invite system** — link-based, friends claim a team, no central account system needed
- Up to 32 human coaches; any remaining teams run by AI
- Fully async — no real-time requirement, everyone plays on their own schedule
- Commissioner controls sim timing — manual trigger or scheduled
- Trade proposals sent between coaches with notification system
- **Commissioner tools:** sim control, trade review, force-advance, edit mode, league message board
- League history saved indefinitely — standings, stats, dynasty champions, records

---

## 12. Technical Architecture

### 12.1 Full Stack

| Layer | Technology | Purpose |
|-------|-----------|---------|
| Backend | PHP 8.x | Game logic, simulation engine, REST API, installer |
| Frontend | React (SPA) | All UI — dashboards, news feed, press conferences |
| Styling | Tailwind CSS + shadcn/ui | Design system and component library |
| Animation | Framer Motion | Page transitions, feed reveals, ticker, modals |
| Data Viz | Recharts | Box scores, rating bars, season performance charts |
| State | Zustand | Lightweight global state management |
| Data Fetching | React Query | Keeps feeds and dashboards fresh without reloads |
| Real-time | Ratchet (PHP WebSockets) | Multiplayer notifications, trade alerts |
| Database | MySQL or SQLite | Game data, league state, narrative tables |
| AI (optional) | Anthropic Claude API | AI media content, AI coach personalities |

> ✅ Every library in this stack is 100% free and open source. Zero ongoing licensing fees — ever.

### 12.2 One-Click Installer

1. Download zip from purchase page
2. Upload to cPanel via File Manager or FTP
3. Visit `/install` in browser
4. Enter: DB host, name, username, password, admin credentials, league name
5. Installer creates all tables, seeds default data, removes `/install` directory
6. Game loads at root URL — done

- Auto-detects MySQL vs. SQLite based on credentials provided
- Install validator confirms setup before committing

### 12.3 Database Schema Overview

**Core Tables:**
`leagues` · `teams` · `coaches` · `players` · `seasons` · `games` · `trades` · `contracts` · `injuries` · `draft_picks` · `draft_classes` · `free_agents`

**Narrative Tables:**
`articles` · `social_posts` · `press_conferences` · `narrative_arcs` · `media_ratings`

**Career Tables:**
`coach_history` · `legacy_scores` · `hall_of_fame` · `season_awards`

### 12.4 File Structure

```
/app               — PHP backend (controllers, models, sim engine, API handlers)
/public            — React frontend build output
/storage/leagues   — per-league markdown narrative files
/storage/uploads   — roster CSV/JSON import files
/install           — installer wizard (auto-removed after install completes)
/config            — DB config, API keys (gitignored)
```

### 12.5 Roster Upload Spec

- **Formats accepted:** CSV or JSON
- **Required fields:** `player_name`, `position`, `overall_rating`, `age`, `team`
- **Optional fields:** individual positional ratings, college, years_pro
- Validation report shown before committing — errors flagged per row
- Upload reversible — fictional player data preserved and restorable

---

## 13. UI & Design Specification

> 📌 This section is the visual bible for Head Coach 26. Every screen must feel like a premium sports broadcast network — ESPN, NFL Network, and The Athletic combined into one application. This is non-negotiable.

### 13.1 Design Identity

The overarching aesthetic is: **Premium Sports Broadcast Network.**

Dark, authoritative, data-rich, with bold typography and team color accents. Think NFL Network's dark studio set meets ESPN.com's content density meets The Athletic's editorial typography. Clean enough to communicate data at a glance. Bold enough to feel like a premium product.

### 13.2 Color System

**Global Palette (CSS Custom Properties):**

```css
--bg-primary:    #0D1117   /* near-black base — every screen */
--bg-surface:    #161B22   /* card backgrounds, panels, sidebars */
--bg-elevated:   #21262D   /* modals, dropdowns, hover states */
--border:        #30363D   /* all dividers and borders */
--text-primary:  #F0F6FC   /* main text */
--text-secondary: #8B949E  /* labels, timestamps, metadata */
--text-muted:    #484F58   /* placeholder text, disabled states */
--accent-red:    #E3342F   /* breaking news, alerts, declining stats */
--accent-gold:   #D4A017   /* MVP callouts, featured stories, championships */
--accent-blue:   #2188FF   /* links, interactive elements, data highlights */
```

**Team Color Theming:**

Each team stores `primary_color` and `secondary_color` hex values in the database. When a user views their team dashboard, the entire UI accent shifts to their team color via CSS variable injection:

```javascript
document.documentElement.style.setProperty('--team-primary', teamColor);
```

Team color used on: header bar, sidebar highlights, player card accents, stat bars. This is the same technique NFL.com and ESPN team pages use — it makes every team feel distinct.

### 13.3 Typography

| Role | Font | Source | Usage |
|------|------|--------|-------|
| Display / Headlines | **Oswald 700** | Google Fonts (free) | H1 headlines, scores, player names in feature cards |
| Body / UI | **Inter** | Google Fonts (free) | Article body text, UI labels, form fields |
| Data / Stats | **IBM Plex Mono** | Google Fonts (free) | Box scores, ratings, stat tables |

```html
<!-- Single import, CDN-delivered, zero cost -->
<link href="https://fonts.googleapis.com/css2?family=Oswald:wght@700&family=Inter:wght@400;500;600&family=IBM+Plex+Mono&display=swap" rel="stylesheet">
```

Score displays and key numbers use Oswald at large sizes — they dominate their context.

### 13.4 Layout System

**Global Shell:**
- **Top navbar:** Logo (left) · Breaking News Ticker (center) · User avatar + team logo + job security indicator (right)
- **Left sidebar** (collapsible): Primary navigation
- **Main content area:** Full width most screens, 2-col on dashboard
- **Breaking news ticker** persists across ALL screens — scrolling marquee of league events

**Sidebar Navigation Items:**
Dashboard · League Hub · My Team · Roster · Transactions · Schedule · Draft · Coaching Staff · Settings

---

**Dashboard (Home Screen):**
- Top row: 4 stat cards — Record · Division Standing · Job Security % · Influence Rating
- Left column (60%): News feed — latest articles, team-first then league
- Right column (40%): Upcoming game card + injury report widget + GridironX latest posts
- Week progress indicator

---

**League Hub (Media Center):**
- Full ESPN.com-style layout with featured story hero slot
- Tabbed nav: All News · Recaps · Features · Columns · GridironX · Radio
- Article cards: large headline · author byline with avatar · team tag · timestamp · read time
- GridironX feed: card per post · handle · team badge · timestamp · like count
- Morning Blitz: formatted dialogue transcript with host names

---

**Team Dashboard:**
- Header: Team name in Oswald · team colors applied · win/loss record
- Roster table: sortable by position/rating/morale · color-coded morale indicator
- Depth chart: visual card layout by position group · drag-and-drop reordering
- Cap space widget: used vs. available · top 5 cap hits listed

---

**Weekly Prep / Game Plan Screen:**
- Split layout: Your team (left) vs. Opponent (right)
- Scouting report card with tendency ratings
- Game plan selector — visual card options for offensive + defensive scheme
- Injury report for both teams
- On sim: animated progress indicator → score reveal with player grades

---

**Press Conference Screen:**
- **Full-screen cinematic modal** — dark, focused, immersive
- Reporter question displayed large in Oswald
- Three answer cards below — each with tone indicator badge
- Selected answer animates closed; consequence hint fades in
- Post-conference: Media Rating change shown with up/down indicator

---

**Draft Room:**
- Split: Your draft board (left) / Live pick feed (right)
- On-the-clock banner in team color with countdown timer
- Prospect cards: name · position · school · rating (hidden/revealed by scouting)
- Trade up/down modal from sidebar
- Post-round: media grades card slides in

### 13.5 Component Library — shadcn/ui

| Component | Used For |
|-----------|----------|
| `Card` | News articles, player cards, stat panels, game results |
| `Badge` | Morale status, injury status, position labels, trend indicators |
| `Dialog` | Press conference modal, trade review, owner meeting |
| `Tabs` | League Hub nav, team page sections, roster filters |
| `Table` | Roster view, box scores, standings, leaderboards |
| `Progress` | Job security bar, influence bar, cap space, player rating bars |
| `Sheet` | Trade detail slide-over, player profile, scouting report |
| `Toast` | Trade alerts, injury updates, sim complete notifications |
| `Select` / `Combobox` | Game plan selection, trade partner picker, filter controls |

### 13.6 Animation Principles (Framer Motion)

- **News feed items:** staggered fade-up on page load (0.05s delay per item)
- **Breaking ticker:** smooth infinite horizontal scroll, pauses on hover
- **Press conference:** question fades in, answer cards slide up, selection collapses with spring physics
- **Score reveal after sim:** numbers count up from zero — the broadcast moment
- **Modal entrances:** scale from 0.95 + fade, 200ms ease-out
- **Page transitions:** subtle fade between routes, 150ms
- **Stat bars:** animate width on mount — makes ratings feel earned, not static
- **Blockbuster trade:** confetti burst when a trade involves a player rated 85+ overall

> 📌 Animation rule: every animation should feel like something a broadcast network would do. Functional, punchy, never decorative for its own sake.

### 13.7 Responsive Behavior

- **Primary target:** desktop browser (1280px+)
- **Tablet (768px+):** sidebar collapses to icon-only, 2-col becomes 1-col
- **Mobile:** functional but not optimized — this is a management sim, not a mobile game
- **Dark mode only** — this is the single theme, not a preference

### 13.8 Full Screen List

Every screen below must be designed and fully implemented:

| Screen | Description |
|--------|-------------|
| Dashboard | Weekly hub — news feed, stats, upcoming game |
| League Hub | Full media center — all content types |
| My Team | Roster, depth chart, cap space, morale |
| Player Profile | Stats, history, contract, personality, narrative moments |
| Weekly Prep | Game plan, scouting report, injury report |
| Box Score / Game Result | Full stat breakdown post-sim |
| Press Conference | Full-screen cinematic modal |
| Trade Center | Propose, review, trade block, history |
| Free Agency | Available players, bidding, my offers |
| Draft Room | Board, live picks, on-clock experience |
| Coaching Staff | Hire/fire, ratings, budget |
| Schedule | Full season calendar with results |
| Standings | Division and conference tables |
| Leaderboards | League-wide stat leaders |
| GridironX Feed | Social media screen |
| Owner Office | Influence meter, expectations, check-ins |
| Settings | League config, API key, roster upload, display |
| Installer | Setup wizard (separate from main app) |

---

## 14. Monetization

| Product | Price | Notes |
|---------|-------|-------|
| Base Game | $29–$39 one-time | Self-hosted, all core features |
| AI Media Pack | $4.99/mo or user's own API key | Unlocks Claude API integration |
| Roster Pack (Annual) | $9.99/year | Updated real-world ratings each NFL season |

- License key system — one key per install domain, validated on install
- Distribution: direct sales via Port City Pixel website
- Updates delivered as patch downloads — no auto-update mechanism needed

---

## 15. Build Phases

### Phase 1 — Foundation
- [ ] Installer and full database setup
- [ ] 32 fictional teams and procedurally generated players
- [ ] Roster management and depth chart editor (drag-and-drop)
- [ ] Basic simulation engine (game plan → sim → box score)
- [ ] Single-player vs. AI coaches
- [ ] Regular season + playoffs
- [ ] Scripted news feed (template-based)
- [ ] Simple press conference system
- [ ] Full UI shell — global nav, dark theme, breaking ticker, team color theming

### Phase 2 — Narrative Layer
- [ ] Full League Hub media center (ESPN/NFL Network aesthetic)
- [ ] Columnist personas, GridironX feed, Morning Blitz (scripted tier)
- [ ] Full press conference system with memory logging
- [ ] Player morale and relationship events
- [ ] Influence system fully implemented
- [ ] Owner relationship and job security meter

### Phase 3 — Depth Systems
- [ ] Full trade system with AI evaluation and counter-offers
- [ ] Free agency bidding system
- [ ] Draft room with scouting system
- [ ] Coaching staff hire/fire
- [ ] Multi-season dynasty with player aging and retirement
- [ ] Career legacy tracking

### Phase 4 — Multiplayer
- [ ] Commissioner install and league setup tools
- [ ] Multi-team invite system (link-based)
- [ ] WebSocket real-time notifications
- [ ] Async game plan submission
- [ ] League message board

### Phase 5 — AI Pack
- [ ] Claude API integration for all media content
- [ ] AI coach personalities via Claude
- [ ] Full narrative memory system (markdown files per league/season)
- [ ] Season arc continuity across all AI-generated content
- [ ] Roster upload system (CSV/JSON import)

---

*Head Coach 26 · GDD v1.1 · Port City Pixel · March 2026 · Confidential*