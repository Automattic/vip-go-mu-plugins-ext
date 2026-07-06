#!/usr/bin/env node

const { default: axios } = require("axios");
const fs = require("fs");
const path = require("path");
const { execSync, execFileSync } = require("child_process");
const { compareVersions, addVersionPrefix, discoverPluginVersions } = require("./utils");
const os = require("os");

// Resolve the path to config.json relative to the script's location
const CONFIG_FILE_PATH = path.resolve(__dirname, "../config.json");
// Project root is where config.json is located
const PROJECT_ROOT = path.dirname(CONFIG_FILE_PATH);

// Parse command line arguments
const args = process.argv.slice(2);
const DRY_RUN = args.includes("--dry-run");

if (DRY_RUN) {
  console.log("🔍 Running in DRY RUN mode - no changes will be committed or pushed");
}

const configFile = fs.readFileSync(CONFIG_FILE_PATH, "utf8");
const globalConfig = JSON.parse(configFile);
console.log("Config", globalConfig);

function getPrefixedVersion(plugin, version) {
  const versionPrefix = globalConfig[plugin].versionPrefix || "";
  return addVersionPrefix(version, versionPrefix);
}

// Legacy functions removed - replaced with git-based version discovery in utils.js

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
  const releaseZipRootFolder = config.releaseZipRootFolder;
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
      
      // Extract directly to the right location.
      execFileSync("unzip", ["-o", tempZipPath, "-d", folder]);

      if (releaseZipRootFolder) {
        const extractedRoot = path.join(folder, releaseZipRootFolder);

        if (!fs.existsSync(extractedRoot)) {
          throw new Error(`Expected release zip root folder ${releaseZipRootFolder} was not found`);
        }

        for (const entry of fs.readdirSync(extractedRoot)) {
          fs.renameSync(path.join(extractedRoot, entry), path.join(folder, entry));
        }

        fs.rmSync(extractedRoot, { recursive: true, force: true });
      }
      
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
    return execSync(command, { cwd: PROJECT_ROOT });
  }
}

async function maybeUpdateVersion(plugin, minorVersion, version) {
  const config = globalConfig[plugin];
  const folderRelative = `${config.folderPrefix}${minorVersion}`;
  const folder = path.join(PROJECT_ROOT, folderRelative);
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

      // update - only remove if folder actually exists
      const folderExists = fs.existsSync(folder);
      if (folderExists) {
        execCommand(`git rm -r ${folderRelative}`);
        execCommand(
          `git commit -m "Removing ${folderRelative} for subtree replacement to ${version}"`
        );
      } else {
        console.log(`Folder ${folderRelative} does not exist on disk (config out of sync), skipping removal`);
      }

      // Ensure parent directory exists for subtree operations
      const parentDir = path.dirname(folder);
      if (!fs.existsSync(parentDir)) {
        fs.mkdirSync(parentDir, { recursive: true });
      }

      if (config.releaseZipFileName) {
        await downloadReleaseZip(plugin, version, folder);
        execCommand(`git add ${folderRelative}`);
        execCommand(`git commit -m "Update ${plugin} ${folderRelative} with tag ${version}"`);
      } else {
        const command = `git subtree add -P ${folderRelative} --squash ${config.repo} ${prefixedVersion} -m "Update ${plugin} ${folderRelative} subtree with tag ${version}"`;
        execCommand(command);
      }

    } else {
      // add - ensure parent directory exists for subtree operations
      const parentDir = path.dirname(folder);
      if (!fs.existsSync(parentDir)) {
        fs.mkdirSync(parentDir, { recursive: true });
      }

      if (config.releaseZipFileName) {
        await downloadReleaseZip(plugin, version, folder);
        execCommand(`git add ${folderRelative}`);
        execCommand(`git commit -m "Add ${plugin} ${folderRelative} with tag ${version}"`);
      } else {
        const command = `git subtree add -P ${folderRelative} --squash ${config.repo} ${prefixedVersion} -m "Add ${plugin} ${folderRelative} subtree with tag ${version}"`;
        execCommand(command);
      }
    }
    globalConfig[plugin].current[minorVersion] = version;
    return true;
  } catch (err) {
    console.error(err);
    return false;
  }
}

function persistConfig() {
  console.log("Persisting config", globalConfig);

  if (DRY_RUN) {
    console.log(`[DRY RUN] Would write ${CONFIG_FILE_PATH}`);
    execCommand('git commit -avm "Update config.json"');
    return;
  }

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

function removeFolder(folderPath, folderRelative) {
  // Use folderRelative for display, folderPath for filesystem operations
  const displayName = folderRelative || folderPath;
  
  if (DRY_RUN) {
    console.log(`[DRY RUN] Would remove ${displayName}`);
    return;
  }

  try {
    fs.rmSync(folderPath, { recursive: true });
    execCommand(`git add ${displayName}`);
    execCommand(`git commit -m "Removing ${displayName}"`);
  } catch (err) {
    console.error(err);
  }
}

/**
 * Cleans up obsolete entries from the config's current property
 * Removes versions that are:
 * - Lower than lowestVersion
 * - In the skip list
 * @returns {boolean} Whether any cleanup was performed
 */
function cleanupObsoleteConfigEntries() {
  console.log("Cleaning up obsolete config entries...");
  let cleanedSomething = false;
  
  for (const plugin in globalConfig) {
    const config = globalConfig[plugin];
    const { current, lowestVersion, skip } = config;
    
    // Create list of versions to remove
    const versionsToRemove = [];
    
    for (const [minorVersion, fullVersion] of Object.entries(current)) {
      // Remove if lower than lowestVersion
      if (compareVersions(minorVersion, lowestVersion) < 0) {
        console.log(`  ${plugin}: Removing ${minorVersion} (${fullVersion}) - below lowestVersion ${lowestVersion}`);
        versionsToRemove.push(minorVersion);
      }
      // Remove if in skip list
      else if (skip.includes(minorVersion)) {
        console.log(`  ${plugin}: Removing ${minorVersion} (${fullVersion}) - in skip list`);
        versionsToRemove.push(minorVersion);
      }
    }
    
    // Remove the obsolete versions
    for (const version of versionsToRemove) {
      delete config.current[version];
      cleanedSomething = true;
    }
    
    if (versionsToRemove.length > 0) {
      console.log(`  ${plugin}: Cleaned up ${versionsToRemove.length} obsolete entries`);
    }
  }
  
  return cleanedSomething;
}

async function maybeUpdateVersions() {
  let updatedSomething = false;

  // Clean up obsolete config entries first
  updatedSomething = cleanupObsoleteConfigEntries() || updatedSomething;

  console.log("Discovering available versions for all plugins in parallel...");
  
  // Discover versions for all plugins in parallel
  const pluginNames = Object.keys(globalConfig);
  const versionDiscoveryPromises = pluginNames.map(async (plugin) => {
    try {
      const availableVersions = await discoverPluginVersions(plugin, globalConfig[plugin]);
      return { plugin, availableVersions };
    } catch (error) {
      console.error(`Failed to discover versions for ${plugin}:`, error.message);
      return { plugin, availableVersions: {} };
    }
  });

  const allVersionsResults = await Promise.all(versionDiscoveryPromises);
  
  // Process updates sequentially (git operations must be serial)
  for (const { plugin, availableVersions } of allVersionsResults) {
    console.log(`Processing updates for ${plugin}...`);
    
    for (const [minorVersion, latestVersion] of Object.entries(availableVersions)) {
      try {
        const updated = await maybeUpdateVersion(plugin, minorVersion, latestVersion);
        updatedSomething = updated || updatedSomething;
      } catch (error) {
        console.error(`Failed to update ${plugin} ${minorVersion} to ${latestVersion}:`, error.message);
      }
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
  const directories = new Set([PROJECT_ROOT]);
  
  // Check if any folder prefixes contain subdirectories
  folderPrefixes.forEach(prefix => {
    const parts = prefix.split('/');
    if (parts.length > 1) {
      // Remove the last part which is the actual prefix
      parts.pop();
      const subDir = path.join(PROJECT_ROOT, parts.join('/'));
      directories.add(subDir);
    }
  });

  const folders = [];

  for (const directory of directories) {
    try {
      if (fs.existsSync(directory)) {
        const dirFolders = fs.readdirSync(directory);
        const relativePath = path.relative(PROJECT_ROOT, directory);
        const dirPrefix = relativePath ? relativePath + '/' : '';
        folders.push(...dirFolders.map(folder => `${dirPrefix}${folder}`));
      } else {
        console.log(`Directory ${directory} does not exist, skipping`);
      }
    } catch (error) {
      console.warn(`Failed to read directory ${directory}:`, error.message);
    }
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
        const folderRelative = globalConfig[plugin].folderPrefix + lowerVersions[lowerVersion];
        const folder = path.join(PROJECT_ROOT, folderRelative);
        delete globalConfig[plugin].current[lowerVersions[lowerVersion]];
        updatedSomething =
          (await removePluginVersion(folder, folderRelative)) || updatedSomething;
      }
    }
    // If it's on the skip list, remove.
    for (const skipVersion of globalConfig[plugin].skip) {
      const folderRelative = globalConfig[plugin].folderPrefix + skipVersion;
      const folder = path.join(PROJECT_ROOT, folderRelative);
      delete globalConfig[plugin].current[skipVersion];
      updatedSomething =
        (await removePluginVersion(folder, folderRelative)) || updatedSomething;
    }
  }

  return updatedSomething;
}

/**
 * Removes plugin folder.
 *
 * @param {string} folder Plugin folder absolute path for filesystem operations
 * @param {string} folderRelative Plugin folder relative path for git operations
 * @returns {boolean} Whether plugin folder was removed or not
 */
function removePluginVersion(folder, folderRelative) {
  if (!fs.existsSync(folder)) {
    return false;
  }

  removeFolder(folder, folderRelative);
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
