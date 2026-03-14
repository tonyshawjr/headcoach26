import { useState, useRef, useCallback } from 'react';
import { useNavigate } from 'react-router-dom';
import { Card, CardContent } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';
import {
  Table, TableHeader, TableBody, TableHead, TableRow, TableCell,
} from '@/components/ui/table';
import {
  Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription, DialogFooter, DialogTrigger, DialogClose,
} from '@/components/ui/dialog';
import { motion } from 'framer-motion';
import {
  Upload, FileSpreadsheet, Check, AlertTriangle, History, Download,
} from 'lucide-react';
import {
  useValidateImport, useImportRoster, useImportHistory, useImportMaddenRoster,
} from '@/hooks/useApi';
import type { MaddenImportResult } from '@/api/client';

// --- CSV Template ---

const CSV_TEMPLATE = `first_name,last_name,position,age,overall_rating,speed,acceleration,agility,strength,awareness,stamina,archetype,height,weight,college
John,Smith,QB,24,78,72,74,70,68,82,80,Field General - QB,75,220,Alabama
Jane,Doe,WR,22,72,88,86,84,58,68,78,Deep Threat - WR,71,185,Ohio State
Mike,Johnson,RB,26,75,82,80,78,76,74,82,Power Back - RB,70,215,Georgia`;

// --- File Drop Zone ---

function FileDropZone({
  onFileLoaded,
  fileName,
}: {
  onFileLoaded: (content: string, name: string) => void;
  fileName: string | null;
}) {
  const inputRef = useRef<HTMLInputElement>(null);
  const [isDragging, setIsDragging] = useState(false);

  const handleFile = useCallback(
    (file: File) => {
      const reader = new FileReader();
      reader.onload = (e) => {
        const text = e.target?.result as string;
        if (text) onFileLoaded(text, file.name);
      };
      reader.readAsText(file);
    },
    [onFileLoaded],
  );

  const handleDrop = useCallback(
    (e: React.DragEvent) => {
      e.preventDefault();
      setIsDragging(false);
      const file = e.dataTransfer.files[0];
      if (file) handleFile(file);
    },
    [handleFile],
  );

  return (
    <div
      onDragOver={(e) => {
        e.preventDefault();
        setIsDragging(true);
      }}
      onDragLeave={() => setIsDragging(false)}
      onDrop={handleDrop}
      onClick={() => inputRef.current?.click()}
      className={`cursor-pointer rounded-lg border-2 border-dashed p-8 text-center transition-colors ${
        isDragging
          ? 'border-[var(--accent-blue)] bg-[var(--accent-blue)]/5'
          : fileName
            ? 'border-green-500/30 bg-green-500/5'
            : 'border-[var(--border)] hover:border-[var(--accent-blue)]/50 hover:bg-[var(--bg-elevated)]'
      }`}
    >
      <input
        ref={inputRef}
        type="file"
        accept=".csv,.json"
        className="hidden"
        onChange={(e) => {
          const file = e.target.files?.[0];
          if (file) handleFile(file);
        }}
      />

      {fileName ? (
        <div className="flex flex-col items-center gap-2">
          <FileSpreadsheet className="h-10 w-10 text-green-400" />
          <p className="text-sm font-medium text-green-400">{fileName}</p>
          <p className="text-xs text-[var(--text-muted)]">Click or drop to replace</p>
        </div>
      ) : (
        <div className="flex flex-col items-center gap-2">
          <Upload className="h-10 w-10 text-[var(--text-muted)]" />
          <p className="text-sm font-medium text-[var(--text-secondary)]">
            Drop a file here or click to browse
          </p>
          <p className="text-xs text-[var(--text-muted)]">Supports CSV and JSON formats</p>
        </div>
      )}
    </div>
  );
}

// --- Validation Report ---

interface ValidationResult {
  valid: boolean;
  headers?: string[];
  row_count?: number;
  warnings?: string[];
  errors?: string[];
}

function ValidationReport({ result }: { result: ValidationResult }) {
  return (
    <motion.div
      initial={{ opacity: 0, y: 10 }}
      animate={{ opacity: 1, y: 0 }}
      transition={{ duration: 0.3 }}
      className="space-y-3"
    >
      <div className="flex items-center gap-2">
        {result.valid ? (
          <>
            <Check className="h-4 w-4 text-green-400" />
            <span className="text-sm font-medium text-green-400">Validation Passed</span>
          </>
        ) : (
          <>
            <AlertTriangle className="h-4 w-4 text-red-400" />
            <span className="text-sm font-medium text-red-400">Validation Failed</span>
          </>
        )}
      </div>

      {result.headers && result.headers.length > 0 && (
        <div className="rounded-lg border border-[var(--border)] bg-[var(--bg-primary)] p-3">
          <p className="text-xs font-medium uppercase tracking-wider text-[var(--text-muted)] mb-2">
            Headers Found
          </p>
          <div className="flex flex-wrap gap-1.5">
            {result.headers.map((h) => (
              <Badge key={h} variant="outline" className="text-xs">
                {h}
              </Badge>
            ))}
          </div>
        </div>
      )}

      {result.row_count !== undefined && (
        <p className="text-sm text-[var(--text-secondary)]">
          Rows detected: <span className="font-medium text-[var(--text-primary)]">{result.row_count}</span>
        </p>
      )}

      {result.warnings && result.warnings.length > 0 && (
        <div className="space-y-1">
          {result.warnings.map((w, i) => (
            <div
              key={i}
              className="flex items-start gap-2 rounded-lg border border-yellow-500/30 bg-yellow-500/10 p-2.5 text-xs text-yellow-300"
            >
              <AlertTriangle className="mt-0.5 h-3 w-3 shrink-0" />
              {w}
            </div>
          ))}
        </div>
      )}

      {result.errors && result.errors.length > 0 && (
        <div className="space-y-1">
          {result.errors.map((e, i) => (
            <div
              key={i}
              className="flex items-start gap-2 rounded-lg border border-red-500/30 bg-red-500/10 p-2.5 text-xs text-red-400"
            >
              <AlertTriangle className="mt-0.5 h-3 w-3 shrink-0" />
              {e}
            </div>
          ))}
        </div>
      )}
    </motion.div>
  );
}

// --- Import Result ---

interface ImportResult {
  imported?: number;
  skipped?: number;
  errors?: string[];
  message?: string;
}

function ImportResultView({ result }: { result: ImportResult }) {
  return (
    <motion.div
      initial={{ opacity: 0, y: 10 }}
      animate={{ opacity: 1, y: 0 }}
      transition={{ duration: 0.3 }}
      className="space-y-3"
    >
      <div className="flex items-center gap-2">
        <Check className="h-4 w-4 text-green-400" />
        <span className="text-sm font-medium text-green-400">Import Complete</span>
      </div>

      <div className="grid grid-cols-2 gap-3">
        <div className="rounded-lg border border-[var(--border)] bg-[var(--bg-primary)] p-3 text-center">
          <p className="font-display text-2xl text-green-400">{result.imported ?? 0}</p>
          <p className="text-xs text-[var(--text-muted)]">Imported</p>
        </div>
        <div className="rounded-lg border border-[var(--border)] bg-[var(--bg-primary)] p-3 text-center">
          <p className="font-display text-2xl text-yellow-400">{result.skipped ?? 0}</p>
          <p className="text-xs text-[var(--text-muted)]">Skipped</p>
        </div>
      </div>

      {result.message && (
        <p className="text-sm text-[var(--text-secondary)]">{result.message}</p>
      )}

      {result.errors && result.errors.length > 0 && (
        <div className="space-y-1">
          {result.errors.map((e, i) => (
            <div
              key={i}
              className="flex items-start gap-2 rounded-lg border border-red-500/30 bg-red-500/10 p-2.5 text-xs text-red-400"
            >
              <AlertTriangle className="mt-0.5 h-3 w-3 shrink-0" />
              {e}
            </div>
          ))}
        </div>
      )}
    </motion.div>
  );
}

// --- Import History Table ---

interface ImportRecord {
  id: number;
  filename: string;
  format: string;
  imported_count: number;
  skipped_count: number;
  error_count: number;
  created_at: string;
}

function ImportHistoryTable({ imports }: { imports: ImportRecord[] }) {
  if (!imports || imports.length === 0) {
    return (
      <div className="flex flex-col items-center justify-center py-8 text-center">
        <History className="h-8 w-8 text-[var(--text-muted)] mb-2" />
        <p className="text-sm text-[var(--text-secondary)]">No imports yet.</p>
        <p className="text-xs text-[var(--text-muted)] mt-1">
          Import a roster file to see history here.
        </p>
      </div>
    );
  }

  return (
    <Table>
      <TableHeader>
        <TableRow className="border-[var(--border)]">
          <TableHead className="text-[var(--text-muted)]">File</TableHead>
          <TableHead className="text-[var(--text-muted)]">Format</TableHead>
          <TableHead className="text-[var(--text-muted)]">Imported</TableHead>
          <TableHead className="text-[var(--text-muted)]">Skipped</TableHead>
          <TableHead className="text-[var(--text-muted)]">Errors</TableHead>
          <TableHead className="text-[var(--text-muted)]">Date</TableHead>
        </TableRow>
      </TableHeader>
      <TableBody>
        {imports.map((imp) => (
          <TableRow key={imp.id} className="border-[var(--border)]">
            <TableCell className="font-medium">{imp.filename}</TableCell>
            <TableCell>
              <Badge variant="outline" className="text-xs uppercase">
                {imp.format}
              </Badge>
            </TableCell>
            <TableCell className="text-green-400">{imp.imported_count}</TableCell>
            <TableCell className="text-yellow-400">{imp.skipped_count}</TableCell>
            <TableCell className="text-red-400">{imp.error_count}</TableCell>
            <TableCell className="text-[var(--text-muted)]">
              {new Date(imp.created_at).toLocaleDateString()}
            </TableCell>
          </TableRow>
        ))}
      </TableBody>
    </Table>
  );
}

// --- Main Page ---

export default function RosterImport() {
  const navigate = useNavigate();
  const [fileContent, setFileContent] = useState<string | null>(null);
  const [fileName, setFileName] = useState<string | null>(null);
  const [format, setFormat] = useState<'csv' | 'json'>('csv');
  const [maddenDialogOpen, setMaddenDialogOpen] = useState(false);

  const validate = useValidateImport();
  const importRoster = useImportRoster();
  const maddenImport = useImportMaddenRoster();
  const { data: historyData, isLoading: historyLoading } = useImportHistory();

  const historyImports = (historyData as { imports?: ImportRecord[] })?.imports ?? [];

  const maddenResult = maddenImport.data as MaddenImportResult | undefined;

  const handleFileLoaded = useCallback((content: string, name: string) => {
    setFileContent(content);
    setFileName(name);
    // Auto-detect format from extension
    if (name.endsWith('.json')) {
      setFormat('json');
    } else {
      setFormat('csv');
    }
    // Reset results
    validate.reset();
    importRoster.reset();
  }, [validate, importRoster]);

  const handleDownloadTemplate = () => {
    const blob = new Blob([CSV_TEMPLATE], { type: 'text/csv' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'roster_template.csv';
    a.click();
    URL.revokeObjectURL(url);
  };

  return (
    <div className="space-y-6">
      {/* Page Header */}
      <motion.div
        initial={{ opacity: 0, y: -10 }}
        animate={{ opacity: 1, y: 0 }}
        transition={{ duration: 0.3 }}
      >
        <div className="flex items-center gap-3">
          <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-[var(--accent-blue)]/10">
            <FileSpreadsheet className="h-5 w-5 text-[var(--accent-blue)]" />
          </div>
          <div>
            <h1 className="font-display text-2xl">Roster Import</h1>
            <p className="text-sm text-[var(--text-secondary)]">
              Import player data from CSV or JSON files
            </p>
          </div>
        </div>
      </motion.div>

      <div className="grid gap-6 lg:grid-cols-3">
        {/* Upload Section */}
        <motion.div
          className="lg:col-span-2 space-y-4"
          initial={{ opacity: 0, scale: 0.95 }}
          animate={{ opacity: 1, scale: 1 }}
          transition={{ duration: 0.4, delay: 0.1 }}
        >
          <Card className="border-[var(--border)] bg-[var(--bg-surface)]">
            <CardContent className="p-5 space-y-4">
              <div className="flex items-center justify-between">
                <div className="flex items-center gap-2">
                  <Upload className="h-4 w-4 text-[var(--accent-blue)]" />
                  <h3 className="font-display text-sm uppercase tracking-wider text-[var(--text-muted)]">
                    Upload File
                  </h3>
                </div>
                <Button variant="ghost" size="sm" onClick={handleDownloadTemplate}>
                  <FileSpreadsheet className="mr-1.5 h-3.5 w-3.5" />
                  Download CSV Template
                </Button>
              </div>

              <FileDropZone onFileLoaded={handleFileLoaded} fileName={fileName} />

              {/* Format Selector */}
              <Tabs
                value={format}
                onValueChange={(v) => setFormat(v as 'csv' | 'json')}
              >
                <TabsList>
                  <TabsTrigger value="csv">CSV</TabsTrigger>
                  <TabsTrigger value="json">JSON</TabsTrigger>
                </TabsList>
              </Tabs>

              {/* Action Buttons */}
              <div className="flex gap-2">
                <Button
                  variant="outline"
                  onClick={() => {
                    if (fileContent) {
                      validate.mutate({ content: fileContent, format });
                    }
                  }}
                  disabled={!fileContent || validate.isPending}
                >
                  {validate.isPending ? (
                    <span className="flex items-center gap-1.5">
                      <FileSpreadsheet className="h-3.5 w-3.5 animate-spin" /> Validating...
                    </span>
                  ) : (
                    <span className="flex items-center gap-1.5">
                      <Check className="h-3.5 w-3.5" /> Validate
                    </span>
                  )}
                </Button>
                <Button
                  onClick={() => {
                    if (fileContent && fileName) {
                      importRoster.mutate({ content: fileContent, format, filename: fileName });
                    }
                  }}
                  disabled={!fileContent || importRoster.isPending}
                >
                  {importRoster.isPending ? (
                    <span className="flex items-center gap-1.5">
                      <Upload className="h-3.5 w-3.5 animate-spin" /> Importing...
                    </span>
                  ) : (
                    <span className="flex items-center gap-1.5">
                      <Upload className="h-3.5 w-3.5" /> Import
                    </span>
                  )}
                </Button>
              </div>

              {/* Validation Error */}
              {validate.isError && (
                <div className="flex items-center gap-2 rounded-lg border border-red-500/30 bg-red-500/10 p-3 text-sm text-red-400">
                  <AlertTriangle className="h-4 w-4 shrink-0" />
                  {(validate.error as Error)?.message || 'Validation failed.'}
                </div>
              )}

              {/* Validation Result */}
              {!!validate.data && (
                <ValidationReport result={validate.data as ValidationResult} />
              )}

              {/* Import Error */}
              {importRoster.isError && (
                <div className="flex items-center gap-2 rounded-lg border border-red-500/30 bg-red-500/10 p-3 text-sm text-red-400">
                  <AlertTriangle className="h-4 w-4 shrink-0" />
                  {(importRoster.error as Error)?.message || 'Import failed.'}
                </div>
              )}

              {/* Import Result */}
              {!!importRoster.data && (
                <ImportResultView result={importRoster.data as ImportResult} />
              )}
            </CardContent>
          </Card>
        </motion.div>

        {/* Template Info */}
        <motion.div
          initial={{ opacity: 0, x: 10 }}
          animate={{ opacity: 1, x: 0 }}
          transition={{ duration: 0.4, delay: 0.2 }}
        >
          <Card className="border-[var(--border)] bg-[var(--bg-surface)]">
            <CardContent className="p-5">
              <div className="flex items-center gap-2 mb-3">
                <FileSpreadsheet className="h-4 w-4 text-[var(--accent-gold)]" />
                <h3 className="font-display text-sm uppercase tracking-wider text-[var(--text-muted)]">
                  Expected Format
                </h3>
              </div>
              <div className="space-y-3">
                <p className="text-sm text-[var(--text-secondary)]">
                  Your CSV file should include the following columns:
                </p>
                <div className="rounded-lg border border-[var(--border)] bg-[var(--bg-primary)] p-3">
                  <pre className="text-xs text-[var(--text-muted)] overflow-x-auto whitespace-pre">
{CSV_TEMPLATE}
                  </pre>
                </div>
                <div className="space-y-1.5">
                  <p className="text-xs font-medium text-[var(--text-muted)] uppercase tracking-wider">
                    Required Fields
                  </p>
                  {['first_name', 'last_name', 'position', 'overall_rating'].map((f) => (
                    <div key={f} className="flex items-center gap-1.5">
                      <Check className="h-3 w-3 text-green-400" />
                      <span className="text-xs text-[var(--text-secondary)]">{f}</span>
                    </div>
                  ))}
                </div>
                <div className="space-y-1.5">
                  <p className="text-xs font-medium text-[var(--text-muted)] uppercase tracking-wider">
                    Optional Fields
                  </p>
                  {[
                    'age, overall_rating, potential',
                    'jersey_number, college, team',
                    'archetype, height, weight',
                    '+ 53 stat columns (speed, strength, etc.)',
                    'Missing stats auto-generated by position',
                  ].map((f) => (
                    <div key={f} className="flex items-center gap-1.5">
                      <span className="h-3 w-3 text-center text-xs text-[var(--text-muted)]">-</span>
                      <span className="text-xs text-[var(--text-secondary)]">{f}</span>
                    </div>
                  ))}
                </div>
              </div>
            </CardContent>
          </Card>
        </motion.div>
      </div>

      {/* Madden Import Section */}
      <motion.div
        initial={{ opacity: 0, y: 10 }}
        animate={{ opacity: 1, y: 0 }}
        transition={{ duration: 0.4, delay: 0.25 }}
      >
        <Card className="border-[var(--border)] bg-[var(--bg-surface)]">
          <CardContent className="p-5">
            <div className="flex items-center gap-2 mb-4">
              <Download className="h-4 w-4 text-[var(--accent-gold)]" />
              <h3 className="font-display text-sm uppercase tracking-wider text-[var(--text-muted)]">
                Madden Roster Import
              </h3>
            </div>

            <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
              <div className="space-y-2 flex-1">
                <p className="text-sm text-[var(--text-secondary)]">
                  Import a complete Madden 26 roster with all 53 attribute ratings, archetypes,
                  X-Factors, and Superstar abilities for every player.
                </p>
                <p className="text-xs text-[var(--text-muted)]">
                  This will replace all existing player data with authentic Madden 26 ratings.
                </p>
              </div>

              <Dialog open={maddenDialogOpen} onOpenChange={setMaddenDialogOpen}>
                <DialogTrigger
                  render={
                    <Button
                      className="shrink-0 bg-gradient-to-r from-yellow-600 to-orange-600 text-white hover:from-yellow-500 hover:to-orange-500"
                      disabled={maddenImport.isPending}
                    />
                  }
                >
                  {maddenImport.isPending ? (
                    <span className="flex items-center gap-1.5">
                      <Download className="h-3.5 w-3.5 animate-spin" />
                      Importing...
                    </span>
                  ) : (
                    <span className="flex items-center gap-1.5">
                      <Download className="h-3.5 w-3.5" />
                      Import Madden 26 Roster
                    </span>
                  )}
                </DialogTrigger>
                <DialogContent>
                  <DialogHeader>
                    <DialogTitle>Confirm Madden 26 Roster Import</DialogTitle>
                    <DialogDescription>
                      This will replace ALL existing players with Madden 26 ratings including
                      53 individual stat ratings, archetypes, X-Factors, and Superstar abilities.
                      This action cannot be undone. Are you sure?
                    </DialogDescription>
                  </DialogHeader>
                  <DialogFooter>
                    <DialogClose render={<Button variant="outline" />}>
                      Cancel
                    </DialogClose>
                    <Button
                      className="bg-gradient-to-r from-yellow-600 to-orange-600 text-white hover:from-yellow-500 hover:to-orange-500"
                      onClick={() => {
                        setMaddenDialogOpen(false);
                        maddenImport.mutate();
                      }}
                    >
                      Yes, Import Madden Roster
                    </Button>
                  </DialogFooter>
                </DialogContent>
              </Dialog>
            </div>

            {/* Madden Import Error */}
            {maddenImport.isError && (
              <div className="mt-4 flex items-center gap-2 rounded-lg border border-red-500/30 bg-red-500/10 p-3 text-sm text-red-400">
                <AlertTriangle className="h-4 w-4 shrink-0" />
                {(maddenImport.error as Error)?.message || 'Madden roster import failed.'}
              </div>
            )}

            {/* Madden Import Result */}
            {maddenResult && (
              <motion.div
                initial={{ opacity: 0, y: 10 }}
                animate={{ opacity: 1, y: 0 }}
                transition={{ duration: 0.3 }}
                className="mt-4 space-y-3"
              >
                <div className="flex items-center gap-2">
                  <Check className="h-4 w-4 text-green-400" />
                  <span className="text-sm font-medium text-green-400">Madden Import Complete</span>
                </div>

                <div className="grid grid-cols-3 gap-3">
                  <div className="rounded-lg border border-[var(--border)] bg-[var(--bg-primary)] p-3 text-center">
                    <p className="font-display text-2xl text-green-400">{maddenResult.imported}</p>
                    <p className="text-xs text-[var(--text-muted)]">Imported</p>
                  </div>
                  <div className="rounded-lg border border-[var(--border)] bg-[var(--bg-primary)] p-3 text-center">
                    <p className="font-display text-2xl text-yellow-400">{maddenResult.skipped}</p>
                    <p className="text-xs text-[var(--text-muted)]">Skipped</p>
                  </div>
                  <div className="rounded-lg border border-[var(--border)] bg-[var(--bg-primary)] p-3 text-center">
                    <p className="font-display text-2xl text-red-400">{maddenResult.errors?.length ?? 0}</p>
                    <p className="text-xs text-[var(--text-muted)]">Errors</p>
                  </div>
                </div>

                {maddenResult.message && (
                  <p className="text-sm text-[var(--text-secondary)]">{maddenResult.message}</p>
                )}

                {maddenResult.skip_summary && maddenResult.skip_summary.length > 0 && (
                  <div className="space-y-1.5">
                    <p className="text-xs font-semibold uppercase tracking-wider text-yellow-400">
                      Why {maddenResult.skipped} players were skipped
                    </p>
                    {maddenResult.skip_summary.map((reason, i) => (
                      <div
                        key={i}
                        className="rounded-lg border border-yellow-500/20 bg-yellow-500/5 px-3 py-2 text-xs text-yellow-300"
                      >
                        {reason}
                      </div>
                    ))}
                  </div>
                )}

                {maddenResult.errors && maddenResult.errors.length > 0 && (
                  <div className="space-y-1.5">
                    <p className="text-xs font-semibold uppercase tracking-wider text-red-400">
                      Errors ({maddenResult.errors.length})
                    </p>
                    <div className="max-h-40 space-y-1 overflow-y-auto">
                      {maddenResult.errors.map((err, i) => (
                        <div
                          key={i}
                          className="flex items-start gap-2 rounded-lg border border-red-500/30 bg-red-500/10 p-2.5 text-xs text-red-400"
                        >
                          <AlertTriangle className="mt-0.5 h-3 w-3 shrink-0" />
                          {err}
                        </div>
                      ))}
                    </div>
                  </div>
                )}

                <Button
                  variant="outline"
                  size="sm"
                  onClick={() => navigate('/roster')}
                >
                  View Roster
                </Button>
              </motion.div>
            )}
          </CardContent>
        </Card>
      </motion.div>

      {/* Import History */}
      <motion.div
        initial={{ opacity: 0, y: 10 }}
        animate={{ opacity: 1, y: 0 }}
        transition={{ duration: 0.4, delay: 0.3 }}
      >
        <Card className="border-[var(--border)] bg-[var(--bg-surface)]">
          <CardContent className="p-5">
            <div className="flex items-center gap-2 mb-4">
              <History className="h-4 w-4 text-[var(--accent-blue)]" />
              <h3 className="font-display text-sm uppercase tracking-wider text-[var(--text-muted)]">
                Import History
              </h3>
            </div>

            {historyLoading ? (
              <div className="flex items-center justify-center py-8">
                <FileSpreadsheet className="h-6 w-6 animate-pulse text-[var(--accent-blue)]" />
              </div>
            ) : (
              <ImportHistoryTable imports={historyImports} />
            )}
          </CardContent>
        </Card>
      </motion.div>
    </div>
  );
}
