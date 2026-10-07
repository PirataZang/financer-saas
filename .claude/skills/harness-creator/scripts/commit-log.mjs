#!/usr/bin/env node
// Per-commit change log: every commit writes .harness/commits/<branch>_<dd_mm_yyyy>_<HH-MM-SS>.json
// describing exactly what that commit did on that branch (and, off the base branch, every commit
// the branch has so far). Self-contained on purpose: --install copies this file into the repo's
// .githooks/, so the hook works in every clone without the skill installed.
//
//   node commit-log.mjs --install [--target DIR] [--force]   install the post-commit hook
//   node commit-log.mjs                                      log HEAD (what the hook runs)
import { execFileSync } from 'node:child_process';
import { chmodSync, copyFileSync, existsSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const HOOKS_DIR = '.githooks';
const LOG_DIR = path.join('.harness', 'commits');
const MARKER = '# harness-creator:commit-log';

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

  const hookFile = path.join(root, HOOKS_DIR, 'post-commit');
  if (existsSync(hookFile) && !readFileSync(hookFile, 'utf8').includes(MARKER) && !force) {
    return { status: 'skipped', reason: `${HOOKS_DIR}/post-commit exists and is not ours (use --force)` };
  }

  mkdirSync(path.dirname(hookFile), { recursive: true });
  copyFileSync(fileURLToPath(import.meta.url), path.join(root, HOOKS_DIR, 'commit-log.mjs'));
  writeFileSync(hookFile, `#!/bin/sh\n${MARKER}\n# Never blocks a commit: the script swallows its own errors.\nnode "$(git rev-parse --show-toplevel)/${HOOKS_DIR}/commit-log.mjs" || true\n`);
  chmodSync(hookFile, 0o755);
  git(root, 'config', 'core.hooksPath', HOOKS_DIR);

  return { status: 'written', path: hookFile };
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

function baseBranch(root, current) {
  const configured = tryGit(root, 'config', '--get', 'init.defaultBranch');
  return [configured, 'main', 'master', 'develop'].find(
    (name) => name && name !== current && tryGit(root, 'rev-parse', '--verify', '--quiet', `refs/heads/${name}`),
  );
}

export function buildLog(root) {
  const hash = git(root, 'rev-parse', 'HEAD');
  const [parents, authorName, authorEmail, authorDate, committerDate, subject] = git(
    root, 'show', '-s', '--format=%P%x00%an%x00%ae%x00%aI%x00%cI%x00%s', hash,
  ).split('\0');
  const body = git(root, 'show', '-s', '--format=%b', hash).trim();
  const branch = tryGit(root, 'rev-parse', '--abbrev-ref', 'HEAD');

  // First parent (merges: what the merge brought into this branch); root commit: the empty tree.
  const parentList = parents ? parents.split(' ') : [];
  const from = parentList[0] ?? git(root, 'hash-object', '-t', 'tree', '/dev/null');
  // The logs themselves get committed with the next commit; they're not "what was done".
  const notLogs = ['--', '.', `:(exclude)${LOG_DIR.split(path.sep).join('/')}`];
  const files = parseChanges(
    git(root, 'diff', '--name-status', '-z', '-M', from, hash, ...notLogs),
    git(root, 'diff', '--numstat', '-z', '-M', from, hash, ...notLogs),
  );

  const log = {
    branch: branch === 'HEAD' ? null : branch,
    commit: {
      hash,
      short: hash.slice(0, 7),
      parents: parentList,
      merge: parentList.length > 1,
      author: { name: authorName, email: authorEmail },
      authored_at: authorDate,
      committed_at: committerDate,
      subject,
      body,
    },
    stats: {
      files: files.length,
      additions: files.reduce((sum, f) => sum + (f.additions ?? 0), 0),
      deletions: files.reduce((sum, f) => sum + (f.deletions ?? 0), 0),
    },
    files,
  };

  // Off the base branch: everything this branch has done so far, newest first.
  const base = branch && branch !== 'HEAD' ? baseBranch(root, branch) : null;
  const mergeBase = base && tryGit(root, 'merge-base', base, hash);
  if (mergeBase) {
    const commits = git(root, 'log', '--format=%H%x00%s%x00%cI', `${mergeBase}..${hash}`)
      .split('\n')
      .filter(Boolean)
      .map((line) => {
        const [h, s, d] = line.split('\0');
        return { hash: h, subject: s, committed_at: d };
      });
    log.branch_history = { base, merge_base: mergeBase, commits };
  }

  return log;
}

export function writeLog(root) {
  const log = buildLog(root);
  const dir = path.join(root, LOG_DIR);
  mkdirSync(dir, { recursive: true });

  const stem = fileStem(log.branch, new Date(log.commit.committed_at));
  // Same second (rebase, quick amend): keep both, tell them apart by hash.
  let file = path.join(dir, `${stem}.json`);
  if (existsSync(file)) file = path.join(dir, `${stem}_${log.commit.short}.json`);

  writeFileSync(file, `${JSON.stringify(log, null, 2)}\n`);
  return file;
}

// ---------- cli ----------

if (process.argv[1] && path.resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  const args = process.argv.slice(2);
  if (args.includes('--help')) {
    console.log('Usage: node commit-log.mjs [--install [--target DIR] [--force]]');
  } else if (args.includes('--install')) {
    const targetIndex = args.indexOf('--target');
    const target = path.resolve(targetIndex >= 0 ? args[targetIndex + 1] : process.cwd());
    const result = install(target, { force: args.includes('--force') });
    console.log(result.status === 'written' ? `WRITTEN ${result.path} (core.hooksPath=${HOOKS_DIR})` : `SKIPPED commit log hook (${result.reason})`);
  } else {
    try {
      const root = git(process.cwd(), 'rev-parse', '--show-toplevel');
      console.log(`commit log: ${path.relative(root, writeLog(root))}`);
    } catch (error) {
      // A log failure must never look like a failed commit.
      console.error(`commit log skipped: ${error.message}`);
    }
  }
}
