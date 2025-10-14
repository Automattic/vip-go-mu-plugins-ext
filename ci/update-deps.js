#!/usr/bin/env node

const { default: axios } = require("axios");
const fs = require("fs");
const path = require("path");
const { execSync } = require("child_process");
const { compareVersions, addVersionPrefix } = require("./utils");
const marked = require("marked");
const os = require("os");

// Resolve the path to config.json relative to the script's location
const CONFIG_FILE_PATH = path.resolve(__dirname, "../config.json");

const { LOBBY_VIP_TOKEN, CHANGELOG_VIP_TOKEN } = process.env;

// Parse command line arguments
const args = process.argv.slice(2);
const DRY_RUN = args.includes("--dry-run");

if (DRY_RUN) {
  console.log("🔍 Running in DRY RUN mode - no changes will be committed or pushed");
}

const configFile = fs.readFileSync(CONFIG_FILE_PATH, "utf8");
const globalConfig = JSON.parse(configFile);
console.log("Config", globalConfig);

const LOBBY_URL = "lobby.vip.wordpress.com";
const CHANGELOG_URL = "wpvipchangelog.wordpress.com";

function getPrefixedVersion(plugin, version) {
  const versionPrefix = globalConfig[plugin].versionPrefix || "";
  return addVersionPrefix(version, versionPrefix);
}

function incrementVersion(plugin, version) {
  const [major, minor] = version.split(".").map(Number);
  const maxMinor = plugin === "jetpack" ? 9 : 25; // Since Jetpack version minors usually don't go over 9, we need to stop looking and jump to the next major.
  let result = "";
  if (minor === maxMinor) {
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
    return "";
  }
  if (!version) {
    return "1";
  }
  return Number(version) + 1 + "";
}

function formatVersion(minor, patch) {
  if (!patch) {
    return `${minor}`;
  }
  if (patch.startsWith("beta")) {
    return `${minor}-${patch}`;
  }
  return `${minor}.${patch}`;
}

async function checkVersionExists(plugin, version) {
  try {
    const prefixedVersion = getPrefixedVersion(plugin, version);
    const exists = await axios.get(
      `${globalConfig[plugin].repo}/tree/${prefixedVersion}`
    );
    return exists.status === 200;
  } catch (e) {
    return false;
  }
}

async function findPatch(plugin, minor) {
  // TODO: this is dumb, and will likely need to be changed when we add next dependency that doesn't follow the semver pattern
  let currentPatch = plugin === "jetpack" ? "beta" : "0";
  let lastPatch = null;
  let foundLastPatch = false;

  while (!foundLastPatch) {
    const version = formatVersion(minor, currentPatch);

    const exists = await checkVersionExists(plugin, version);
    if (exists) {
      lastPatch = currentPatch;
    } else if (!currentPatch.startsWith("beta")) {
      foundLastPatch = true;
    }

    currentPatch = incrementPatchVersion(currentPatch, exists);
  }
  return lastPatch;
}

/**
 * Downloads and extracts a plugin release zip file.
 *
 * @param {string} plugin Plugin name
 * @param {string} version Version tag to download
 * @param {string} folder Destination folder to extract to
 * @returns {boolean} Whether the download and extraction was successful
 */
async function downloadReleaseZip(plugin, version, folder) {
  const config = globalConfig[plugin];
  const repoUrl = config.repo;
  const releaseZipFileName = config.releaseZipFileName;
  const prefixedVersion = getPrefixedVersion(plugin, version);
  const zipUrl = `${repoUrl}/releases/download/${prefixedVersion}/${releaseZipFileName}.zip`;
  
  // Check for dry run mode at the beginning
  if (DRY_RUN) {
    console.log(`[DRY RUN] Would download and extract ${zipUrl} to ${folder}`);
    return true;
  }
  
  console.log(`Downloading zip from ${zipUrl}...`);
  
  try {
    // Create parent directory for the folder if needed
    const parentDir = path.dirname(folder);
    if (!fs.existsSync(parentDir)) {
      fs.mkdirSync(parentDir, { recursive: true });
    }
    
    // Download the zip file to a temporary location
    const tempDir = fs.mkdtempSync(path.join(os.tmpdir(), `${plugin}-${version}-`));
    const tempZipPath = path.join(tempDir, 'temp.zip');
    
    try {
      const response = await axios({
        method: 'get',
        url: zipUrl,
        responseType: 'arraybuffer'
      });
      
      fs.writeFileSync(tempZipPath, response.data);
      console.log(`Downloaded zip to temporary location`);
      
      // Extract directly to the right location
      execSync(`unzip -o ${tempZipPath} -d ${folder}`);
      
      // Clean up temp directory
      fs.rmSync(tempDir, { recursive: true, force: true });
      
      console.log(`Successfully downloaded and extracted ${plugin} ${version} to ${folder}`);
      return true;
    } catch (error) {
      // Clean up temp directory on error
      if (fs.existsSync(tempDir)) {
        fs.rmSync(tempDir, { recursive: true, force: true });
      }
      throw error;
    }
  } catch (error) {
    throw new Error(`Failed to download or extract ${plugin} ${version}: ${error.message}`);
  }
}

/**
 * Executes a command or logs it in dry run mode
 * 
 * @param {string} command Command to execute
 * @returns {string|null} Command output or null in dry run mode
 */
function execCommand(command) {
  if (DRY_RUN) {
    console.log(`[DRY RUN] Would execute: ${command}`);
  } else {
    return execSync(command);
  }
}

async function pingSlack(message) {
  if (DRY_RUN) {
    console.log(`[DRY RUN] Would send Slack message: ${message}`);
    return;
  }

  if (process.env.SLACK_WEBHOOK) {
    const payload = {
      text: message,
    };
    await axios.post(process.env.SLACK_WEBHOOK, payload);
  } else {
    throw new Error("No slack webhook configured");
  }
}

async function maybeUpdateVersion(plugin, minorVersion, version) {
  const config = globalConfig[plugin];
  const folder = `${config.folderPrefix}${minorVersion}`;
  const prefixedVersion = getPrefixedVersion(plugin, version);

  try {
    if (config.current[minorVersion]) {
      const oldVersion = config.current[minorVersion];
      const versionCmp = compareVersions(version, oldVersion);
      if (versionCmp < 0) {
        console.log(
          `${minorVersion} tried to downgrade to ${version}, but skipped`
        );
        return false;
      } else if (versionCmp === 0) {
        console.log(`${minorVersion} already up to date`);
        return false;
      }

      // update
      execCommand(`git rm -r ${folder}`);
      execCommand(
        `git commit -m "Removing ${folder} for subtree replacement to ${version}"`
      );

      if (config.releaseZipFileName) {
        await downloadReleaseZip(plugin, version, folder);
        execCommand(`git add ${folder}`);
        execCommand(`git commit -m "Update ${plugin} ${folder} with tag ${version}"`);
      } else {
        const command = `git subtree add -P ${folder} --squash ${config.repo} ${prefixedVersion} -m "Update ${plugin} ${folder} subtree with tag ${version}"`;
        execCommand(command);
      }

      if (
        plugin === "jetpack" &&
        oldVersion.includes("beta") &&
        !version.includes("beta")
      ) {
        draftJPPost(version, "release");
      }
    } else {
      // add
      if (config.releaseZipFileName) {
        await downloadReleaseZip(plugin, version, folder);
        execCommand(`git add ${folder}`);
        execCommand(`git commit -m "Add ${plugin} ${folder} with tag ${version}"`);
      } else {
        const command = `git subtree add -P ${folder} --squash ${config.repo} ${prefixedVersion} -m "Add ${plugin} ${folder} subtree with tag ${version}"`;
        execCommand(command);
      }
      if (plugin === "jetpack" && version.includes("beta")) {
        draftJPPost(version, "beta");
      }
    }
    await pingSlack(
      `Updated ${folder} to ${version}\nhttps://github.com/Automattic/vip-go-mu-plugins-ext/commits/trunk`
    );
    globalConfig[plugin].current[minorVersion] = version;
    return true;
  } catch (err) {
    console.error(err);
    return false;
  }
}

/**
 * Drafts a post for Jetpack releases.
 *
 * @param {string} version - The version of Jetpack being released
 * @param {string} type - Type of post being drafted. Accepted values: beta, release
 * @returns {boolean} Whether the post was successfully drafted or not
 */
async function draftJPPost(version, type) {
  const allowedTypes = ["beta", "release"];
  if (!allowedTypes.includes(type)) {
    return false;
  }

  if (type === "beta" && !version.includes("beta")) {
    return false;
  }

  const changelog = await fetchChangelog(version);
  const section = extractChangelogSection(changelog, version, type);

  if (section) {
    let title;
    let content;
    let p2;
    if (type === "beta") {
      title = `Call for Testing: Jetpack ${version}`;
      content = createJPBetaPostContent(version, section);
      p2 = LOBBY_URL;
    } else {
      title = `New Release: Jetpack ${version}`;
      content = createJPReleasePostContent(version, section);
      p2 = CHANGELOG_URL;
    }

    const post = await createJPPost(title, content, type);
    if (post.id) {
      const postUrl = `https://${p2}/wp-admin/post.php?post=${post.id}&action=edit`;
      pingSlack(
        `<!subteam^S01SYE0V8TA> Jetpack ${version} ${type} draft created for review: ${postUrl}. Don't forget to deploy first before publishing!`
      );
      return true;
    } else {
      pingSlack(
        `<!subteam^S01SYE0V8TA> Error creating Jetpack ${version} draft.`
      );
      return false;
    }
  } else {
    pingSlack(
      `<!subteam^S01SYE0V8TA> Error generating Jetpack ${version} changelog. Please review and manually generate.`
    );
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
  const prefixedVersion = getPrefixedVersion("jetpack", version);
  const url = `https://raw.githubusercontent.com/Automattic/jetpack-production/${prefixedVersion}/CHANGELOG.md`;

  const response = await axios.get(url);
  return response.data;
}

/**
 * Extract changelog section for specified Jetpack version.
 *
 * @param {string} changelog - The entire changelog file contents of Jetpack
 * @param {string} version - The version of Jetpack being released
 * @param {string} type - Type of Jetpack version being released. Accepted values: beta, release
 * @returns {string|bool} changelog section for the specified version
 */
function extractChangelogSection(changelog, version, type) {
  let regex = new RegExp(
    `^\\s*(## ${version}\\s.*?)^\\s*### Other changes`,
    "ms"
  );
  let match = regex.exec(changelog);

  if (!match && type === "release") {
    // Re-try with [x.x] format in changelog title since that's also used for releases
    regex = new RegExp(
      `^\\s*(## \\[${version}\\]\\s.*?)^\\s*### Other changes`,
      "ms"
    );
    match = regex.exec(changelog);
  }

  if (match) {
    const changes = match[1].trim();
    // Strip first line out since it's just a heading
    const firstLineRegex = new RegExp(`(?<=\\n).*`, "ms");
    const section = firstLineRegex.exec(changes);
    if (section) {
      // Strip out the PR numbers since they're not needed
      return section[0].replace(/(\s*\[#\d+])/g, "");
    }
  }

  return false;
}

/**
 * Creates the draft for the Jetpack post.
 *
 * @param {string} title - The title of the post
 * @param {string} content - The content of the post
 * @param {string} type - The type of Jetpack announcement post
 * @returns {Object|bool} The response data from the API
 */
async function createJPPost(title, content, type) {
  if (DRY_RUN) {
    console.log(`[DRY RUN] Would create Jetpack post: "${title}"`);
    return { id: 12345 }; // Mock ID for dry run
  }

  let p2;
  let bearerToken;
  let cat;
  let tag;
  if (type === "beta") {
    p2 = LOBBY_URL;
    bearerToken = LOBBY_VIP_TOKEN;
    cat = 636069;
    tag = 636069;
  } else {
    p2 = CHANGELOG_URL;
    bearerToken = CHANGELOG_VIP_TOKEN;
    cat = 1171;
    tag = 5905;
  }

  const data = {
    title: title,
    content: content,
    status: "draft",
    categories: cat,
    tags: tag,
  };

  return axios
    .post(`https://public-api.wordpress.com/wp/v2/sites/${p2}/posts`, data, {
      headers: {
        "Content-Type": "application/json",
        Authorization: `Bearer ${bearerToken}`,
      },
    })
    .then((response) => {
      console.log(`Status Code: ${response.status}`);
      console.log("Data:", response.data);
      return response.data;
    })
    .catch((error) => {
      console.error("Error:", error.message);
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
  const image =
    "https://lobby-vip.files.wordpress.com/2021/05/3-v1_52018_preview-2.png?w=960";
  let content = `<img src="${image}" alt="New Jetpack release">`;
  content += `<p>Jetpack ${version} has been made the default Jetpack version on the VIP Platform.</p>`;
  content += `<h2>What is being added or changed?</h2>`;
  content += marked.parse(section);

  const prefixedVersion = getPrefixedVersion("jetpack", version);
  const releaseNotesLink = `https://github.com/Automattic/jetpack-production/releases/tag/${prefixedVersion}`;
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
  const prefixedVersion = getPrefixedVersion("jetpack", version);
  const downloadLink = `<a href="https://github.com/Automattic/jetpack-production/releases/tag/${prefixedVersion}">available here</a>`;
  let content = `<p>Jetpack <strong>${version}</strong> is available now for testing and the download link is ${downloadLink} </p>`;

  const officialVersion = version.replace(/-beta\d?/g, "");
  const today = new Date();
  const dateFormatter = new Intl.DateTimeFormat("en-US", {
    weekday: "long",
    year: "numeric",
    month: "long",
    day: "numeric",
  });
  const releaseDate = dateFormatter.format(today.setDate(today.getDate() + 15)); // Assumes it's a Tuesday
  content += `<p>Jetpack ${officialVersion} will be deployed to VIP on <strong>${releaseDate}</strong>*. The upgrade is expected to be performed at 17:00 UTC (1:00PM ET).</p>`;

  content += `<p><i>*This deployment date and time are subject to change if issues are discovered during testing of the Jetpack release.</i></p>
    <p>A full list of changes is available in the <a href="https://github.com/Automattic/jetpack/commits/" target="_blank">commit log</a>.</p>
    <h2>What is being added or changed?</h2>`;
  content += marked.parse(section) + '\n';

  content += marked.parse(`## What do I need to do?

We recommend the below:

1. Installing the release on your non-production sites using [these instructions](https://docs.wpvip.com/how-tos/jetpack/version-updates/#h-pinning-to-a-version).
2. Running through the testing flows outlined in the [Jetpack Testing Guide](https://github.com/Automattic/jetpack/blob/trunk/projects/plugins/jetpack/to-test.md).

As you're testing, there are a few things to keep in mind:

- Check your browser's [JavaScript console](https://wordpress.org/documentation/article/using-your-browser-to-diagnose-javascript-errors/) and see if there are any errors reported by Jetpack there.
- Use [Query Monitor](https://docs.wpvip.com/how-tos/enable-query-monitor/) to help make PHP notices and warnings more noticeable and report anything you see.

## Questions?

If you have any questions, related to this release, please [open a support ticket](https://docs.wpvip.com/technical-references/vip-support/) and we will be happy to assist.`);

  return content;
}

function persistConfig() {
  console.log("Persisting config", globalConfig);

  try {
    fs.writeFileSync(CONFIG_FILE_PATH, JSON.stringify(globalConfig, null, 2));
    execCommand('git commit -avm "Update config.json"');
  } catch (err) {
    console.error(err);
  }
}

function maybeConfigGit() {
  let email = "";
  try {
    email = execSync("git config user.email").toString().trim();
  } catch (err) {
    console.error(err);
  }

  if (!email) {
    try {
      execCommand('git config user.email "Jetpack@update.bot"');
      execCommand('git config user.name "Jetpack Update Bot"');
    } catch (err) {
      console.error(err);
    }
  }
}

function removeFolder(folderName) {
  if (DRY_RUN) {
    console.log(`[DRY RUN] Would remove ${folderName}`);
    return;
  }

  try {
    fs.rmSync(folderName, { recursive: true });
    execCommand(`git add ${folderName}`);
    execCommand(`git commit -m "Removing ${folderName}"`);
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
    let foundLastMinor = false;
    while (!foundLastMinor) {
      if (
        config.skip.includes(currentMinor) ||
        config.ignore.includes(currentMinor)
      ) {
        console.log("Skipping", currentMinor);
      } else {
        console.log("Checking", currentMinor);
        const patch = await findPatch(plugin, currentMinor);
        if (patch === null) {
          console.log("Not found");
          foundLastMinor = true;
        } else {
          const version = formatVersion(currentMinor, patch);
          console.log("Found:", version);

          const updated = await maybeUpdateVersion(
            plugin,
            currentMinor,
            version
          );
          updatedSomething = updated || updatedSomething;
        }
      }
      currentMinor = incrementVersion(plugin, currentMinor);
    }
  }

  return updatedSomething;
}

/**
 * Get all folders at directories where plugin folders might be located.
 */
function getAllFolders() {
  const folderPrefixes = Object.values(globalConfig).map(config => config.folderPrefix);
  // Get all unique directory paths where plugin folders might be located
  const directories = new Set(['./']);
  
  // Check if any folder prefixes contain subdirectories
  folderPrefixes.forEach(prefix => {
    const parts = prefix.split('/');
    if (parts.length > 1) {
      // Remove the last part which is the actual prefix
      parts.pop();
      directories.add('./' + parts.join('/') + '/');
    }
  });

  const folders = [];

  for (const directory of directories) {
    const dirFolders = fs.readdirSync(directory);
    const dirPrefix = directory.replace('./', '');
    folders.push(...dirFolders.map(folder => `${dirPrefix}${folder}`));
  }
  
  return folders;
}

/**
 * Checks folders against config to see if they need to be removed from repo.
 *
 * @returns bool updatedSomething Whether something was deleted or not
 */
async function maybeDeleteRemovedVersions() {
  console.log("Checking existing folders");

  let updatedSomething = false;
  const folders = getAllFolders();
  for (const plugin in globalConfig) {
    // Remove lower versions than the allowed lowest version.
    let lowerVersions = await getLowerVersionsThanLowest(folders, plugin);
    if (lowerVersions.length > 0) {
      for (const lowerVersion in lowerVersions) {
        const folder =
          globalConfig[plugin].folderPrefix + lowerVersions[lowerVersion];
        delete globalConfig[plugin].current[lowerVersions[lowerVersion]];
        updatedSomething =
          (await removePluginVersion(folder)) || updatedSomething;
      }
    }
    // If it's on the skip list, remove.
    for (const toRemove in globalConfig[plugin].skip) {
      const folder =
        globalConfig[plugin].folderPrefix + globalConfig[plugin].skip[toRemove];
      delete globalConfig[plugin].current[toRemove];
      updatedSomething =
        (await removePluginVersion(folder)) || updatedSomething;
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
async function removePluginVersion(folder) {
  if (!fs.existsSync(folder)) {
    return false;
  }

  removeFolder(folder);
  try {
    await pingSlack(
      `Removed ${folder}\nhttps://github.com/Automattic/vip-go-mu-plugins-ext/commits/trunk`
    );
  } catch (err) {
    console.error(err);
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
async function getLowerVersionsThanLowest(folders, plugin) {
  let lowerVersions = [];
  const folderPrefix = globalConfig[plugin].folderPrefix;
  const lowestVersion = globalConfig[plugin].lowestVersion;
  for (const folder in folders) {
    if (!folders[folder].startsWith(folderPrefix)) {
      continue;
    }
    const versionNumber = folders[folder].substring(folderPrefix.length);
    if ( compareVersions(versionNumber, lowestVersion) < 0 ) {
      lowerVersions.push(versionNumber);
    }
  }
  return lowerVersions;
}

async function main() {
  maybeConfigGit();

  let updatedSomething = false;

  updatedSomething = await maybeUpdateVersions();
  updatedSomething = (await maybeDeleteRemovedVersions()) || updatedSomething;

  if (updatedSomething) {
    persistConfig();
    try {
      execCommand("git push");
    } catch (err) {
      console.error(err);
    }
  }
}

main();
