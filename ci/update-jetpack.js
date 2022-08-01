const { default: axios } = require('axios');
const fs = require('fs');

const configFile = fs.readFileSync('./config.json', 'utf8');
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

async function main() {


    let currentMinor = config.lowestVersion;
    let foundLastMinor = false;

    while (!foundLastMinor) {
        let currentPatch = 'beta';
        let lastPatch = '';
        let foundLastPatch = false;
        console.log('checking', currentMinor);

        while (!foundLastPatch) {
            const version =formatVersion(currentMinor, currentPatch);

            const exists = await checkVersionExists(version);
            if (exists) {
                lastPatch = currentPatch;
                currentPatch = incrementPatchVersion(currentPatch);
            } else {
                if (currentPatch === 'beta') {
                    foundLastMinor = true;
                }
                foundLastPatch = true;
            }
        }
        if (!foundLastMinor) {
            const version = formatVersion(currentMinor, lastPatch);
            console.log('Found:', version);
        }
        currentMinor = incrementVersion(currentMinor);
    }
}

main();

