#!/usr/bin/env node

/**
 * Compares two versions against each other
 * @param {*} a latestVersion Latest version in the plugin repo
 * @param {*} b currentVersion Current version in the vip-go-mu-plugins-ext repo
 * @param {string} prefix The prefix to strip (e.g., "v")
 * @returns int -1, 1, 0
 */
function compareVersions(a, b, prefix = '') {
    if (a === b) {
        return 0;
    }
    const aParts = parseVersion(a, prefix)
    const bParts = parseVersion(b, prefix)

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

function parseVersion(version, prefix = '') {
    // First strip any prefix before parsing
    const strippedVersion = stripVersionPrefix(version, prefix);
    return strippedVersion.split(/[.-]/).map(part => isNaN(Number(part)) ? part : Number(part));
}

function isBeta(version) {
    return typeof version === 'string' && version.indexOf( 'beta') !== -1;
}

function stripBetaFromString( string ) {
    return string.replace( 'beta', '' );
}

/**
 * Strips any non-numeric prefix from a version string
 * 
 * @param {string} version Version string (e.g., "v1.2.3" or "1.2.3")
 * @param {string} prefix Optional specific prefix to remove (e.g., "v")
 * @returns {string} Version string without prefix
 */
function stripVersionPrefix(version, prefix = '') {
    if (typeof version !== 'string') {
        return version;
    }
    
    if (prefix) {
        // If a specific prefix is provided, only strip that prefix
        return version.startsWith(prefix) ? version.substring(prefix.length) : version;
    }

    return version;
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
    // Make sure there's no existing prefix
    const strippedVersion = stripVersionPrefix(version, prefix);
    return `${prefix}${strippedVersion}`;
}

module.exports = {
    compareVersions,
    isBeta,
    stripVersionPrefix,
    addVersionPrefix
};
