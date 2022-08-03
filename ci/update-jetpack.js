const { default: axios } = require('axios');
const fs = require('fs');
const { execSync} = require('child_process');

const CONFIG_FILE = './config.json';
const JETPACK_REPO = 'https://github.com/Automattic/jetpack-production';

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

    if (config.skip.includes(result)) {
        return incrementVersion(result);
    }

    return result;
}

function incrementPatchVersion(version) {
    if (version === 'beta') {
        return '';
    }
    if (!version) {
        return 1;
    }
    return Number(version) + 1;
}

function formatVersion(minor, patch) {
    if (!patch) {
        return `${minor}`;
    }
    if (patch === 'beta') {
        return `${minor}-beta`;
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
    let lastPatch = '';
    let foundLastPatch = false;

    while (!foundLastPatch) {
        const version = formatVersion(minor, currentPatch);

        const exists = await checkVersionExists(version);
        if (exists) {
            lastPatch = currentPatch;
            currentPatch = incrementPatchVersion(currentPatch);
        } else {
            if (currentPatch === 'beta') {
                return null;
            }
            foundLastPatch = true;
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
    const folder = `jetpack-${minorVersion}`;

    if (config.current[minorVersion] === version) {
        console.log(`${minorVersion} already up to date`);
        return false;
    } else {
        if (config.current[minorVersion]) {
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

async function main() {
    maybeConfigGit();

    let currentMinor = config.lowestVersion;
    let foundLastMinor = false;
    let updatedSomething = false;
    while (!foundLastMinor) {

        console.log('checking', currentMinor);
        const patch = await findPatch(currentMinor);
        if (patch === null) {
            console.log('Not found');
            foundLastMinor = true;
        } else {
            const version = formatVersion(currentMinor, patch);
            console.log('Found:', version);

            const updated = await maybeUpdateVersion(currentMinor, version);
            updatedSomething = updated || updatedSomething;

            currentMinor = incrementVersion(currentMinor);
        }
    }

    if (updatedSomething) {
        persistConfig();
        execSync('git push');
    }
}

main();

