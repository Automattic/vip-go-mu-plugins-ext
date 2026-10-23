import { spawn } from 'node:child_process';

export default function globalSetup(): Promise<unknown> {
    if (process.env.CODESPACES === 'true' && process.env.GITHUB_TOKEN) {
        const child = spawn('gh', ['cs', 'ports', 'visibility', '-c', `${process.env.CODESPACE_NAME}`, '80:public'], { stdio: ['ignore', 'inherit', 'inherit'] });
        return new Promise((resolve) => {
            child.once('exit', resolve);
        });
    }

    return Promise.resolve();
}
