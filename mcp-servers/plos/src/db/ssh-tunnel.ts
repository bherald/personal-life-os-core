import { spawn, ChildProcess } from 'child_process';
import { CONFIG } from '../config.js';
import { logger } from '../util/logger.js';

let tunnelProcess: ChildProcess | null = null;

/**
 * Establish SSH tunnels to a remote PLOS instance for MySQL and PostgreSQL.
 * Uses autossh-style approach: single ssh process with two -L forwards.
 */
export async function startTunnels(): Promise<void> {
  if (process.env.SKIP_SSH_TUNNEL === 'true') {
    logger.info('SSH tunnel skipped (SKIP_SSH_TUNNEL=true, direct LAN connection)');
    return;
  }
  if (tunnelProcess) return;

  const { host, user } = CONFIG.ssh;

  return new Promise((resolve, reject) => {
    const proc = spawn('ssh', [
      '-N',                           // No remote command
      ...(CONFIG.ssh.keyPath === '' ? [] : ['-i', CONFIG.ssh.keyPath]),
      '-o', 'BatchMode=yes',          // Never prompt for password/passphrase
      '-o', `StrictHostKeyChecking=${CONFIG.ssh.strictHostKeyChecking}`,
      '-o', 'ServerAliveInterval=30',
      '-o', 'ServerAliveCountMax=3',
      '-o', 'ExitOnForwardFailure=yes',
      '-L', `${CONFIG.mysql.port}:127.0.0.1:3306`,
      '-L', `${CONFIG.pgsql.port}:127.0.0.1:5432`,
      `${user}@${host}`,
    ], {
      stdio: ['ignore', 'pipe', 'pipe'],
    });

    tunnelProcess = proc;

    proc.on('error', (err) => {
      logger.error('SSH tunnel process error', { error: err.message });
      tunnelProcess = null;
      reject(err);
    });

    proc.on('exit', (code) => {
      logger.warn('SSH tunnel exited', { code });
      tunnelProcess = null;
    });

    // SSH with -N doesn't produce stdout on success.
    // Wait briefly then test connectivity.
    setTimeout(async () => {
      if (proc.killed || proc.exitCode !== null) {
        reject(new Error('SSH tunnel failed to start'));
        return;
      }
      logger.info('SSH tunnels established', {
        mysql: `127.0.0.1:${CONFIG.mysql.port} → ${host}:3306`,
        pgsql: `127.0.0.1:${CONFIG.pgsql.port} → ${host}:5432`,
      });
      resolve();
    }, 1500);
  });
}

export function stopTunnels(): void {
  if (tunnelProcess) {
    tunnelProcess.kill();
    tunnelProcess = null;
    logger.info('SSH tunnels closed');
  }
}
