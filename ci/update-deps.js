#!/usr/bin/env node

const { default: axios } = require('axios');
const fs = require('fs');
const { execSync } = require('child_process');
const { compareVersions } = require( './utils' );
const marked = require('marked');

const CONFIG_FILE = './config.json';

const { LOBBY_VIP_TOKEN } = process.env;

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
            const oldVersion = config.current[minorVersion];
            const versionCmp = compareVersions(version, oldVersion);
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
            if ( plugin === 'jetpack' && oldVersion.includes('beta') && ! version.includes('beta') ) {
                // Only draft if we are going from beta -> release
                draftJPPost(version, 'release');
            }
        } else {
            // add
            const command = `git subtree add -P ${folder} --squash ${config.repo} ${version} -m "Add ${plugin} ${folder} subtree with tag ${version}"`;
            execSync(command);
            if ( plugin === 'jetpack' && version.includes( 'beta' ) ) {
                draftJPPost(version, 'beta');
            }
        }
        await pingSlack(`Updated ${folder} to ${version}\nhttps://github.com/Automattic/vip-go-mu-plugins-ext/commits/trunk`);
        globalConfig[plugin].current[minorVersion] = version;
        return true;
    } catch (err) {
        console.error(err);
        return false;
    }
}

/**
 * Drafts a post on Lobby VIP for Jetpack releases.
 *
 * @param {string} version - The version of Jetpack being released
 * @param {string} type - Type of post being drafted. Accepted values: beta, release
 * @returns {boolean} Whether the post was successfully drafted or not
 */
async function draftJPPost( version, type ) {
    const allowedTypes = [ 'beta', 'release' ];
    if ( ! allowedTypes.includes( type ) ) {
        return false;
    }

    if ( type === 'beta' && ! version.includes( 'beta' ) ) {
        return false;
    }

    const changelog = await fetchChangelog( version );
    const section = extractChangelogSection( changelog, version, type );

    if ( section ) {
        let title;
        let content;
        if ( type === 'beta' ) {
            title = `Call for Testing: Jetpack ${version}`;
            content = createJPBetaPostContent(version, section);
        } else {
            title = `New Release: Jetpack ${version}`;
            content = createJPReleasePostContent(version, section);
        }
        const post = createJPPost( title, content );
        if ( post ) {
            const postUrl = `https://lobby.vip.wordpress.com/wp-admin/post.php?post=${post.id}&action=edit`
            pingSlack(`@vip-cantina-team Jetpack ${version} draft created for review: ${postUrl}. Don't forget to deploy first before publishing!`);
            return true;
        } else {
            pingSlack(`@vip-cantina-team Error creating Jetpack ${version} draft.`);
            return false;
        }
    }
}

/**
 * Gets the entire changelog file contents of Jetpack from GitHub.
 *
 * @async
 * @param {string} version - The version of Jetpack being released
 * @returns {Promise<Object>} - The response data from the API
 */
async function fetchChangelog(version) {
    const url = `https://raw.githubusercontent.com/Automattic/jetpack-production/${version}/CHANGELOG.md`;

    const response = await axios.get(url);
    return response.data;
}

/**
 * Extract changelog section for specified Jetpack version.
 *
 * @param {string} changelog - The entire changelog file contents of Jetpack
 * @param {string} version - The version of Jetpack being released
 * @param {string} type - Type of Jetpack version being released. Accepted values: beta, release
 * @returns {string} changelog section for the specified version
 */
function extractChangelogSection(changelog, version, type) {
    let regex = new RegExp(`^\\s*(## ${version}\\s.*?)^\\s*### Other changes`, 'ms');
    let match = regex.exec(changelog);

    if ( ! match && type === 'release' ) {
        // Re-try with [x.x] format in changelog title since that's also used for releases
        regex = new RegExp(`^\\s*(## \\[${version}\\]\\s.*?)^\\s*### Other changes`, 'ms');
        match = regex.exec(changelog);
    }

    if ( match ) {
        return match[1].trim();
    }

    return false;
}

/**
 * Creates the draft for the Jetpack post.
 *
 * @param {string} title - The title of the post
 * @param {string} content - The content of the post
 */
function createJPPost(title, content) {
    const data = {
        title: title,
        content: content,
        status: 'draft',
        categories: 636069,
        tags: 636069,
    };

    axios.post('https://public-api.wordpress.com/wp/v2/sites/lobby.vip.wordpress.com/posts', data, {
        headers: {
            'Content-Type': 'application/json',
            'Authorization': `Bearer ${LOBBY_VIP_TOKEN}`
        }
    })
    .then((response) => {
        console.log(`Status Code: ${response.status}`);
        console.log('Data:', response.data);
        return response.data;
    })
    .catch((error) => {
        console.error('Error:', error.message);
        return false;
    });
}

/**
 * Create body content for Jetpack release post.
 *
 * @param {string} version - The version of Jetpack being released
 * @param {string} section - The changelog section for the specified version
 * @return {string} The body content for the Jetpack release post
 */
function createJPReleasePostContent(version, section) {
    const image = 'https://lobby-vip.files.wordpress.com/2021/05/3-v1_52018_preview-2.png?w=960';
    let content = `<img src="${image}" alt="New Jetpack release">`;
    content += `<p>Jetpack ${version} has been made the default Jetpack version on the VIP Platform.</p>`;
    content += `<h1>What is being added or changed?</h1>`;
    content += marked.parse(section);

    const releaseNotesLink = `https://github.com/Automattic/jetpack-production/releases/tag/${version}`;
    content += `<p>For more details about this release (including specific changes), please see the <a href="${releaseNotesLink}" target="_blank">release notes</a>.</p>`;
    content += `<h3>Questions?</h3>`;
    content += `If you have any questions, related to this release, please open a <a href="https://wpvip.com/documentation/developing-with-vip/accessing-vip-support/" target="_blank">support ticket</a> and we will be happy to assist.`;

    return content;
}

/**
 * Creates the content for the Jetpack beta post to go to the Lobby
 *
 * @param {string} version - The version of Jetpack being released
 * @param {string} section - The section of the changelog for this beta version
 * @returns {string} content - The generated content for the Lobby post
 */
function createJPBetaPostContent(version, section) {
    const downloadLink = `<a href="https://github.com/Automattic/jetpack-production/releases/tag/${version}">available here</a>`;
    let content = `<p>Jetpack <strong>${version}</strong> is available now for testing and the download link is ${downloadLink} </p>`;

    const officialVersion = version.replace(/-beta\d?/g, '');
    const today = new Date();
    const dateFormatter = new Intl.DateTimeFormat('en-US', {
        weekday: 'long',
        year: 'numeric',
        month: 'long',
        day: 'numeric'
    });
    const releaseDate = dateFormatter.format(today.setDate(today.getDate() + 9)); // Assumes it's a Tuesday
    content += `<p>Jetpack ${officialVersion} will be deployed to VIP on <strong>${releaseDate}</strong>*. The upgrade is expected to be performed at 17:00 UTC (1:00PM ET).</p>`;

    content += `<p><i>*This deployment date and time are subject to change if issues are discovered during testing of the Jetpack release.</i></p>
    <p>A full list of changes is available in the <a href="https://github.com/Automattic/jetpack/commits/" target="_blank">commit log</a>.</p>
    <h1>What is being added or changed?</h1>`;
    content += marked.parse(section);

    content += `<h1>What do I need to do?</h1>
    <p>We recommend the below:</p>
    <ol>
    <li>Installing the release on your non-production sites using <a href="https://github.com/Automattic/jetpack/blob/trunk/projects/plugins/jetpack/to-test.md" target="_blank">these instructions</a>.</li>
    <li>Running through the testing flows outlined in the <a href="https://github.com/Automattic/jetpack/blob/jetpack/branch-${officialVersion}/projects/plugins/jetpack/to-test.md" target="_blank">Jetpack Testing Guide</a>.</li>
    </ol>

    <p>As you're testing, there are a few things to keep in mind:</p>
    <ul>
    <li>Check your browser's <a href="https://wordpress.org/documentation/article/using-your-browser-to-diagnose-javascript-errors/" target="_blank">JavaScript console</a> and see if there are any errors reported by Jetpack there.</li>
    <li>Use <a href="https://docs.wpvip.com/how-tos/enable-query-monitor/" target="_blank">Query Monitor</a> to help make PHP notices and warnings more noticeable and report anything you see.</li>
    </ul>
    <h2>Questions?</h2>
    <p>If you have any questions, related to this release, please <a href="https://docs.wpvip.com/technical-references/vip-support/" target="_blank">open a support ticket</a> and we will be happy to assist.</p>`;

    return content;
}

function persistConfig() {
    console.log('Persisting config', globalConfig);

    try {
        fs.writeFileSync(CONFIG_FILE, JSON.stringify(globalConfig, null, 2));
        execSync('git commit -avm "Update config.json"');
    } catch( err ) {
        console.error( err );
    }
}


function maybeConfigGit() {
    let email = '';
    try {
        email = execSync('git config user.email').toString().trim();
    } catch ( err ) {
        console.error( err );
    }

    if (!email) {
        try {
            execSync('git config user.email "Jetpack@update.bot"');
            execSync('git config user.name "Jetpack Update Bot"');
        } catch( err ) {
            console.error( err );
        }
    }
}

function removeFolder(folderName) {
    console.log(`Removing ${folderName}`);

    try {
        fs.rmSync(folderName, { recursive: true });
        execSync(`git add ${folderName}`)
        execSync(`git commit -m "Removing ${folderName}"`);
    } catch (err) {
        console.error(err);
    }
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
        // Remove lower versions than the allowed lowest version.
        let lowerVersions = await getLowerVersionsThanLowest( folders, plugin );
        if ( lowerVersions.length > 0 ) {
            for( const lowerVersion in lowerVersions ) {
                const folder = globalConfig[plugin].folderPrefix + lowerVersions[lowerVersion];
                delete globalConfig[plugin].current[lowerVersions[lowerVersion]]
                updatedSomething = await removePluginVersion( folder ) || updatedSomething;
            }
        }
        // If it's on the skip list, remove.
        for ( const toRemove in globalConfig[plugin].skip ) {
            const folder = globalConfig[plugin].folderPrefix + globalConfig[plugin].skip[toRemove];
            delete globalConfig[plugin].current[toRemove]
            updatedSomething = await removePluginVersion( folder ) || updatedSomething;
        }
    }

    return updatedSomething;
}

/**
 * Removes plugin folder and pings slack.
 *
 * @param string folder Plugin folder to remove
 * @returns bool Whether plugin folder was removed or not
 */
async function removePluginVersion( folder ) {
    if ( ! fs.existsSync( folder ) ) {
        return false;
    }

    removeFolder( folder );
    try {
        await pingSlack(`Removed ${folder}\nhttps://github.com/Automattic/vip-go-mu-plugins-ext/commits/trunk`);
    } catch ( err ) {
        console.error( err );
    }
    return true;
}

/**
 * Gets lower versions than lowest allowed version for a plugin.
 * For example, if we lowestVersion is 10.7 and we have 9.8 & 10.8 for versions, we'd consider 9.8 to be
 * a lower version than the lowest allowed version.
 *
 * @param array folders List of folders in directory
 * @param string plugin Plugin name
 * @returns array lowerVersion Lowest version allowed for plugin
 */
async function getLowerVersionsThanLowest( folders, plugin ) {
    let lowerVersions = [];
    const folderPrefix = globalConfig[plugin].folderPrefix;
    for( const folder in folders ) {
        if ( ! folders[folder].startsWith( folderPrefix ) ) {
            continue;
        }
        const versionNumber = folders[folder].substring( folderPrefix.length );
        if ( -1 === compareVersions( versionNumber, globalConfig[plugin].lowestVersion ) ) {
            lowerVersions.push( versionNumber );
        }
    }
    return lowerVersions;
}

async function main() {
    maybeConfigGit();

    let updatedSomething = false;

    updatedSomething = await maybeUpdateVersions();
    updatedSomething = await maybeDeleteRemovedVersions() || updatedSomething;

    if (updatedSomething) {
        persistConfig();
        try {
            execSync('git push');
        } catch( err ) {
            console.error( err );
        }
    }
}

main();
