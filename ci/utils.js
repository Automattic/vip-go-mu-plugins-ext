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
    const aParts = parseVersion(a);
    const bParts = parseVersion(b);

    const majorCmpr = aParts[0] - bParts[0];
    if (majorCmpr !== 0) {
        return majorCmpr;
    }

    const minorCmpr = aParts[1] - bParts[1];
    if (minorCmpr !== 0) {
        return minorCmpr;
    }

    if ( isBeta( a ) && isBeta( b ) ) {
        return stripBetaFromString( aParts[2] ) - stripBetaFromString( bParts[2] );
    } else if ( isBeta( b ) && ! isBeta( a ) ) {
        return 1;
    } else if ( isBeta( a) && ! isBeta( b ) ) {
        return -1;
    }

    if (aParts.length === 2) {
        return -1;
    }
    if (bParts.length === 2) {
        return 1;
    }

    return aParts[2] - bParts[2];
}

function parseVersion(version) {
    return version.split(/[.-]/).map(part => isNaN(Number(part)) ? part : Number(part));
}

function isBeta(version) {
    return typeof version === 'string' && version.indexOf( 'beta') !== -1;
}

function stripBetaFromString( string ) {
    return string.replace( 'beta', '' );
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
    // Match semver patterns: X.Y[.Z][-beta[N]]
    const match = versionString.match(/^(\d+)\.(\d+)(?:\.(\d+))?(?:-beta(\d*))?$/);
    if (!match) {
        return null;
    }
    
    const [, major, minor, patch, beta] = match;
    return {
        major: parseInt(major, 10),
        minor: parseInt(minor, 10),
        patch: patch ? parseInt(patch, 10) : null,
        beta: beta !== undefined ? (beta ? parseInt(beta, 10) : 1) : null,
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

module.exports = {
    compareVersions,
    isBeta,
    addVersionPrefix,
    fetchAllTags,
    parseVersionString,
    discoverPluginVersions
};
