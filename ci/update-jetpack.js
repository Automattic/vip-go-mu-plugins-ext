const { default: axios } = require('axios');
const fs = require('fs');
const { execSync} = require('child_process');

const CONFIG_FILE = './config.json';
const JETPACK_REPO = 'https://github.com/Automattic/jetpack-production';
const JETPACK_FOLDER_PREFIX = 'jetpack-';

const configFile = fs.readFileSync(CONFIG_FILE, 'utf8');
const config = JSON.parse(configFile);
console.log('Config', config);

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

function parseVersion( version ) {
    return version.split(/[.-]/).map(part => isNaN(Number(part)) ? part : Number(part));
}

function compareVersions(a,b) {
    if ( a === b ) {
        return 0;
    }
    const aParts = parseVersion(a)
    const bParts = parseVersion(b)

    const majorCmpr = aParts[0] - bParts[0];
    if ( majorCmpr !== 0 ) {
        return majorCmpr;
    }

    const minorCmpr = aParts[1] - bParts[1];
    if ( minorCmpr !== 0 ) {
        return minorCmpr;
    }

    if ( aParts.length === 3 && aParts[2] === 'beta' ) {
        return -1;
    }
    if ( bParts.length === 3 && bParts[2] === 'beta' ) {
        return 1;
    }

    if ( aParts.length === 2) {
        return -1;
    }
    if ( bParts.length === 2) {
        return 1;
    }

    return aParts[2] - bParts[2];
}

const MAX_BETA = 10;

function incrementPatchVersion(version, skipBeta = false) {
    const betaMatch = version.match(/beta(\d+)?/);
    const betaNumber = betaMatch && betaMatch[1] ? Number(betaMatch[1]) : 1;
    if (betaMatch && betaNumber < MAX_BETA && !skipBeta) {
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

function formatVersion(minor, patch) {
    if (!patch) {
        return `${minor}`;
    }
    if (patch.startsWith('beta')) {
        return `${minor}-${patch}`;
    }
    return `${minor}.${patch}`;
}

async function checkVersionExists(version) {
    try {
        const exists = await axios.get(`https://github.com/Automattic/jetpack-production/tree/${version}`);
        return exists.status === 200;
    } catch(e) {
        return false
    }
}


async function findPatch(minor) {
    let currentPatch = 'beta';
    let lastPatch = null;
    let foundLastPatch = false;

    while (!foundLastPatch) {
        const version = formatVersion(minor, currentPatch);

        const exists = await checkVersionExists(version);

        if (exists) {
            lastPatch = currentPatch;
            currentPatch = incrementPatchVersion(currentPatch);
        } else if(! currentPatch.startsWith('beta')) {
            foundLastPatch = true;
        } else {
            currentPatch = incrementPatchVersion(currentPatch, true);
        }
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

async function maybeUpdateVersion(minorVersion, version) {
    const folder = `${JETPACK_FOLDER_PREFIX}${minorVersion}`;

    if ( config.current[minorVersion] ) {
        const versionCmp = compareVersions(version,config.current[minorVersion]);
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
        const command = `git subtree add -P ${folder} --squash ${JETPACK_REPO} ${version} -m "Update jetpack ${folder} subtree with tag ${version}"`;
        execSync(command);
    } else {
        // add
        const command = `git subtree add -P ${folder} --squash ${JETPACK_REPO} ${version} -m "Add jetpack ${folder} subtree with tag ${version}"`;
        execSync(command);
    }
    await pingSlack(`Updated ${folder} to ${version}\nhttps://github.com/Automattic/vip-go-mu-plugins-ext/commits/trunk`);
    config.current[minorVersion] = version;
    return true;
}

function persistConfig() {
    console.log('Persisting config', config);

    fs.writeFileSync(CONFIG_FILE, JSON.stringify(config, null, 2));

    execSync('git commit -avm "Update config current"');
}


function maybeConfigGit() {
    let email ='';
    try {
        email = execSync('git config user.email').toString().trim();
    } catch(e) {
    }

    if (!email) {
        execSync('git config user.email "Jetpack@update.bot"');
        execSync('git config user.name "Jetpack Update Bot"');
    }
}

function removeFolder(folderName) {
    console.log(`Removing ${folderName}`);
    fs.rmdirSync( folderName, { recursive: true } );
    execSync( `git add ${ folderName }` )
    execSync( `git commit -m "Removing ${folderName}"`);
}

async function maybeUpdateVersions() {
    let currentMinor = config.lowestVersion;
    let foundLastMinor = false;
    let updatedSomething = false;
    while (!foundLastMinor) {
        if (config.skip.includes(currentMinor) || config.ignore.includes(currentMinor)) {
            console.log('Skipping', currentMinor);
        } else {
            console.log('Checking', currentMinor);
            const patch = await findPatch(currentMinor);
            if (patch === null) {
                console.log('Not found');
                foundLastMinor = true;
            } else {
                const version = formatVersion(currentMinor, patch);
                console.log('Found:', version);

                const updated = await maybeUpdateVersion(currentMinor, version);
                updatedSomething = updated || updatedSomething;

            }
        }
        currentMinor = incrementVersion(currentMinor);
    }
    return updatedSomething;
}

function isMinorSupported(minor) {
    if ( config.skip.includes(minor) ) {
        return false;
    }

    if ( compareVersions(minor, config.lowestVersion) < 0 ) {
        return false;
    }

    return true;

}

async function maybeDeleteRemovedVersions() {
    console.log('Checking existing folders');

    let updatedSomething = false;
    const folders = fs.readdirSync('./');
    const jetpackFolders = folders.filter(folder => folder.startsWith(JETPACK_FOLDER_PREFIX));
    for (const folder of jetpackFolders) {
        const [, minor] = folder.split(JETPACK_FOLDER_PREFIX);
        const supported = isMinorSupported(minor);
        if (!supported) {
            removeFolder(folder);

            delete config.current[minor]
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

