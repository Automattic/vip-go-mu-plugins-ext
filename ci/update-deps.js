#!/usr/bin/env node

const { default: axios } = require('axios');
const fs = require('fs');
const { execSync } = require('child_process');
const { compareVersions } = require( './utils' );

const CONFIG_FILE = './config.json';

const configFile = fs.readFileSync(CONFIG_FILE, 'utf8');
const globalConfig = JSON.parse(configFile);
console.log('Config', globalConfig);

function incrementVersion(version) {
    const [major, minor] = version.split('.').map(Number);
    let result = '';
    if (minor === 9) {
        result = `${major + 1}.0`;
    } else {
        result = `${major}.${minor + 1}`;
    }

    return result;
}

function incrementPatchVersion(version, versionExists) {
    const betaMatch = version.match(/beta(\d+)?/);
    if (betaMatch && versionExists) {
        const betaNumber = betaMatch && betaMatch[1] ? Number(betaMatch[1]) : 1;
        return `beta${betaNumber + 1}`;
    }
    if (betaMatch) {
        return '';
    }
    if (!version) {
        return '1';
    }
    return (Number(version) + 1) + '';
}

function formatVersion(plugin, minor, patch) {
    if (!patch) {
        return `${minor}`;
    }
    if (patch.startsWith('beta')) {
        return `${minor}-${patch}`;
    }
    return `${minor}.${patch}`;
}

async function checkVersionExists(plugin, version) {

    try {
        const exists = await axios.get(`${globalConfig[plugin].repo}/tree/${version}`);
        return exists.status === 200;
    } catch (e) {
        return false
    }
}

async function findPatch(plugin, minor) {
    // TODO: this is dumb, and will likely need to be changed when we add next dependency that doesn't follow the semver pattern
    let currentPatch = plugin === 'parsely' ? '0' : 'beta';
    let lastPatch = null;
    let foundLastPatch = false;

    while (!foundLastPatch) {
        const version = formatVersion(plugin, minor, currentPatch);

        const exists = await checkVersionExists(plugin, version);
        if (exists) {
            lastPatch = currentPatch;
        } else if (!currentPatch.startsWith('beta')) {
            foundLastPatch = true;
        }

        currentPatch = incrementPatchVersion(currentPatch, exists);
    }
    return lastPatch;
}

async function pingSlack(message) {
    if (process.env.SLACK_WEBHOOK) {
        const payload = {
            text: message,
        };
        await axios.post(process.env.SLACK_WEBHOOK, payload);
    } else {
        throw new Error('No slack webhook configured');
    }
}

async function maybeUpdateVersion(plugin, minorVersion, version) {
    const config = globalConfig[plugin];
    const folder = `${config.folderPrefix}${minorVersion}`;

    try {
        if (config.current[minorVersion]) {
            const versionCmp = compareVersions(version, config.current[minorVersion]);
            if (versionCmp < 0) {
                console.log(`${minorVersion} tried to downgrade to ${version}, but skipped`);
                return false;
            } else if (versionCmp === 0) {
                console.log(`${minorVersion} already up to date`);
                return false;
            }

            // update
            execSync(`git rm -r ${folder}`);
            execSync(`git commit -m "Removing ${folder} for subtree replacement to ${version}"`);
            const command = `git subtree add -P ${folder} --squash ${config.repo} ${version} -m "Update ${plugin} ${folder} subtree with tag ${version}"`;
            execSync(command);
        } else {
            // add
            const command = `git subtree add -P ${folder} --squash ${config.repo} ${version} -m "Add ${plugin} ${folder} subtree with tag ${version}"`;
            execSync(command);
        }
        await pingSlack(`Updated ${folder} to ${version}\nhttps://github.com/Automattic/vip-go-mu-plugins-ext/commits/trunk`);
        globalConfig[plugin].current[minorVersion] = version;
        return true;
    } catch (err) {
        console.error(err);
        return false;
    }
}

function persistConfig() {
    console.log('Persisting config', globalConfig);

    fs.writeFileSync(CONFIG_FILE, JSON.stringify(globalConfig, null, 2));

    execSync('git commit -avm "Update config.json"');
}


function maybeConfigGit() {
    let email = '';
    try {
        email = execSync('git config user.email').toString().trim();
    } catch (e) {
    }

    if (!email) {
        execSync('git config user.email "Jetpack@update.bot"');
        execSync('git config user.name "Jetpack Update Bot"');
    }
}

function removeFolder(folderName) {
    console.log(`Removing ${folderName}`);
    fs.rmdirSync(folderName, { recursive: true });
    execSync(`git add ${folderName}`)
    execSync(`git commit -m "Removing ${folderName}"`);
}

async function maybeUpdateVersions() {
    let updatedSomething = false;

    for (const plugin in globalConfig) {
        console.log(`Updating ${plugin}`);

        const config = globalConfig[plugin];
        console.log(config);

        let currentMinor = config.lowestVersion;
        let foundLastMinor = false
        while (!foundLastMinor) {
            if (config.skip.includes(currentMinor) || config.ignore.includes(currentMinor)) {
                console.log('Skipping', currentMinor);
            } else {
                console.log('Checking', currentMinor);
                const patch = await findPatch(plugin, currentMinor);
                if (patch === null) {
                    console.log('Not found');
                    foundLastMinor = true;
                } else {
                    const version = formatVersion(plugin, currentMinor, patch);
                    console.log('Found:', version);

                    const updated = await maybeUpdateVersion(plugin, currentMinor, version);
                    updatedSomething = updated || updatedSomething;

                }
            }
            currentMinor = incrementVersion(currentMinor);
        }
    }

    return updatedSomething;
}

function isMinorSupported(plugin, minor) {
    if (globalConfig[plugin].skip.includes(minor)) {
        return false;
    }

    if (compareVersions(minor, globalConfig[plugin].lowestVersion) < 0) {
        return false;
    }

    return true;

}

/**
 * Checks folders against config to see if they need to be removed from repo.
 *
 * @returns bool updatedSomething Whether something was deleted or not
 */
async function maybeDeleteRemovedVersions() {
    console.log('Checking existing folders');

    let updatedSomething = false;
    const folders = fs.readdirSync('./');
    for (const plugin in globalConfig) {
        for ( const toRemove in globalConfig[plugin].skip ) {
            const folder = globalConfig[plugin].folderPrefix + globalConfig[plugin].skip[toRemove];
            if ( ! fs.existsSync( folder ) ) {
                continue;
            }

            console.log( 'Removing ' + folder );
            removeFolder( folder );
            delete globalConfig[plugin].current[toRemove]
            updatedSomething = true;
            await pingSlack(`Removed ${folder}\nhttps://github.com/Automattic/vip-go-mu-plugins-ext/commits/trunk`);
        }
    }

    return updatedSomething;
}

async function main() {
    maybeConfigGit();

    let updatedSomething = false;

    updatedSomething = await maybeUpdateVersions();
    updatedSomething = await maybeDeleteRemovedVersions() || updatedSomething;

    if (updatedSomething) {
        persistConfig();
        execSync('git push');
    }
}

main();
