#!/usr/bin/env node

const { execSync } = require("child_process");

/**
 * Compares two versions against each other
 * @param {*} a latestVersion Latest version in the plugin repo
 * @param {*} b currentVersion Current version in the vip-go-mu-plugins-ext repo
 * @returns int -1, 1, 0
 */
function compareVersions(a, b) {
    if (a === b) {
        return 0;
    }
    const aVersion = parseVersionString(a);
    const bVersion = parseVersionString(b);

    if (!aVersion || !bVersion) {
        throw new Error(`Cannot compare invalid versions: ${a} and ${b}`);
    }

    const majorCmpr = aVersion.major - bVersion.major;
    if (majorCmpr !== 0) {
        return majorCmpr;
    }

    const minorCmpr = aVersion.minor - bVersion.minor;
    if (minorCmpr !== 0) {
        return minorCmpr;
    }

    if (aVersion.patch !== bVersion.patch) {
        if (aVersion.patch === null) {
            return -1;
        }
        if (bVersion.patch === null) {
            return 1;
        }
        return aVersion.patch - bVersion.patch;
    }

    if (!aVersion.prerelease && !bVersion.prerelease) {
        return 0;
    }
    if (!aVersion.prerelease) {
        return 1;
    }
    if (!bVersion.prerelease) {
        return -1;
    }

    const channelOrder = { alpha: 0, beta: 1 };
    const channelCmpr = channelOrder[aVersion.prerelease.channel] - channelOrder[bVersion.prerelease.channel];
    if (channelCmpr !== 0) {
        return channelCmpr;
    }

    return aVersion.prerelease.number - bVersion.prerelease.number;
}

function isBeta(version) {
    return typeof version === 'string' && version.indexOf( 'beta') !== -1;
}

/**
 * Fetches all tags from a Git repository using git ls-remote
 * @param {string} repoUrl - Git repository URL
 * @param {string} versionPrefix - Optional prefix to filter tags (e.g., "v")
 * @returns {string[]} Array of tag names (without prefix)
 */
function fetchAllTags(repoUrl, versionPrefix = '') {
    try {
        const output = execSync(`git ls-remote --tags ${repoUrl}`, { 
            encoding: 'utf8',
            timeout: 30000 // 30 second timeout
        });
        
        const tags = output
            .split('\n')
            .map(line => {
                const match = line.match(/refs\/tags\/(.+?)(\^\{\})?$/);
                return match ? match[1] : null;
            })
            .filter(Boolean)
            .map(tag => versionPrefix && tag.startsWith(versionPrefix) ? tag.slice(versionPrefix.length) : tag)
            .filter(tag => versionPrefix === '' || !tag.includes('/')) // Only keep version-like tags
            .sort();
        
        return tags;
    } catch (error) {
        console.error(`Failed to fetch tags from ${repoUrl}:`, error.message);
        return [];
    }
}

/**
 * Parse a version string into structured components
 * @param {string} versionString - Version string to parse (e.g., "12.8.2" or "13.0-beta1")
 * @returns {Object|null} Parsed version object or null if invalid
 */
function parseVersionString(versionString) {
    // Match stable versions plus legacy "-beta1" and SemVer "-alpha.1"/"-beta.1" prereleases.
    const match = versionString.match(/^(\d+)\.(\d+)(?:\.(\d+))?(?:-(?:(alpha)(?:\.(\d+))?|(beta)(?:\.?(\d+))?))?$/);
    if (!match) {
        return null;
    }
    
    const [, major, minor, patch, alphaChannel, alphaNumber, betaChannel, betaNumber] = match;
    const prereleaseChannel = alphaChannel || betaChannel;
    const prereleaseNumber = alphaNumber || betaNumber;
    const normalizedPrereleaseNumber = prereleaseChannel
        ? (prereleaseNumber ? parseInt(prereleaseNumber, 10) : 1)
        : null;

    return {
        major: parseInt(major, 10),
        minor: parseInt(minor, 10),
        patch: patch ? parseInt(patch, 10) : null,
        alpha: prereleaseChannel === 'alpha' ? normalizedPrereleaseNumber : null,
        beta: prereleaseChannel === 'beta' ? normalizedPrereleaseNumber : null,
        prerelease: prereleaseChannel
            ? { channel: prereleaseChannel, number: normalizedPrereleaseNumber }
            : null,
        minorKey: `${major}.${minor}`,
        raw: versionString,
        
        toString() { return this.raw; },
        
        compare(other) {
            return compareVersions(this.raw, other.raw);
        }
    };
}

/**
 * Discovers all available versions for a plugin from its repository
 * @param {string} plugin - Plugin name
 * @param {Object} config - Plugin configuration from config.json
 * @returns {Object} Object with minorKey -> latestVersion mapping
 */
function discoverPluginVersions(plugin, config) {
    console.log(`Discovering versions for ${plugin}...`);
    
    const versionPrefix = config.versionPrefix || '';
    const tags = fetchAllTags(config.repo, versionPrefix);
    
    if (tags.length === 0) {
        console.warn(`No tags found for ${plugin}`);
        return {};
    }
    
    console.log(`Found ${tags.length} tags for ${plugin}`);
    
    // Parse and group versions by minor version
    const versionsByMinor = {};
    
    for (const tag of tags) {
        const version = parseVersionString(tag);
        if (!version) {
            continue; // Skip invalid version strings
        }
        
        // Skip versions below lowestVersion
        if (compareVersions(version.raw, config.lowestVersion) < 0) {
            continue;
        }
        
        // Skip versions in skip or ignore lists
        if (config.skip.includes(version.minorKey) || config.ignore.includes(version.minorKey)) {
            continue;
        }
        
        // Group by minor version, keeping the latest patch for each
        const current = versionsByMinor[version.minorKey];
        if (!current || version.compare(current) > 0) {
            versionsByMinor[version.minorKey] = version;
        }
    }
    
    // Convert back to string format for compatibility
    const result = {};
    for (const [minorKey, version] of Object.entries(versionsByMinor)) {
        result[minorKey] = version.raw;
    }
    
    console.log(`Discovered versions for ${plugin}:`, Object.keys(result));
    return result;
}

/**
 * Adds a prefix to a version string
 * 
 * @param {string} version Version string without prefix
 * @param {string} prefix The prefix to add (e.g., "v")
 * @returns {string} Version string with prefix
 */
function addVersionPrefix(version, prefix = '') {
    if (!prefix) {
        return version;
    }

    return `${prefix}${version}`;
}

/**
 * Resolves a release zip file name, for plugins whose release asset names include the version
 *
 * @param {string} releaseZipFileName File name without ".zip", optionally containing "{version}"
 * @param {string} version Version string without prefix
 * @returns {string} File name with "{version}" replaced
 */
function resolveReleaseZipFileName(releaseZipFileName, version) {
    return releaseZipFileName.replaceAll('{version}', version);
}

module.exports = {
    compareVersions,
    isBeta,
    addVersionPrefix,
    resolveReleaseZipFileName,
    fetchAllTags,
    parseVersionString,
    discoverPluginVersions
};
