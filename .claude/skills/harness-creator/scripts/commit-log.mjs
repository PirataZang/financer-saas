#!/usr/bin/env node
// Per-commit change log, written BEFORE the commit and included IN it: the pre-commit hook writes
// .harness/commits/<branch>_<dd_mm_yyyy>_<HH-MM-SS>.json (what is being committed on this branch,
// plus the free-text notes from .harness/commit-notes.md) and stages it. Hash and message don't
// exist yet at that point — the JSON lives inside its own commit, so `git log -- <file>` links them.
// Self-contained on purpose: --install copies this file into the repo's .githooks/.
//
//   node commit-log.mjs --install [--target DIR] [--force]   install the hooks
//   node commit-log.mjs                                      pre-commit: write + stage the log
//   node commit-log.mjs --after-commit                       post-commit: clear notes, resync index
import { execFileSync } from 'node:child_process';
import { chmodSync, copyFileSync, existsSync, mkdirSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const HOOKS_DIR = '.githooks';
const LOG_DIR = '.harness/commits';
const NOTES_FILE = '.harness/commit-notes.md';
const MARKER = '# harness-creator:commit-log';
const HOOKS = {
  'pre-commit': '',
  // Partial commits (`git commit <paths>`) stage the log in a temporary index; re-add it to the real one.
  'post-commit': ' --after-commit',
};

// Inherits GIT_INDEX_FILE from the hook, so partial/`-a` commits see their own index.
const git = (cwd, ...args) => execFileSync('git', args, { cwd, encoding: 'utf8', stdio: ['ignore', 'pipe', 'ignore'] }).trimEnd();
const tryGit = (cwd, ...args) => {
  try {
    return git(cwd, ...args);
  } catch {
    return null;
  }
};

// ---------- install ----------

export function install(target, { force = false } = {}) {
  const root = tryGit(target, 'rev-parse', '--show-toplevel');
  if (!root) return { status: 'skipped', reason: 'not a git repository (run git init first)' };

  const hooksPath = tryGit(root, 'config', '--get', 'core.hooksPath');
  if (hooksPath && hooksPath !== HOOKS_DIR && !force) {
    return { status: 'skipped', reason: `core.hooksPath is already "${hooksPath}" (use --force to switch to ${HOOKS_DIR})` };
  }

  const foreign = Object.keys(HOOKS).filter((name) => {
    const file = path.join(root, HOOKS_DIR, name);
    return existsSync(file) && !readFileSync(file, 'utf8').includes(MARKER);
  });
  if (foreign.length && !force) {
    return { status: 'skipped', reason: `${foreign.map((n) => `${HOOKS_DIR}/${n}`).join(', ')} exists and is not ours (use --force)` };
  }

  mkdirSync(path.join(root, HOOKS_DIR), { recursive: true });
  copyFileSync(fileURLToPath(import.meta.url), path.join(root, HOOKS_DIR, 'commit-log.mjs'));
  for (const [name, flag] of Object.entries(HOOKS)) {
    const file = path.join(root, HOOKS_DIR, name);
    writeFileSync(file, `#!/bin/sh\n${MARKER}\n# Never blocks a commit: the script swallows its own errors.\nnode "$(git rev-parse --show-toplevel)/${HOOKS_DIR}/commit-log.mjs"${flag} || true\n`);
    chmodSync(file, 0o755);
  }
  git(root, 'config', 'core.hooksPath', HOOKS_DIR);

  return { status: 'written', path: path.join(root, HOOKS_DIR, 'pre-commit') };
}

// ---------- log ----------

const pad = (n) => String(n).padStart(2, '0');

/** "feature/login" → "feature-login"; detached HEAD (rebase, checkout <sha>) → "detached". */
export function branchSlug(branch) {
  if (!branch || branch === 'HEAD') return 'detached';
  return branch.replace(/[^A-Za-z0-9._-]+/g, '-');
}

/** main + 2026-10-07T14:30:05 (local) → main_07_10_2026_14-30-05 */
export function fileStem(branch, date) {
  return `${branchSlug(branch)}_${pad(date.getDate())}_${pad(date.getMonth() + 1)}_${date.getFullYear()}_${pad(date.getHours())}-${pad(date.getMinutes())}-${pad(date.getSeconds())}`;
}

/** Joins `git diff --name-status -z` and `--numstat -z` (renames included) into one list. */
export function parseChanges(nameStatusZ, numstatZ) {
  const files = [];
  const ns = nameStatusZ.split('\0');
  for (let i = 0; i < ns.length - 1; ) {
    const status = ns[i++];
    const renamed = /^[RC]/.test(status);
    const from = renamed ? ns[i++] : undefined;
    files.push({ status: status[0], path: ns[i++], ...(renamed ? { from } : {}), additions: 0, deletions: 0 });
  }

  const byPath = new Map(files.map((file) => [file.path, file]));
  const num = numstatZ.split('\0');
  for (let i = 0; i < num.length - 1; ) {
    const [added, deleted, inlinePath] = num[i++].split('\t');
    const filePath = inlinePath || (i++, num[i++]); // rename: "a\td\t" \0 old \0 new
    const file = byPath.get(filePath);
    if (!file) continue;
    // Binary files report "-": no line counts.
    file.additions = added === '-' ? null : Number(added);
    file.deletions = deleted === '-' ? null : Number(deleted);
  }
  return files;
}

/** "Name <email> 1759845005 -0300" (git var GIT_AUTHOR_IDENT) → { name, email } */
function parseIdent(ident) {
  const match = /^(.*) <(.*)> \d+ [+-]\d{4}$/.exec(ident ?? '');
  return match ? { name: match[1], email: match[2] } : null;
}

function baseBranch(root, current) {
  const configured = tryGit(root, 'config', '--get', 'init.defaultBranch');
  return [configured, 'main', 'master', 'develop'].find(
    (name) => name && name !== current && tryGit(root, 'rev-parse', '--verify', '--quiet', `refs/heads/${name}`),
  );
}

/** What the commit about to be made contains (the staged index vs HEAD). */
export function buildLog(root, now = new Date()) {
  const head = tryGit(root, 'rev-parse', '--verify', '--quiet', 'HEAD');
  const branch = tryGit(root, 'symbolic-ref', '--quiet', '--short', 'HEAD');
  const from = head ?? git(root, 'hash-object', '-t', 'tree', '/dev/null'); // first commit: vs empty tree
  // The logs themselves are bookkeeping, not "what was done".
  const notLogs = ['--', '.', `:(exclude)${LOG_DIR}`, `:(exclude)${NOTES_FILE}`];
  const files = parseChanges(
    git(root, 'diff', '--cached', '--name-status', '-z', '-M', from, ...notLogs),
    git(root, 'diff', '--cached', '--numstat', '-z', '-M', from, ...notLogs),
  );

  const notesPath = path.join(root, NOTES_FILE);
  const notes = existsSync(notesPath) ? readFileSync(notesPath, 'utf8').trim() : '';

  const log = {
    branch,
    created_at: now.toISOString(),
    author: parseIdent(tryGit(root, 'var', 'GIT_AUTHOR_IDENT')),
    parent: head,
    // Conflict-resolution commit of a merge (plain merges don't run pre-commit).
    ...(existsSync(path.join(git(root, 'rev-parse', '--git-dir'), 'MERGE_HEAD'))
      ? { merging: git(root, 'rev-parse', 'MERGE_HEAD') }
      : {}),
    notes: notes || null,
    stats: {
      files: files.length,
      additions: files.reduce((sum, f) => sum + (f.additions ?? 0), 0),
      deletions: files.reduce((sum, f) => sum + (f.deletions ?? 0), 0),
    },
    files,
  };

  // Off the base branch: the commits this branch already has (newest first); this one comes next.
  const base = branch ? baseBranch(root, branch) : null;
  const mergeBase = base && head && tryGit(root, 'merge-base', base, head);
  if (mergeBase) {
    const commits = git(root, 'log', '--format=%H%x00%s%x00%cI', `${mergeBase}..${head}`)
      .split('\n')
      .filter(Boolean)
      .map((line) => {
        const [hash, subject, committedAt] = line.split('\0');
        return { hash, subject, committed_at: committedAt };
      });
    log.branch_history = { base, merge_base: mergeBase, previous_commits: commits };
  }

  return log;
}

/** pre-commit: write the log and stage it into the commit being made. */
export function writeLog(root, now = new Date()) {
  // A log staged by an aborted commit (empty message, failed hook) would ride along twice.
  const stale = git(root, 'diff', '--cached', '--name-only', '--diff-filter=A', '-z',
    ...(tryGit(root, 'rev-parse', '--verify', '--quiet', 'HEAD') ? ['HEAD'] : []), '--', LOG_DIR)
    .split('\0').filter(Boolean);
  for (const file of stale) {
    git(root, 'rm', '--cached', '--quiet', '--', file);
    rmSync(path.join(root, file), { force: true });
  }

  // The notes go into the JSON; the draft file itself never gets committed (e.g. after `git add -A`).
  git(root, 'rm', '--cached', '--quiet', '--ignore-unmatch', '--', NOTES_FILE);

  const log = buildLog(root, now);
  const dir = path.join(root, LOG_DIR);
  mkdirSync(dir, { recursive: true });

  const stem = fileStem(log.branch, now);
  let file = path.join(dir, `${stem}.json`);
  for (let n = 2; existsSync(file); n += 1) file = path.join(dir, `${stem}_${n}.json`); // same second

  writeFileSync(file, `${JSON.stringify(log, null, 2)}\n`);
  git(root, 'add', '--', path.relative(root, file));
  return file;
}

/** post-commit: notes were consumed; partial commits left the log out of the real index. */
export function afterCommit(root) {
  const committed = git(root, 'diff-tree', '--no-commit-id', '--name-only', '-r', '-z', '--root', 'HEAD', '--', LOG_DIR)
    .split('\0').filter(Boolean);
  // No log in this commit (--no-verify, rebase): the notes weren't used, keep them for the next one.
  if (!committed.length) return;
  git(root, 'add', '--', ...committed);
  rmSync(path.join(root, NOTES_FILE), { force: true });
}

// ---------- cli ----------

if (process.argv[1] && path.resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  const args = process.argv.slice(2);
  if (args.includes('--help')) {
    console.log('Usage: node commit-log.mjs [--install [--target DIR] [--force] | --after-commit]');
  } else if (args.includes('--install')) {
    const targetIndex = args.indexOf('--target');
    const target = path.resolve(targetIndex >= 0 ? args[targetIndex + 1] : process.cwd());
    const result = install(target, { force: args.includes('--force') });
    console.log(result.status === 'written' ? `WRITTEN ${HOOKS_DIR}/pre-commit + post-commit (core.hooksPath=${HOOKS_DIR})` : `SKIPPED commit log hooks (${result.reason})`);
  } else {
    try {
      const root = git(process.cwd(), 'rev-parse', '--show-toplevel');
      if (args.includes('--after-commit')) afterCommit(root);
      else console.log(`commit log: ${path.relative(root, writeLog(root))}`);
    } catch (error) {
      // A log failure must never look like a failed commit.
      console.error(`commit log skipped: ${error.message}`);
    }
  }
}
