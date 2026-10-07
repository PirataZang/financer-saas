// node --test scripts/commit-log.test.mjs — runs real git commits in throwaway repos.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { existsSync, mkdirSync, mkdtempSync, readFileSync, renameSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { branchSlug, fileStem, install } from './commit-log.mjs';

function repo() {
  const dir = mkdtempSync(path.join(tmpdir(), 'commit-log-'));
  const git = (...args) => execFileSync('git', args, { cwd: dir, encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] });
  git('init', '-q', '-b', 'main');
  git('config', 'user.name', 'Test');
  git('config', 'user.email', 'test@example.com');
  assert.equal(install(dir).status, 'written');
  const write = (file, content) => writeFileSync(path.join(dir, file), content);
  // Files in a commit, split into the log(s) and the rest.
  const committed = (rev = 'HEAD') => {
    const names = git('show', '--name-only', '--format=', rev).split('\n').filter(Boolean);
    return { logs: names.filter((n) => n.startsWith('.harness/commits/')), files: names.filter((n) => !n.startsWith('.harness/') && !n.startsWith('.githooks/')) };
  };
  const readLog = (rev = 'HEAD') => JSON.parse(git('show', `${rev}:${committed(rev).logs[0]}`));
  const status = () => git('status', '--porcelain');
  return { dir, git, write, committed, readLog, status };
}

test('file names: branch + local date/time, safe characters', () => {
  assert.equal(fileStem('main', new Date(2026, 9, 7, 9, 5, 3)), 'main_07_10_2026_09-05-03');
  assert.equal(branchSlug('feature/login form'), 'feature-login-form');
  assert.equal(branchSlug(null), 'detached');
});

test('the log is written before the commit and travels inside it', () => {
  const { dir, git, write, committed, readLog, status } = repo();
  try {
    write('a.txt', 'one\ntwo\n');
    mkdirSync(path.join(dir, '.harness'), { recursive: true });
    write('.harness/commit-notes.md', 'Adiciona a.txt\n');
    git('add', '-A', '.'); // stages the notes draft too — it must not be committed
    git('commit', '-q', '-m', 'first');
    assert.equal(git('ls-tree', '-r', '--name-only', 'HEAD').includes('commit-notes.md'), false);

    const first = committed();
    assert.equal(first.logs.length, 1);
    assert.match(first.logs[0], /^\.harness\/commits\/main_\d{2}_\d{2}_\d{4}_\d{2}-\d{2}-\d{2}(_\d+)?\.json$/);
    let log = readLog();
    assert.equal(log.branch, 'main');
    assert.equal(log.parent, null);
    assert.equal(log.notes, 'Adiciona a.txt');
    assert.deepEqual(log.author, { name: 'Test', email: 'test@example.com' });
    assert.deepEqual(log.files.filter((f) => f.path === 'a.txt'), [{ status: 'A', path: 'a.txt', additions: 2, deletions: 0 }]);
    assert.equal(existsSync(path.join(dir, '.harness/commit-notes.md')), false, 'notes are consumed by the commit');
    assert.equal(status(), '', 'nothing left behind');

    // Branch: rename + binary, then `commit -a`, then a partial `commit <path>`.
    const mainHead = git('rev-parse', 'HEAD').trim();
    git('checkout', '-q', '-b', 'feature/x');
    renameSync(path.join(dir, 'a.txt'), path.join(dir, 'b.txt'));
    write('img.bin', Buffer.from([0, 1, 2, 0, 255]));
    git('add', '-A', '.');
    git('commit', '-q', '-m', 'rename and binary');
    log = readLog();
    assert.equal(log.parent, mainHead);
    assert.deepEqual(
      log.files.sort((a, b) => a.path.localeCompare(b.path)),
      [
        { status: 'R', path: 'b.txt', from: 'a.txt', additions: 0, deletions: 0 },
        { status: 'A', path: 'img.bin', additions: null, deletions: null },
      ],
    );
    assert.equal(status(), '');

    write('b.txt', 'one\nTWO\nthree\n');
    git('commit', '-q', '-am', 'edit');
    log = readLog();
    assert.deepEqual(log.stats, { files: 1, additions: 2, deletions: 1 });
    assert.equal(log.branch_history.base, 'main');
    assert.deepEqual(log.branch_history.previous_commits.map((c) => c.subject), ['rename and binary']);
    assert.equal(status(), '');

    write('b.txt', 'partial\n');
    write('c.txt', 'not committed\n');
    git('add', 'c.txt');
    git('commit', '-q', '-m', 'partial', '--', 'b.txt');
    assert.deepEqual(committed().files, ['b.txt']);
    assert.equal(committed().logs.length, 1);
    assert.deepEqual(readLog().files.map((f) => f.path), ['b.txt']);
    assert.equal(status(), 'A  c.txt\n', 'the real index keeps the log and the other staged file');

    // Every commit has exactly one log of its own.
    const counts = git('log', '--format=%H').trim().split('\n').map((rev) => committed(rev).logs.length);
    assert.deepEqual(counts, [1, 1, 1, 1]);
  } finally {
    rmSync(dir, { recursive: true, force: true });
  }
});

test('an aborted commit does not leave a second log behind', () => {
  const { dir, git, write, committed, status } = repo();
  try {
    write('a.txt', 'x\n');
    git('add', 'a.txt', '.githooks');
    // Empty message aborts after pre-commit already staged a log.
    assert.throws(() => git('commit', '-q', '-m', ''));
    assert.match(status(), /A {2}\.harness\/commits\//);

    git('commit', '-q', '-m', 'real');
    assert.equal(committed().logs.length, 1);
    assert.equal(status(), '');
  } finally {
    rmSync(dir, { recursive: true, force: true });
  }
});

test('--no-verify skips the log and keeps the notes for the next commit', () => {
  const { dir, git, write, committed } = repo();
  try {
    mkdirSync(path.join(dir, '.harness'), { recursive: true });
    write('.harness/commit-notes.md', 'pending notes');
    write('a.txt', 'x\n');
    git('add', 'a.txt', '.githooks');
    git('commit', '-q', '--no-verify', '-m', 'skip');
    assert.equal(committed().logs.length, 0);
    assert.equal(readFileSync(path.join(dir, '.harness/commit-notes.md'), 'utf8'), 'pending notes');
  } finally {
    rmSync(dir, { recursive: true, force: true });
  }
});

test('does not clobber hooks it does not own', () => {
  const dir = mkdtempSync(path.join(tmpdir(), 'commit-log-'));
  try {
    execFileSync('git', ['init', '-q'], { cwd: dir });
    execFileSync('git', ['config', 'core.hooksPath', '.husky'], { cwd: dir });
    assert.equal(install(dir).status, 'skipped');
    assert.equal(install(dir, { force: true }).status, 'written');
    assert.equal(install(mkdtempSync(path.join(tmpdir(), 'no-git-'))).status, 'skipped');
  } finally {
    rmSync(dir, { recursive: true, force: true });
  }
});
