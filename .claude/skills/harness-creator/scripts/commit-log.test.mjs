// node --test scripts/commit-log.test.mjs — runs real git in throwaway repos.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { mkdtempSync, readdirSync, readFileSync, renameSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { branchSlug, fileStem, install } from './commit-log.mjs';

function repo() {
  const dir = mkdtempSync(path.join(tmpdir(), 'commit-log-'));
  const git = (...args) => execFileSync('git', args, { cwd: dir, encoding: 'utf8' });
  git('init', '-q', '-b', 'main');
  git('config', 'user.name', 'Test');
  git('config', 'user.email', 'test@example.com');
  return { dir, git };
}

const logs = (dir) => readdirSync(path.join(dir, '.harness', 'commits')).sort();
const readLog = (dir, name) => JSON.parse(readFileSync(path.join(dir, '.harness', 'commits', name), 'utf8'));

test('file names: branch + local date/time, safe characters', () => {
  assert.equal(fileStem('main', new Date(2026, 9, 7, 9, 5, 3)), 'main_07_10_2026_09-05-03');
  assert.equal(branchSlug('feature/login form'), 'feature-login-form');
  assert.equal(branchSlug('HEAD'), 'detached');
});

test('every commit writes its own JSON with what it changed on that branch', () => {
  const { dir, git } = repo();
  try {
    assert.equal(install(dir).status, 'written');
    assert.equal(git('config', 'core.hooksPath').trim(), '.githooks');

    writeFileSync(path.join(dir, 'a.txt'), 'one\ntwo\n');
    git('add', 'a.txt', '.githooks');
    git('commit', '-q', '-m', 'first', '-m', 'body text');

    let [first] = logs(dir);
    assert.match(first, /^main_\d{2}_\d{2}_\d{4}_\d{2}-\d{2}-\d{2}\.json$/);
    let log = readLog(dir, first);
    log.files = log.files.filter((f) => !f.path.startsWith('.githooks/'));
    assert.equal(log.branch, 'main');
    assert.equal(log.commit.subject, 'first');
    assert.equal(log.commit.body, 'body text');
    assert.deepEqual(log.files, [{ status: 'A', path: 'a.txt', additions: 2, deletions: 0 }]);
    assert.equal(log.branch_history, undefined);

    git('checkout', '-q', '-b', 'feature/x');
    renameSync(path.join(dir, 'a.txt'), path.join(dir, 'b.txt'));
    writeFileSync(path.join(dir, 'img.bin'), Buffer.from([0, 1, 2, 0, 255]));
    git('add', '-A', '.');
    git('commit', '-q', '-m', 'rename and binary');
    writeFileSync(path.join(dir, 'b.txt'), 'one\nTWO\nthree\n');
    git('commit', '-q', '-am', 'edit');

    const branchLogs = logs(dir).filter((name) => name.startsWith('feature-x_'));
    assert.equal(branchLogs.length, 2, 'two commits in the same second still get two files');
    const byHash = Object.fromEntries(branchLogs.map((name) => [readLog(dir, name).commit.subject, readLog(dir, name)]));

    assert.deepEqual(
      byHash['rename and binary'].files.sort((a, b) => a.path.localeCompare(b.path)),
      [
        { status: 'R', path: 'b.txt', from: 'a.txt', additions: 0, deletions: 0 },
        { status: 'A', path: 'img.bin', additions: null, deletions: null },
      ],
    );
    log = byHash.edit;
    assert.deepEqual(log.stats, { files: 1, additions: 2, deletions: 1 });
    assert.equal(log.branch_history.base, 'main');
    assert.deepEqual(log.branch_history.commits.map((c) => c.subject), ['edit', 'rename and binary']);
  } finally {
    rmSync(dir, { recursive: true, force: true });
  }
});

test('does not clobber hooks it does not own', () => {
  const { dir, git } = repo();
  try {
    git('config', 'core.hooksPath', '.husky');
    assert.equal(install(dir).status, 'skipped');
    assert.equal(install(dir, { force: true }).status, 'written');
    assert.equal(install(mkdtempSync(path.join(tmpdir(), 'no-git-'))).status, 'skipped');
  } finally {
    rmSync(dir, { recursive: true, force: true });
  }
});
