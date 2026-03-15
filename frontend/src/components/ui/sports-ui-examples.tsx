/**
 * Sports UI — Migration Examples
 * ===============================
 * This file shows concrete before/after patterns for converting
 * existing pages to use the sports-ui component system.
 *
 * NOT meant to be imported — this is a reference document.
 * Delete once migration is complete.
 */

/* ================================================================
   EXAMPLE 1: Page Header
   ================================================================

   BEFORE (admin-style):

   <div className="flex items-center gap-3">
     <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-[var(--accent-blue)]/10">
       <BarChart3 className="h-5 w-5 text-[var(--accent-blue)]" />
     </div>
     <div>
       <h1 className="font-display text-2xl">League Leaders</h1>
       <p className="text-sm text-[var(--text-secondary)]">Current season stat leaders</p>
     </div>
   </div>

   AFTER (sports-media):

   <PageHeader
     title="League Leaders"
     subtitle="Current season stat leaders"
     icon={BarChart3}
     actions={
       <SportsTabs
         tabs={[
           { key: 'standard', label: 'Standard', icon: BarChart3 },
           { key: 'advanced', label: 'Advanced', icon: TrendingUp },
         ]}
         activeTab={tab}
         onChange={setTab}
         variant="pills"
       />
     }
   />

   ================================================================ */

/* ================================================================
   EXAMPLE 2: Two-Column Layout with Sidebar
   ================================================================

   BEFORE:

   <div className="grid gap-5 lg:grid-cols-3">
     <div className="space-y-5 lg:col-span-2">
       {mainContent}
     </div>
     <div className="space-y-5">
       {sidebarContent}
     </div>
   </div>

   AFTER:

   <ContentGrid layout="main-sidebar">
     <MainColumn>
       {mainContent}
     </MainColumn>
     <SidebarColumn>
       {sidebarContent}
     </SidebarColumn>
   </ContentGrid>

   ================================================================ */

/* ================================================================
   EXAMPLE 3: Stat Cards Row
   ================================================================

   BEFORE (scattered inline stats):

   <div className="flex gap-5">
     <div className="text-center">
       <p className="text-[10px] font-bold uppercase tracking-[0.15em] text-[var(--text-muted)]">Rating</p>
       <p className="font-stat text-2xl leading-none">75</p>
     </div>
   </div>

   AFTER (StatCard grid):

   <div className="grid grid-cols-2 sm:grid-cols-4 gap-3">
     <StatCard label="Record" value="8-3" accentColor={team.primary_color} />
     <StatCard label="Rating" value={75} accentColor="var(--accent-blue)" />
     <StatCard label="Morale" value={82} trend="up" sub="4" accentColor="var(--accent-gold)" />
     <StatCard label="Injuries" value={2} accentColor="var(--accent-red)" />
   </div>

   ================================================================ */

/* ================================================================
   EXAMPLE 4: Data Tables
   ================================================================

   BEFORE (shadcn Table):

   <Card className="overflow-hidden border-[var(--border)] bg-[var(--bg-surface)]">
     <div className="h-[2px] w-full" style={{ background: `linear-gradient(...)` }} />
     <Table>
       <TableHeader>
         <TableRow>
           <TableHead className="text-[10px] font-bold uppercase tracking-[0.15em]">#</TableHead>
           ...
         </TableRow>
       </TableHeader>
       <TableBody>
         {filtered.map((p) => (
           <TableRow key={p.id} onClick={() => navigate(`/player/${p.id}`)}>
             <TableCell className="font-stat text-xs">{p.jersey_number}</TableCell>
             ...
           </TableRow>
         ))}
       </TableBody>
     </Table>
   </Card>

   AFTER (DataTable):

   <DataTable
     accentColor={team.primary_color}
     rowKey={(p) => p.id}
     onRowClick={(p) => navigate(`/player/${p.id}`)}
     data={filtered}
     columns={[
       { key: 'jersey_number', label: '#', width: 'w-12', stat: true,
         render: (p) => <span className="text-xs text-[var(--text-muted)]">{p.jersey_number}</span> },
       { key: 'name', label: 'Name',
         render: (p) => <span className="text-[13px] font-medium">{p.first_name} {p.last_name}</span> },
       { key: 'position', label: 'Pos',
         render: (p) => <span className="inline-flex items-center justify-center w-9 h-6 rounded text-[10px] font-bold uppercase bg-[var(--bg-elevated)] text-[var(--text-secondary)] border border-[var(--border)]">{p.position}</span> },
       { key: 'age', label: 'Age' },
       { key: 'overall_rating', label: 'OVR', stat: true,
         render: (p) => <RatingBadge rating={p.overall_rating} size="sm" /> },
       { key: 'status', label: 'Status',
         render: (p) => p.injury
           ? <span className="text-[10px] font-semibold text-red-400">Injured</span>
           : <span className="text-[10px] font-semibold text-green-400">Active</span> },
     ]}
   />

   ================================================================ */

/* ================================================================
   EXAMPLE 5: Buttons
   ================================================================

   BEFORE (shadcn Button):

   <Button size="sm" className="gap-1.5 bg-[var(--accent-blue)] text-white hover:bg-[var(--accent-blue)]/90">
     <FastForward className="h-3.5 w-3.5" />
     Start Season
   </Button>
   <Button size="sm" variant="outline" className="gap-1.5">
     <ClipboardList className="h-3.5 w-3.5" />
     View Roster
   </Button>

   AFTER (ActionButton):

   <ActionButton icon={FastForward} size="sm">
     Start Season
   </ActionButton>
   <ActionButton icon={ClipboardList} variant="secondary" size="sm">
     View Roster
   </ActionButton>

   ================================================================ */

/* ================================================================
   EXAMPLE 6: Sidebar Widgets
   ================================================================

   BEFORE (SidebarWidget inline component):

   <motion.div className="rounded-lg border border-[var(--border)] bg-[var(--bg-surface)] overflow-hidden">
     <div className="flex items-center gap-2 px-4 py-2.5 border-b border-[var(--border)]">
       <div className="h-3.5 w-[3px] rounded-full" style={{ backgroundColor: accentColor }} />
       <h3 className="text-[10px] font-bold uppercase tracking-[0.15em]" style={{ color: accentColor }}>
         Team Snapshot
       </h3>
     </div>
     <div className="p-4">{children}</div>
   </motion.div>

   AFTER (SidePanel):

   <SidePanel
     title="Team Snapshot"
     accentColor={team.primary_color}
     action={{ label: 'Full Stats', to: '/my-team' }}
     delay={0.2}
   >
     {children}
   </SidePanel>

   ================================================================ */

/* ================================================================
   EXAMPLE 7: Tab Navigation
   ================================================================

   BEFORE (shadcn Tabs):

   <Tabs value={view} onValueChange={(v) => setView(v)}>
     <TabsList className="bg-[var(--bg-elevated)]">
       <TabsTrigger value="roster" className="text-xs font-semibold uppercase tracking-wider">Roster</TabsTrigger>
       <TabsTrigger value="depth" className="text-xs font-semibold uppercase tracking-wider">Depth Chart</TabsTrigger>
     </TabsList>
   </Tabs>

   AFTER (SportsTabs — underline variant):

   <SportsTabs
     tabs={[
       { key: 'roster', label: 'Roster', icon: Users },
       { key: 'depth', label: 'Depth Chart', icon: LayoutList },
     ]}
     activeTab={tab}
     onChange={setTab}
     variant="underline"
   />

   AFTER (SportsTabs — pills variant, good for filters):

   <SportsTabs
     tabs={[
       { key: 'standard', label: 'Standard' },
       { key: 'advanced', label: 'Advanced' },
     ]}
     activeTab={tab}
     onChange={setTab}
     variant="pills"
   />

   ================================================================ */

/* ================================================================
   EXAMPLE 8: Player Listings
   ================================================================

   BEFORE (inline mapping with mixed styles):

   <div className="flex items-center gap-3 text-sm">
     <span className="w-5 text-center text-xs text-[var(--text-muted)]">{i + 1}</span>
     <span className="flex-1 truncate">{l.first_name} {l.last_name}</span>
     <Badge variant="outline" className="text-[10px]">{l.position}</Badge>
     <span className="text-xs text-[var(--text-muted)]">{l.team}</span>
     <span className="w-16 text-right font-mono font-semibold">{l.total}</span>
   </div>

   AFTER (PlayerRow):

   <div className="rounded-lg border border-[var(--border)] bg-[var(--bg-surface)] overflow-hidden">
     {players.map((p, i) => (
       <PlayerRow
         key={p.player_id}
         rank={i + 1}
         name={`${p.first_name} ${p.last_name}`}
         position={p.position}
         rating={p.overall_rating}
         team={p.team}
         stat={{ label: 'YDS', value: p.total }}
         onClick={() => navigate(`/player/${p.player_id}`)}
       />
     ))}
   </div>

   ================================================================ */

/* ================================================================
   EXAMPLE 9: Full Page Structure
   ================================================================

   A complete page using the sports-ui system:

   export default function MyTeamPage() {
     return (
       <PageLayout
         hero={
           <Hero colorLeft={team.primary_color}>
             <div className="flex items-center gap-4">
               <TeamBadge ... size="xl" />
               <div>
                 <h1 className="font-display text-3xl text-white">
                   {team.city} {team.name}
                 </h1>
                 <p className="text-sm text-white/70">8-3 / 1st in NFC East</p>
               </div>
             </div>
           </Hero>
         }
       >
         <div className="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-6">
           <StatCard label="Record" value="8-3" accentColor={team.primary_color} />
           <StatCard label="Rating" value={75} />
           <StatCard label="Morale" value={82} trend="up" sub="4" />
           <StatCard label="Cap Space" value="$12.4M" />
         </div>

         <SportsTabs
           tabs={[
             { key: 'roster', label: 'Roster' },
             { key: 'depth', label: 'Depth Chart' },
             { key: 'staff', label: 'Staff' },
           ]}
           activeTab={tab}
           onChange={setTab}
         />

         <div className="mt-5">
           {tab === 'roster' && (
             <DataTable
               accentColor={team.primary_color}
               columns={rosterColumns}
               data={players}
               rowKey={(p) => p.id}
               onRowClick={(p) => navigate(`/player/${p.id}`)}
               striped
             />
           )}
         </div>
       </PageLayout>
     );
   }

   ================================================================ */

export {};
